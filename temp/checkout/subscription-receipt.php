<?php
/**
 * Change Subscription's Payment method Page
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<ul class="order_details">
	<li class="order">
		<?php
		echo wp_kses( sprintf( esc_html__( 'Subscription Number: %s', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>' . esc_html( $subscription->get_order_number() ) . '</strong>' ), array( 'strong' => true ) );
		?>
	</li>
	<li class="date">
		<?php
		echo wp_kses( sprintf( esc_html__( 'Next Payment Date: %s', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>' . esc_html( $subscription->get_date_to_display( 'next_payment' ) ) . '</strong>' ), array( 'strong' => true ) );
		?>
	</li>
	<li class="total">
		<?php
		echo wp_kses_post( sprintf( esc_html__( 'Total: %s', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>' . $subscription->get_formatted_order_total() . '</strong>' ) );
		?>
	</li>
	<?php if ( $subscription->get_payment_method_title() ) : ?>
		<li class="method">
			<?php
			echo wp_kses( sprintf( esc_html__( 'Payment Method: %s', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>' . esc_html( $subscription->get_payment_method_to_display() ) . '</strong>' ), array( 'strong' => true ) );
			?>
		</li>
	<?php endif; ?>
</ul>

<?php do_action( 'woocommerce_receipt_' . $subscription->get_payment_method(), $subscription->get_id() ); ?>

<div class="clear"></div>
