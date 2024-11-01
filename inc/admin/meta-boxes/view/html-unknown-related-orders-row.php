<?php
/**
 * Display a row in the related orders table for a unknown subscription or order.
 *
 * @var int $order_id A WC_Order or WC_Subscription order id.
 * @var string $relationship The order's or subscription's relationship.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<tr>
	<td>
		<?php
		// translators: placeholder is an order ID.
		echo sprintf( esc_html_x( '#%s', 'hash before order number', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order_id ) );
		?>
		<div class="asub-unknown-order-info-wrapper">
			<?php echo awc_help_tip( __( "This couldn't be loaded from the database.", 'aco-woo-subscription' ) ); ?></a>
		</div>
	</td>
	<td><?php echo esc_html( $relationship ); ?></td>
	<td>&mdash;</td>
	<td>&mdash;</td>
	<td>&mdash;</td>
</tr>
