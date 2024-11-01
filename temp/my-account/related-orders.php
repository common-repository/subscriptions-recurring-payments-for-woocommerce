<?php
/**
 * Related Orders table on the View Subscription page
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<header>
	<h2><?php esc_html_e( 'Related orders', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>
</header>

<table class="shop_table shop_table_responsive my_account_orders woocommerce-orders-table woocommerce-MyAccount-orders woocommerce-orders-table--orders">

	<thead>
		<tr>
			<th class="order-number woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php esc_html_e( 'Order', 'subscriptions-recurring-payments-for-woocommerce' ); ?></span></th>
			<th class="order-date woocommerce-orders-table__header woocommerce-orders-table__header-order-date woocommerce-orders-table__header-order-date"><span class="nobr"><?php esc_html_e( 'Date', 'subscriptions-recurring-payments-for-woocommerce' ); ?></span></th>
			<th class="order-status woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php esc_html_e( 'Status', 'subscriptions-recurring-payments-for-woocommerce' ); ?></span></th>
			<th class="order-total woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></span></th>
			<th class="order-actions woocommerce-orders-table__header woocommerce-orders-table__header-order-actions">&nbsp;</th>
		</tr>
	</thead>

	<tbody>
		<?php foreach ( $subscription_orders as $subscription_order ) :
			$order = wc_get_order( $subscription_order );

			if ( ! $order ) {
				continue;
			}

			$item_count = $order->get_item_count();
			$order_date = $order->get_date_created();

			?><tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?>">
				<td class="order-number woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Order Number', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
					<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
						<?php echo sprintf( esc_html_x( '#%s', 'hash before order number', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_order_number() ) ); ?>
					</a>
				</td>
				<td class="order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( 'Date', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
					<time datetime="<?php echo esc_attr( $order_date->date( 'Y-m-d' ) ); ?>" title="<?php echo esc_attr( $order_date->getTimestamp() ); ?>"><?php echo wp_kses_post( $order_date->date_i18n( wc_date_format() ) ); ?></time>
				</td>
				<td class="order-status woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'subscriptions-recurring-payments-for-woocommerce' ); ?>" style="white-space:nowrap;">
					<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
				</td>
				<td class="order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
					<?php
					// translators: $1: formatted order total for the order, $2: number of items bought
					echo wp_kses_post( sprintf( _n( '%1$s for %2$d item', '%1$s for %2$d items', $item_count, 'subscriptions-recurring-payments-for-woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
					?>
				</td>
				<td class="order-actions woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions">
					<?php $actions = array();

					if ( $order->needs_payment() && awc_get_objects_property( $order, 'id' ) == $subscription->get_last_order( 'ids', 'any' ) ) {
						$actions['pay'] = array(
							'url'  => $order->get_checkout_payment_url(),
							'name' => esc_html_x( 'Pay', 'pay for a subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
						);
					}

					if ( in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ) ) ) {
						$redirect = wc_get_page_permalink( 'myaccount' );

						if ( awc_is_view_subscription_page() ) {
							$redirect = $subscription->get_view_order_url();
						}

						$actions['cancel'] = array(
							'url'  => $order->get_cancel_order_url( $redirect ),
							'name' => esc_html_x( 'Cancel', 'an action on a subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
						);
					}

					$actions['view'] = array(
						'url'  => $order->get_view_order_url(),
						'name' => esc_html_x( 'View', 'view a subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
					);

					$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );

					if ( $actions ) {
						foreach ( $actions as $key => $action ) {
							echo wp_kses_post( '<a href="' . esc_url( $action['url'] ) . '" class="woocommerce-button button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a>' );
						}
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php do_action( 'woocommerce_subscription_details_after_subscription_related_orders_table', $subscription ); ?>
