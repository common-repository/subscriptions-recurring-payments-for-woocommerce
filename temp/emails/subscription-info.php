<?php
/**
 * Subscription information template
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( empty( $subscriptions ) ) {
	return;
}

$has_automatic_renewal = false;
$is_parent_order       = awc_order_contains_subscription( $order, 'parent' );
?>
<div style="margin-bottom: 40px;">
<h2><?php esc_html_e( 'Subscription information', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>
<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 0.5em;" border="1">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'ID', 'subscription ID table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Start date', 'table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'End date', 'table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Recurring total', 'table heading', 'subscriptions-recurring-payments-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $subscriptions as $subscription ) : ?>
		<?php $has_automatic_renewal = $has_automatic_renewal || ! $subscription->is_manual(); ?>
		<tr>
			<td class="td" scope="row" style="text-align:left;"><a href="<?php echo esc_url( ( $is_admin_email ) ? awc_get_edit_post_link( $subscription->get_id() ) : $subscription->get_view_order_url() ); ?>"><?php echo sprintf( esc_html_x( '#%s', 'subscription number in email table. (eg: #106)', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( $subscription->get_order_number() ) ); ?></a></td>
			<td class="td" scope="row" style="text-align:left;"><?php echo esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'start_date', 'site' ) ) ); ?></td>
			<td class="td" scope="row" style="text-align:left;"><?php echo esc_html( ( 0 < $subscription->get_time( 'end' ) ) ? date_i18n( wc_date_format(), $subscription->get_time( 'end', 'site' ) ) : _x( 'When cancelled', 'Used as end date for an indefinite subscription', 'subscriptions-recurring-payments-for-woocommerce' ) ); ?></td>
			<td class="td" scope="row" style="text-align:left;">
				<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
				<?php if ( $is_parent_order && $subscription->get_time( 'next_payment' ) > 0 ) : ?>
					<br>
					<small><?php printf( esc_html__( 'Next payment: %s', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'next_payment', 'site' ) ) ) ); ?></small>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
</tbody>
</table>
<?php if ( $has_automatic_renewal && ! $is_admin_email && $subscription->get_time( 'next_payment' ) > 0 ) {
	if ( count( $subscriptions ) === 1 ) {
		$subscription   = reset( $subscriptions );
		$my_account_url = $subscription->get_view_order_url();
	} else {
		$my_account_url = wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) );
	}

	printf( '<small>%s</small>', wp_kses_post( sprintf( _n(
		// Translators: Placeholders are opening and closing My Account link tags.
		'This subscription is set to renew automatically using your payment method on file. You can manage or cancel this subscription from your %smy account page%s.',
		'These subscriptions are set to renew automatically using your payment method on file. You can manage or cancel your subscriptions from your %smy account page%s.',
		count( $subscriptions ),
		'subscriptions-recurring-payments-for-woocommerce'
	), '<a href="' . $my_account_url . '">', '</a>' ) ) );
}?>
</div>

