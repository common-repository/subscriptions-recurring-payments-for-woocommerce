<?php
/**
 * Display the related orders for a subscription or order
 *
 * @var object $post The primitive post object that is being displayed (as an order or subscription)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<div class="woocommerce_subscriptions_related_orders">
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Order Number', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Relationship', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Date', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Status', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
				<th><?php echo esc_html_x( 'Total', 'table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php do_action( 'woocommerce_subscriptions_related_orders_meta_box_rows', $post ); ?>
		</tbody>
	</table>
</div>
