/**
 * Payment Method Renderer JS
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
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'mage/url',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/error-processor',
        // 'Magento_Checkout/js/model/full-screen-loader'
    ],
    function ($, Component, url, customerData, errorProcessor) {
        'use strict';

        return Component.extend(
            {
                redirectAfterPlaceOrder: false,
                defaults: {
                    template: 'Bleumi_BleumiPay/payment/bleumipaymethod'
                },

                /**
                 * Returns send check to info 
                 */
                getMailingAddress: function () {
                    return window.checkoutConfig.payment.checkmo.mailingAddress;
                },
                afterPlaceOrder: function () {
                    var body = $('body').loader();
                    try {
                        body.loader('show');
                        var custom_controller_url = url.build('bleumipay/start/index'); //your custom controller url
                        $.post(custom_controller_url, 'json')
                            .done(
                                function (response) {
                                    // customerData.invalidate(['cart']);
                                    window.location.href = response.redirectUrl;
                                }
                            )
                            .fail(
                                function (response) {
                                    errorProcessor.process(response, this.messageContainer);
                                }
                            )
                            .always(
                                function () {
                                    // fullScreenLoader.stopLoader();
                                    body.loader('destroy');
                                }
                            );
                    } catch (error) {
                        window.console.error(error)
                        window.console.log(error)
                    }
                },
                getLogo: function () {
                    return require.toUrl('Bleumi_BleumiPay/images/BleumiPay.png');
                }
            }
        );
    }
);
