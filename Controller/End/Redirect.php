<?php

/**
 * Redirect
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

namespace Bleumi\BleumiPay\Controller\End;

use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\ScopeInterface;
use Bleumi\BleumiPay\Cron\APIHandler;

/**
 * Redirect
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

class Redirect extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $orderFactory;
    protected $scopeConfig;

    /**
     * Constructor.
     *
     * @param \Magento\Framework\App\Action\Context              $context           Context.                                                          
     * @param \Magento\Framework\View\Result\PageFactory         $resultPageFactory Result Page Factory.
     * @param \Magento\Sales\Model\OrderFactory                  $orderFactory      Order Factory.
     * @param \Psr\Log\LoggerInterface                           $logger            Log writer.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig       Scope Config.
     *
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    /**
     * Get Checkout
     *
     * @return \Magento\Checkout\Model\Session      Session.
     */
    protected function _getCheckout()
    {
        return $this->_objectManager->get('Magento\Checkout\Model\Session');
    }

    /**
     * Redirect to to checkout success
     *
     * @return void
     */
    public function execute()
    {
        $api = new APIHandler($this->scopeConfig->getValue('payment/bleumipaymethod/api_key', ScopeInterface::SCOPE_STORE),   $this->logger);
        $order_id = $this->_getCheckout()->getLastRealOrderId();
        try {
            if ($order_id && $order_id == filter_input(INPUT_GET, 'id')) {
                $this->_redirect('checkout/onepage/success', ['_secure' => true]);
                $params = array(
                    "hmac_alg" => filter_input(INPUT_GET, 'hmac_alg'),
                    "hmac_input" => base64_decode(filter_input(INPUT_GET, 'hmac_input')),
                    "hmac_keyId" => filter_input(INPUT_GET, 'hmac_keyId'),
                    "hmac_value" => filter_input(INPUT_GET, 'hmac_value'),
                );
                $order = $this->orderFactory->create()->loadByIncrementId($order_id);
                $isValid = $api->validateUrl($params);

                if ($isValid === true && $order["status"] === "pending") {
                    $order->setState("awaiting_confirmation", true)->save();
                    $order->setStatus("awaiting_confirmation", true)->save();
                } else {
                    $this->logger->critical('Bleumi Pay payment validation failed hence status not changed', ["exception" => json_encode($order)]);
                }
            } else {
                $this->logger->critical('Bleumi Pay payment validation failed' . $order_id . "||" . filter_input(INPUT_GET, 'id'), ["exception" => json_encode($order)]);
                $this->_redirect('checkout/cart');
            }
            $resultPage = $this->resultPageFactory->create();
            return $resultPage;
        } catch (\Throwable $th) {
            $this->logger->critical('Bleumi Pay payment validation failed ' . $order_id, ["exception" => json_encode($th)]);
            $this->_redirect('checkout/onepage/success', ['_secure' => true]);
        }
    }
}
