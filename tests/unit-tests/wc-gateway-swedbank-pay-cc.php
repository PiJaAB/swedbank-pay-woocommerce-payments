<?php

class WC_Unit_Gateway_Swedbank_Pay_CC extends WC_Unit_Test_Case {
	/**
	 * @var WC_Gateway_Swedbank_Pay_Cc
	 */
	private $gateway;

	/**
	 * @var WooCommerce
	 */
	private $wc;

	/**
	 * Setup test case.
	 */
	public function setUp() {
		parent::setUp();

		$this->wc = WC();

		// Init SwedbankPay Payments plugin
		$this->gateway              = new WC_Gateway_Swedbank_Pay_Cc();
		$this->gateway->enabled     = 'yes';
		$this->gateway->testmode    = 'yes';
		$this->gateway->description = 'Test';

		// Add SwedbankPay to PM List
		tests_add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
	}

	/**
	 * Register Payment Gateway.
	 *
	 * @param $gateways
	 *
	 * @return mixed
	 */
	public function payment_gateways( $gateways ) {
		$payment_gateways[ $this->gateway->id ] = $this->gateway;

		return $gateways;
	}

	public function test_payment_gateway() {
		/** @var WC_Payment_Gateways $gateways */
		$gateways = $this->wc->payment_gateways();
		$this->assertInstanceOf( WC_Payment_Gateways::class, new $gateways );

		$gateways = $gateways->payment_gateways();
		//$this->assertIsArray( $gateways );
		$this->assertTrue( is_array( $gateways ) );
		$this->assertArrayHasKey( 'payex_psp_cc', $gateways );
	}

	public function test_order() {
		$order = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );
		$order->save();

		$this->assertEquals( $this->gateway->id, $order->get_payment_method() );
	}

	public function test_process_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );
		$order->save();

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertFalse( $result );
	}

	public function test_add_payment_method() {
		$_SERVER['HTTP_USER_AGENT'] = '';
		$result                     = $this->gateway->add_payment_method();

		$this->assertEquals( 'failure', $result['result'] );
	}

	public function test_payment_confirm() {
		/** @var WC_Order $order */
		$order = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->set_payment_method( $this->gateway );
		$order->set_currency( 'SEK' );
		$order->update_meta_data( '_payex_payment_id', '/invalid/payment/id' );
		$order->save();

		$_GET['key'] = $order->get_order_key();
		$result      = $this->gateway->payment_confirm();

		$this->assertNull( $result );
	}

	/**
	 * @expectedException Exception
	 */
	public function test_capture_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->save();
		$this->gateway->capture_payment( $order );
	}

	/**
	 * @expectedException Exception
	 */
	public function test_cancel_payment() {
		$order = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->save();
		$this->gateway->cancel_payment( $order );
	}

	public function test_process_refund() {
		$order  = WC_Helper_Order::create_order();
		$order->set_billing_phone('+1-555-32123');
		$order->save();
		$result = $this->gateway->process_refund( $order->get_id(), $order->get_total(), 'Test' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
