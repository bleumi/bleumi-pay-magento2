<?php

/**
 * Hint
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

namespace Bleumi\BleumiPay\Block\Adminhtml\System\Config\Fieldset;

use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;

/**
 * Hint
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

class Hint extends Template implements RendererInterface
{

    /**
     * Render method
     *
     * @param AbstractElement $element      Abstract Element.
     *
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $html = '';

        if ($element->getComment()) {
            $html .= sprintf('<tr id="row_%s">', $element->getHtmlId());
            $html .= '<td colspan="1"><p class="note"><span>';
            $html .= sprintf(
                '<div class="bleumipay-warning-note"><p>Please, Setup the Bleumi Pay portal before configuring the module.</p><ul class="config_points"><li>Create API key.</li><li> Create and validate Gas account.</li><li>Setup Hosted Checkout.</li></ul><p>Login to <a target="_blank" href="https://pay.bleumi.com/app/">Bleumi Pay</a> and set things up.</p><p>For more assitance please contact <a href="mailto:support@bleumi.com">support@bleumi.com</a></p></div>',
                $element->getComment()
            );
            $html .= '</span></p></td></tr>';
        }

        return $html;
    }
}
