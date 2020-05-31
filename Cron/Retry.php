<?php

/**
 * Retry Cron
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
use Magento\Store\Model\ScopeInterface;
use \Bleumi\BleumiPay\Cron\Payment;
use \Bleumi\BleumiPay\Cron\Order;
use \Bleumi\BleumiPay\Cron\ExceptionHandler;

/**
 * Retry Cron
 *
 * ("Retry failed transient actions") functions
 * Finds all the orders that failed during data synchronization
 * and re-performs them
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

class Retry
{
    protected $logger;
    protected $scopeConfig;
    protected $payments;
    protected $orders;
    protected $store;
    protected $api;
    protected $error_handler;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig scopeConfig.
     * @param LoggerInterface                                    $logger      Log writer
     *
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $apiKey = $this->scopeConfig->getValue('payment/bleumipaymethod/api_key', ScopeInterface::SCOPE_STORE);
        $this->api = new APIHandler($apiKey, $logger);
        $this->payments = new Payment($scopeConfig, $logger);
        $this->orders = new Order($scopeConfig, $logger);
        $this->store = new DBHandler($logger);
        $this->error_handler = new ExceptionHandler($logger);
    }

    /**
     * Retry cron main function.
     *
     * @return void
     */
    public function execute()
    {
        $data_source = 'retry-cron';
        $this->logger->info('bleumi_pay: ' . $data_source . ' : looking for orders with transient errors');
        $retry_orders = $this->store->getTransientErrorOrders();
        foreach ($retry_orders as $order) {
            $entity_id = $order['entity_id'];
            $action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
            $this->error_handler->checkRetryCount($entity_id);

            $bp_hard_error = $this->store->getMeta($entity_id, 'bleumipay_hard_error');
            if ($bp_hard_error === 'yes') {
                $this->logger->info('bleumi_pay: retry-cron: Skipping, hard error found for order : ' . $entity_id);
            } else {
                $this->logger->info('bleumi_pay: retry-cron: order-id :' . $entity_id . '  $action  ' . $action);
                switch ($action) {
                case "syncOrder":
                    $this->orders->syncOrder($order, $data_source);
                    break;
                case "syncPayment":
                    $this->payments->syncPayment($order, $data_source);
                    break;
                case "settle":
                    $result = $this->api->getPaymentTokenBalance($entity_id);
                    if (is_null($result[0]['code'])) {
                        $this->orders->settleOrder($order, $result[1], $data_source);
                    }
                    break;
                case "refund":
                    $result = $this->api->getPaymentTokenBalance($entity_id);
                    if (is_null($result[0]['code'])) {
                        $this->orders->refundOrder($order, $result[1], $data_source);
                    }
                    break;
                default:
                    break;
                }
            }
        }
    }
}
