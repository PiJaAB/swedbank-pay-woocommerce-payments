<?php

defined( 'ABSPATH' ) || exit;

class WC_Payment_Token_Swedbank_Pay extends WC_Payment_Token_Swedbank_Pay_Base {
	/**
	 * Token Type String.
	 *
	 * @var string
	 */
	protected $type = 'Swedbank_Pay';

	/**
	 * Stores Credit Card payment token data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'last4'            => '',
		'expiry_year'      => '',
		'expiry_month'     => '',
		'card_type'        => '',
		'masked_pan'       => '',
		'recurrence_token' => '',
	);

	/**
	 * Returns Recurrence token
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string Masked Pan
	 */
	public function get_recurrence_token( $context = 'view' ) {
		return $this->get_prop( 'recurrence_token', $context );
	}

	/**
	 * Set Recurrence token
	 *
	 * @param string $masked_pan Recurrence token
	 */
	public function set_recurrence_token( $masked_pan ) {
		$this->set_prop( 'recurrence_token', $masked_pan );
	}
}
