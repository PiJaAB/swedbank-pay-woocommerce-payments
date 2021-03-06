<?php

namespace SwedbankPay\Payments\WooCommerce;

use SwedbankPay\Core\Order;
use SwedbankPay\Core\OrderInterface;
use SwedbankPay\Core\Api\Transaction;
use SwedbankPay\Core\Api\TransactionInterface;
use WC_Background_Process;
use WC_Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Background_Process', false ) ) {
	include_once WC_ABSPATH . '/includes/abstracts/class-wc-background-process.php';
}

/**
 * Class WC_Background_Swedbank_Queue
 */
class WC_Background_Swedbank_Pay_Queue extends WC_Background_Process {
	/**
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * @var WC_Background_Swedbank_Pay_Queue
	 */
	private static $instance;

	private $fp = null;

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		$this->logger = wc_get_logger();

		// Uses unique prefix per blog so each blog has separate queue.
		$this->prefix = 'wp_' . get_current_blog_id();
		$this->action = 'wc_swedbank_pay_queue';

		parent::__construct();
	}

	/**
	 * Schedule fallback event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event(
				time() + MINUTE_IN_SECONDS,
				$this->cron_interval_identifier,
				$this->cron_hook_identifier
			);
		}
	}


	/**
	 * Lock process
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 */
	protected function lock_process() {
		$this->fp = fopen(dirname( __FILE__ ) . '/../lockfile', "r+");
		
		if (flock($this->fp, LOCK_EX | LOCK_NB)) {  // acquire an exclusive lock
			$this->log( 'Acquired file lock' );
			return parent::lock_process();
		} else {
			fclose($this->fp);
			throw new \Exception ( 'Couldn\'t get the lock. Possible the queue is already running?' );
		}
	}

	/**
	 * Unlock process
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		parent::unlock_process();
		$this->log( 'Released file lock' );
		flock($this->fp, LOCK_UN);
		fclose($this->fp);
		$this->fp = null;
		return $this;
	}

	/**
	 * Get batch.
	 *
	 * @return \stdClass Return the first batch from the queue.
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$results = array();

		// phpcs:disable
		$data    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$key_column} ASC",
			$key ) ); // @codingStandardsIgnoreLine.
		// phpcs:enable

		foreach ( $data as $id => $result ) {
			$task = array_filter( (array) maybe_unserialize( $result->$value_column ) );

			$batch       = new \stdClass();
			$batch->key  = $result->$column;
			$batch->data = $task;

			$results[ $id ] = $batch;

			// Create Sorting Flow by Transaction Number
			$sorting_flow[ $id ] = 0;
			$webhook             = json_decode( $task[0]['webhook_data'], true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				if ( $webhook && isset( $webhook['transaction']['number'] ) ) {
					$sorting_flow[ $id ] = $webhook['transaction']['number'];
				}
			}
		}

		// Sorting
		array_multisort( $sorting_flow, SORT_ASC, SORT_NUMERIC, $results );
		unset( $data, $sorting_flow );

		// Get first result
		$batch = array_shift( $results );

		return $batch;
	}

	/**
	 * Log message.
	 *
	 * @param $message
	 */
	private function log( $message ) {
		$this->logger->info( $message, array( 'source' => 'wc_swedbank_pay_queue' ) );
	}

	/**
	 * Code to execute for each item in the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return bool
	 */
	protected function task( $item ) {
		$this->log( sprintf( 'Start task: %s', var_export( $item, true ) ) );
		$this->log( sprintf( 'Running task with file-lock: %s', $this->fp !== null ? 'true' : false ) );
		try {
			$data = json_decode( $item['webhook_data'], true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw new \Exception( 'Invalid webhook data' );
			}

			$gateways = WC()->payment_gateways()->get_available_payment_gateways();

			/** @var \WC_Gateway_Swedbank_Pay_Cc $gateway */
			$gateway = isset( $gateways[ $item['payment_method_id'] ] ) ? $gateways[ $item['payment_method_id'] ] : false;
			if ( ! $gateway ) {
				throw new \Exception(
					sprintf(
						'Can\'t retrieve payment gateway instance: %s',
						$item['payment_method_id']
					)
				);
			}

			if ( ! isset( $data['payment'] ) || ! isset( $data['payment']['id'] ) ) {
				throw new \Exception( 'Error: Invalid payment value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			// Get Order by Payment Id
			$payment_id = $data['payment']['id'];
			$order_id   = $this->get_post_id_by_meta( '_payex_payment_id', $payment_id );
			if ( ! $order_id ) {
				throw new \Exception( sprintf( 'Error: Failed to get order Id by Payment Id %s', $payment_id ) );
			}

			// Get Order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new \Exception( sprintf( 'Error: Failed to get order by Id %s', $order_id ) );
			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR]: Validation error: %s', $e->getMessage() ) );

			return false;
		}

		try {
			// Fetch transactions list
			$transactions = $gateway->core->fetchTransactionsList( $payment_id );
			$gateway->core->saveTransactions( $order_id, $transactions );

			// Extract transaction from list
			$transaction_id = $data['transaction']['number'];
			$transaction    = $gateway->core->findTransaction( 'number', $transaction_id );
			$this->log( sprintf( 'Transaction: %s', var_export( $transaction, true ) ) );
			if ( ! $transaction ) {
				throw new \Exception( sprintf( 'Failed to fetch transaction number #%s', $transaction_id ) );
			}

			// Process transaction
			try {
				// Disable status change hook
				remove_action( 'woocommerce_order_status_changed', 'WC_Swedbank_Pay::order_status_changed', 10 );
				// Hack for verification
				/** @var Order $order */
				$SwedbankOrder = new Order($gateway->adapter->getOrderData($order->get_id()));

				if (is_array($transaction)) {
						$transaction = new Transaction($transaction);
				} elseif (!$transaction instanceof Transaction) {
						throw new \InvalidArgumentException('Invalid a transaction parameter');
				}

				// Apply action
				if ($transaction->getType() === 'Verification') {
					if ($transaction->isFailed()) {
						$gateway->core->updateOrderStatus(
								$order_id,
								OrderInterface::STATUS_FAILED,
								sprintf('Verification has been failed. Reason: %s.', $transaction->getFailedDetails()),
								$transaction->getNumber()
						);
					} else if ($transaction->isPending()) {
							$gateway->core->updateOrderStatus(
									$order_id,
									OrderInterface::STATUS_AUTHORIZED,
									'Verification is pending.',
									$transaction->getNumber()
							);
					} else {
						$gateway->core->updateOrderStatus(
								$order_id,
								'Verified',
								'Card has been verified.',
								$transaction->getNumber()
						);

						// Save Payment Token
						if ($SwedbankOrder->needsSaveToken()) {
							$verifications = $gateway->core->fetchVerificationList($SwedbankOrder->getPaymentId());
							foreach ($verifications as $verification) {
								if ($verification->getPaymentToken() || $verification->getRecurrenceToken()) {
										// Add payment token
										$gateway->adapter->savePaymentToken(
												$SwedbankOrder->getCustomerId(),
												$verification->getPaymentToken(),
												$verification->getRecurrenceToken(),
												$verification->getCardBrand(),
												$verification->getMaskedPan(),
												$verification->getExpireDate(),
												$SwedbankOrder->getOrderId()
										);

										// Use the first item only
										break;
								}
							}
						}
					}
				} elseif ($transaction->getType() === TransactionInterface::TYPE_CAPTURE || $transaction->getType() === TransactionInterface::TYPE_SALE) {
					if (
						!$transaction->isFailed()
						&& !$transaction->isPending()
						&& $SwedbankOrder->needsSaveToken()
					) {
						// Save Payment Token
						$verifications = $gateway->core->fetchVerificationList($SwedbankOrder->getPaymentId());
						foreach ($verifications as $verification) {
							if ($verification->getPaymentToken() || $verification->getRecurrenceToken()) {
								// Add payment token
								$gateway->adapter->savePaymentToken(
										$SwedbankOrder->getCustomerId(),
										$verification->getPaymentToken(),
										$verification->getRecurrenceToken(),
										$verification->getCardBrand(),
										$verification->getMaskedPan(),
										$verification->getExpireDate(),
										$SwedbankOrder->getOrderId()
								);

								// Use the first item only
								break;
							}
						}
						$order = wc_get_order( $order ); // Refresh the order
					}
					$payment_tokens = $order->get_payment_tokens();
					$this->log( sprintf( '[INFO]: Maybe update payment tokens: %s', $payment_tokens && count($payment_tokens) > 0 && function_exists( 'wcs_get_subscriptions_for_order' ) ? 'yes' : 'no' ) );
					if ($payment_tokens && count($payment_tokens) > 0 && function_exists( 'wcs_get_subscriptions_for_order' )) {
						$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
						$this->log( sprintf( '[INFO]: Subscription count: %s', $subscriptions ? count($subscriptions) : 0 ) );
						foreach ( $subscriptions as $subscription ) {
							$old_payment_tokens = $subscription->get_payment_tokens();
							$identical = $old_payment_tokens && count($old_payment_tokens) === count($payment_tokens);
							if ($identical) {
								foreach ($payment_tokens as $token) {
									$identical = in_array($old_payment_tokens, $token);
									if (!$identical) break;
								}
							}
							$this->log( sprintf( '[INFO]: Updating payment tokens: %s', !$identical ? 'yes' : 'no' ) );
							if (!$identical) {
								$this->log( sprintf( '[INFO]: Updating subscription: %s, new tokens: %s', $subscription->get_id(), implode(',', $payment_tokens)) );
								update_post_meta($subscription->get_id(), '_payment_tokens', $payment_tokens );
							}
						}
					}
					$gateway->core->processTransaction( $order->get_id(), $transaction );
				} else {
					$gateway->core->processTransaction( $order->get_id(), $transaction );
				}
			} catch ( \Exception $e ) {
				$this->log( sprintf( '[WARNING]: Transaction processing: %s', $e->getMessage() ) );
			}

			// Enable status change hook
			add_action( 'woocommerce_order_status_changed', 'WC_Swedbank_Pay::order_status_changed', 10, 4 );

			return false;
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR]: %s', $e->getMessage() ) );
		}

		return true;
	}

	/**
	 * This runs once the job has completed all items on the queue.
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();

		$this->log( 'Completed swedbank-pay queue job.' );
	}

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

		/**
		 * Handle
		 *
		 * Pass each queue item to the task handler, while remaining
		 * within server memory and time limit constraints.
		 */
		protected function handle() {
			try {
				parent::handle();
			} catch (Exception $e) {
				$this->log( sprintf( '[ERROR]: %s', $e->getMessage() ) );
				wp_die();
			}
		}

	/**
	 * Get Post Id by Meta
	 *
	 * @param $key
	 * @param $value
	 *
	 * @return null|string
	 */
	private function get_post_id_by_meta( $key, $value ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s;",
				$key,
				$value
			)
		);
	}
}
