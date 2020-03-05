<?php

namespace BleumiPay\PaymentGateway\Controller\End;

use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\ScopeInterface;
use BleumiPay\PaymentGateway\Cron\APIHandler;

class Redirect extends \Magento\Framework\App\Action\Action
{
    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var \Magento\Sales\Model\OrderFactory;
     */
    protected $orderFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Index constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Psr\Log\LoggerInterface $logger
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

    protected function _getCheckout()
    {
        return $this->_objectManager->get('Magento\Checkout\Model\Session');
    }

    /**
     * @return \Magento\Checkout\Model\Session
     */

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
            if ($order_id && $order_id == $_GET["id"]) {
                $this->_redirect('checkout/onepage/success', ['_secure' => true]);
                $params = array(
                    "hmac_alg" => $_GET["hmac_alg"],
                    "hmac_input" => base64_decode($_GET["hmac_input"]),
                    "hmac_keyId" => $_GET["hmac_keyId"],
                    "hmac_value" => $_GET["hmac_value"],
                );
                // $this->_eventManager->dispatch('create_invoice_for_bleumi_pay', ['params' => $params]);
                $order = $this->orderFactory->create()->loadByIncrementId($order_id);
                $isValid = $api->validateUrl($params);

                if ($isValid === true && $order["status"] === "pending") {
                    $order->setState("awaiting_confirmation", true)->save();
                    $order->setStatus("awaiting_confirmation", true)->save();
                } else {
                    $this->logger->critical('Bleumi Pay payment validation failed hence status not changed', ["exception" => json_encode($order)]);
                }
            } else {
                $this->logger->critical('Bleumi Pay payment validation failed' . $order_id . "||" . $_GET["id"], ["exception" => json_encode($order)]);
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
