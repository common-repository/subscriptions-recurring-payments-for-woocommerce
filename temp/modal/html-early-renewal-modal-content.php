<?php
/**
 * The template for displaying the early renewal modal.
 *
 
 * @var WC_Subscription  $subscription          The subscription being renewed early.
 * @var WC_DateTime|null $new_next_payment_date 
 * @var array            $totals                The subscription's totals array used to display the subscription totals table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$include_item_removal_links = $include_switch_links = false;
?>
<div class="awc_early_renew_modal_totals_table">
<?php do_action( 'woocommerce_subscription_totals', $subscription, $include_item_removal_links, $totals, $include_switch_links ); ?>
</div>
<p class="awc_early_renew_modal_note">
<?php if ( ! empty( $new_next_payment_date ) ) {
	echo wp_kses_post( sprintf(
		__( 'By renewing your subscription early your next payment will be %s.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'<strong>' . esc_html( date_i18n( wc_date_format(), $new_next_payment_date->getOffsetTimestamp() ) ) . '</strong>'
	) );
} else {
	echo wp_kses_post( sprintf(
		__( 'By renewing your subscription early, your scheduled next payment on %s will be cancelled.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'<strong>' . esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'next_payment', 'site' ) ) ) . '</strong>'
	) );
}?>
</br>
<?php echo wp_kses_post( sprintf(
	__( 'Want to renew early via the checkout? Click %shere.%s', 'subscriptions-recurring-payments-for-woocommerce' ),
	'<a href="' . esc_url( awc_get_early_renewal_url( $subscription ) ) . '">',
	'</a>'
) ) ?>
</p>
