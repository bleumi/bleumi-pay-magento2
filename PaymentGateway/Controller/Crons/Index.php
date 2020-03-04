<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace BleumiPay\PaymentGateway\Controller\Crons;

use BleumiPay\PaymentGateway\Cron\Order;
use BleumiPay\PaymentGateway\Cron\Payment;
use BleumiPay\PaymentGateway\Cron\Retry;

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

    protected $payments;
    protected $orders;
    protected $retry;

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

        $this->orders = new Order($scopeConfig, $logger);
        $this->payments = new Payment($scopeConfig, $logger);
        $this->retry = new Retry($scopeConfig, $logger);

        parent::__construct($context);
    }

    public function execute()
    {

        if ($_GET["id"] === "payments") {
            $this->payments->execute();
        } elseif ($_GET["id"] === "orders") {
            $this->orders->execute();
        } elseif ($_GET["id"] === "retry") {
            $this->retry->execute();
        }
    }
}
