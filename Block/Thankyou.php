<?php

namespace BleumiPay\PaymentGateway\Block;

class Thankyou extends \Magento\Checkout\Block\Onepage\Success
{
    public function getOrder()
    {
        $order = $this->_checkoutSession->getLastRealOrder();
        return $order;
    }
}
