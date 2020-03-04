<?php

namespace BleumiPay\PaymentGateway\Cron;

use Psr\Log\LoggerInterface;

class ExceptionHandler {

    protected $store;
    protected $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->store = new DBHandler($logger);
    }

    public function logException($entity_id, $retry_action, $code, $message) {
        if ($code == 400)  {
            $this->logHardException($entity_id, $retry_action, $code, $message) ;
        } else {
            $this->logTransientException($entity_id, $retry_action, $code, $message) ;
        }
    }

    public function logTransientException($entity_id, $retry_action, $code, $message) {
        $tries_count = 0;
        //Get previous transient errors for this order
        $prev_count = (int)$this->store->getMeta($entity_id, 'bleumipay_transient_error_count');
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

    public function logHardException($entity_id, $retry_action, $code, $message) {
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error',  'yes');
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error_code', $code);
        $this->store->updateMetaData($entity_id, 'bleumipay_hard_error_msg', $message);
        if (!is_null($retry_action)) {
            $this->store->updateMetaData($entity_id, 'bleumipay_retry_action', $retry_action);
        }
    }

    public function clearTransientError($entity_id) {
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_code');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_msg');
        $this->store->deleteMetaData($entity_id, 'bleumipay_transient_error_count');
        $this->store->deleteMetaData($entity_id, 'bleumipay_retry_action');
    }

    public function checkRetryCount($entity_id) {
        $retry_count = (int)$this->store->getMeta($entity_id, 'bleumipay_transient_error_count');
        $action = $this->store->getMeta($entity_id, 'bleumipay_retry_action');
        if ($retry_count > 3) {
            $code = 'E907';
            $msg = 'Retry count exceeded.';
            $this->logHardException($entity_id, $action, $code , $msg);
        }
        return $retry_count;
    }

}