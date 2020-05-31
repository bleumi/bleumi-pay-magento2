<?php

/**
 * Thankyou
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

namespace Bleumi\BleumiPay\Block;

/**
 * Thankyou
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

class Thankyou extends \Magento\Checkout\Block\Onepage\Success
{
    /**
     * Get Order
     *
     * @return string
     */
    public function getOrder()
    {
        $order = $this->_checkoutSession->getLastRealOrder();
        return $order;
    }
}
