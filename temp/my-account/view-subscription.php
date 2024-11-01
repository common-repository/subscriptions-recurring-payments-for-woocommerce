<?php
/**
 * View Subscription
 *
 * Shows the details of a particular subscription on the account page
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

wc_print_notices();

/**
 * Gets subscription details table template
 * @param AWC_Subscription $subscription A subscription object
 
 */
do_action( 'woocommerce_subscription_details_table', $subscription );

/**
 * Gets subscription totals table template
 * @param AWC_Subscription $subscription A subscription object
 
 */
do_action( 'woocommerce_subscription_totals_table', $subscription );

do_action( 'woocommerce_subscription_details_after_subscription_table', $subscription );

if(AWC_Settings::get_option( 'load_customer_details' ))
	wc_get_template( 'order/order-details-customer.php', array( 'order' => $subscription ) );
?>

<div class="clear"></div>
