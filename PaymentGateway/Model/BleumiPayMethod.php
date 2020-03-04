<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace BleumiPay\PaymentGateway\Model;



/**
 * Pay In Store payment method model
 */
class BleumiPayMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = "bleumipaymethod";
    protected $_isOffline = true;

    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        $apiKey = $this->_scopeConfig->getValue(
            'payment/bleumipaymethod/api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if (!$apiKey) {
            return false;
        }
        return parent::isAvailable($quote);
    }
}
