<?php

/**
 * Index
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

namespace Bleumi\BleumiPay\Controller\Start;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Bleumi\BleumiPay\Cron\APIHandler;

/**
 * Index
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

class Index extends \Magento\Framework\App\Action\Action
{
    protected $checkoutSession;
    protected $resultJsonFactory;
    protected $logger;
    protected $scopeConfig;
    protected $api;

    /**
     * Constructor.
     *
     * @param \Magento\Framework\App\Action\Context              $context           Context.
     * @param \Magento\Checkout\Model\Session                    $checkoutSession   Session.
     * @param \Magento\Framework\Controller\Result\JsonFactory   $resultJsonFactory Result JSON Factory.
     * @param \Psr\Log\LoggerInterface                           $logger            Log write.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig       Scope Configuration.
     *
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
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

     * @return object
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
            throw new LocalizedException(__('Something went wrong while receiving API Response'));
        }
    }
}
