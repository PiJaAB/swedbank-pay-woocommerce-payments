<?php

use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\OrderInterface;
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
    if ( !$order ) return;
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

  /**
   * Get Order Data.
   *
   * @param mixed $order_id
   *
   * @return array
   */
  public function getOrderData($order_id)
  {
      $data = parent::getOrderData($order_id);
      $validBilling = true;
      $validBilling = $validBilling && (bool) $data[OrderInterface::BILLING_COUNTRY_CODE];
      $validBilling = $validBilling && (bool) $data[OrderInterface::BILLING_CITY];
      $validBilling = $validBilling && (bool) $data[OrderInterface::BILLING_POSTCODE];
      $validBilling = $validBilling && (bool) $data[OrderInterface::BILLING_FIRST_NAME];

      if (!$validBilling) {
        $data[OrderInterface::BILLING_COUNTRY] = null;
        $data[OrderInterface::BILLING_COUNTRY_CODE] = null;
        $data[OrderInterface::BILLING_ADDRESS1] = null;
        $data[OrderInterface::BILLING_ADDRESS2] = null;
        $data[OrderInterface::BILLING_ADDRESS3] = null;
        $data[OrderInterface::BILLING_CITY] = null;
        $data[OrderInterface::BILLING_STATE] = null;
        $data[OrderInterface::BILLING_POSTCODE] = null;
        $data[OrderInterface::BILLING_PHONE] = null;
        $data[OrderInterface::BILLING_EMAIL] = null;
        $data[OrderInterface::BILLING_FIRST_NAME] = null;
        $data[OrderInterface::BILLING_LAST_NAME] = null;
      }

      $validShipping = true;
      $validShipping = $validShipping && (bool) $data[OrderInterface::SHIPPING_COUNTRY_CODE];
      $validShipping = $validShipping && (bool) $data[OrderInterface::SHIPPING_CITY];
      $validShipping = $validShipping && (bool) $data[OrderInterface::SHIPPING_POSTCODE];
      $validShipping = $validShipping && (bool) $data[OrderInterface::SHIPPING_FIRST_NAME];

      if (!$validShipping) {
        $data[OrderInterface::SHIPPING_COUNTRY] = null;
        $data[OrderInterface::SHIPPING_COUNTRY_CODE] = null;
        $data[OrderInterface::SHIPPING_ADDRESS1] = null;
        $data[OrderInterface::SHIPPING_ADDRESS2] = null;
        $data[OrderInterface::SHIPPING_ADDRESS3] = null;
        $data[OrderInterface::SHIPPING_CITY] = null;
        $data[OrderInterface::SHIPPING_STATE] = null;
        $data[OrderInterface::SHIPPING_POSTCODE] = null;
        $data[OrderInterface::SHIPPING_PHONE] = null;
        $data[OrderInterface::SHIPPING_EMAIL] = null;
        $data[OrderInterface::SHIPPING_FIRST_NAME] = null;
        $data[OrderInterface::SHIPPING_LAST_NAME] = null;
      }
      
      return $data;
  }
}
