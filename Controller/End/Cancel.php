<?php

/**
 * Cancel
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

use Magento\Framework\App\Action\Action;

/**
 * Cancel
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

class Cancel extends Action
{

    /**
     * Get Checkout
     *
     * @return \Magento\Checkout\Model\Session
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
        if ($this->_getCheckout()->getLastRealOrderId()) {

            $order = $this->_getCheckout()->getLastRealOrder();
            if ($order->getId() && !$order->isCanceled()) {
                $order->registerCancellation('Canceled by Customer')->save();
            }

            $this->_getCheckout()->restoreQuote();
            $this->_redirect('checkout/cart');
        }
    }
}
