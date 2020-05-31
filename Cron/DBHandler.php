<?php

/**
 * DBHandler
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

namespace Bleumi\BleumiPay\Cron;

use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;
use \Magento\Sales\Model\Order;

/**
 * DBHandler 
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

class DBHandler
{

    const CRON_COLLISION_SAFE_MINUTES = 10;

    const AWAIT_PAYMENT_MINUTES = 24 * 60;

    protected $objectManager;
    protected $resource;
    protected $connection;
    protected $logger;
    protected $sales_order;
    protected $sales_order_payment;
    protected $bleumi_pay_cron;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger Log writer
     *
     * @return object
     */
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
     * Get the (Pending/Awaiting confirmation/Multi Token Payment)
     * order for the entity_id.
     *
     * @param $entity_id Order ID to get the details
     *
     * @return object
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
     *
     * @return array
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
     * Usage: Orders cron to get all orders that are in
     * 'awaiting_confirmation' status to check if
     * they are still awaiting even after 24 hours.
     *
     * @param $status Filter criteria - status value
     * @param $type   The field to filter on. ('bleumipay_payment_status', 'status')
     *
     * @return object
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
     *
     * @param $start_at Filter criteria - orders that are modified after this value will be returned
     *
     * @return object
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
     * Creates and executes the UPDATE statement
     * for a string columns of any table
     *
     * @param $table_name   Table name
     * @param $entity_id    ID of the Order to update
     * @param $column_name  Column name
     * @param $column_value Value
     *
     * @return object
     */
    public function updateStringData($table_name, $entity_id, $column_name, $column_value = null)
    {
        $table_name = $this->resource->getTableName($table_name);
        if (!empty($entity_id)) {
            $sql = "UPDATE " . $table_name . " SET " . $column_name . " = null WHERE entity_id = " . $entity_id;
            if (!empty($column_value)) {
                $sql = "UPDATE " . $table_name . " SET " . $column_name . " = '" . $column_value . "'  WHERE entity_id = " . $entity_id;
            }
            $this->connection->query($sql);
        }
    }

    /**
     * Update Order Meta Data
     *
     * @param $entity_id    ID of the Order to update
     * @param $column_name  Column Name
     * @param $column_value Value
     * 
     * @return void
     */
    public function updateMetaData($entity_id, $column_name, $column_value)
    {
        return $this->updateStringData('sales_order', $entity_id, $column_name, $column_value);
    }

    /**
     * Delete Order Meta Data
     *
     * @param $entity_id   ID of the Order to delete
     * @param $column_name Column Name
     *
     * @return void
     */
    public function deleteMetaData($entity_id, $column_name)
    {
        return $this->updateStringData('sales_order', $entity_id, $column_name);
    }

    /**
     * Get Order Meta Data
     *
     * @param $entity_id   ID of the Order to get
     * @param $column_name Column Name
     *
     * @return void
     */
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

    /**
     * Update Cron Execution time
     *
     * @param $name Name of the column to update in bleumi_pay_cron table
     * @param $time value (UNIX date/time)
     *
     * @return void
     */
    public function updateRuntime($name, $time)
    {
        $sql = "UPDATE " . $this->bleumi_pay_cron . " SET " . $name . "='" . $time . "'  WHERE id = 1 ";
        $this->connection->query($sql);
    }

    /**
     * Get the previous execution time for the given cron job
     *
     * @param $name Name of column to fetch value for
     *
     * @return void
     */
    public function getCronTime($name)
    {
        $result = $this->connection->fetchAll("SELECT * FROM bleumi_pay_cron WHERE id = 1");
        return $result[0][$name];
    }
}
