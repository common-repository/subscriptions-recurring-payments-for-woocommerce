<?php
/**
 * Admin new switch order email (plain text)
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

$count = count( $subscriptions );

// translators: $1: customer's first name and last name, $2: how many subscriptions customer switched
printf( _nx( 'Customer %1$s has switched their subscription. The details of their new subscription are as follows:', 'Customer %1$s has switched %2$d of their subscriptions. The details of their new subscriptions are as follows:', $count, 'Used in switch notification admin email', 'subscriptions-recurring-payments-for-woocommerce' ), $order->get_formatted_billing_full_name(), $count );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * @hooked AWC_Subscriptions_Email::order_details() Shows the order details table.
 
 */
do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

remove_filter( 'woocommerce_order_item_meta_end', 'AWC_Subscription_Switchers::print_switch_link', 10 );

foreach ( $subscriptions as $subscription ) {
	do_action( 'awc_email_subscription_orders_detail', $subscription, $sent_to_admin, $plain_text, $email );
}

add_filter( 'woocommerce_order_item_meta_end', 'AWC_Subscription_Switchers::print_switch_link', 10, 3 );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
