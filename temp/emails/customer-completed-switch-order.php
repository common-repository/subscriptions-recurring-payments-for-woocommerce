<?php
/**
 * Customer completed subscription change email
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p><?php esc_html_e( 'You have successfully changed your subscription items. Your new order and subscription details are shown below for your reference:', 'subscriptions-recurring-payments-for-woocommerce' ); ?></p>

<?php
do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
?>

<h2><?php echo esc_html__( 'New subscription details', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>

<?php
foreach ( $subscriptions as $subscription ) {
	do_action( 'awc_email_subscription_orders_detail', $subscription, $sent_to_admin, $plain_text, $email );
}

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
