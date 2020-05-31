<?php

/**
 * ExceptionHandler
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

use Psr\Log\LoggerInterface;

/**
 * ExceptionHandler
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

class ExceptionHandler
{

    protected $store;
    protected $logger;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger Log writer
     *
     * @return object
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->store = new DBHandler($logger);
    }

    /**
     * Function description.
     *
     * @param $entity_id    Order ID to log error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logException($entity_id, $retry_action, $code, $message)
    {
        if ($code == 400) {
            $this->logHardException($entity_id, $retry_action, $code, $message);
        } else {
            $this->logTransientException($entity_id, $retry_action, $code, $message);
        }
    }

    /**
     * Log transient exception for an order.
     *
     * @param $entity_id    Order ID to log the transient error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logTransientException($entity_id, $retry_action, $code, $message)
    {
        $tries_count = 0;
        //Get previous transient errors for this order
        $prev_count = (int) $this->store->getMeta($entity_id, 'bleumipay_transient_error_count');
        if (isset($prev_count) && !is_null($prev_count)) {
            $tries_count = $prev_count;
        }
        $prev_code = $this->store->getMeta($entity_id, 'bleumipay_transient_error_code');
        $prev_action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
        //If the same error occurs with same retry_action, then inc the retry count
        if (isset($prev_code) && isset($prev_action) && ($prev_code === $code) && ($prev_action === $retry_action)) {
            $tries_count++;
        } else {
            //Else restart count
            $tries_count = 0;
            $this->store->updateMetaData($entity_id, 'bleumipay_transient_error', 'yes');
            $this->store->updateMetaData($entity_id, 'bleumipay_transient_error_code', $code);
            $this->store->updateMetaData($entity_id, 'bleumipay_transient_error_msg', $message);
            if (!is_null($retry_action)) {
                $this->store->updateMetaData($entity_id, 'bleumipay_retry_action', $retry_action);
            }
        }
        $this->store->updateMetaData($entity_id, 'bleumipay_transient_error_count', $tries_count);
    }

    /**
     * Log hard expection for an order.
     *
     * @param $entity_id    Order ID to log the hard error
     * @param $retry_action The retry action to be performed 
     * @param $code         Error Code
     * @param $message      Error Message
     *
     * @return void
     */
    public function logHardException($entity_id, $retry_action, $code, $message)
    {
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error',  'yes');
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error_code', $code);
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error_msg', $message);
        if (!is_null($retry_action)) {
            $this->store->updateMetaData($entity_id, 'bleumipay_retry_action', $retry_action);
        }
    }

    /**
     * Clear transient error from an order.
     *
     * @param $entity_id Order ID to remove the transient error.
     *
     * @return void
     */
    public function clearTransientError($entity_id)
    {
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_code');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_msg');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_count');
        $this->store->deleteMetaData($entity_id, 'bleumipay_retry_action');
    }

    /**
     * Get previous retry counts
     *
     * @param $entity_id Order ID to get the retry count.
     *
     * @return void
     */
    public function checkRetryCount($entity_id)
    {
        $retry_count = (int) $this->store->getMeta($entity_id, 'bleumipay_transient_error_count');
        $action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
        if ($retry_count > 3) {
            $code = 'E907';
            $msg = 'Retry count exceeded.';
            $this->logHardException($entity_id, $action, $code, $msg);
        }
        return $retry_count;
    }
}
