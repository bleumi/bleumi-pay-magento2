<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
 **/

namespace BleumiPay\PaymentGateway\Setup;

use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use Magento\Store\Model\StoreManagerInterface;

class InstallData implements InstallDataInterface
{
    private $storeManagerInterface;

    /**
     * Custom Order-State code
     */
    const ORDER_STATE_AWAITING_CONFIRMATION_CODE = 'awaiting_confirmation';
    const ORDER_STATE_PAYMENT_FAILED_CODE = 'payment_failed';
    const ORDER_STATE_PAYMENT_MULTITOKEN_CODE = 'multi_token_payment';

    /**
     * Custom Order-Status code
     */
    const ORDER_STATUS_AWAITING_CONFIRMATION_CODE = 'awaiting_confirmation';
    const ORDER_STATUS_PAYMENT_FAILED_CODE = 'payment_failed';
    const ORDER_STATUS_PAYMENT_MULTITOKEN_CODE = 'multi_token_payment';

    /**
     * Custom Order-Status label
     */
    const ORDER_STATUS_AWAITING_CONFIRMATION_LABEL = 'Awaiting Payment Confirmation';
    const ORDER_STATUS_PAYMENT_FAILED_LABEL = 'Payment Failed';
    const ORDER_STATUS_PAYMENT_MULTITOKEN_LABEL = 'Multi Token Payment';

    /**
     * Status Factory
     *
     * @var StatusFactory
     */
    protected $statusFactory;

    /**
     * Status Resource Factory
     *
     * @var StatusResourceFactory
     */
    protected $statusResourceFactory;

    /**
     * InstallData constructor
     *
     * @param StatusFactory $statusFactory
     * @param StatusResourceFactory $statusResourceFactory
     */
    public function __construct(
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->storeManagerInterface = $storeManager;
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
    }
    /**
     * {@inheritdoc}
     **/
    public function install(
        ModuleDataSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $data = [
            'id' => 1,
            'payment_updated_at' => date("Y-m-d H:i:s"),
            'order_updated_at' => date("Y-m-d H:i:s"),
        ];

        $setup->getConnection()->insertOnDuplicate($setup->getTable('bleumi_pay_cron'), $data);

        $this->addNewOrderStateAndStatus();
    }

    /**
     * Create new custom order status and assign it to the new custom order state
     *
     * @return void
     *
     * @throws Exception
     */
    protected function addNewOrderStateAndStatus()
    {
        /** @var StatusResource $statusResource */
        $statusResource = $this->statusResourceFactory->create();
        $this->addItem($statusResource,
            self::ORDER_STATE_AWAITING_CONFIRMATION_CODE,
            self::ORDER_STATUS_AWAITING_CONFIRMATION_CODE,
            self::ORDER_STATUS_AWAITING_CONFIRMATION_LABEL);

        $this->addItem($statusResource,
            self::ORDER_STATE_PAYMENT_FAILED_CODE,
            self::ORDER_STATUS_PAYMENT_FAILED_CODE,
            self::ORDER_STATUS_PAYMENT_FAILED_LABEL);

        $this->addItem($statusResource,
            self::ORDER_STATE_PAYMENT_MULTITOKEN_CODE,
            self::ORDER_STATUS_PAYMENT_MULTITOKEN_CODE,
            self::ORDER_STATUS_PAYMENT_MULTITOKEN_LABEL);
    }

    protected function addItem($statusResource, $stateCode, $statusCode, $statusLabel)
    {
        /** @var Status $status */
        $status = $this->statusFactory->create();
        $status->setData([
            'status' => $statusCode,
            'label' => $statusLabel,
        ]);

        try {
            $statusResource->save($status);
        } catch (AlreadyExistsException $exception) {
            return;
        }
        $status->assignState($stateCode, true, true);
    }

}
