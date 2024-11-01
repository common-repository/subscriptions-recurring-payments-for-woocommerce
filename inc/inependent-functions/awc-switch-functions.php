<?php
/**
 * WooCommerce Subscriptions Switch Functions
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Check if a given order was to switch a subscription
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 
 */
function awc_order_contains_switch( $order ) {

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! awc_is_order( $order ) || awc_order_contains_renewal( $order ) ) {

		$is_switch_order = false;

	} else {

		$switched_subscriptions = awc_get_subscriptions_for_switch_order( $order );

		if ( ! empty( $switched_subscriptions ) ) {
			$is_switch_order = true;
		} else {
			$is_switch_order = false;
		}
	}

	return apply_filters( 'woocommerce_subscriptions_is_switch_order', $is_switch_order, $order );
}

/**
 * Get the subscriptions that had an item switch for a given order (if any).
 *
 * @param int|WC_Order $order_id The post_id of a shop_order post or an instance of a WC_Order object
 * @return array Subscription details in post_id => WC_Subscription form.
 
 */
function awc_get_subscriptions_for_switch_order( $order ) {
	return awc_get_subscriptions_for_order( $order, array( 'order_type' => 'switch' ) );
}

/**
 * Get all the orders which have recorded a switch for a given subscription.
 *
 * @param int|WC_Subscription $subscription_id The post_id of a shop_subscription post or an instance of a WC_Subscription object
 * @return array Order details in post_id => WC_Order form.
 
 */
function awc_get_switch_orders_for_subscription( $subscription_id ) {
	$subscription = awc_get_subscription( $subscription_id );
	return $subscription->get_related_orders( 'all', 'switch' );
}

/**
 * Checks if a given product is of a switchable type
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product to check
 * @return bool
 
 */
function awc_is_product_switchable_type( $product ) {

	if ( ! is_object( $product ) ) {
		$product = wc_get_product( $product );
	}

	$variation = null;

	if ( empty( $product ) ) {
		$is_product_switchable = false;
	} else {

		// back compat for parent products
		if ( $product->is_type( 'subscription_variation' ) && $product->get_parent_id() ) {
			$variation = $product;
			$product   = wc_get_product( $product->get_parent_id() );
		}

		if(AWC_Settings::get_option( 'switch_subscription_variation' ) && !AWC_Settings::get_option( 'switch_subscription_group' )){
			$is_product_switchable = $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) && 'publish' === awc_get_objects_property( $product, 'post_status' );	
		}elseif(AWC_Settings::get_option( 'switch_subscription_group' ) && !AWC_Settings::get_option( 'switch_subscription_variation' )){
			$is_product_switchable = (bool) AWC_Subscription_Products::get_visible_grouped_parent_product_ids( $product );
		}elseif(AWC_Settings::get_option( 'switch_subscription_group' ) && AWC_Settings::get_option( 'switch_subscription_variation' )){
			$is_product_switchable = ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) && 'publish' === awc_get_objects_property( $product, 'post_status' ) ) || AWC_Subscription_Products::get_visible_grouped_parent_product_ids( $product );
		}else{
			$is_product_switchable = false;
		}
	}

	return apply_filters( 'awc_is_product_switchable', $is_product_switchable, $product, $variation );
}
