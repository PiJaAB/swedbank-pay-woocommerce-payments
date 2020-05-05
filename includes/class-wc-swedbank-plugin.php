<?php

namespace SwedbankPay\Payments\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Payments\WooCommerce\WC_Background_Swedbank_Pay_Queue;
use WC_Order;
use WC_Admin_Meta_Boxes;
use Exception;

class WC_Swedbank_Plugin {

	/** Payment IDs */
	const PAYMENT_METHODS = [
		'payex_checkout',
		'payex_psp_cc',
		'payex_psp_invoice',
		'payex_psp_vipps',
		'payex_psp_swish'
	];

	/**
	 * @var WC_Background_Swedbank_Pay_Queue
	 */
	public static $background_process;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Includes
		$this->includes();

		// Activation
		register_activation_hook( __FILE__, [ $this, 'install' ] );

		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );
		add_action( 'plugins_loaded', [ $this, 'init' ], 0 );
		add_action( 'woocommerce_init', [ $this, 'woocommerce_init' ] );
		add_action( 'woocommerce_loaded', [ $this, 'woocommerce_hook_loaded' ] );

		// Add statuses for payment complete
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', [ $this, 'add_valid_order_statuses' ], 10, 2 );

		// Status Change Actions
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::order_status_changed', 10, 4 );

		// Add meta boxes
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes' );

		// Add action buttons
		add_action( 'woocommerce_order_item_add_action_buttons', __CLASS__ . '::add_action_buttons', 10, 1 );

		// Add scripts and styles for admin
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::admin_enqueue_scripts' );

		// Add Admin Backend Actions
		add_action( 'wp_ajax_swedbank_pay_capture', [ $this, 'ajax_swedbank_pay_capture' ] );

		add_action( 'wp_ajax_swedbank_pay_cancel', [ $this, 'ajax_swedbank_pay_cancel' ] );

		// Filters
		add_filter( 'swedbank_pay_generate_uuid', [ $this, 'generate_uuid' ], 10, 1 );
		add_filter( 'swedbank_pay_payment_description', [ $this, 'payment_description' ], 10, 2 );

		// Process swedbank queue
		if ( ! is_multisite() ) {
			add_action( 'customize_save_after', [ $this, 'maybe_process_queue' ] );
			add_action( 'after_switch_theme', [ $this, 'maybe_process_queue' ] );
		}

		// Add admin menu
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 99 );

		// Add Upgrade Notice
		if ( version_compare( get_option( 'woocommerce_payex_psp_version', '1.2.0' ), '1.2.0', '<' ) ) {
			add_action( 'admin_notices', __CLASS__ . '::upgrade_notice' );
		}
	}

	public function includes() {
		$vendorsDir = dirname( __FILE__ ) . '/../vendors';

		//if ( ! class_exists( '\\SwedbankPay\\Api\\Client\\Client', false ) ) {
			//require_once $vendorsDir.'/swedbank-pay-sdk-php/vendor/autoload.php';
		//}

        spl_autoload_register(function ($className) {
            $autoLoadPath = dirname(__FILE__) . '/../vendors/swedbank-pay-sdk-php/src/';
            $classFile = $autoLoadPath . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
            if (file_exists($classFile)) {
                require_once $classFile;
                return true;
            }

            return false;
        });

        spl_autoload_register(function ($className) {
            $autoLoadPath = dirname(__FILE__) . '/../vendors/swedbank-pay-core-library/src/';
            $classFile = $autoLoadPath . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
            if (file_exists($classFile)) {
                require_once $classFile;
                return true;
            }

            return false;
        });

        if ( ! class_exists( '\\Ramsey\\Uuid\\Uuid', false ) ) {
			require_once $vendorsDir . '/ramsey-uuid/vendor/autoload.php';
		}

		if ( ! class_exists( 'FullNameParser', false ) ) {
			require_once $vendorsDir . '/php-name-parser/vendor/autoload.php';
		}

		require_once( dirname( __FILE__ ) . '/class-wc-swedbank-pay-transactions.php' );
		require_once( dirname( __FILE__ ) . '/class-wc-swedbank-pay-queue.php' );
		require_once( dirname( __FILE__ ) . '/class-wc-swedbank-pay-icon.php' );
	}

	/**
	 * Install
	 */
	public function install() {
		// Install Schema
		WC_Swedbank_Pay_Transactions::instance()->install_schema();

		// Set Version
		if ( ! get_option( 'woocommerce_payex_psp_version' ) ) {
			add_option( 'woocommerce_payex_psp_version', '1.1.0' );
		}
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		// Functions
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init() {
		include_once( dirname( __FILE__ ) . '/class-wc-background-swedbank-pay-queue.php' );
		self::$background_process = new WC_Background_Swedbank_Pay_Queue();
	}

	/**
	 * WooCommerce Loaded: load classes
	 */
	public function woocommerce_hook_loaded() {
		// Includes
		include_once( dirname( __FILE__ ) . '/class-wc-payment-token-swedbank-pay.php' );
	}

	/**
	 * Register payment gateway
	 *
	 * @param string $class_name
	 */
	public static function register_gateway( $class_name ) {
		global $px_gateways;

		if ( ! $px_gateways ) {
			$px_gateways = [];
		}

		if ( ! isset( $px_gateways[ $class_name ] ) ) {
			// Initialize instance
			if ( $gateway = new $class_name ) {
				$px_gateways[] = $class_name;

				// Register gateway instance
				add_filter( 'woocommerce_payment_gateways', function ( $methods ) use ( $gateway ) {
					$methods[] = $gateway;

					return $methods;
				} );
			}
		}
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param array $statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function add_valid_order_statuses( $statuses, $order ) {
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, self::PAYMENT_METHODS ) ) {
			$statuses = array_merge( $statuses, [
				'processing',
				'completed'
			] );
		}

		return $statuses;
	}

	/**
	 * Order Status Change: Capture/Cancel
	 *
	 * @param $order_id
	 * @param $from
	 * @param $to
	 * @param $order
	 */
	public static function order_status_changed( $order_id, $from, $to, $order ) {
		// We are need "on-hold" only
		if ( $from !== 'on-hold' ) {
			return;
		}

		$payment_method = $order->get_payment_method();
		if ( ! in_array( $payment_method, self::PAYMENT_METHODS ) ) {
			return;
		}

        // Get Payment Gateway
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

		/** @var \WC_Gateway_Swedbank_Pay_Cc $gateway */
		$gateway = $gateways[ $payment_method ];

		switch ( $to ) {
			case 'cancelled':
				// Cancel payment
                try {
                    // Disable status change hook
                    remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

                    $gateway->cancel_payment( $order );
                } catch ( Exception $e ) {
                    $message = $e->getMessage();
                    WC_Admin_Meta_Boxes::add_error( $message );

                    // Rollback
                    $order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'swedbank-pay-woocommerce-payments' ), $message ) );
                }
				break;
			case 'processing':
			case 'completed':
				// Capture payment
            try {
                // Disable status change hook
                remove_action( 'woocommerce_order_status_changed', 'WC_Payex_Psp::order_status_changed', 10 );

                // Capture
                $gateway->capture_payment( $order );
            } catch ( Exception $e ) {
                $message = $e->getMessage();
                WC_Admin_Meta_Boxes::add_error( $message );

                // Rollback
                $order->update_status( $from, sprintf( __( 'Order status rollback. %s', 'swedbank-pay-woocommerce-payments' ), $message ) );
            }
				break;
			default:
				// no break
		}
	}

	/**
	 * Add meta boxes in admin
	 * @return void
	 */
	public static function add_meta_boxes() {
		global $post_id;
		if ( $order = wc_get_order( $post_id ) ) {
			$payment_method = $order->get_payment_method();
			if ( in_array( $payment_method, self::PAYMENT_METHODS ) ) {
				$payment_id = get_post_meta( $post_id, '_payex_payment_id', true );
				if ( ! empty( $payment_id ) ) {
					add_meta_box(
						'swedbank_payment_actions',
						__( 'Swedbank Pay Payments Actions', 'swedbank-pay-woocommerce-payments' ),
						__CLASS__ . '::order_meta_box_payment_actions',
						'shop_order',
						'side',
						'default'
					);
				}
			}
		}
	}

	/**
	 * MetaBox for Payment Actions
	 * @return void
	 */
	public static function order_meta_box_payment_actions() {
		global $post_id;
		$order      = wc_get_order( $post_id );
		$payment_id = get_post_meta( $post_id, '_payex_payment_id', true );
		if ( empty( $payment_id ) ) {
			return;
		}

        // Get Payment Gateway
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if ( ! isset( $gateways[ $order->get_payment_method() ] ) ) {
            return;
        }

        /** @var \WC_Gateway_Swedbank_Pay_Cc $gateway */
        $gateway = $gateways[ $order->get_payment_method() ];

		// Fetch payment info
		try {
            $result = $gateway->core->fetchPaymentInfo( $payment_id );
		} catch ( \Exception $e ) {
			// Request failed
			return;
		}

		wc_get_template(
			'admin/payment-actions.php',
			[
                'gateway'    => $gateway,
				'order'      => $order,
				'order_id'   => $post_id,
				'payment_id' => $payment_id,
				'info'       => $result
			],
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Add action buttons to Order view
	 * @param WC_Order $order
	 */
	public static function add_action_buttons( $order ) {
		// Get Payment Gateway
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, self::PAYMENT_METHODS ) ) {
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();

			if ( isset( $gateways[ $payment_method ] ) ) {
				/** @var \WC_Gateway_Swedbank_Pay_Cc $gateway */
				$gateway = $gateways[ $payment_method ];

				wc_get_template(
					'admin/action-buttons.php',
					[
						'gateway'    => $gateway,
						'order'      => $order
					],
					'',
					dirname( __FILE__ ) . '/../templates/'
				);
			}
		}
	}

	/**
	 * Enqueue Scripts in admin
	 *
	 * @param $hook
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook ) {
		if ( $hook === 'post.php' ) {
			// Scripts
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script( 'swedbank-pay-admin-js', plugin_dir_url( __FILE__ ) . '../assets/js/admin' . $suffix . '.js' );

			// Localize the script
			$translation_array = [
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'text_wait' => __( 'Please wait...', 'swedbank-pay-woocommerce-payments' ),
			];
			wp_localize_script( 'swedbank-pay-admin-js', 'SwedbankPay_Admin', $translation_array );

			// Enqueued script with localized data
			wp_enqueue_script( 'swedbank-pay-admin-js' );
		}
	}

	/**
	 * Action for Capture
	 */
	public function ajax_swedbank_pay_capture() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		$order_id = (int) $_REQUEST['order_id'];
		$order = wc_get_order( $order_id );

        // Get Payment Gateway
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if ( isset( $gateways[ $order->get_payment_method() ] ) ) {
            $gateway = $gateways[ $order->get_payment_method() ];

            try {
                $gateway->capture_payment( $order_id );
                wp_send_json_success( __( 'Capture success.', 'swedbank-pay-woocommerce-payments' ) );
            } catch ( Exception $e ) {
                $message = $e->getMessage();
                wp_send_json_error( $message );
            }
        }
	}

	/**
	 * Action for Cancel
	 */
	public function ajax_swedbank_pay_cancel() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		$order_id = (int) $_REQUEST['order_id'];
        $order = wc_get_order( $order_id );

        // Get Payment Gateway
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if ( isset( $gateways[ $order->get_payment_method() ] ) ) {
            $gateway = $gateways[$order->get_payment_method()];

            try {
                $gateway->cancel_payment( $order_id );
                wp_send_json_success( __( 'Cancel success.', 'swedbank-pay-woocommerce-payments' ) );
            } catch ( Exception $e ) {
                $message = $e->getMessage();
                wp_send_json_error( $message );
            }
        }
	}

	/**
	 * Generate UUID
	 *
	 * @param $node
	 *
	 * @return string
	 */
	public function generate_uuid( $node ) {
		return \Ramsey\Uuid\Uuid::uuid5( \Ramsey\Uuid\Uuid::NAMESPACE_OID, $node )->toString();
	}

	/**
	 * Dispatch Background Process
	 */
	public function maybe_process_queue() {
		self::$background_process->dispatch();
	}

	/**
	 * Provide Admin Menu items
	 */
	public function admin_menu() {
		// Add Upgrade Page
		global $_registered_pages;

		$hookname = get_plugin_page_hookname( 'wc-payex-psp-upgrade', '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::upgrade_page' );
		}

		$_registered_pages[ $hookname ] = true;
	}

	/**
	 * Upgrade Page
	 */
	public static function upgrade_page() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Run Database Update
		include_once( dirname( __FILE__ ) . '/class-wc-swedbank-pay-update.php' );
		\WC_Swedbank_Pay_Update::update();

		echo esc_html__( 'Upgrade finished.', 'swedbank-pay-woocommerce-payments' );
	}

	/**
	 * Upgrade Notice
	 */
	public static function upgrade_notice() {
		if ( current_user_can( 'update_plugins' ) ) {
			?>
			<div id="message" class="error">
				<p>
					<?php
					echo esc_html__( 'Warning! Swedbank Pay WooCommerce payments plugin requires to update the database structure.', 'swedbank-pay-woocommerce-payments' );
					echo ' ' . sprintf( esc_html__( 'Please click %s here %s to start upgrade.', 'swedbank-pay-woocommerce-payments' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-payex-psp-upgrade' ) ) . '">', '</a>' );
					?>
				</p>
			</div>
			<?php
		}
	}
}