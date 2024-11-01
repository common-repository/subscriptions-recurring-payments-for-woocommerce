<?php
/**
 * Customer payment retry email
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>


<p><?php printf( esc_html__( 'Hi %s,', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php printf( esc_html_x( 'The automatic payment to renew your subscription has failed. We will retry the payment %s.', 'In customer renewal invoice email', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( awc_get_human_time_diff( $retry->get_time() ) ) ); ?></p>


<p><?php echo wp_kses( sprintf( _x( 'To reactivate the subscription now, you can also log in and pay for the renewal from your account page: %1$sPay Now &raquo;%2$s', 'In customer renewal invoice email', 'subscriptions-recurring-payments-for-woocommerce' ), '<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">', '</a>' ), array( 'a' => array( 'href' => true ) ) );?></p>

<?php
do_action( 'awc_email_subscription_orders_detail', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
