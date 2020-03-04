/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
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
                component: 'BleumiPay_PaymentGateway/js/view/payment/method-renderer/bleumipaymethod-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);