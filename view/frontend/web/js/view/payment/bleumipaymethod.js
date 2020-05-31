/**
 * Payment Method JS
 *
 * XML version 1
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   MIT; see LICENSE
 * @link      http://pay.bleumi.com
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'bleumipaymethod',
                component: 'Bleumi_BleumiPay/js/view/payment/method-renderer/bleumipaymethod-method'
            }
        );
        /**
    * Add view logic here if needed 
    */
        return Component.extend({});
    }
);