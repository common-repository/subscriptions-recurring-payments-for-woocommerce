<?php
/**
 * Outputs a subscription variation's pricing fields for WooCommerce 2.3+
 *
 *
 * @var int $loop
 * @var WP_POST $variation
 * @var WC_Product_Subscription_Variation $variation_product
 * @var string $billing_period
 * @var array $variation_data array of variation data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="variable_subscription_trial variable_subscription_pricing_2_3 show_if_variable-subscription variable_subscription_trial_sign_up">
	<p class="form-row form-row-first form-field show_if_variable-subscription sign-up-fee-cell">
		<label for="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]"><?php printf( esc_html__( 'Sign-up fee (%s)', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( get_woocommerce_currency_symbol() ) ); ?></label>
		<input type="text" class="wc_input_price wc_input_subscription_intial_price wc_input_subscription_initial_price" name="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( AWC_Subscriptions::get_sign_up_fee( $variation_product ) ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
	</p>
	<p class="form-row form-row-last show_if_variable-subscription _fre_trail_fields">
		<label for="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]">
			<?php esc_html_e( 'Free trial', 'subscriptions-recurring-payments-for-woocommerce' ); ?>
			<?php // translators: placeholder is trial period validation message if passed an invalid value (e.g. "Trial period can not exceed 4 weeks") ?>
			<?php echo awc_help_tip( sprintf( _x( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'Trial period dropdown\'s description in pricing fields', 'subscriptions-recurring-payments-for-woocommerce' ), self::awc_get_trial_period_validation_message() ) ); ?>
		</label>
			<input type="number" class="wc_input_subscription_trial_length" name="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( AWC_Subscription_Products::awc_get_trial_length( $variation_product ) ); ?>">

			<select name="variable_subscription_trial_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_trial_period">
		<?php foreach ( awc_get_available_time_periods() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, AWC_Subscription_Products::awc_get_trial_period( $variation_product ) ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
</div>
<div class="variable_subscription_pricing variable_subscription_pricing_2_3 show_if_variable-subscription">
	<p class="form-row form-row-first form-field show_if_variable-subscription _subscription_price_field">
		<label for="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]">
			<?php
			// translators: placeholder is a currency symbol / code
			printf( esc_html__( 'Subscription price (%s)', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( get_woocommerce_currency_symbol() ) );
			?>
		</label>
		
		<input type="text" class="wc_input_price wc_input_subscription_price" name="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( AWC_Subscription_Products::awc_get_regular_price( $variation_product ) ) ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
		<select name="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period_interval">
		<?php foreach ( awc_get_subscription_period_interval_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, AWC_Subscription_Products::awc_get_interval( $variation_product ) ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
		
		<select name="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_period">
		<?php foreach ( awc_get_subscription_period_strings() as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $billing_period ); ?>><?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
		
	</p>
	<p class="form-row form-row-last show_if_variable-subscription _subscription_length_field">
		<label for="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]">
			<?php esc_html_e( 'Expire after', 'subscriptions-recurring-payments-for-woocommerce' ); ?>
			<?php echo awc_help_tip( _x( 'Automatically expire the subscription after this length of time. This length is in addition to any free trial or amount of time provided before a synchronised first renewal date.', 'Subscription Length dropdown\'s description in pricing fields', 'subscriptions-recurring-payments-for-woocommerce' ) ); ?>
		</label>
		<select name="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]" class="wc_input_subscription_length">
		<?php foreach ( awc_get_subscription_ranges( $billing_period ) as $key => $value ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, AWC_Subscription_Products::awc_get_length( $variation_product ) ); ?>> <?php echo esc_html( $value ); ?></option>
		<?php endforeach; ?>
		</select>
	</p>
	<hr class="subscription_divided" />
</div>
