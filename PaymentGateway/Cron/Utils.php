<?php

namespace BleumiPay\PaymentGateway\Cron;

use Magento\Framework\App\ObjectManager;
use \Magento\Sales\Model\Order;

class Utils
{

    public static function updateOrderStatus($entity_id, $status, $msg = null, $valid_statuses = array())
    {
        if (!empty($entity_id) && !empty($status)) {
            $order = ObjectManager::getInstance()->create('Magento\Sales\Model\Order')->load($entity_id);
            $order_status = $order['status'];
            if ((count($valid_statuses) == 0) || in_array($order_status, $valid_statuses)) {
                $order->setState($status, true, $msg);
                $order->setStatus($status, true);
                $order->save();
                return true;
            }
        }
        return false;
    }

    public static function updatePendingOrder($entity_id, $status)
    {
        return self::updateOrderStatus($entity_id, $status);
    }

    /**
     * Changes the order status to 'payment_failed'
     */

    public static function failThisOrder($entity_id)
    {
        return self::updateOrderStatus($entity_id, "payment_failed", 'Payment Failed.');
    }

    /**
     * Changes the order status to 'multi_token_payment'
     */

    public static function markAsMultiTokenPayment($entity_id)
    {
        $valid_statuses = array("on-hold", "pending", "awaiting_confirmation");
        return self::updateOrderStatus($entity_id, "multi_token_payment", 'Multi Token Payment.', $valid_statuses);
    }

    /**
     * Helper function - Returns the difference in minutes between 2 datetimes
     */

    public static function getMinutesDiff($dateTime1, $dateTime2)
    {
        $minutes = abs($dateTime1 - $dateTime2) / 60;
        return $minutes;
    }

    /**
     * Adds Order note
     */

    public static function addOrderNote($entity_id, $note, $notifyCustomer = false)
    {
        $order = ObjectManager::getInstance()->create('Magento\Sales\Model\Order')->load($entity_id);
        $history = $order->addStatusHistoryComment($note);
        $history->setIsCustomerNotified($notifyCustomer);
        $order->save();
    }

    /**
     * Returns the transaction link for the txhash in the given chain
     */

    public static function getTransactionLink($txHash, $chain = null)
    {
        if (($chain === 'alg_mainnet') || ($chain === 'alg_testnet')) {
            return 'https://testnet.algoexplorer.io/tx/' . $txHash;
        } else {
            return 'https://goerli.etherscan.io/tx/' . $txHash;
        }
    }

}
