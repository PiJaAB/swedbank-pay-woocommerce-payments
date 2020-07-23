<?php // phpcs:disable
/*
 * Plugin Name: Swedbank Pay Payments
 * Plugin URI: https://www.swedbankpay.com/
 * Description: (Preview). Provides a Credit Card Payment Gateway through Swedbank Pay for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 2.0.0-beta.1
 * Text Domain: swedbank-pay-woocommerce-payments
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 4.1.1
 */

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin;

defined( 'ABSPATH' ) || exit;

include_once( dirname( __FILE__ ) . '/includes/class-wc-swedbank-plugin.php' );

class WC_Swedbank_Pay extends WC_Swedbank_Plugin {
	const TEXT_DOMAIN = 'swedbank-pay-woocommerce-payments';
	// phpcs:enable
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Activation
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_filter( 'wc_order_statuses', array( $this, 'wc_manual_processing_order_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'order_status_needs_payment' ));
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_loaded', array( $this, 'woocommerce_loaded' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		add_action( 'admin_notices', array( $this,	'alert_manual_orders' ) );
	}

	/**
	 * Install
	 */
	public function install() {
		// Check dependencies
		if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
			die( 'This plugin can\'t be activated. Please run `composer install` to install dependencies.' );
		}

		parent::install();
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payex_psp_cc' ) . '">' . __(
				'Settings',
				'swedbank-pay-woocommerce-payments'
			) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files and register post status for manual attention
	 */
	public function init() {
		// Localization
		load_plugin_textdomain(
			'swedbank-pay-woocommerce-payments',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
		register_post_status( 'wc-manual-processing', array(
			'label'                     => __('Manual Processing', 'swedbank-pay-woocommerce-payments'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: 1: Total amount of orders */
			'label_count'               => _n_noop( 'Manual Processing <span class="count">(%1$s)</span>', 'Manual Processing <span class="count">(%1$s)</span>', 'swedbank-pay-woocommerce-payments')
		));
	}

	/**
	 * WooCommerce Loaded: load classes
	 */
	public function woocommerce_loaded() {
		// Includes
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payment-token-swedbank-pay-base.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payment-token-swedbank-pay.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payment-token-swedbank-pay-legacy.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-patched-adapter.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-cc.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-invoice.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-vipps.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-swedbank-pay-swish.php' );

		// Register Gateways
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Cc' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Invoice' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Vipps' );
		WC_Swedbank_Pay::register_gateway( 'WC_Gateway_Swedbank_Pay_Swish' );
	}

	/**
	 * Add Manual Processing status
	 */
	public function wc_manual_processing_order_status( $order_statuses ) {
		$index = array_search('wc-processing', array_keys($order_statuses));
		if ($index === false) {
			return array('wc-manual-processing' => __( 'Manual Processing', 'swedbank-pay-woocommerce-payments' )) + $order_statuses;
		}
		return array_slice($order_statuses, 0, $index, true) +
    array('wc-manual-processing' => __( 'Manual Processing', 'swedbank-pay-woocommerce-payments' )) +
    array_slice($order_statuses, $index, count($order_statuses)-$index, true);
	}

	/**
	 * Add Manual Processing status
	 */
	public function order_status_needs_payment( $order_statuses ) {
		$order_statuses[] = 'manual-processing';
		return $order_statuses;
	}

	/**
	 * Enqueue styles.
	 */
	public function admin_styles() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		// Register admin styles.
		wp_register_style( 'woocommerce_manual_processing_style', plugin_dir_url( __FILE__ ) . 'assets/css/status-manual-processing.css', array(), WC_VERSION );
		if ( in_array( $screen_id, wc_get_screen_ids() ) ) {
			wp_enqueue_style( 'woocommerce_manual_processing_style' );
		}
	}
	public function alert_manual_orders() {
		$result = wc_get_orders(array(
			'limit'=>9,
			'type'=> 'shop_order',
			'status'=> 'wc-manual-processing',
			'paginate' => true,
			)
		);
		// Cast because the type of $result->total is inconsistent.
		if ((string) $result->total === "0") return;
		?>
		<div class="error">
			<h2><?php echo __( "There are orders requiring manual processing.", 'swedbank-pay-woocommerce-payments' ); ?></h2>
			<p><?php echo __( "One or more orders are marked for manual processing. Please verify their payment status as soon as possible.", 'swedbank-pay-woocommerce-payments' ); ?></p>
			<h4><?php echo __( "List of relevant orders:", 'swedbank-pay-woocommerce-payments' ); ?></h4>
			<ul style="list-style: disc;padding-inline-start: 40px;">
				<?php
				foreach ($result->orders as $order) {
					echo '<li><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $order->ID . '</a></li>';
				}
				if ($result->max_num_pages > 1) echo '<li>...</li>';
				?>
			</ul>
			<p><a href="<?php
				echo esc_url( admin_url( 'edit.php?post_status=wc-manual-processing&post_type=shop_order' ) ); 
			?>"><?php
				/* translators: 1: Total amount of orders */
				printf(_n( 'List the <strong class="count">%1$s</strong> order.', 'List all <strong class="count">%1$s</strong> orders.', $result->total, 'swedbank-pay-woocommerce-payments' ), $result->total);
			?></a></p>
		</div>
		<?php
	}
}

new WC_Swedbank_Pay();
