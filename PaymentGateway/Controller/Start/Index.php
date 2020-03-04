<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace BleumiPay\PaymentGateway\Controller\Start;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use BleumiPay\PaymentGateway\Cron\APIHandler;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    protected $api;

    /**
     * Index constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session\Proxy $checkoutSession,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $this->api = new APIHandler($this->scopeConfig->getValue('payment/bleumipaymethod/api_key', ScopeInterface::SCOPE_STORE),  $logger);
        parent::__construct($context);
    }

    /**
     * Start checkout by requesting checkout code and dispatching customer to Bleumi Pay.
     */
    public function execute()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $urls = array(
            "success" => $this->_url->getUrl("bleumipay/end/redirect"),
            "cancel" => $this->_url->getUrl("bleumipay/end/cancel", ["order_id" => $order->getId()])
        );

        $createCheckout = $this->api->create($order, $urls);

        if (!empty($createCheckout) && !empty($createCheckout['url'])) {
            $result = $this->resultJsonFactory->create();
            return $result->setData(['redirectUrl' => $createCheckout['url']]);
        } else {
            $order->registerCancellation('Canceled due to errors')->save();
            $this->checkoutSession->restoreQuote();
            throw new LocalizedException(__('Something went wrong while recieving Api Response'));
        }
    }
}
