<?php

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 * See COPYING.txt for license details.
 **/

namespace BleumiPay\PaymentGateway\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritdoc}
     */
    public function install(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $installer = $setup;
        $installer->startSetup();
        try {
            $tableName = $installer->getTable('bleumi_pay_cron');

            // Check if the table already exists
            if ($installer->getConnection()->isTableExists($tableName) != true) {

                $connection = $installer->getConnection();

                $table = $connection
                    ->newTable($tableName)
                    ->addColumn(
                        'id',
                        Table::TYPE_INTEGER,
                        null,
                        [
                            'identity' => true,
                            'nullable' => false,
                            'primary' => true,
                            'unsigned' => true,
                        ],
                        'ID'
                    )
                    ->addColumn(
                        'payment_updated_at',
                        Table::TYPE_TIMESTAMP,
                        null,
                        ['nullable' => true, 'default' => Table::TIMESTAMP_INIT],
                        'Payment Updated'
                    )
                    ->addColumn(
                        'order_updated_at',
                        Table::TYPE_TIMESTAMP,
                        null,
                        ['nullable' => true, 'default' => Table::TIMESTAMP_INIT],
                        'Order Updated'
                    )
                    ->setComment('Cron job parameters (Bleumi Pay)');

                $connection->createTable($table);

            }

            $this->addColumnToSales($connection, $setup, 'bleumipay_addresses', Table::TYPE_TEXT, 0, 'Wallet Addresses (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_payment_status', Table::TYPE_TEXT, 20, 'Payment Status (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_txid', Table::TYPE_TEXT, 20, 'Transaction ID for Operation (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_data_source', Table::TYPE_TEXT, 20, 'Data Source (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_transient_error', Table::TYPE_TEXT, 20, 'Transient Error Indicator (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_transient_error_code', Table::TYPE_TEXT, 20, 'Transient Error Code (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_transient_error_msg', Table::TYPE_TEXT, 256, 'Transient Error Message (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_retry_action', Table::TYPE_TEXT, 256, 'Transient Error Retry Action (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_transient_retry_count', Table::TYPE_TEXT, 20, 'Transient Error Retry Count (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_hard_error', Table::TYPE_TEXT, 20, 'Hard Error Indicator (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_hard_error_code', Table::TYPE_TEXT, 20, 'Hard Error Code (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_hard_error_msg', Table::TYPE_TEXT, 256, 'Hard Error Message (Bleumi Pay)');
            $this->addColumnToSales($connection, $setup, 'bleumipay_processing_completed', Table::TYPE_TEXT, 20, 'Processing completed Indicator (Bleumi Pay)');

        } catch (\Throwable $th) {
            // echo "unable to create table";
            // print_r($th);
        }

        $setup->endSetup();
    }

    /*
     * Add a column to the sales_order table
     *
     */
    private function addColumnToSales($connection, $setup, $columnName, $dataType, $length, $comment)
    {
        if ($connection->tableColumnExists('sales_order', $columnName) === false) {
            $connection
                ->addColumn(
                    $setup->getTable('sales_order'),
                    $columnName,
                    [
                        'type' => $dataType,
                        'length' => $length,
                        'comment' => $comment,
                    ]
                );
        }
    }
}
