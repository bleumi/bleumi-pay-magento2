<?php

/*****************************************
 *
 * Bleumi Pay Payments CRON ("Payments Processor") functions
 *
 * Check statuses/payment received in Bleumi Pay and update Orders.
 *
 * All payments received after the last time this job run are applied to the orders
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

class Payment
{
    protected $logger;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;
    protected $store;
    protected $api;
    protected $payments;
    protected $nextToken;
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
     * Payments cron
     *
     */

    public function execute()
    {
        $data_source = 'payments-cron';

        $start_at = $this->store->getCronTime('payment_updated_at');
        $this->logger->info('bleumi_pay: ' . $data_source . ' : looking for payment modified after : ' . $start_at);
        $next_token = '';
        $updated_at = 0;
        do {
            $result = $this->api->get_payments($start_at, $next_token);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->logger->critical('bleumi_pay:' . $data_source . ' : get_payments api request failed. ' . $result[0]['message'] . ' exiting payments-cron.');
                return $result[0];
            }
            $payments = $result[1]['results'];
            if (is_null($payments)) {
                $this->logger->critical('bleumi_pay:' . $data_source . ' : unable to fetch payments to process');
                $errorStatus = array(
                    'code' => -1,
                    'message' => __('no payments data found.', 'bleumipay'),
                );
                return $errorStatus;
            }
            try {
                $next_token = $result[1]['next_token'];
            } catch (\Exception $e) {

            }
            if (is_null($next_token)) {
                $next_token = '';
            }

            foreach ($payments as $payment) {
                $updated_at = $payment['updated_at'];
                $this->logger->info('bleumi_pay: ' . $data_source . ' : processing payment : ' . $payment['id'] . ' ' . date('Y-m-d H:i:s', $updated_at));
                $this->syncPayment($payment, $data_source);
            }

        } while ($next_token !== '');

        if ($updated_at > 0) {
            $updated_at = $updated_at + 1;
            $this->store->updateRuntime("payment_updated_at", date('Y-m-d H:i:s', $updated_at));
            $this->logger->info('bleumi_pay ' . $data_source . ' : setting payment_updated_at: ' . date('Y-m-d H:i:s', $updated_at));
        }
    }

    public function syncPayment($payment, $data_source)
    {
        $order = $this->store->getPendingOrder($payment["id"]);
        $entity_id = null;
        try {
            $entity_id = $order[0]["entity_id"];
        } catch (\Exception $e) {

        }
        if (!empty($entity_id)) {

            $bp_hard_error = $this->store->getMeta($entity_id, 'bleumipay_hard_error');
            // If there is a hard error (or) transient error action does not match, return
            $bp_transient_error = $this->store->getMeta($entity_id, 'bleumipay_transient_error');
            $bp_retry_action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
            if (($bp_hard_error == 'yes') || (($bp_transient_error == 'yes') && ($bp_retry_action != 'syncPayment'))) {
                $msg = 'syncPayment: ' . $data_source . ' ' . $entity_id . ' : Skipping, hard error found (or) retry_action mismatch, order retry_action is : ' . $bp_retry_action;
                $this->logger->info($msg);
                return;
            }

            // If already processing completed, no need to sync
            $bp_processing_completed = $this->store->getMeta($entity_id, 'bleumipay_processing_completed');
            if ($bp_processing_completed == 'yes') {
                $msg = 'Processing already completed for this order. No further changes possible.';
                $this->logger->info('syncPayment: ' . $data_source . ' : ' . $entity_id . ' ' . $msg);
                return;
            }

            // Exit payments_cron update if bp_payment_status indicated operations are in progress or completed
            $order_status = $order[0]['status'];
            $bp_payment_status = $this->store->getMeta($entity_id, 'bleumipay_payment_status');
            $invalid_bp_statuses = array('settle_in_progress', 'settled', 'settle_failed', 'refund_in_progress', 'refunded', 'refund_failed');
            if (in_array($bp_payment_status, $invalid_bp_statuses)) {
                $msg = 'syncPayment: ' . $data_source . ' : ' . $entity_id . ' exiting .. bp_status:' . $bp_payment_status . ' order_status:' . $order_status;
                $this->logger->info($msg);
                return;
            }

            // skip payments_cron update if order was sync-ed by orders_cron in recently.
            $bp_data_source = $this->store->getMeta($entity_id, 'bleumipay_data_source');
            $currentTime = strtotime(date("Y-m-d H:i:s")); //server unix time
            $date_modified = strtotime($order[0]['updated_at']);
            $minutes = Utils::getMinutesDiff($currentTime, $date_modified);
            if ($minutes < $this->store::cron_collision_safe_minutes) {
                if (($data_source === 'payments-cron') && ($bp_data_source === 'orders-cron')) {
                    $msg = __('syncPayment:' . $entity_id . ' skipping payment processing at this time as Orders_CRON processed this order recently, will be processing again later', 'bleumipay');
                    $this->error_handler->logTransientException($entity_id, 'syncPayment', 'E102', $msg);
                    return;
                }
            }

            $addresses = json_encode($payment["addresses"]);
            $this->logger->info('bleumi_pay: $addresses ' . $addresses);
            $this->store->updateMetaData($entity_id, 'bleumipay_addresses', $addresses);
            //Get token balance
            $result = $this->api->getPaymentTokenBalance($payment, $entity_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                if ($result[0]['code'] == -2) {
                    $success = Utils::markAsMultiTokenPayment($entity_id);
                    if ($success) {
                        $msg = $result[0]['message'];
                        $this->logger->info("bleumi_pay: '. $data_source .' : syncPayment : order-id: " . $entity_id . " " . $msg . "', order status changed to 'multi_token_payment");
                    }
                } else {
                    $this->logger->critical("bleumi_pay: " . $data_source . " : syncPayment : order-id: " . $entity_id . 'get token balance error');
                }
                return;
            }
            $payment_info = $result[1];
            $amount = 0;
            try {
                $amount = (float) $payment_info['token_balances'][0]['balance'];
            } catch (\Exception $e) {

            }

            $order_value = (float) $order[0]["grand_total"];
            $this->logger->info('bleumi_pay: $amount ' . $amount);
            $this->logger->info('bleumi_pay: $order_value ' . $order_value);
            if (!empty($amount) && ($amount >= $order_value)) {
                Utils::updatePendingOrder($entity_id, "processing");
                $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', "no");
                $this->store->updateMetaData($entity_id, 'bleumipay_payment_status', "payment-received");
                $this->store->updateMetaData($entity_id, 'bleumipay_data_source', $data_source);
                $this->logger->info("bleumi_pay: " . $data_source . " : syncPayment : order-id: " . $entity_id . " set to 'processing'");
            }
        }
    }

}
