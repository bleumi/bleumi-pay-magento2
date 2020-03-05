<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * 
 */

namespace BleumiPay\PaymentGateway\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\ObjectManager;
use \BleumiPay\PaymentGateway\Cron\ExceptionHandler;

class APIHandler
{
    private $logger;
    protected $payment_instance;
    protected $HC_instance;
    protected $error_handler;
    protected $store;

    public function __construct(
        $APIKEY,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $config = \Bleumi\Pay\Configuration::getDefaultConfiguration()->setApiKey('x-api-key', $APIKEY);
        $this->payment_instance = new \Bleumi\Pay\Api\PaymentsApi(new \GuzzleHttp\Client(), $config);
        $this->HC_instance = new \Bleumi\Pay\Api\HostedCheckoutsApi(new \GuzzleHttp\Client(), $config);
        $this->error_handler = new ExceptionHandler($logger);
        $this->store = new DBHandler($logger);
    }

    /**
     * Create payment in Bleumi Pay for the given order_id
     */
    public function create($order, $urls)
    {
        try {
            $id = $order->getId();
            $createReq = new \Bleumi\Pay\Model\CreateCheckoutUrlRequest();
            $createReq->setId($id);
            $createReq->setCurrency($order->getStoreCurrencyCode());
            $createReq->setAmount($order->getGrandTotal());
            $createReq->setSuccessUrl($urls["success"]);
            $createReq->setCancelUrl($urls["cancel"]);
            $createReq->setBase64Transform(true);
            $result = $this->HC_instance->createCheckoutUrl($createReq);

            $this->logger->info("bleumi_pay: Payment request created for " . $id);

            return $result;
        } catch (\Exception $e) {
            $this->logger->critical('bleumi_pay: Payement request creation failed', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Validate Payment Completion Parameters.
     */
    public function validateUrl($params)
    {
        try {
            $validateReq = new \Bleumi\Pay\Model\ValidateCheckoutRequest();
            $validateReq->setHmacAlg($params["hmac_alg"]);
            $validateReq->setHmacInput($params["hmac_input"]);
            $validateReq->setHmacKeyId($params["hmac_keyId"]);
            $validateReq->setHmacValue($params["hmac_value"]);
            $result = $this->HC_instance->validateCheckoutPayment($validateReq);

            return $result['valid'];
        } catch (\Exception $e) {
            $this->logger->critical('bleumi_pay: payment validation failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Retrieves the payment details for the order_id from Bleumi Pay
     */
    public function get_payments($updated_after_time, $next_token)
    {
        $result = null;
        $errorStatus = array();
        $next_token = $next_token;
        $sort_by = "updatedAt";
        $sort_order = "ascending";
        $start_at = strtotime($updated_after_time);
        try {
            $result = $this->payment_instance->listPayments($next_token, $sort_by, $sort_order, $start_at);
        } catch (\Exception $e) {
            $msg = 'get_payments: failed, response: ' . $e->getMessage();
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $this->logger->critical('bleumi_pay: ' . $msg);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Retrieves the payment details for the entity_id from Bleumi Pay
     */
    public function get_payment($entity_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPayment($entity_id);
        } catch (\Exception $e) {
            $msg = 'get_payment: failed order-id:' . $entity_id;
            $this->logger->critical('bleumi_pay: ' . $msg);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Retrieves the payment operation details for the payment_id, tx_id from Bleumi Pay
     */
    public function get_payment_operation($id, $tx_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPaymentOperation($id, $tx_id);
        } catch (\Exception $e) {
            $msg = 'get_payment_operation: failed : payment-id: ' . $id . ' tx_id: ' . $tx_id . ' response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay: ' . $msg);
            $this->error_handler->logException($id, 'get_payment_operation', $e->getCode(), $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * List of Payment Operations
     */
    public function list_payment_operations($id, $next_token = null)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->listPaymentOperations($id, $next_token);
        } catch (\Exception $e) {
            $msg = 'list_payment_operations: failed : ' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay: ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * List Tokens
     */
    public function list_tokens($storeCurrency)
    {
        $result = array();
        $errorStatus = array();
        try {
            $tokens = $this->HC_instance->listTokens();
            foreach ($tokens as $item) {
                if ($item['currency'] === $storeCurrency) {
                    array_push($result, $item);
                }
            }    
        } catch (\Exception $e) {
            $msg = 'list_tokens: failed, response: ' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay:  ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * Settle payment in Bleumi Pay for the given order_id
     */

    public function settle_payment($payment_info, $order)
    {
        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        $tokenBalance = $payment_info['token_balances'][0];
        $token = $tokenBalance['addr'];
        $paymentSettleRequest = new \Bleumi\Pay\Model\PaymentSettleRequest();
        $amount = (string) $order['grand_total'];
        $paymentSettleRequest->setAmount($amount);
        $paymentSettleRequest->setToken($token);
        try {
            $result = $this->payment_instance->settlePayment($paymentSettleRequest, $id, $tokenBalance['chain']);
            $entity_id = $order['entity_id'];
            $this->error_handler->clearTransientError($entity_id);
        } catch (\Exception $e) {
            $this->logger->info('bleumi_pay: settle_payment --Exception--' . $e->getMessage());
            $msg = 'settle_payment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay:  ' . $msg);

        }
        return array($errorStatus, $result);
    }

    /**
     * Refund payment in Bleumi Pay for the given order_id
     */

    public function refund_payment($payment_info, $entity_id)
    {
        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        try {
            $token_balance = $payment_info['token_balances'][0];
            $amount = (float) $token_balance['balance'];
            if ($amount > 0) {
                $paymentRefundRequest = new \Bleumi\Pay\Model\PaymentRefundRequest();
                $paymentRefundRequest->setToken($token_balance['addr']);
                $result = $this->payment_instance->refundPayment($paymentRefundRequest, $id, $token_balance['chain']);
            }
            $this->error_handler->clearTransientError($entity_id);
        } catch (\Exception $e) {
            $msg = 'refund_payment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay:  ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * To ignore ALGO balance when Algorand ASA payment is made
     * Input: Array of token balances
     * Output: A new array which does not contain the ALGO token balance if any Algorand ASA token balance is found
     */

    public function ignoreALGO($token_balances)
    {
        $algo_token_found = false;
        $ret_token_balances = array();
        foreach ($token_balances as $item) {
            if (($item['network'] === 'algorand') && ($item['addr'] !== 'ALGO')) {
                $algo_token_found = true;
            }
        }
        foreach ($token_balances as $item) {
            if ($item['network'] === 'algorand') {
                if (($algo_token_found) && ($item['addr'] !== 'ALGO')) {
                    array_push($ret_token_balances, $item);
                }
            } else {
                array_push($ret_token_balances, $item);
            }
        }
        return $ret_token_balances;
    }

    /**
     * To check whether payment is made using multiple ERC-20 tokens
     * It is possible that user could have made payment to the wallet address using a different token
     * Returns false if balance>0 is found for more than 1 token when network='ethereum', chain=['mainnet', 'goerli']
     */

    public function isMultiTokenPayment($payment)
    {
        $chains = array('mainnet', 'goerli', 'xdai_testnet', 'xdai');
        $tokenBalances = null;
        foreach ($chains as $chain) {
            try {
                $tokenBalances = $payment['balances']['ethereum'][$chain];
            } catch (\Exception $e) {

            }
            $this->logger->info('bleumi_pay: isMultiTokenPayment : chainBalance ' . $chain . ' ' . json_encode($tokenBalances));
            if (!is_null($tokenBalances)) {
                $count = 0;
                foreach ($tokenBalances as $tokenBalance) {
                    $balance = $tokenBalance['balance'];
                    if ($balance > 0) {
                        $count = $count + 1;
                    }
                }
                $this->logger->info('bleumi_pay: isMultiTokenPayment: tokenBalances count ' . count($tokenBalances));
                return ($count > 1);
            }
        }
        return false;
    }

    /**
     * Get Payment Token Balance - from payment object
     * Parses the payment object which uses dictionaries
     *
     * {
     *  "id": "535",
     *  "addresses": {
     *    "ethereum": {
     *      "goerli": {
     *        "addr": "0xbead07d152c64159190842ec1d6144f1a4a6cae9"
     *      }
     *    }
     *  },
     *  "balances": {
     *    "ethereum": {
     *      "goerli": {
     *        "0x115615dbd0f835344725146fa6343219315f15e5": {
     *          "blockNum": "1871014",
     *          "token_balance": "10000000",
     *          "balance": "10",
     *          "token_decimals": 6
     *        }
     *      }
     *    }
     *  },
     *    "createdAt": 1577086517,
     *    "updatedAt": 1577086771
     * }
     * @return object
     */
    public function getPaymentTokenBalance($payment = null, $entity_id)
    {

        $chain = '';
        $addr = '';
        $token_balances = array();
        $payment_info = array();
        $errorStatus = array();

        //Call get_payment API to set $payment if found null.
        if (is_null($payment)) {
            $result = $this->get_payment($entity_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->logger->critical('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ' get_payment api failed : ' . $result[0]['message']);
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'get payment details failed ',
                );
                return array($errorStatus, $payment_info);
            }
            $payment = $result[1];
        }

        //If still not payment data is found, return error
        if (is_null($payment)) {
            $errorStatus = array(
                'code' => -1,
                'message' => 'no payment details found ',
            );
            return array($errorStatus, $payment_info);
        }

        $payment_info['id'] = $payment['id'];
        $payment_info['addresses'] = $payment['addresses'];
        $payment_info['balances'] = $payment['balances'];
        //$payment_info['created_at'] = $payment['created_at'];
        //$payment_info['updated_at'] = $payment['updated_at'];

        if ($this->isMultiTokenPayment($payment)) {
            $msg = 'More than one token balance found';
            $errorStatus['code'] = -2;
            $errorStatus['message'] = $msg;
            return array($errorStatus, $payment_info);
        }
        $order = ObjectManager::getInstance()->create('Magento\Sales\Model\Order')->load($entity_id);
        $storeCurrency = $order->getStoreCurrencyCode();
        $result = $this->list_tokens($storeCurrency);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $this->logger->critical('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ' list_tokens api failed : ' . $result[0]['message']);
            return array($result[0], $payment_info);
        }
        $tokens = $result[1];
        if (count($tokens) > 0) {
            foreach ($tokens as $token) {
                $network = $token['network'];
                $chain = $token['chain'];
                $addr = $token['addr'];
                $token_balance = null;
                try {
                    $token_balance = $payment['balances'][$network][$chain][$addr];
                } catch (\Exception $e) {
                    continue;
                }
                /*{
                "balance": "0",
                "token_decimals": 6,
                "blockNum": "1896563",
                "token_balance": "0"
                }*/
                if (!is_null($token_balance['balance'])) {
                    $balance = (float) $token_balance['balance'];
                    if ($balance > 0) {
                        $item = array();
                        $item['network'] = $network;
                        $item['chain'] = $chain;
                        $item['addr'] = $addr;
                        $item['balance'] = $token_balance['balance'];
                        $item['token_decimals'] = $token_balance['token_decimals'];
                        $item['blockNum'] = $token_balance['blockNum'];
                        $item['token_balance'] = $token_balance['token_balance'];
                        array_push($token_balances, $item);
                    }
                }
            }
        }
        $this->logger->info('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ', before algo_ignore token_balances: ' . json_encode($token_balances));
        $ret_token_balances = $this->ignoreALGO($token_balances);
        $this->logger->info('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ', after algo_ignore token_balances: ' . json_encode($ret_token_balances));
        $balance_count = count($ret_token_balances);

        if ($balance_count > 0) {
            $payment_info['token_balances'] = $ret_token_balances;
            if ($balance_count > 1) {
                $msg = 'More than one token balance found';
                $this->logger->critical('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ', balance_count: ' . $balance_count . ', ' . $msg);
                $errorStatus['code'] = -2;
                $errorStatus['message'] = $msg;
            }
        } else {
            $this->logger->info('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ', no token balance found ');
        }

        return array($errorStatus, $payment_info);
    }

    /**
     * Verify Payment operation completion status.
     */
    public function verifyOperationCompletion($orders, $operation, $data_source)
    {

        $completion_status = '';
        $op_failed_status = '';
        if ($operation === 'settle') {
            $completion_status = 'settled';
            $op_failed_status = 'settle_failed';
        } else if ($operation === 'refund') {
            $completion_status = 'refunded';
            $op_failed_status = 'refund_failed';
        }

        foreach ($orders as $order) {
            $entity_id = $order['entity_id'];
            $tx_id = $this->store->getMeta($entity_id, 'bleumipay_txid');
            if (is_null($tx_id)) {
                $this->logger->critical('bleumi_pay: verifyOperationCompletion: order-id :' . $entity_id . ' tx-id is not set.');
                continue;
            }
            //For such orders perform get operation & check whether status has become 'true'
            $result = $this->get_payment_operation($entity_id, $tx_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = $result[0]['message'];
                $this->logger->critical('bleumi_pay: verifyOperationCompletion: order-id :' . $entity_id . ' get_payment_operation api request failed: ' . $msg);
                continue;
            }
            $status = $result[1]['status'];
            $txHash = $result[1]['hash'];
            $chain = $result[1]['chain'];
            if (!is_null($status)) {
                if ($status == 'yes') {
                    $note = 'Tx hash for Bleumi Pay transfer ' . $txHash . ' Transaction Link : ' . Utils::getTransactionLink($txHash, $chain);
                    Utils::addOrderNote($entity_id, $note, true);
                    $this->store->updateMetaData($entity_id, 'bleumipay_payment_status', $completion_status);
                    if ($operation === 'settle') {
                        $this->store->updateMetaData($entity_id, 'bleumipay_processing_completed', 'yes');
                    }
                } else {
                    $msg = 'payment operation failed';
                    $this->store->updateMetaData($entity_id, 'bleumipay_payment_status', $op_failed_status);
                    if ($operation === 'settle') {
                        //Settle failure will be retried again & again
                        $this->error_handler->logTransientException($entity_id, $operation, 'E908', $msg);
                    } else {
                        //Refund failure will not be processed again
                        $this->error_handler->logHardException($entity_id, $operation, 'E909', $msg);
                    }
                    $this->logger->critical('bleumi_pay: verifyOperationCompletion: order-id :' . $entity_id . ' ' . $operation . ' ' . $msg);
                }
                $this->store->updateMetaData($entity_id, 'bleumipay_data_source', $data_source);
            }
        }
    }

}
