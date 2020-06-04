<?php
/** @var WC_Gateway_Swedbank_Pay_Cc $gateway */
/** @var WC_Order $order */
/** @var int $order_id */
/** @var string $payment_id */
/** @var array $info */

defined( 'ABSPATH' ) || exit;

?>
<div>
	<strong><?php _e( 'Payment Info', 'swedbank-pay-woocommerce-payments' ); ?></strong>
	<br/>
	<strong><?php _e( 'Number', 'swedbank-pay-woocommerce-payments' ); ?>
		:</strong> <?php echo esc_html( $info['payment']['number'] ); ?>
	<br/>
	<strong><?php _e( 'Instrument', 'swedbank-pay-woocommerce-payments' ); ?>
		: </strong> <?php echo esc_html( $info['payment']['instrument'] ); ?>
	<br/>
	<strong><?php _e( 'Operation', 'swedbank-pay-woocommerce-payments' ) ?>
		: </strong> <?php echo esc_html( $info['payment']['operation'] ); ?>
	<br/>
	<?php if ( isset($info['payment']['intent']) ): ?>
		<strong><?php _e( 'Intent', 'swedbank-pay-woocommerce-payments' ); ?>
			: </strong> <?php echo esc_html( $info['payment']['intent'] ); ?>
		<br/>
	<?php endif; ?>
	<strong><?php _e( 'State', 'swedbank-pay-woocommerce-payments' ); ?>
		: </strong> <?php echo esc_html( $info['payment']['state'] ); ?>
	<br/>
	<?php if ( $gateway->core->canCapture( $order->get_id() ) ) : ?>
		<button id="swedbank_pay_capture"
				data-nonce="<?php echo wp_create_nonce( 'swedbank_pay' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Capture Payment', 'swedbank-pay-woocommerce-payments' ); ?>
		</button>
	<?php endif; ?>

	<?php if ( $gateway->core->canCancel( $order->get_id() ) ) : ?>
		<button id="swedbank_pay_cancel"
				data-nonce="<?php echo wp_create_nonce( 'swedbank_pay' ); ?>"
				data-payment-id="<?php echo esc_html( $payment_id ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Cancel Payment', 'swedbank-pay-woocommerce-payments' ); ?>
		</button>
	<?php endif; ?>
</div>
