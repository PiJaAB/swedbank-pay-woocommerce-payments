<?php

use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\Order\PlatformUrlsInterface;
use SwedbankPay\Core\ConfigurationInterface;
use SwedbankPay\Core\Log\LogLevel;

defined('ABSPATH') || exit;


class WC_Patched_Adapter extends WC_Adapter
{
  /**
   * @var WC_Payment_Gateway
   */
  private $gateway;

  /**
   * WC_Adapter constructor.
   *
   * @param WC_Payment_Gateway $gateway
   */
  public function __construct(WC_Payment_Gateway $gateway)
  {
    parent::__construct($gateway);
    $this->gateway = $gateway;
  }

  private static function patchPhoneNumber($phone) {
		if ( ! $phone ) {
			return null;
		}

		if (preg_match ( '/^\+[0-9]+$/', $phone)) {
			return $phone;
		}

		if (!preg_match ( '/^\+?[0-9\s-]+$/', $phone)) {
			return null;
		}

		$phone = preg_replace('/[\s-]/', '', $phone);

		if (!preg_match ( '/^(?:\+|0)[0-9]/', $phone)) {
      return null;
		}

		if ($phone[0] !== '+' ) {
			$phone = substr_replace($phone, '+46', 0, 1);
    }

    return $phone;
  }
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
  
    $order = wc_get_order($order_id);

    if ($order->get_meta('_payex_payment_state') === $status) {
      $this->log(LogLevel::WARNING, sprintf('Action of Transaction #%s already performed', $transaction_id));

      return;
    }

    if ($transaction_id) {
      $order->update_meta_data('_transaction_id', $transaction_id);
      $order->save_meta_data();
    }

    switch ($status) {
      case OrderInterface::STATUS_PENDING:
        $order->update_meta_data('_payex_payment_state', $status);
        $order->update_status('on-hold', $message);
        break;
      case OrderInterface::STATUS_AUTHORIZED:
        $order->update_meta_data('_payex_payment_state', $status);
        $order->save_meta_data();

        // Reduce stock
        $order_stock_reduced = $order->get_meta('_order_stock_reduced');
        if (!$order_stock_reduced) {
            wc_reduce_stock_levels($order->get_id());
        }

        $order->update_status('on-hold', $message);

        break;
      case 'Verified':
        $order->update_meta_data('_payex_payment_state', $status);
        $order->save_meta_data();

        $order->payment_complete($transaction_id);
        $order->add_order_note($message);
        break;
      case OrderInterface::STATUS_CAPTURED:
        $order->update_meta_data('_payex_payment_state', $status);
        $order->save_meta_data();

        $order->update_status('completed', $message);
        $order->add_order_note($message);
        break;
      case OrderInterface::STATUS_CANCELLED:
        $order->update_meta_data('_payex_payment_state', $status);
        $order->save_meta_data();

        if (!$order->has_status('cancelled')) {
            $order->update_status('cancelled', $message);
        } else {
            $order->add_order_note($message);
        }
        break;
      case OrderInterface::STATUS_REFUNDED:
        // @todo Implement Refunds creation
        // @see wc_create_refund()

        $order->update_meta_data('_payex_payment_state', $status);
        $order->save_meta_data();

        if (!$order->has_status('refunded')) {
            $order->update_status('refunded', $message);
        } else {
            $order->add_order_note($message);
        }

        break;
      case OrderInterface::STATUS_FAILED:
        $order->update_status('failed', $message);
        break;

    }
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
      } else {
        $data[OrderInterface::BILLING_PHONE] = WC_Patched_Adapter::patchPhoneNumber($data[OrderInterface::BILLING_PHONE]);
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
      } else {
        $data[OrderInterface::SHIPPING_PHONE] = WC_Patched_Adapter::patchPhoneNumber($data[OrderInterface::SHIPPING_PHONE]);
      }
      
      return $data;
  }

      /**
     * Get Platform Urls of Actions of Order (complete, cancel, callback urls).
     *
     * @param mixed $order_id
     *
     * @return array
     */
    public function getPlatformUrls($order_id)
    {
        $order = wc_get_order($order_id);

        $callback_url = $order !== false ?
          add_query_arg(
              array(
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ),
            WC()->api_request_url(get_class($this->gateway))
          ) :
          WC()->api_request_url(get_class($this->gateway));

        if ($this->gateway->is_new_credit_card) {
            $ret = array(
                PlatformUrlsInterface::COMPLETE_URL => add_query_arg(
                    'action',
                    'swedbank_card_store',
                    admin_url('admin-ajax.php')
                ),
                PlatformUrlsInterface::CANCEL_URL => wc_get_account_endpoint_url('payment-methods'),
                PlatformUrlsInterface::CALLBACK_URL => $callback_url,
                PlatformUrlsInterface::TERMS_URL => '',
            );
        } else if ($this->gateway->is_change_credit_card) {
            $ret = array(
                PlatformUrlsInterface::COMPLETE_URL => add_query_arg(
                    array(
                        'verify' => 'true',
                        'key' => $order->get_order_key(),
                    ),
                    $this->gateway->get_return_url($order)
                ),
                PlatformUrlsInterface::CANCEL_URL => $order->get_cancel_order_url_raw(),
                PlatformUrlsInterface::CALLBACK_URL => $callback_url,
                PlatformUrlsInterface::TERMS_URL => $this->getConfiguration()[ConfigurationInterface::TERMS_URL],
            );
        } else {
          $ret = array(
            PlatformUrlsInterface::COMPLETE_URL => $this->gateway->get_return_url($order),
            PlatformUrlsInterface::CANCEL_URL => $order->get_cancel_order_url_raw(),
            PlatformUrlsInterface::CALLBACK_URL => $callback_url,
            PlatformUrlsInterface::TERMS_URL => $this->getConfiguration()[ConfigurationInterface::TERMS_URL],
          );
        }
        /*var_dump($ret);
        die();*/
        return $ret;
    }

    /**
     * Save Payment Token.
     *
     * @param mixed $customer_id
     * @param string $payment_token
     * @param string $recurrence_token
     * @param string $card_brand
     * @param string $masked_pan
     * @param string $expiry_date
     * @param mixed|null $order_id
     */
    public function savePaymentToken(
      $customer_id,
      $payment_token,
      $recurrence_token,
      $card_brand,
      $masked_pan,
      $expiry_date,
      $order_id = null
  ) {
      $expiry_date = explode('/', $expiry_date);

      // Create Payment Token
      $token = new WC_Payment_Token_Swedbank_Pay();
      $token->set_gateway_id($this->gateway->id);
      $token->set_token($payment_token);
      $token->set_recurrence_token($recurrence_token);
      $token->set_last4(substr($masked_pan, -4));
      $token->set_expiry_year($expiry_date[1]);
      $token->set_expiry_month($expiry_date[0]);
      $token->set_card_type(strtolower($card_brand));
      $token->set_user_id($customer_id);
      $token->set_masked_pan($masked_pan);
      $token->save();
      if (!$token->get_id()) {
          throw new \Exception(__('There was a problem adding the card.', 'swedbank-pay-woocommerce-payments'));
      }

      // Add payment token
      if ($order_id) {
          $order = wc_get_order($order_id);
          $order->add_payment_token($token);
          $order->save_meta_data();
      }
  }
}
