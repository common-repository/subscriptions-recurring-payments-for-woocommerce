<?php
/**
 * Cancelled Subscription email (plain text)
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

// translators: $1: customer's billing first name and last name
printf( __( 'A subscription belonging to %1$s has expired. Their subscription\'s details are as follows:', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription->get_formatted_billing_full_name() );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

/**
 * @hooked AWC_Subscriptions_Email::order_details() Shows the order details table.
 
 */
do_action( 'awc_email_subscription_orders_detail', $subscription, $sent_to_admin, $plain_text, $email );

echo "\n----------\n\n";

$last_order_time_created = $subscription->get_time( 'last_order_date_created', 'site' );

if ( ! empty( $last_order_time_created ) ) {
	// translators: placeholder is last time subscription was paid
	echo sprintf( __( 'Last Order Date: %s', 'subscriptions-recurring-payments-for-woocommerce' ), date_i18n( wc_date_format(), $last_order_time_created ) ) . "\n";
}

$end_time = $subscription->get_time( 'end', 'site' );

if ( ! empty( $end_time ) ) {
	// translators: placeholder is localised date string
	echo sprintf( __( 'End Date: %s', 'subscriptions-recurring-payments-for-woocommerce' ), date_i18n( wc_date_format(), $end_time ) ) . "\n";
}

do_action( 'woocommerce_email_order_meta', $subscription, $sent_to_admin, $plain_text, $email );

echo "\n" . sprintf( _x( 'View Subscription: %s', 'in plain emails for subscription information', 'subscriptions-recurring-payments-for-woocommerce' ), awc_get_edit_post_link( $subscription->get_id() ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action( 'woocommerce_email_customer_details', $subscription, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
