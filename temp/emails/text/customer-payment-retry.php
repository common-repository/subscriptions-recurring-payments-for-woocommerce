<?php
/**
 * Customer payment retry email (plain text)
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

/* translators: %s: Customer first name */
echo sprintf( esc_html__( 'Hi %s,', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: lowercase human time diff in the form returned by awc_get_human_time_diff(), e.g. 'in 12 hours' */
echo sprintf( esc_html_x( 'The automatic payment to renew your subscription has failed. We will retry the payment %s.', 'In customer renewal invoice email', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( awc_get_human_time_diff( $retry->get_time() ) ) ) . "\n\n";

// translators: %1$s: link to checkout payment url, note: no full stop due to url at the end
echo sprintf( esc_html_x( 'To reactivate the subscription now, you can also log in and pay for the renewal from your account page: %1$s', 'In customer renewal invoice email', 'subscriptions-recurring-payments-for-woocommerce' ), esc_attr( $order->get_checkout_payment_url() ) );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
