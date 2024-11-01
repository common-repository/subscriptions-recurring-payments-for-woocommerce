<?php
/**
 * Subscription details table
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<table class="shop_table subscription_details">
	<tbody>
		<tr>
			<td><?php esc_html_e( 'Status', 'subscriptions-recurring-payments-for-woocommerce' ); ?></td>
			<td><?php echo esc_html( awc_get_subscription_status_name( $subscription->get_status() ) ); ?></td>
		</tr>
		<?php do_action( 'awc_subscription_details_table_before_dates', $subscription ); ?>
		<?php
		$dates_to_display = apply_filters( 'awc_subscription_details_table_dates_to_display', array(
			'start_date'              => _x( 'Start date', 'customer subscription table header', 'subscriptions-recurring-payments-for-woocommerce' ),
			'last_order_date_created' => _x( 'Last order date', 'customer subscription table header', 'subscriptions-recurring-payments-for-woocommerce' ),
			'next_payment'            => _x( 'Next payment date', 'customer subscription table header', 'subscriptions-recurring-payments-for-woocommerce' ),
			'end'                     => _x( 'End date', 'customer subscription table header', 'subscriptions-recurring-payments-for-woocommerce' ),
			'trial_end'               => _x( 'Trial end date', 'customer subscription table header', 'subscriptions-recurring-payments-for-woocommerce' ),
		), $subscription );
		foreach ( $dates_to_display as $date_type => $date_title ) : ?>
			<?php $date = $subscription->get_date( $date_type ); ?>
			<?php if ( ! empty( $date ) ) : ?>
				<tr>
					<td><?php echo esc_html( $date_title ); ?></td>
					<td><?php echo esc_html( $subscription->get_date_to_display( $date_type ) ); ?></td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php do_action( 'awc_subscription_details_table_after_dates', $subscription ); ?>

		<?php //echo 'auto renewal: ' . AWC_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) . '<br/>'; ?>
		<?php if ( AWC_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Auto renew', 'subscriptions-recurring-payments-for-woocommerce' ); ?></td>
				<td>
					<div class="asub-auto-renew-toggle">
						<?php

						$toggle_classes = array( 'subscription-auto-renew-toggle', 'subscription-auto-renew-toggle--hidden' );

						if ( $subscription->is_manual() ) {
							$toggle_label     = __( 'Enable auto renew', 'subscriptions-recurring-payments-for-woocommerce' );
							$toggle_classes[] = 'subscription-auto-renew-toggle--off';

							if ( AWC_Subscriptions::is_duplicate_site() ) {
								$toggle_classes[] = 'subscription-auto-renew-toggle--disabled';
							}
						} else {
							$toggle_label     = __( 'Disable auto renew', 'subscriptions-recurring-payments-for-woocommerce' );
							$toggle_classes[] = 'subscription-auto-renew-toggle--on';
						}?>
						<a href="#" class="<?php echo esc_attr( implode( ' ' , $toggle_classes ) ); ?>" aria-label="<?php echo esc_attr( $toggle_label ) ?>"><i class="subscription-auto-renew-toggle__i" aria-hidden="true"></i></a>
						<?php if ( AWC_Subscriptions::is_duplicate_site() ) : ?>
								<small class="subscription-auto-renew-toggle-disabled-note"><?php echo esc_html__( 'Using the auto-renewal toggle is disabled while in staging mode.', 'subscriptions-recurring-payments-for-woocommerce' ); ?></small>
						<?php endif; ?>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<?php do_action( 'awc_subscription_details_table_before_payment_method', $subscription ); ?>
		<?php if ( $subscription->get_time( 'next_payment' ) > 0 ) : ?>
			<tr>
				<td><?php esc_html_e( 'Payment', 'subscriptions-recurring-payments-for-woocommerce' ); ?></td>
				<td>
					<span data-is_manual="<?php echo esc_attr( wc_bool_to_string( $subscription->is_manual() ) ); ?>" class="subscription-payment-method"><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></span>
				</td>
			</tr>
		<?php endif; ?>
		<?php do_action( 'woocommerce_subscription_before_actions', $subscription ); ?>
		<?php $actions = awc_get_all_user_actions_for_subscription( $subscription, get_current_user_id() ); ?>

		<?php
			// echo 'actions omar <br/><pre>';
			// print_r($actions);
			// echo '</pre>';
		
		?>
		<?php if ( ! empty( $actions ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Actions', 'subscriptions-recurring-payments-for-woocommerce' ); ?></td>
				<td class="user_actions">
					<?php foreach ( $actions as $key => $action ) : ?>
						<a href="<?php echo esc_url( $action['url'] ); ?>" class="button <?php echo sanitize_html_class( $key ) ?>"><?php echo esc_html( $action['name'] ); ?></a>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endif; ?>
		<?php do_action( 'woocommerce_subscription_after_actions', $subscription ); ?>
	</tbody>
</table>

<?php if ( $notes = $subscription->get_customer_order_notes() ) : ?>
	<h2><?php esc_html_e( 'Subscription updates', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>
	<ol class="woocommerce-OrderUpdates commentlist notes">
		<?php foreach ( $notes as $note ) : ?>
		<li class="woocommerce-OrderUpdate comment note">
			<div class="woocommerce-OrderUpdate-inner comment_container">
				<div class="woocommerce-OrderUpdate-text comment-text">
					<p class="woocommerce-OrderUpdate-meta meta"><?php echo esc_html( date_i18n( _x( 'l jS \o\f F Y, h:ia', 'date on subscription updates list. Will be localized', 'subscriptions-recurring-payments-for-woocommerce' ), awc_date_to_time( $note->comment_date ) ) ); ?></p>
					<div class="woocommerce-OrderUpdate-description description">
						<?php echo wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) ); ?>
					</div>
	  				<div class="clear"></div>
	  			</div>
				<div class="clear"></div>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
<?php endif; ?>
