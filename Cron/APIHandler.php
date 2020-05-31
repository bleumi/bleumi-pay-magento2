<?php

/**
 * APIHandler
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

namespace Bleumi\BleumiPay\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\ObjectManager;
use \Bleumi\BleumiPay\Cron\ExceptionHandler;

/**
 * APIHandler 
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */

class APIHandler
{
    protected $logger;
    protected $payment_instance;
    protected $HC_instance;
    protected $error_handler;
    protected $store;

    /**
     * Constructor
     *
     * @param string          $APIKEY API key or token
     * @param LoggerInterface $logger Log writer.
     *
     * @return void
     */
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
     * Create payment in Bleumi Pay for the given order
     *
     * @param $order Store Order object
     * @param $urls  Object with success and cancel URL links
     *
     * @return object
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
     *
     * @param $params The hmac parameters returned from hosted checkout page
     *
     * @return bool
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
     *
     * @param $updated_after_time Filter criteria to fetch payments after this UNIX timestamp 
     * @param $next_token         The token to get next page of results
     *
     * @return array
     */
    public function getPayments($updated_after_time, $next_token)
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
            $msg = 'getPayments: failed, response: ' . $e->getMessage();
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
     *
     * @param $entity_id ID of the Bleumi Pay payment
     *
     * @return array
     */
    public function getPayment($entity_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPayment($entity_id);
        } catch (\Exception $e) {
            $msg = 'getPayment: failed order-id:' . $entity_id;
            $this->logger->critical('bleumi_pay: ' . $msg);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * Retrieves the payment operation details for the id, tx_id
     * from Bleumi Pay
     *
     * @param $id    ID of the Bleumi Pay payment
     * @param $tx_id Tranaction ID of the Bleumi Pay payment Operation
     *
     * @return array
     */
    public function getPaymentOperation($id, $tx_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPaymentOperation($id, $tx_id);
        } catch (\Exception $e) {
            $msg = 'getPaymentOperation: failed : payment-id: ' . $id . ' tx_id: ' . $tx_id . ' response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->logger->critical('bleumi_pay: ' . $msg);
            $this->error_handler->logException($id, 'getPaymentOperation', $e->getCode(), $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * List of Payment Operations
     *
     * @param $id         ID of the Bleumi Pay payment
     * @param $next_token The token to get next page of results
     *
     * @return array
     */
    public function listPaymentOperations($id, $next_token = null)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->listPaymentOperations($id, $next_token);
        } catch (\Exception $e) {
            $msg = 'listPaymentOperations: failed : ' . $id . '; response: ' . $e->getMessage();
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
     * List the tokens the merchant has configured for the given currency
     *
     * @param $storeCurrency The store currency code, used to filter the tokens
     *
     * @return array
     */
    public function listTokens($storeCurrency)
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
            $msg = 'listTokens: failed for currency: ' . $storeCurrency . '; response: ' . $e->getMessage();
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
     * Settle payment in Bleumi Pay for the given order
     *
     * @param $payment_info Payment information object
     * @param $order        Store Order object
     *
     * @return array
     */
    public function settlePayment($payment_info, $order)
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
            $this->logger->info('bleumi_pay: settlePayment --Exception--' . $e->getMessage());
            $msg = 'settlePayment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
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
     * Refund payment in Bleumi Pay for the given order
     *
     * @param $payment_info Payment information object
     * @param $entity_id    Order ID
     *
     * @return array
     */
    public function refundPayment($payment_info, $entity_id)
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
            $msg = 'refundPayment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
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
     *
     * @param array $token_balances Array of token balances
     *
     * @return array    A new array which does not contain the ALGO token balance if any Algorand ASA token balance is found
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
     * To check whether payment is made using multiple tokens
     * It is possible that user could have made payment to
     * the wallet address using a different token
     * Output is false
     *         if balance>0 is found
     *         for more than 1 token
     *
     * @param $payment Payment Object
     *
     * @return bool
     */
    public function isMultiTokenPayment($payment)
    {
        $networks = array('ethereum', 'algorand', 'rsk');
        $token_balances = array();
        $chain_token_balances = null;
        foreach ($networks as $network) {
            $chains = array();
            if ($network === 'ethereum') {
                $chains = array('mainnet', 'goerli', 'xdai_testnet', 'xdai');
            } else if ($network === 'algorand') {
                $chains = array('alg_mainnet', 'alg_testnet');
            } else if ($network === 'rsk') {
                $chains = array('rsk', 'rsk_testnet');
            }
            foreach ($chains as $chain) {
                try {
                    $chain_token_balances = $payment['balances'][$network][$chain];
                } catch (\Exception $e) {
                }
                if (!is_null($chain_token_balances)) {
                    foreach ($chain_token_balances as $addr => $token_balance) {
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
        }
        $ret_token_balances = $this->ignoreALGO($token_balances);
        return (count($ret_token_balances) > 1);
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
     *
     * @param $entity_id Order ID
     * @param $payment   Payment Object
     *
     * @return array
     */
    public function getPaymentTokenBalance($entity_id, $payment = null)
    {
        $chain = '';
        $addr = '';
        $token_balances = array();
        $payment_info = array();
        $errorStatus = array();

        //Call getPayment API to set $payment if found null.
        if (is_null($payment)) {
            $result = $this->getPayment($entity_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->logger->critical('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ' getPayment api failed : ' . $result[0]['message']);
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
        $result = $this->listTokens($storeCurrency);
        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $this->logger->critical('bleumi_pay: getPaymentTokenBalance: order-id :' . $entity_id . ' listTokens api failed : ' . $result[0]['message']);
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
     *
     * @param array  $orders      Array of Orders
     * @param string $operation   settle/refund
     * @param string $data_source The cron job invoking this function
     *
     * @return void
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
            //For such orders perform get operation &
            //check whether status has become 'true'

            $result = $this->getPaymentOperation($entity_id, $tx_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = $result[0]['message'];
                $this->logger->critical('bleumi_pay: verifyOperationCompletion: order-id :' . $entity_id . ' getPaymentOperation api request failed: ' . $msg);
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
