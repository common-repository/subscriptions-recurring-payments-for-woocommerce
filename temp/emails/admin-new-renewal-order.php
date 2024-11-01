<?php
/**
 * Admin new renewal order email
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: $1: customer's billing first name and last name */ ?>
<p><?php printf( esc_html_x( 'You have received a subscription renewal order from %1$s. Their order is as follows:', 'Used in admin email: new renewal order', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_formatted_billing_full_name() ) );?></p>

<?php
/**
 * @hooked AWC_Subscriptions_Email::order_details() Shows the order details table.
 
 */
do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
