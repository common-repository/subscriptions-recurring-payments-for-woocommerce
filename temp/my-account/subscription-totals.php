<?php
/**
 * Subscription totals table
 *
 * 
 * @package WooCommerce_Subscription/Templates

 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<?php
$include_switch_links       = true;
$include_item_removal_links = awc_can_items_be_removed( $subscription );
$totals                     = $subscription->get_order_item_totals();

// Don't display the payment method as it is included in the main subscription details table.
unset( $totals['payment_method'] );
?>
<h2><?php esc_html_e( 'Subscription totals', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>

<?php do_action( 'woocommerce_subscription_totals', $subscription, $include_item_removal_links, $totals, $include_switch_links ); ?>
