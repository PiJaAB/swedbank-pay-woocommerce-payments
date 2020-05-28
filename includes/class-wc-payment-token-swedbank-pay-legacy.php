<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/* Token for Recur-only transactions */
class WC_Payment_Token_Swedbank_Pay_Legacy extends WC_Payment_Token_Swedbank_Pay_Base {
	/**
	 * Token Type String.
	 *
	 * @var string
	 */
	protected $type = 'Swedbank_Pay_Legacy';

	/**
	 * Get type to display to user.
	 *
	 * @param string $deprecated Deprecated since WooCommerce 3.0.
	 *
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) {
		return parent::get_display_name( $deprecated ) . '<br/>(Legacy)';
  }
}
