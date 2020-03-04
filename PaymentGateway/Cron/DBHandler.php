<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

namespace BleumiPay\PaymentGateway\Cron;

use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;
use \Magento\Sales\Model\Order;

class DBHandler
{

    const cron_collision_safe_minutes = 10;

    const await_payment_minutes = 24 * 60;

    private $objectManager;
    private $resource;
    private $connection;
    protected $logger;
    protected $sales_order;
    protected $sales_order_payment;
    protected $bleumi_pay_cron;

    public function __construct(LoggerInterface $logger)
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $this->connection = $this->resource->getConnection();
        $this->logger = $logger;
        $this->sales_order = $this->resource->getTableName("sales_order");
        $this->sales_order_payment = $this->resource->getTableName("sales_order_payment");
        $this->bleumi_pay_cron = $this->resource->getTableName("bleumi_pay_cron");
    }

    /**
     * Get the (Pending/Awaiting confirmation/Multi Token Payment) order for the entity_id.
     */

    public function getPendingOrder($entity_id)
    {
        $sql = "SELECT * FROM " . $this->sales_order . " WHERE entity_id = " . $entity_id . " AND status IN ('pending', 'awaiting_confirmation', 'multi_token_payment')";
        $result = $this->connection->fetchAll($sql);

        if (count($result) == 0) {
            $this->logger->info("bleumi_pay: getPendingOrder: order-id: '. $entity_id .' No pending orders found ");
        } else {
            $this->logger->info("bleumi_pay: getPendingOrder:  order-id: '. $entity_id .' Found : " . count($result) . " pending/awaiting_confirmation order");
        }
        return $result;
    }

    /**
     * Get all orders with transient errors.
     * Used by Retry cron to reprocess such orders
     */

    public function getTransientErrorOrders()
    {

        $sql = "
                SELECT
                    s.*
                FROM " .
        $this->sales_order . " s, " .
        $this->sales_order_payment . " sp
                WHERE
                    s.bleumipay_transient_error = 'yes' AND
                    ((s.bleumipay_processing_completed = 'no') OR (s.bleumipay_processing_completed IS NULL)) AND
                    sp.method = 'bleumipaymethod' AND
                    s.entity_id = sp.parent_id
                ORDER BY
                    s.updated_at ASC
                ";
        $result = $this->connection->fetchAll($sql);

        if (count($result) == 0) {
            $this->logger->info("bleumi_pay: getTransientErrorOrders: No transient error orders found");
        } else {
            $this->logger->info("bleumi_pay: getTransientErrorOrders: Found : " . count($result) . " transient error orders");
        }

        return $result;
    }

    /**
     * Get all orders with status = $orderStatus
     * Usage: Orders cron to get all orders that are in 'awaiting_confirmation' status to check if
     * they are still awaiting even after 24 hours.
     */

    public function getOrdersForStatus($status, $type)
    {

        $sql = "
                SELECT
                    s.*
                FROM " .
        $this->sales_order . " s, " .
        $this->sales_order_payment . " sp
                WHERE
                    s.entity_id = sp.parent_id AND
                    s.$type = '" . $status . "' AND
                    ((s.bleumipay_processing_completed = 'no') OR (s.bleumipay_processing_completed IS NULL)) AND
                    sp.method = 'bleumipaymethod'
                ORDER BY
                    s.updated_at ASC
                ";
        $result = $this->connection->fetchAll($sql);

        if (count($result) == 0) {
            $this->logger->info("bleumi_pay: getOrdersForStatus: No '" . $status . "' orders found");
        } else {
            $this->logger->info("bleumi_pay: getOrdersForStatus: Found : " . count($result) . " '" . $status . "' orders");
        }
        return $result;
    }

    /**
     * Get all orders that are modified after $start_at
     * Usage: The list of orders processed by Orders cron
     */

    public function getUpdatedOrders($start_at)
    {

        $sql = "
                SELECT
                    s.*
                FROM " .
        $this->sales_order . " s, " .
        $this->sales_order_payment . " sp
                WHERE
                    s.entity_id = sp.parent_id AND
                    ((s.bleumipay_processing_completed = 'no') OR (s.bleumipay_processing_completed IS NULL)) AND
                    s.status IN ('canceled', 'complete') AND
                    s.updated_at BETWEEN '" . $start_at . "' AND now() AND
                    sp.method = 'bleumipaymethod'
                ORDER BY
                    s.updated_at ASC
                ";
        $result = $this->connection->fetchAll($sql);

        if (count($result) == 0) {
            $this->logger->info("bleumi_pay: getUpdatedOrders: No  orders found");
        } else {
            $this->logger->info("bleumi_pay: getUpdatedOrders: Found : " . count($result) . " orders");
        }

        return $result;
    }

    /**
     * Helper function - creates and executes the UPDATE statement for a string columns of any table
     */

    public function updateStringData($table_name, $entity_id, $column_name, $column_value)
    {
        $table_name = $this->resource->getTableName($table_name);
        if (!empty($entity_id) && !empty($column_value)) {
            $sql = " UPDATE " . $table_name . " SET " . $column_name . " = '" . $column_value . "'  WHERE entity_id = " . $entity_id;
            $this->connection->query($sql);
        }
    }

    public function updateMetaData($entity_id, $column_name, $column_value)
    {
        return $this->updateStringData('sales_order', $entity_id, $column_name, $column_value);
    }

    public function deleteMetaData($entity_id, $column_name)
    {
        return $this->updateStringData('sales_order', $entity_id, $column_name, '');
    }

    public function getMeta($entity_id, $column_name)
    {
        try {
            $sql = "SELECT " . $column_name . " FROM " . $this->sales_order . " WHERE entity_id = " . $entity_id;
            $result = $this->connection->fetchAll($sql);
            return $result[0][$column_name];
        } catch (\Throwable $th) {
            $this->logger->info('bleumi_pay: getMeta: exception for entity_id : ' . $entity_id);
            $this->logger->info('bleumi_pay: getMeta: exception for $column_name  : ' . $column_name);
            return null;
        }
    }

    public function updateRuntime($name, $time)
    {
        $sql = "UPDATE " . $this->bleumi_pay_cron . " SET " . $name . "='" . $time . "'  WHERE id = 1 ";
        $this->connection->query($sql);
    }

    /**
     * Get the previous execution time for the given cron job
     */

    public function getCronTime($name)
    {
        $result = $this->connection->fetchAll("SELECT * FROM bleumi_pay_cron WHERE id = 1");
        return $result[0][$name];
    }

}
