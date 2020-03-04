<?php

/*****************************************
 *
 * Bleumi Pay Orders CRON ("Orders Updater") functions
 *
 * Updates WooCommerce Order Statuses changes to Bleumi Pay
 *
 * Any status updates in orders is posted to Bleumi Pay by these function
 *
 *
 ******************************************/
/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

namespace BleumiPay\PaymentGateway\Cron;

use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use \BleumiPay\PaymentGateway\Cron\APIHandler;
use \BleumiPay\PaymentGateway\Cron\DBHandler;

class Order
{
    protected $logger;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;
    protected $store;
    protected $api;
    protected $tokens;
    protected $error_handler;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $apiKey = $this->scopeConfig->getValue('payment/bleumipaymethod/api_key', ScopeInterface::SCOPE_STORE);
        $this->store = new DBHandler($logger);
        $this->api = new APIHandler($apiKey, $logger);
        $this->error_handler = new ExceptionHandler($logger);
    }

    /**
     *
     * Orders cron
     *
     */

    public function execute()
    {
        $data_source = 'orders-cron';
        $start_at = $this->store->getCronTime("order_updated_at");
        $this->logger->info('bleumi_pay: ' . $data_source . ' : looking for orders modified after : ' . $start_at);
        $orders = $this->store->getUpdatedOrders($start_at);
        $updated_at = 0;
        foreach ($orders as $order) {
            $updated_at = $order['updated_at'];
            $this->logger->info('bleumi_pay: ' . $data_source . ' : processing payment :' . $order['entity_id']);
            $this->syncOrder($order, $data_source);
        }
        if ($updated_at > 0) {
            $updated_at = strtotime($updated_at) + 1;
            $this->store->updateRuntime("order_updated_at", date("Y-m-d H:i:s", $updated_at));
            $this->logger->info('bleumi_pay ' . $data_source . ' : setting order_updated_at: ' . date('Y-m-d H:i:s', $updated_at));
        }

        //To verify the status of settle_in_progress orders
        $this->verifySettleOperationStatuses($data_source);
        //Fail order that are awaiting payment confirmation after cut-off (24 Hours) time.
        $this->failUnconfirmedPaymentOrders($data_source);
        //To verify the status of refund_in_progress orders
        $this->verifyRefundOperationStatuses($data_source);
        //To ensure balance in all tokens are refunded
        $this->verifyCompleteRefund($data_source);
    }

    public function syncOrder($order, $data_source)
    {
        $entity_id = null;
        try {
            $entity_id = $order['entity_id'];
        } catch (\Exception $e) {

        }
        if (empty($entity_id)) {
            return;
        }

        $bp_hard_error = $this->store->getMeta($entity_id, 'bleumipay_hard_error');
        $bp_transient_error = $this->store->getMeta($entity_id, 'bleumipay_transient_error');
        $bp_retry_action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
        $order_modified_date = strtotime($order['updated_at']); // coverts formated date to unix time

        // If there is a hard error, return
        if (($bp_hard_error == 'yes')) {
            $msg = 'bleumi_pay: syncOrder: ' . $data_source . ' :' . $entity_id . ' Skipping, hard error found. ';
            $this->logger->info($msg);
            return;
        }

        // If there is a transient error & retry_action does not match, return
        if ((($bp_transient_error == 'yes') && ($bp_retry_action != 'syncOrder'))) {
            $msg = 'bleumi_pay: syncOrder:  ' . $data_source . ' : ' . $entity_id . ' Skipping, transient error found and retry_action does not match, order retry_action is : ' . $bp_retry_action;
            $this->logger->info($msg);
            return;
        }

        //If Bleumi Pay processing completed, return
        $bp_processing_completed = $this->store->getMeta($entity_id, 'bleumipay_processing_completed');
        if ($bp_processing_completed == 'yes') {
            $msg = 'Processing already completed for this order. No further changes possible.';
            $this->logger->info('bleumi_pay: syncOrder: ' . $data_source . ' :' . $entity_id . ' ' . $msg);
            return;
        }

        //If order is in settle_in_progress or refund_in_progress, return
        $bp_payment_status = $this->store->getMeta($entity_id, 'bleumipay_payment_status');
        if (($bp_payment_status == 'refund_in_progress') || ($bp_payment_status == 'settle_in_progress')) {
            return;
        }

        $prev_data_source = $this->store->getMeta($entity_id, 'bleumipay_data_source');
        $currentTime = strtotime(date("Y-m-d H:i:s")); //Server Unix time
        $order_modified_date = strtotime($order['updated_at']);
        $minutes = Utils::getMinutesDiff($currentTime, $order_modified_date);
        if ($minutes < $this->store::cron_collision_safe_minutes) {
            // Skip orders-cron update if order was updated by payments-cron recently.
            if (($data_source === 'orders-cron') && ($prev_data_source === 'payments-cron')) {
                $msg = 'Skipping syncOrder at this time as payments-cron updated this order recently, will be re-tried again';
                $this->logger->critical('bleumi_pay: syncOrder: ' . $data_source . ' :' . $entity_id . ' ' . $msg);
                $this->error_handler->logTransientException($entity_id, 'syncOrder', 'E200', $msg);
                return;
            }
        }

        $order_status = $order["status"];
        $result = $this->api->getPaymentTokenBalance(null, $entity_id);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            //If balance of more than 1 token is found, log transient error & return
            if ($result[0]['code'] == -2) {
                $success = Utils::markAsMultiTokenPayment($entity_id);
                if ($success) {
                    $msg = $result[0]['message'];
                    $this->logger->info("bleumi_pay: '. $data_source .' : syncOrder : order-id:  '. $entity_id .' " . $msg . "', order status changed to 'multi_token_payment");
                }
            } else {
                $this->logger->critical('bleumi_pay: ' . $data_source . ' : syncOrder:' . $entity_id . ' token balance error : ' . $result[0]['message']);
            }
            return;
        }
        $payment_info = $result[1];

        //If no payment amount is found, return

        $amount = 0;
        try {
            $amount = (float) $payment_info['token_balances'][0]['balance'];
        } catch (\Exception $e) {

        }

        if ($amount == 0) {
            $msg = 'order-id' . $entity_id . ' payment is blank.';
            $this->logger->info('bleumi_pay:  ' . $data_source . ' : syncOrder:' . $msg);
            return;
        }

        switch ($order_status) {
            case "complete":
                $msg = 'syncOrder:' . $entity_id . '  settling payment.';
                $this->logger->info('bleumi_pay: ' . $data_source . ' :' . $msg);
                $this->settleOrder($order, $payment_info, $data_source);
                break;
            case "canceled":
                $msg = 'syncOrder:  ' . $data_source . ' :' . $entity_id . '  refunding payment.';
                $this->logger->info('bleumi_pay: ' . $msg);
                $this->refundOrder($order, $payment_info, $data_source);
                break;
            default:
                $this->logger->info('bleumi_pay:  ' . $data_source . ' : syncOrder: ' . $entity_id . ' switch case : unhandled order status: ' . $order_status);
                break;
        }
    }

    /**
     * Settle orders and set to settle_in_progress Bleumi Pay status
     */
    public function settleOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        $entity_id = $order['entity_id'];
        usleep(300000); // rate limit delay.
        $result = $this->api->settle_payment($payment_info, $order);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->error_handler->logTransientException($entity_id, 'syncOrder', 'E103', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                //$order->reduce_order_stock(); // Reduce stock levels
                $this->store->updateMetaData($entity_id, 'bleumipay_txid', $operation['txid']);
                $this->store->updateMetaData($entity_id, 'bleumipay_payment_status', 'settle_in_progress');
                $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', 'no');
                $this->store->updateMetaData($entity_id, 'bleumipay_data_source', $data_source);
                $this->error_handler->clearTransientError($entity_id);
            }
            $msg = 'settle_payment invoked, tx-id is: ' . $operation['txid'];
        }
        $this->logger->info('bleumi_pay: ' . $data_source . ' : settleOrder :' . $entity_id . ' ' . $msg);
    }

    /**
     * Refund Orders and set to refund_in_progress Bleumi Pay status
     */
    public function refundOrder($order, $payment_info, $data_source)
    {
        $msg = '';
        usleep(300000); // rate limit delay.
        $entity_id = $order['entity_id'];
        $result = $this->api->refund_payment($payment_info, $entity_id);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $msg = $result[0]['message'];
            $this->error_handler->logTransientException($entity_id, 'syncOrder', 'E205', $msg);
        } else {
            $operation = $result[1];
            if (!is_null($operation['txid'])) {
                $this->store->updateMetaData($entity_id, 'bleumipay_txid', $operation['txid']);
                $this->store->updateMetaData($entity_id, 'bleumipay_payment_status', 'refund_in_progress');
                $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', 'no');
                $this->error_handler->clearTransientError($entity_id);
                $msg = ' refund_payment invoked, tx-id is: ' . $operation['txid'];
            }
        }
        $this->logger->info('bleumi_pay: ' . $data_source . ' : refundOrder : ' . $entity_id . $msg);
        $this->store->updateMetaData($entity_id, 'bleumipay_data_source', $data_source);
    }

    /**
     * Find Orders which are in refund_in_progress Bleumi Pay status
     */
    public function verifyRefundOperationStatuses($data_source)
    {
        $orders = $this->store->getOrdersForStatus('refund_in_progress', 'bleumipay_payment_status');
        if (count($orders) > 0) {
            $operation = "refund";
            $this->api->verifyOperationCompletion($orders, $operation, $data_source);
        }
    }

    /**
     * Fail the orders that are not confirmed even after cut-off time. (1 hour)
     */
    public function failUnconfirmedPaymentOrders($data_source)
    {
        $orders = $this->store->getOrdersForStatus('awaiting_confirmation', 'status');
        foreach ($orders as $order) {
            $entity_id = $order['entity_id'];
            $currentTime = strtotime(date("Y-m-d H:i:s")); //Server UNIX time
            $order_updated_date = strtotime($order['updated_at']);
            $minutes = Utils::getMinutesDiff($currentTime, $order_updated_date);
            if ($minutes > $this->store::await_payment_minutes) {
                $msg = 'Payment confirmation not received before cut-off time, elapsed minutes: ' . round($minutes, 2);
                $this->logger->info('failUnconfirmedPaymentOrders: ' . $entity_id . $msg);
                Utils::failThisOrder($entity_id);
            }
        }
    }

    /**
     * Verify that the refund is complete
     */
    public function verifyCompleteRefund($data_source)
    {
        $orders = $this->store->getOrdersForStatus('refunded', 'bleumipay_payment_status');
        foreach ($orders as $order) {
            $entity_id = $order['entity_id'];
            $result = $this->api->getPaymentTokenBalance(null, $entity_id);
            $payment_info = $result[1];
            $token_balances = array();
            try {
                $token_balances = $payment_info['token_balances'];
            } catch (\Exception $e) {

            }

            $token_balances_modified = array();
            //All tokens are refunded, can mark the order as processing completed
            if (count($token_balances) == 0) {
                $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', 'yes');
                $this->logger->info('bleumi_pay : verifyCompleteRefund: ' . $entity_id . ' processing completed. token_balance count =' . count($token_balances));
                return;
            }
            $next_token = '';
            do {
                $ops_result = $this->api->list_payment_operations($entity_id);
                $operations = $ops_result[1]['results'];
                $next_token = null;
                try {
                    $next_token = $operations['next_token'];
                } catch (\Exception $e) {

                }

                if (is_null($next_token)) {
                    $next_token = '';
                }

                $valid_operations = array('createAndRefundWallet', 'refundWallet');

                foreach ($token_balances as $token_balance) {
                    $token_balance['refunded'] = 'no';
                    foreach ($operations as $operation) {
                        if (isset($operation['hash']) && (!is_null($operation['hash']))) {
                            if (($operation['inputs']['token'] === $token_balance['addr']) && ($operation['status'] == 'yes') && ($operation['chain'] == $token_balance['chain']) && (in_array($operation['func_name'], $valid_operations))) {
                                $token_balance['refunded'] = 'yes';
                                break;
                            }
                        }
                    }
                    array_push($token_balances_modified, $token_balance);
                }
            } while ($next_token !== '');

            $all_refunded = 'yes';
            foreach ($token_balances_modified as $token_balance) {
                if ($token_balance['refunded'] === 'no') {
                    $amount = $token_balance['balance'];
                    if (!is_null($amount)) {
                        $payment_info['id'] = $entity_id;
                        $item = array(
                            'chain' => $token_balance['chain'],
                            'addr' => $token_balance['addr'],
                            'balance' => $token_balance['balance'],
                        );
                        $payment_info['token_balances'] = array($item);
                        $this->refundOrder($order, $payment_info, $data_source);
                        $all_refunded = 'no';
                        break;
                    }
                }
            }
            if ($all_refunded == 'yes') {
                $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', 'yes');
                $this->logger->info('bleumi_pay : verifyCompleteRefund: ' . $entity_id . ' processing completed.');
            }
        }
    }

    /**
     * Find Orders which are in bp_payment_status = settle_in_progress
     * and check transaction status
     */

    public function verifySettleOperationStatuses($data_source)
    {
        $orders = $this->store->getOrdersForStatus('settle_in_progress', 'bleumipay_payment_status');
        $operation = "settle";
        $this->api->verifyOperationCompletion($orders, $operation, $data_source);
    }

}
