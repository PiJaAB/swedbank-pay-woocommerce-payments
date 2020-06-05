<?php

use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\Log\LogLevel;

defined('ABSPATH') || exit;

class WC_Patched_Adapter extends WC_Adapter
{
  /**
   * Update Order Status.
   *
   * @param mixed $order_id
   * @param string $status
   * @param string|null $message
   * @param mixed|null $transaction_id
   */
  public function updateOrderStatus($order_id, $status, $message = null, $transaction_id = null) {
    $order = wc_get_order($order_id);
    $order->read_meta_data(true);
    $old_transaction_number = $order->get_meta('_transaction_number');
    if ($old_transaction_number && $transaction_id) {
        $this->log(LogLevel::INFO, sprintf("Old transaction id: %s\nNew transaction id: %s", $old_transaction_number, $transaction_id));
        if ((int) $old_transaction_number >= (int) $transaction_id) {
            $this->log(LogLevel::WARNING, 'ATTEMPTED TO PROCESS A PREVIOUS TRANSACTION WHEN A LATER ONE HAD ALREADY BEEN PROCESSED!');
            return;
        }
        $this->log(LogLevel::INFO, sprintf("Storing transaction metadata: %s", $transaction_id));
        $order->update_meta_data('_transaction_number', (string) $transaction_id);
        $order->save_meta_data();
    } else if ($transaction_id) {
        $this->log(LogLevel::INFO, sprintf("Old transaction id: %s\nNew transaction id: %s", $old_transaction_number, $transaction_id));
        $this->log(LogLevel::INFO, sprintf("Storing transaction metadata: %s", $transaction_id));
        $order->update_meta_data('_transaction_number', (string) $transaction_id);
        $order->save_meta_data();
    } else {
        $this->log(LogLevel::INFO, sprintf("Old transaction id: %s\nNew transaction id: %s", $old_transaction_number, $transaction_id));
    }
    parent::updateOrderStatus($order_id, $status, $message, $transaction_id);
  }
}
