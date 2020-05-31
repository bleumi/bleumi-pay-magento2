<?php

/**
 * BleumiPayMethod File Doc Comment
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */

namespace Bleumi\BleumiPay\Model;

/**
 * BleumiPayMethod Class Doc Comment
 * 
 * Pay In Store payment method model
 * 
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */
class BleumiPayMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = "bleumipaymethod";
    protected $_isOffline = true;

    /**
     * Create payment in Bleumi Pay for the given order_id
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote = null Parameter description.
     *
     * @return object
     */
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
