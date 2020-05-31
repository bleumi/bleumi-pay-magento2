<?php

/**
 * Utils
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */

namespace Bleumi\BleumiPay\Cron;

use Magento\Framework\App\ObjectManager;
use \Magento\Sales\Model\Order;

/**
 * Utility functions
 *
 * PHP version 5
 *
 * @category  Bleumi
 * @package   Bleumi_BleumiPay
 * @author    Bleumi Pay <support@bleumi.com>
 * @copyright 2020 Bleumi, Inc. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE
 * @link      http://pay.bleumi.com
 */

class Utils
{
    /**
     * Updates the Order Status
     *
     * @param $entity_id      Order ID to apply new status
     * @param $status         New order status
     * @param $msg            Message for the status change
     * @param array $valid_statuses The statuses the order can be in for the update to apply
     *
     * @return bool
     */
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

    /**
     * Update Pending Order
     *
     * @param $entity_id Order ID
     * @param $status    New order status
     *
     * @return bool
     */
    public static function updatePendingOrder($entity_id, $status)
    {
        return self::updateOrderStatus($entity_id, $status);
    }

    /**
     * Changes the order status to 'payment_failed'
     *
     * @param $entity_id ID of the order to change status to 'payment_failed'
     *
     * @return bool
     */
    public static function failThisOrder($entity_id)
    {
        return self::updateOrderStatus($entity_id, "payment_failed", 'Payment Failed.');
    }

    /**
     * Changes the order status to 'multi_token_payment'
     *
     * @param $entity_id ID of the order to change status to 'multi_token_payment'
     *
     * @return bool
     */
    public static function markAsMultiTokenPayment($entity_id)
    {
        $valid_statuses = array("on-hold", "pending", "awaiting_confirmation");
        return self::updateOrderStatus($entity_id, "multi_token_payment", 'Multi Token Payment.', $valid_statuses);
    }

    /**
     * Get Minutes Difference - Returns the difference in minutes between 2 datetimes
     *
     * @param $dateTime1 start datetime
     * @param $dateTime2 end datetime
     *
     * @return bool
     */
    public static function getMinutesDiff($dateTime1, $dateTime2)
    {
        $minutes = abs($dateTime1 - $dateTime2) / 60;
        return $minutes;
    }

    /**
     * Adds Order note
     *
     * @param $entity_id      Order ID to add note
     * @param string $note           Text of note to add
     * @param bool   $notifyCustomer Notify customer indicator.
     *
     * @return void
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
     *
     * @param string $txHash Transaction hash.
     * @param string $chain  Network.
     *
     * @return string
     */
    public static function getTransactionLink($txHash, $chain = null)
    {
        switch ($chain) {
        case 'alg_mainnet':
            return 'https://algoexplorer.io/tx/' . $txHash;
                break;
        case 'alg_testnet':
            return 'https://algoexplorer.io/tx/' . $txHash;
                break;
        case 'rsk':
            return 'https://explorer.rsk.co/tx/' . $txHash;
                break;
        case 'rsk_testnet':
            return 'https://explorer.testnet.rsk.co/tx/' . $txHash;
                break;
        case 'mainnet':
        case 'xdai':    
            return 'https://etherscan.io/tx/' . $txHash;
                break;
        case 'goerli':
        case 'xdai_testnet':     
            return 'https://goerli.etherscan.io/tx/' . $txHash;
                break;    
        default:
            return;
        }
    }
}
