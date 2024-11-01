<?php
/**
 * Customer completed subscription change email (plain text)
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

/* translators: %s: Customer first name */
echo sprintf( esc_html__( 'Hi %s,', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
esc_html_e( 'You have successfully changed your subscription items. Your new order and subscription details are shown below for your reference:', 'subscriptions-recurring-payments-for-woocommerce' );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

// translators: placeholder is order's view url
echo "\n" . sprintf( __( 'View your order: %s', 'subscriptions-recurring-payments-for-woocommerce' ), $order->get_view_order_url() );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

foreach ( $subscriptions as $subscription ) {

	do_action( 'awc_email_subscription_orders_detail', $subscription, $sent_to_admin, $plain_text, $email );

	// translators: placeholder is subscription's view url
	echo "\n" . sprintf( __( 'View your subscription: %s', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription->get_view_order_url() );
}
echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
