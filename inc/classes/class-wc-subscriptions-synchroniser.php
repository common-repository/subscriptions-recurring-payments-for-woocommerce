<?php
/**
 * Allow for payment dates to be synchronised to a specific day of the week, month or year.
 *
 */
class WC_Subscriptions_Synchroniser {

	public static $setting_id;
	public static $setting_id_proration;
	public static $setting_id_days_no_fee;

	public static $post_meta_key       = '_subscription_payment_sync_date';
	public static $post_meta_key_day   = '_subscription_payment_sync_date_day';
	public static $post_meta_key_month = '_subscription_payment_sync_date_month';

	public static $sync_field_label;
	public static $sync_description;
	public static $sync_description_year;

	public static $billing_period_ranges;

	// strtotime() only handles English, so can't use $wp_locale->weekday in some places
	protected static $weekdays = array(
		1 => 'Monday',
		2 => 'Tuesday',
		3 => 'Wednesday',
		4 => 'Thursday',
		5 => 'Friday',
		6 => 'Saturday',
		7 => 'Sunday',
	);

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {
		self::$setting_id             = AWC_Subscriptions_Admin::$option_prefix . '_sync_payments';
		self::$setting_id_proration   = AWC_Subscriptions_Admin::$option_prefix . '_prorate_synced_payments';
		self::$setting_id_days_no_fee = AWC_Subscriptions_Admin::$option_prefix . '_days_no_fee';

		self::$sync_field_label      = __( 'Synchronise renewals', 'subscriptions-recurring-payments-for-woocommerce' );
		self::$sync_description      = __( 'Align the payment date for all customers who purchase this subscription to a specific day of the week or month.', 'subscriptions-recurring-payments-for-woocommerce' );
		// translators: placeholder is a year (e.g. "2016")
		self::$sync_description_year = sprintf( _x( 'Align the payment date for this subscription to a specific day of the year. If the date has already taken place this year, the first payment will be processed in %s. Set the day to 0 to disable payment syncing for this product.', 'used in subscription product edit screen', 'subscriptions-recurring-payments-for-woocommerce' ), gmdate( 'Y', awc_date_to_time( '+1 year' ) ) );

		

		// When enabled, add the sync selection fields to the Edit Product screen
		add_action( 'woocommerce_subscriptions_product_options_pricing', __CLASS__ . '::subscription_product_fields' );
		add_action( 'woocommerce_variable_subscription_pricing', __CLASS__ . '::variable_subscription_product_fields', 10, 3 );

		// Add the translated fields to the Subscriptions admin script
		add_filter( 'woocommerce_subscriptions_admin_script_parameters', __CLASS__ . '::admin_script_parameters', 10 );

		// Save sync options when a subscription product is saved
		add_action( 'woocommerce_process_product_meta_subscription', __CLASS__ . '::save_subscription_meta', 10 );

		// Save sync options when a variable subscription product is saved
		add_action( 'woocommerce_process_product_meta_variable-subscription', __CLASS__ . '::process_product_meta_variable_subscription' );
		add_action( 'woocommerce_save_product_variation', __CLASS__ . '::save_product_variation', 20, 2 );

		// Make sure the expiration dates are calculated from the synced start date
		add_filter( 'woocommerce_subscriptions_product_trial_expiration_date', __CLASS__ . '::recalculate_product_trial_expiration_date', 10, 2 );
		add_filter( 'woocommerce_subscriptions_product_expiration_date', __CLASS__ . '::recalculate_product_expiration_date', 10, 3 );

		// Display a product's first payment date on the product's page to make sure it's obvious to the customer when payments will start
		add_action( 'woocommerce_single_product_summary', __CLASS__ . '::products_first_payment_date', 31 );

		// Display a product's first payment date on the product's page to make sure it's obvious to the customer when payments will start
		add_action( 'woocommerce_subscriptions_product_first_renewal_payment_time', __CLASS__ . '::products_first_renewal_payment_time', 10, 4 );

		// Maybe mock a free trial on the product for calculating totals and displaying correct shipping costs
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::maybe_set_free_trial', 0, 1 );
		add_action( 'woocommerce_subscription_cart_before_grouping', __CLASS__ . '::maybe_unset_free_trial' );
		add_action( 'woocommerce_subscription_cart_after_grouping', __CLASS__ . '::maybe_set_free_trial' );
		add_filter( 'awc_recurring_cart_start_date', __CLASS__ . '::maybe_unset_free_trial', 0, 1 );
		add_filter( 'awc_recurring_cart_end_date', __CLASS__ . '::maybe_set_free_trial', 100, 1 );
		add_filter( 'woocommerce_subscriptions_calculated_total', __CLASS__ . '::maybe_unset_free_trial', 10000, 1 );
		add_action( 'woocommerce_cart_totals_before_shipping', __CLASS__ . '::maybe_set_free_trial' );
		add_action( 'woocommerce_cart_totals_after_shipping', __CLASS__ . '::maybe_unset_free_trial' );
		add_action( 'woocommerce_review_order_before_shipping', __CLASS__ . '::maybe_set_free_trial' );
		add_action( 'woocommerce_review_order_after_shipping', __CLASS__ . '::maybe_unset_free_trial' );

		// Set prorated initial amount when calculating initial total
		add_filter( 'woocommerce_subscriptions_cart_get_price', __CLASS__ . '::set_prorated_price_for_calculation', 10, 2 );

		// When creating a subscription check if it contains a synced product and make sure the correct meta is set on the subscription
		add_action( 'save_post', __CLASS__ . '::maybe_add_subscription_meta', 10, 1 );

		// When adding an item to a subscription, check if it is for a synced product to make sure the sync meta is set on the subscription. We can't attach to just the 'woocommerce_new_order_item' here because the '_product_id' and '_variation_id' meta are not set before it fires
		add_action( 'woocommerce_ajax_add_order_item_meta', __CLASS__ . '::ajax_maybe_add_meta_for_item', 10, 2 );

		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			add_action( 'woocommerce_order_add_product', __CLASS__ . '::maybe_add_meta_for_new_product', 10, 3 );
			add_action( 'woocommerce_add_order_item_meta', array( __CLASS__, 'maybe_add_order_item_meta' ), 10, 2 );
		} else {
			add_action( 'woocommerce_new_order_item', __CLASS__ . '::maybe_add_meta_for_new_line_item', 10, 3 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'maybe_add_line_item_meta' ), 10, 3 );
		}

		// Make sure the sign-up fee for a synchronised subscription is correct
		add_filter( 'woocommerce_subscriptions_sign_up_fee', __CLASS__ . '::get_synced_sign_up_fee', 1, 3 );

		// If it's an initial sync order and the total is zero, and nothing needs to be shipped, do not reduce stock
		add_filter( 'woocommerce_order_item_quantity', __CLASS__ . '::maybe_do_not_reduce_stock', 10, 3 );

		add_filter( 'woocommerce_subscriptions_recurring_cart_key', __CLASS__ . '::add_to_recurring_cart_key', 10, 2 );

		

		// Don't display migrated order item meta on the Edit Order screen
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_order_itemmeta' ) );
	}





	/**
	 * Check if payment syncing is enabled on the store.
	 *
	 
	 */
	public static function is_syncing_enabled() {
		return AWC_Settings::get_option('synchronise_renewal');
	}

	/**
	 * Check if payments can be prorated on the store.
	 *
	 
	 */
	public static function is_sync_proration_enabled() {
		return 'no' !== AWC_Settings::get_option('prorate_first_renewal');
	}



	/**
	 * Add the sync setting fields to the Edit Product screen
	 *
	 
	 */
	public static function subscription_product_fields() {
		global $post, $wp_locale;

		if ( self::is_syncing_enabled() ) {

			// Set month as the default billing period
			if ( ! $subscription_period = get_post_meta( $post->ID, '_subscription_period', true ) ) {
				$subscription_period = 'month';
			}

			// Determine whether to display the week/month sync fields or the annual sync fields
			$display_week_month_select = ( ! in_array( $subscription_period, array( 'month', 'week' ) ) ) ? 'display: none;' : '';
			$display_annual_select     = ( 'year' != $subscription_period ) ? 'display: none;' : '';

			$payment_day = self::get_products_payment_day( $post->ID );

			// An annual sync date is already set in the form: array( 'day' => 'nn', 'month' => 'nn' ), create a MySQL string from those values (year and time are irrelvent as they are ignored)
			if ( is_array( $payment_day ) ) {
				$payment_month = ( 0 === (int) $payment_day['day'] ) ? 0 : $payment_day['month'];
				$payment_day   = $payment_day['day'];
			} else {
				$payment_month = 0;
			}

			echo '<div class="options_group subscription_pricing subscription_sync show_if_subscription hidden">';
			echo '<div class="subscription_sync_week_month" style="' . esc_attr( $display_week_month_select ) . '">';

			woocommerce_wp_select(
				array(
					'id'          => self::$post_meta_key,
					'class'       => 'wc_input_subscription_payment_sync select short',
					'label'       => self::$sync_field_label,
					'options'     => self::get_billing_period_ranges( $subscription_period ),
					'description' => self::$sync_description,
					'desc_tip'    => true,
					'value'       => $payment_day, // Explicity set value in to ensure backward compatibility
				)
			);

			echo '</div>';

			echo '<div class="subscription_sync_annual" style="' . esc_attr( $display_annual_select ) . '">';

			?><p class="form-field _subscription_payment_sync_date_day_field">
				<label for="_subscription_payment_sync_date_day"><?php echo esc_html( self::$sync_field_label ); ?></label>
				<span class="wrap">

					<label for="<?php echo esc_attr( self::$post_meta_key_month ); ?>" class="awc_hidden_label"><?php esc_html_e( 'Month for Synchronisation', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
					<select id="<?php echo esc_attr( self::$post_meta_key_month ); ?>" name="<?php echo esc_attr( self::$post_meta_key_month ); ?>" class="wc_input_subscription_payment_sync last" >
						<?php foreach ( self::get_year_sync_options() as $value => $label ) { ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $payment_month, true ) ?>><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>

					<?php $daysInMonth = $payment_month ? gmdate( 't', wc_string_to_timestamp( "2001-{$payment_month}-01" ) ) : 0; ?>
					<input type="number" id="<?php echo esc_attr( self::$post_meta_key_day ); ?>" name="<?php echo esc_attr( self::$post_meta_key_day ); ?>" class="wc_input_subscription_payment_sync" value="<?php echo esc_attr( $payment_day ); ?>" placeholder="<?php echo esc_attr_x( 'Day', 'input field placeholder for day field for annual subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ); ?>" step="1" min="<?php echo esc_attr( min( 1, $daysInMonth ) ); ?>" max="<?php echo esc_attr( $daysInMonth ); ?>" <?php disabled( 0, $payment_month, true ); ?> />
				</span>
				<?php echo awc_help_tip( self::$sync_description_year ); ?>
			</p><?php

			echo '</div>';
			echo '</div>';

		}
	}

	/**
	 * Add the sync setting fields to the variation section of the Edit Product screen
	 *
	 
	 */
	public static function variable_subscription_product_fields( $loop, $variation_data, $variation ) {

		if ( self::is_syncing_enabled() ) {

			// Set month as the default billing period
			$subscription_period = AWC_Subscription_Products::awc_get_period( $variation );

			if ( empty( $subscription_period ) ) {
				$subscription_period = 'month';
			}

			$display_week_month_select = ( ! in_array( $subscription_period, array( 'month', 'week' ) ) ) ? 'display: none;' : '';
			$display_annual_select     = ( 'year' != $subscription_period ) ? 'display: none;' : '';

			$payment_day = self::get_products_payment_day( $variation );

			// An annual sync date is already set in the form: array( 'day' => 'nn', 'month' => 'nn' ), create a MySQL string from those values (year and time are irrelvent as they are ignored)
			if ( is_array( $payment_day ) ) {
				$payment_month = ( 0 === (int) $payment_day['day'] ) ? 0 : $payment_day['month'];
				$payment_day   = $payment_day['day'];
			} else {
				$payment_month = 0;
			}

			include( plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/admin/html-variation-synchronisation.php' );
		}
	}

	/**
	 * Save sync options when a subscription product is saved
	 *
	 
	 */
	public static function save_subscription_meta( $post_id ) {

		if ( empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) ) {
			return;
		}

		// Set month as the default billing period
		if ( ! isset( $_POST['_subscription_period'] ) ) {
			$_POST['_subscription_period'] = 'month';
		}

		if ( 'year' == $_POST['_subscription_period'] ) { // save the day & month for the date rather than just the day

			$_POST[ self::$post_meta_key ] = array(
				'day'   => isset( $_POST[ self::$post_meta_key_day ] ) ? $_POST[ self::$post_meta_key_day ] : 0,
				'month' => isset( $_POST[ self::$post_meta_key_month ] ) ? $_POST[ self::$post_meta_key_month ] : '01',
			);

		} else {

			if ( ! isset( $_POST[ self::$post_meta_key ] ) ) {
				$_POST[ self::$post_meta_key ] = 0;
			}
		}

		update_post_meta( $post_id, self::$post_meta_key, $_POST[ self::$post_meta_key ] );
	}

	/**
	 * Save sync options when a variable subscription product is saved
	 *
	 
	 */
	public static function process_product_meta_variable_subscription( $post_id ) {

		if ( empty( $_POST['_asubnonce_save_variations'] ) || ! wp_verify_nonce( $_POST['_asubnonce_save_variations'], 'awc_subscription_variations' ) || ! isset( $_POST['variable_post_id'] ) || ! is_array( $_POST['variable_post_id'] ) ) {
			return;
		}

		// Make sure the parent product doesn't have a sync value (in case it was once a simple subscription)
		update_post_meta( $post_id, self::$post_meta_key, 0 );
	}

	/**
	 * Save sync options when a variable subscription product is saved
	 *
	 
	 */
	public static function save_product_variation( $variation_id, $index ) {

		if ( empty( $_POST['_asubnonce_save_variations'] ) || ! wp_verify_nonce( $_POST['_asubnonce_save_variations'], 'awc_subscription_variations' ) || ! isset( $_POST['variable_post_id'] ) || ! is_array( $_POST['variable_post_id'] ) ) {
			return;
		}

		$day_field   = 'variable' . self::$post_meta_key_day;
		$month_field = 'variable' . self::$post_meta_key_month;

		if ( 'year' == $_POST['variable_subscription_period'][ $index ] ) { // save the day & month for the date rather than just the day

			$_POST[ 'variable' . self::$post_meta_key ][ $index ] = array(
				'day'   => isset( $_POST[ $day_field ][ $index ] ) ? $_POST[ $day_field ][ $index ] : 0,
				'month' => isset( $_POST[ $month_field ][ $index ] ) ? $_POST[ $month_field ][ $index ] : 0,
			);

		} elseif ( ! isset( $_POST[ 'variable' . self::$post_meta_key ][ $index ] ) ) {
			$_POST[ 'variable' . self::$post_meta_key ][ $index ] = 0;
		}

		update_post_meta( $variation_id, self::$post_meta_key, $_POST[ 'variable' . self::$post_meta_key ][ $index ] );
	}

	/**
	 * Add translated syncing options for our client side script
	 *
	 
	 */
	public static function admin_script_parameters( $script_parameters ) {

		// Get admin screen id
		$screen = get_current_screen();

		if ( 'product' == $screen->id ) {

			$billing_period_strings = self::get_billing_period_ranges();

			$script_parameters['syncOptions'] = array(
				'week'  => $billing_period_strings['week'],
				'month' => $billing_period_strings['month'],
				'year'  => self::get_year_sync_options(),
			);
		}

		return $script_parameters;
	}

	/**
	 * Determine whether a product, specified with $product, needs to have its first payment processed on a
	 * specific day (instead of at the time of sign-up).
	 *
	 * @return (bool) True is the product's first payment will be synced to a certain day.
	 
	 */
	public static function is_product_synced( $product ) {

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! is_object( $product ) || ! self::is_syncing_enabled() || 'day' == AWC_Subscription_Products::awc_get_period( $product ) || ! AWC_Subscription_Products::awc_is_subscription( $product ) ) {
			return false;
		}

		$payment_date = self::get_products_payment_day( $product );

		return ( ! is_array( $payment_date ) && $payment_date > 0 ) || ( isset( $payment_date['day'] ) && $payment_date['day'] > 0 );
	}

	/**
	 *
	 * @param WC_Product $product
	 *
	 * @return bool
	 */
	public static function is_product_prorated( $product ) {
		if ( false === self::is_sync_proration_enabled() || false === self::is_product_synced( $product ) ) {
			$is_product_prorated = false;
		} elseif ( 'yes' == AWC_Settings::get_option('prorate_first_renewal') && 0 == AWC_Subscription_Products::awc_get_trial_length( $product ) ) {
			$is_product_prorated = true;
		} elseif ( 'virtual' == AWC_Settings::get_option('prorate_first_renewal') && $product->is_virtual() && 0 == AWC_Subscription_Products::awc_get_trial_length( $product ) ) {
			$is_product_prorated = true;
		} else {
			$is_product_prorated = false;
		}

		return $is_product_prorated;
	}

	/**
	 * Determine whether the payment for a subscription should be the full price upfront.
	 *
	 * This method is particularly concerned with synchronized subscriptions. It will only return
	 * true when the following conditions are met:
	 *
	 * - There is no free trial
	 * - The subscription is synchronized
	 * - The store owner has determined that new subscribers need to pay for their subscription upfront.
	 *
	 * Additionally, if the store owner sets a number of days prior to the synchronization day that do not
	 * require an upfront payment, this method will check to see whether the current date falls within that
	 * period for the given product.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Product $product The product to check.
	 * @param string     $from_date Optional. A MySQL formatted date/time string from which to calculate from. The default is an empty string which is today's date/time.
	 *
	 * @return bool Whether an upfront payment is required for the product.
	 */
	public static function is_payment_upfront( $product, $from_date = '' ) {
		static $results = array();
		$is_upfront     = null;

		if ( array_key_exists( $product->get_id(), $results ) ) {
			return $results[ $product->get_id() ];
		}

		// Normal cases where we aren't concerned with an upfront payment.
		if (
			0 !== AWC_Subscription_Products::awc_get_trial_length( $product ) ||
			! self::is_product_synced( $product )
		) {
			$is_upfront = false;
		}

		// Maybe account for number of days without a fee.
		if ( null === $is_upfront ) {
			$no_fee_days    = self::get_number_of_grace_period_days();
			$payment_date   = self::calculate_first_payment_date( $product, 'timestamp', $from_date );
			$from_timestamp = $from_date ? awc_date_to_time( $from_date ) : gmdate( 'U' );
			$site_offset    = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

			// The payment date is today - check for it in site time.
			if ( gmdate( 'Ymd', $payment_date + $site_offset ) === gmdate( 'Ymd', $from_timestamp + $site_offset ) ) {
				$is_upfront = true;
			} elseif ( 'recurring' !== AWC_Settings::get_option('prorate_first_renewal') ) {
				$is_upfront = false;
			} elseif ( $no_fee_days > 0 ) {
				// When proration setting is 'recurring' and there is a grace period.
				$buffer_date = $payment_date - ( $no_fee_days * DAY_IN_SECONDS );

				$is_upfront = $from_timestamp < awc_date_to_time( gmdate( 'Y-m-d 23:59:59', $buffer_date ) );
			} else {
				$is_upfront = true;
			}
		}

		/**
		 * Filter whether payment is upfront for a given product.
		 *
		 * @param bool       $is_upfront Whether the product needs to be paid upfront.
		 * @param WC_Product $product    The current product.
		 */
		$results[ $product->get_id() ] = apply_filters( 'woocommerce_subscriptions_payment_upfront', $is_upfront, $product );

		return $results[ $product->get_id() ];
	}

	/**
	 * Get the day of the week, month or year on which a subscription's payments should be
	 * synchronised to.
	 *
	 * @return int The day the products payments should be processed, or 0 if the payments should not be sync'd to a specific day.
	 
	 */
	public static function get_products_payment_day( $product ) {

		if ( ! self::is_syncing_enabled() ) {
			$payment_date = 0;
		} else {
			$payment_date = AWC_Subscription_Products::awc_get_meta_data( $product, 'subscription_payment_sync_date', 0 );
		}

		return apply_filters( 'woocommerce_subscriptions_product_sync_date', $payment_date, $product );
	}

	/**
	 * Calculate the first payment date for a synced subscription.
	 *
	 * The date is calculated in UTC timezone.
	 *
	 * @param WC_Product $product A subscription product.
	 * @param string $type (optional) The format to return the first payment date in, either 'mysql' or 'timestamp'. Default 'mysql'.
	 * @param string $from_date (optional) The date to calculate the first payment from in GMT/UTC timzeone. If not set, it will use the current date. This should not include any trial period on the product.
	 
	 */
	public static function calculate_first_payment_date( $product, $type = 'mysql', $from_date = '' ) {

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! self::is_product_synced( $product ) ) {
			return 0;
		}

		$period       = AWC_Subscription_Products::awc_get_period( $product );
		$trial_length = AWC_Subscription_Products::awc_get_trial_length( $product );

		// For billing intervals > 1:
		// When the proration setting is 'recurring', there is a full upfront payment for the entire billing interval
		// So, the first payment date should be calculated after the entire interval
		// When the proration setting is 'no' or 'yes', the upfront payment is until the next date occurrence (1 week/month/year).
		// So, the first payment date should be calculated with 1 as the interval
		$interval = AWC_Settings::get_option('prorate_first_renewal') === 'recurring' ? AWC_Subscription_Products::awc_get_interval( $product ) : 1;

		$from_date_param = $from_date;

		if ( empty( $from_date ) ) {
			$from_date = gmdate( 'Y-m-d H:i:s' );
		}

		// If the subscription has a free trial period, the first payment should be synced to a day after the free trial
		if ( $trial_length > 0 ) {
			$from_date = AWC_Subscription_Products::get_trial_expiration_date( $product, $from_date );
		}

		$from_timestamp = awc_date_to_time( $from_date ) + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); // Site time
		$payment_day    = self::get_products_payment_day( $product );
		$no_fee_days    = self::get_number_of_grace_period_days();

		if ( 'week' == $period ) {

			// Get the day of the week for the from date
			$from_day = gmdate( 'N', $from_timestamp );

			// To account for rollover of the weekdays. For example, if from day is Saturday and the payment date is Monday,
			// and the grace period is 2, Saturday is day 6 and Monday is day 1.
			$add_days = $payment_day < $from_day ? 0 : 7;

			// Calculate difference between the the two days after adding number of weekdays and compare against grace period
			if ( ( $payment_day + $add_days - $from_day ) <= $no_fee_days ) {
				$from_timestamp = awc_add_time( $interval - 1, $period, $from_timestamp );
			}

			// strtotime() will figure out if the day is in the future or today (see: https://gist.github.com/thenbrent/9698083)
			$first_payment_timestamp = awc_strtotime_dark_knight( self::$weekdays[ $payment_day ], $from_timestamp );
		} elseif ( 'month' == $period ) {

			// strtotime() needs to know the month, so we need to determine if the payment day has occurred this month yet or if we want the last day of the month (see: https://gist.github.com/thenbrent/9698083)
			if ( $payment_day > 27 ) { // we actually want the last day of the month
				$payment_day = gmdate( 't', $from_timestamp ); // the number of days in the month
			}

			$from_day = gmdate( 'j', $from_timestamp );

			// If 'from day' is before 'sync day' in the month
			if ( $from_day <= $payment_day ) {
				if ( $from_day + $no_fee_days >= $payment_day ) { // In grace period
					$month        = gmdate( 'F', $from_timestamp );
					$month_number = gmdate( 'm', $from_timestamp );
				} else { // If not in grace period, then the sync day has passed by. So, reduce interval by 1.
					$month        = gmdate( 'F', awc_add_months( $from_timestamp, $interval - 1 ) );
					$month_number = gmdate( 'm', awc_add_months( $from_timestamp, $interval - 1 ) );
				}
			} else { // If 'from day' is after 'sync day' in the month
				$days_in_month = gmdate( 't', $from_timestamp );
				// Use 'days in month' to account for end of month dates
				if ( $from_day + $no_fee_days - $days_in_month >= $payment_day ) { // In grace period
					$month        = gmdate( 'F', awc_add_months( $from_timestamp, 1 ) );
					$month_number = gmdate( 'm', awc_add_months( $from_timestamp, 1 ) );
				} else { // Not in grace period, so add interval number of months
					$month        = gmdate( 'F', awc_add_months( $from_timestamp, $interval ) );
					$month_number = gmdate( 'm', awc_add_months( $from_timestamp, $interval ) );
				}
			}
			// when a certain number of months are added and the first payment date moves to next year
			if ( $month_number < gmdate( 'm', $from_timestamp ) ) {
				$year       = gmdate( 'Y', $from_timestamp );
				$year++;
				$first_payment_timestamp = awc_strtotime_dark_knight( "{$payment_day} {$month} {$year}", $from_timestamp );
			} else {
				$first_payment_timestamp = awc_strtotime_dark_knight( "{$payment_day} {$month}", $from_timestamp );
			}
		} elseif ( 'year' == $period ) {

			// We can't use $wp_locale here because it is translated
			$month_map = array(
				'01' => 'January',
				'02' => 'February',
				'03' => 'March',
				'04' => 'April',
				'05' => 'May',
				'06' => 'June',
				'07' => 'July',
				'08' => 'August',
				'09' => 'September',
				'10' => 'October',
				'11' => 'November',
				'12' => 'December',
			);

			$month             = $month_map[ $payment_day['month'] ];
			$payment_month_day = sprintf( '%02d%02d', $payment_day['month'], $payment_day['day'] );
			$year              = gmdate( 'Y', $from_timestamp );
			$from_month_day    = gmdate( 'md', $from_timestamp );

			if ( $from_month_day > $payment_month_day ) { // If 'from day' is after 'sync day' in the year
				$year++;
			}

			if ( $from_timestamp + ( $no_fee_days * DAY_IN_SECONDS ) >=
				awc_strtotime_dark_knight( "{$payment_day['day']} {$month} {$year}" ) ) { // In grace period
				$first_payment_timestamp = awc_strtotime_dark_knight( "{$payment_day['day']} {$month} {$year}", $from_timestamp );
			} else { // If not in grace period, then the sync day has passed by. So, reduce interval by 1.
				$year += $interval - 1;
				$first_payment_timestamp = awc_strtotime_dark_knight( "{$payment_day['day']} {$month} {$year}", awc_add_time( $interval - 1, $period, $from_timestamp ) );
			}
		}

		// We calculated a timestamp for midnight on the specific day in the site's timezone, let's push it to 3am to account for any daylight savings changes
		$first_payment_timestamp += 3 * HOUR_IN_SECONDS;

		// And convert it to the UTC equivalent of 3am on that day
		$first_payment_timestamp -= ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );

		$first_payment = ( 'mysql' == $type && 0 != $first_payment_timestamp ) ? gmdate( 'Y-m-d H:i:s', $first_payment_timestamp ) : $first_payment_timestamp;

		return apply_filters( 'woocommerce_subscriptions_synced_first_payment_date', $first_payment, $product, $type, $from_date, $from_date_param );
	}

	/**
	 * Return an i18n'ified associative array of sync options for 'year' as billing period
	 *
	 
	 */
	public static function get_year_sync_options() {
		global $wp_locale;

		$year_sync_options[0] = __( 'Do not synchronise', 'subscriptions-recurring-payments-for-woocommerce' );
		$year_sync_options   += $wp_locale->month;

		return $year_sync_options;
	}

	/**
	 * Return an i18n'ified associative array of all possible subscription periods.
	 *
	 
	 */
	public static function get_billing_period_ranges( $billing_period = '' ) {
		global $wp_locale;

		if ( empty( self::$billing_period_ranges ) ) {

			foreach ( array( 'week', 'month' ) as $key ) {
				self::$billing_period_ranges[ $key ][0] = __( 'Do not synchronise', 'subscriptions-recurring-payments-for-woocommerce' );
			}

			// Week
			$weekdays = array_merge( $wp_locale->weekday, array( $wp_locale->weekday[0] ) );
			unset( $weekdays[0] );
			foreach ( $weekdays as $i => $weekly_billing_period ) {
				// translators: placeholder is a day of the week
				self::$billing_period_ranges['week'][ $i ] = sprintf( __( '%s each week', 'subscriptions-recurring-payments-for-woocommerce' ), $weekly_billing_period );
			}

			// Month
			foreach ( range( 1, 27 ) as $i ) {
				// translators: placeholder is a number of day with language specific suffix applied (e.g. "1st", "3rd", "5th", etc...)
				self::$billing_period_ranges['month'][ $i ] = sprintf( __( '%s day of the month', 'subscriptions-recurring-payments-for-woocommerce' ), AWC_Subscriptions::append_numeral_suffix( $i ) );
			}
			self::$billing_period_ranges['month'][28] = __( 'Last day of the month', 'subscriptions-recurring-payments-for-woocommerce' );

			self::$billing_period_ranges = apply_filters( 'woocommerce_subscription_billing_period_ranges', self::$billing_period_ranges );
		}

		if ( empty( $billing_period ) ) {
			return self::$billing_period_ranges;
		} elseif ( isset( self::$billing_period_ranges[ $billing_period ] ) ) {
			return self::$billing_period_ranges[ $billing_period ];
		} else {
			return array();
		}
	}

	/**
	 * Add the first payment date to a products summary section
	 *
	 
	 */
	public static function products_first_payment_date( $echo = false ) {
		global $product;

		$first_payment_date = '<p class="first-payment-date"><small>' . self::get_products_first_payment_date( $product ) . '</small></p>';

		if ( false !== $echo ) {
			echo wp_kses( $first_payment_date, array( 'p' => array( 'class' => array() ), 'small' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		return $first_payment_date;
	}

	/**
	 * Return a string explaining when the first payment will be completed for the subscription.
	 *
	 
	 */
	public static function get_products_first_payment_date( $product ) {

		$first_payment_date = '';

		if ( self::is_product_synced( $product ) ) {
			$first_payment_timestamp = self::calculate_first_payment_date( $product->get_id(), 'timestamp' );

			if ( 0 != $first_payment_timestamp ) {

				$is_first_payment_today  = self::is_today( $first_payment_timestamp );

				if ( $is_first_payment_today ) {
					$payment_date_string = __( 'Today!', 'subscriptions-recurring-payments-for-woocommerce' );
				} else {
					$payment_date_string = date_i18n( wc_date_format(), $first_payment_timestamp + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
				}

				if ( self::is_product_prorated( $product ) && ! $is_first_payment_today ) {
					// translators: placeholder is a date
					$first_payment_date = sprintf( __( 'First payment prorated. Next payment: %s', 'subscriptions-recurring-payments-for-woocommerce' ), $payment_date_string );
				} else {
					// translators: placeholder is a date
					$first_payment_date = sprintf( __( 'First payment: %s', 'subscriptions-recurring-payments-for-woocommerce' ), $payment_date_string );
				}

				$first_payment_date = '<small>' . $first_payment_date . '</small>';
			}
		}

		return apply_filters( 'woocommerce_subscriptions_synced_first_payment_date_string', $first_payment_date, $product );
	}

	/**
	 * If a product is synchronised to a date in the future, make sure that is set as the product's first payment date
	 *
	 
	 */
	public static function products_first_renewal_payment_time( $first_renewal_timestamp, $product_id, $from_date, $timezone ) {

		if ( self::is_product_synced( $product_id ) ) {

			$next_renewal_timestamp = self::calculate_first_payment_date( $product_id, 'timestamp', $from_date );

			if ( ! self::is_today( $next_renewal_timestamp ) ) {

				if ( 'site' == $timezone ) {
					$next_renewal_timestamp += ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
				}
				$first_renewal_timestamp = $next_renewal_timestamp;
			}
		}

		return $first_renewal_timestamp;
	}

	/**
	 * Make sure a synchronised subscription's price includes a free trial, unless it's first payment is today.
	 *
	 
	 */
	public static function maybe_set_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if (
				self::is_product_synced( $cart_item['data'] ) &&
				! self::is_payment_upfront( $cart_item['data'] ) &&
				! self::is_product_prorated( $cart_item['data'] ) &&
				! self::is_today( self::calculate_first_payment_date( $cart_item['data'], 'timestamp' ) )
			) {
				$current_trial_length = AWC_Subscription_Products::awc_get_trial_length( WC()->cart->cart_contents[ $cart_item_key ]['data'] );
				$new_trial_length     = ( $current_trial_length > 1 ) ? $current_trial_length : 1;
				awc_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', $new_trial_length, 'set_prop_only' );
			}
		}

		return $total;
	}

	/**
	 * Make sure a synchronised subscription's price includes a free trial, unless it's first payment is today.
	 *
	 
	 */
	public static function maybe_unset_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( self::is_product_synced( $cart_item['data'] ) ) {
				awc_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', AWC_Subscription_Products::awc_get_trial_length( awc_get_canonical_product_id( $cart_item ) ), 'set_prop_only' );
			}
		}
		return $total;
	}

	/**
	 * Check if the cart includes a subscription that needs to be synced.
	 *
	 * @return bool Returns true if any item in the cart is a subscription sync request, otherwise, false.
	 
	 */
	public static function cart_contains_synced_subscription( $cart = null ) {
		$cart            = ( empty( $cart ) && isset( WC()->cart ) ) ? WC()->cart : $cart;
		$contains_synced = false;

		if ( self::is_syncing_enabled() && ! empty( $cart ) && ! awc_cart_contains_renewal() ) {

			foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {
				if ( self::is_product_synced( $cart_item['data'] ) ) {
					$contains_synced = $cart_item;
					break;
				}
			}
		}

		return $contains_synced;
	}

	/**
	 * Maybe set the time of a product's trial expiration to be the same as the synced first payment date for products where the first
	 * renewal payment date falls on the same day as the trial expiration date, but the trial expiration time is later in the day.
	 *
	 * When making sure the first payment is after the trial expiration in @see self::calculate_first_payment_date() we only check
	 * whether the first payment day comes after the trial expiration day, because we don't want to pushing the first payment date
	 * a month or year in the future because of a few hours difference between it and the trial expiration. However, this means we
	 * could still end up with a trial end time after the first payment time, even though they are both on the same day because the
	 * trial end time is normally calculated from the start time, which can be any time of day, but the first renewal time is always
	 * set to be 3am in the site's timezone. For example, the first payment date might be calculate to be 3:00 on the 21st April 2017,
	 * while the trial end date is on the same day at 3:01 (or any time after that on the same day). So we need to check both the time and day. We also don't want to make the first payment date/time skip a year because of a few hours difference. That means we need to either modify the trial end time to be 3:00am or make the first payment time occur at the same time as the trial end time. The former is pretty hard to change, but the later will sync'd payments will be at a different times if there is a free trial ending on the same day, which could be confusing. o_0
	 *
	 * Fixes #1328
	 *
	 * @param mixed $trial_expiration_date MySQL formatted date on which the subscription's trial will end, or 0 if it has no trial
	 * @param mixed $product_id The product object or post ID of the subscription product
	 * @return mixed MySQL formatted date on which the subscription's trial is set to end, or 0 if it has no trial
	 .13
	 */
	public static function recalculate_product_trial_expiration_date( $trial_expiration_date, $product_id ) {

		if ( $trial_expiration_date > 0 && self::is_product_synced( $product_id ) ) {

			$trial_expiration_timestamp = awc_date_to_time( $trial_expiration_date );
			remove_filter( 'woocommerce_subscriptions_product_trial_expiration_date', __METHOD__ ); // avoid infinite loop
			$first_payment_timestamp    = self::calculate_first_payment_date( $product_id, 'timestamp' );
			add_filter( 'woocommerce_subscriptions_product_trial_expiration_date', __METHOD__, 10, 2 ); // avoid infinite loop

			// First make sure the day is in the past so that we don't end up jumping a month or year because of a few hours difference between now and the billing date
			// Use site time to check if the trial expiration and first payment fall on the same day
			$site_offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

			if ( $trial_expiration_timestamp > $first_payment_timestamp && gmdate( 'Ymd', $first_payment_timestamp + $site_offset ) === gmdate( 'Ymd', $trial_expiration_timestamp + $site_offset ) ) {
				$trial_expiration_date = date( 'Y-m-d H:i:s', $first_payment_timestamp );
			}
		}

		return $trial_expiration_date;
	}

	/**
	 * Make sure the expiration date is calculated from the synced start date for products where the start date
	 * will be synced.
	 *
	 * @param string $expiration_date MySQL formatted date on which the subscription is set to expire
	 * @param mixed $product_id The product/post ID of the subscription
	 * @param mixed $from_date A MySQL formatted date/time string from which to calculate the expiration date, or empty (default), which will use today's date/time.
	 
	 */
	public static function recalculate_product_expiration_date( $expiration_date, $product_id, $from_date ) {

		if ( self::is_product_synced( $product_id ) && ( $subscription_length = AWC_Subscription_Products::awc_get_length( $product_id ) ) > 0 ) {

				$subscription_period = AWC_Subscription_Products::awc_get_period( $product_id );
				$first_payment_date  = self::calculate_first_payment_date( $product_id, 'timestamp' );

				$expiration_date = date( 'Y-m-d H:i:s', awc_add_time( $subscription_length, $subscription_period, $first_payment_date ) );
		}

		return $expiration_date;
	}

	/**
	 * Check if a given timestamp (in the UTC timezone) is equivalent to today in the site's time.
	 *
	 * @param int $timestamp A time in UTC timezone to compare to today.
	 */
	public static function is_today( $timestamp ) {

		// Convert timestamp to site's time
		$timestamp += (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		return gmdate( 'Y-m-d', current_time( 'timestamp' ) ) == gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Filters WC_Subscriptions_Order::get_sign_up_fee() to make sure the sign-up fee for a subscription product
	 * that is synchronised is returned correctly.
	 *
	 * @param float The initial sign-up fee charged when the subscription product in the order was first purchased, if any.
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return float The initial sign-up fee charged when the subscription product in the order was first purchased, if any.
	 
	 */
	public static function get_synced_sign_up_fee( $sign_up_fee, $subscription, $product_id ) {

		if ( awc_is_subscription( $subscription ) && self::subscription_contains_synced_product( $subscription ) && count( awc_get_line_items_with_a_trial( $subscription->get_id() ) ) < 0 ) {
			$sign_up_fee = max( $subscription->get_total_initial_payment() - $subscription->get_total(), 0 );
		}

		return $sign_up_fee;
	}

	/**
	 * Removes the "set_subscription_prices_for_calculation" filter from the WC Product's woocommerce_get_price hook once
	 *
	 
	 *
	 * @param int        $price   The current price.
	 * @param WC_Product $product The product object.
	 *
	 * @return int
	 */
	public static function set_prorated_price_for_calculation( $price, $product ) {

		if ( AWC_Subscription_Products::awc_is_subscription( $product ) && self::is_product_prorated( $product ) && 'none' == AWC_Subscription_Cart::get_calculation_type() ) {

			$next_payment_date = self::calculate_first_payment_date( $product, 'timestamp' );

			if ( self::is_today( $next_payment_date ) ) {
				return $price;
			}

			switch ( AWC_Subscription_Products::awc_get_period( $product ) ) {
				case 'week':
					$days_in_cycle = 7 * AWC_Subscription_Products::awc_get_interval( $product );
					break;
				case 'month':
					$days_in_cycle = gmdate( 't' ) * AWC_Subscription_Products::awc_get_interval( $product );
					break;
				case 'year':
					$days_in_cycle = ( 365 + gmdate( 'L' ) ) * AWC_Subscription_Products::awc_get_interval( $product );
					break;
			}

			$days_until_next_payment = ceil( ( $next_payment_date - gmdate( 'U' ) ) / ( 60 * 60 * 24 ) );

			$sign_up_fee = AWC_Subscription_Products::awc_get_sign_up_fee( $product );

			if ( $sign_up_fee > 0 && 0 == AWC_Subscription_Products::awc_get_trial_length( $product ) ) {
				$price = $sign_up_fee + ( $days_until_next_payment * ( ( $price - $sign_up_fee ) / $days_in_cycle ) );
			} else {
				$price = $days_until_next_payment * ( $price / $days_in_cycle );
			}

			$price = round( $price, wc_get_price_decimals() );
		}

		return $price;
	}

	/**
	 * Retrieve the full translated weekday word.
	 *
	 * Week starts on translated Monday and can be fetched
	 * by using 1 (one). So the week starts with 1 (one)
	 * and ends on Sunday with is fetched by using 7 (seven).
	 *
	 
	 * @access public
	 *
	 * @param int $weekday_number 1 for Monday through 7 Sunday
	 * @return string Full translated weekday
	 */
	public static function get_weekday( $weekday_number ) {
		global $wp_locale;

		if ( 7 == $weekday_number ) {
			$weekday = $wp_locale->get_weekday( 0 );
		} else {
			$weekday = $wp_locale->get_weekday( $weekday_number );
		}

		return $weekday;
	}

	/**
	 * Override quantities used to lower stock levels by when using synced subscriptions. If it's a synced product
	 * that does not have proration enabled and the payment date is not today, do not lower stock levels.
	 *
	 * @param integer $qty the original quantity that would be taken out of the stock level
	 * @param array $order order data
	 * @param array $item item data for each item in the order
	 *
	 * @return int
	 */
	public static function maybe_do_not_reduce_stock( $qty, $order, $order_item ) {
		if ( awc_order_contains_subscription( $order, array( 'parent', 'resubscribe' ) ) && 0 == $order_item['line_total'] ) {
			$subscriptions = awc_get_subscriptions_for_order( $order );
			$product_id    = awc_get_canonical_product_id( $order_item );

			foreach ( $subscriptions as $subscription ) {
				if ( self::subscription_contains_synced_product( $subscription ) && $subscription->has_product( $product_id ) ) {
					foreach ( $subscription->get_items() as $subscription_item ) {
						if ( awc_get_canonical_product_id( $subscription_item ) == $product_id && 0 < $subscription_item['line_total'] ) {
							$qty = 0;
						}
					}
				}
			}
		}
		return $qty;
	}

	/**
	 * Add subscription meta for subscription that contains a synced product.
	 *
	 * @param WC_Order Parent order for the subscription
	 * @param WC_Subscription new subscription
	 
	 */
	public static function maybe_add_subscription_meta( $post_id ) {

		if ( 'shop_subscription' == get_post_type( $post_id ) && ! self::subscription_contains_synced_product( $post_id ) ) {

			$subscription = awc_get_subscription( $post_id );

			foreach ( $subscription->get_items() as $item ) {
				$product = $item->get_product();

				if ( self::is_product_synced( $product ) ) {
					update_post_meta( $subscription->get_id(), '_contains_synced_subscription', 'true' );
					break;
				}
			}
		}
	}

	/**
	 * When adding an item to an order/subscription via the Add/Edit Subscription administration interface, check if we should be setting
	 * the sync meta on the subscription.
	 *
	 * @param int The order item ID of an item that was just added to the order
	 * @param array The order item details
	 
	 */
	public static function ajax_maybe_add_meta_for_item( $item_id, $item ) {

		check_ajax_referer( 'order-item', 'security' );

		if ( self::is_product_synced( awc_get_canonical_product_id( $item ) ) ) {
			self::maybe_add_subscription_meta( absint( $_POST['order_id'] ) );
		}
	}

	/**
	 * When adding a product to an order/subscription via the AWC_Subscription::add_product() method, check if we should be setting
	 * the sync meta on the subscription.
	 *
	 * @param int The post ID of a WC_Order or child object
	 * @param int The order item ID of an item that was just added to the order
	 * @param object The WC_Product for which an item was just added
	 
	 */
	public static function maybe_add_meta_for_new_product( $subscription_id, $item_id, $product ) {
		if ( self::is_product_synced( $product ) ) {
			self::maybe_add_subscription_meta( $subscription_id );
		}
	}

	/**
	 * Check if a given subscription is synced to a certain day.
	 *
	 * @param int|WC_Subscription Accepts either a subscription object of post id
	 * @return bool
	 
	 */
	public static function subscription_contains_synced_product( $subscription_id ) {

		if ( is_object( $subscription_id ) ) {
			$subscription_id = $subscription_id->get_id();
		}

		return 'true' == get_post_meta( $subscription_id, '_contains_synced_subscription', true );
	}

	/**
	 * If the cart item is synced, add a '_synced' string to the recurring cart key.
	 *
	 
	 */
	public static function add_to_recurring_cart_key( $cart_key, $cart_item ) {
		$product = $cart_item['data'];

		if ( false === strpos( $cart_key, '_synced' ) && self::is_product_synced( $product ) ) {
			$cart_key .= '_synced';
		}

		return $cart_key;
	}

	/**
	 * When adding a product line item to an order/subscription via the WC_Abstract_Order::add_product() method, check if we should be setting
	 * the sync meta on the subscription.
	 *
	 * Attached to WC 3.0+ hooks and uses WC 3.0 methods.
	 *
	 * @param int The new line item id
	 * @param WC_Order_Item
	 * @param int The post ID of a WC_Subscription
	 
	 */
	public static function maybe_add_meta_for_new_line_item( $item_id, $item, $subscription_id ) {
		if ( is_callable( array( $item, 'get_product' ) ) ) {
			$product = $item->get_product();

			if ( self::is_product_synced( $product ) ) {
				self::maybe_add_subscription_meta( $subscription_id );
			}
		}
	}

	/**
	 * Store a synced product's signup fee on the line item on the subscription and order.
	 *
	 * When calculating prorated sign up fees during switches it's necessary to get the sign-up fee paid.
	 * For synced product purchases we cannot rely on the order line item price as that might include a prorated recurring price or no recurring price all.
	 *
	 * Attached to WC 3.0+ hooks and uses WC 3.0 methods.
	 *
	 * @param WC_Order_Item_Product $item The order item object.
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 
	 */
	public static function maybe_add_line_item_meta( $item, $cart_item_key, $cart_item ) {
		if ( self::is_product_synced( $cart_item['data'] ) && ! self::is_today( self::calculate_first_payment_date( $cart_item['data'], 'timestamp' ) ) ) {
			$item->add_meta_data( '_synced_sign_up_fee', AWC_Subscription_Products::awc_get_sign_up_fee( $cart_item['data'] ) );
		}
	}

	/**
	 * Store a synced product's signup fee on the line item on the subscription and order.
	 *
	 * This function is a pre WooCommerce 3.0 version of @see WC_Subscriptions_Synchroniser::maybe_add_line_item_meta()
	 *
	 * @param int $item_id The order item ID.
	 * @param array $cart_item The cart item's data.
	 
	 */
	public static function maybe_add_order_item_meta( $item_id, $cart_item ) {
		if ( self::is_product_synced( $cart_item['data'] ) && ! self::is_today( self::calculate_first_payment_date( $cart_item['data'], 'timestamp' ) ) ) {
			wc_update_order_item_meta( $item_id, '_synced_sign_up_fee', AWC_Subscription_Products::awc_get_sign_up_fee( $cart_item['data'] ) );
		}
	}

	/**
	 * Hides synced subscription meta on the edit order and subscription screen on non-debug sites.
	 *
	 
	 * @param array $hidden_meta_keys the list of meta keys hidden on the edit order and subscription screen.
	 * @return array $hidden_meta_keys
	 */
	public static function hide_order_itemmeta( $hidden_meta_keys ) {
		if ( apply_filters( 'woocommerce_subscriptions_hide_synchronization_itemmeta', ! defined( 'awc_DEBUG' ) || true !== awc_DEBUG ) ) {
			$hidden_meta_keys[] = '_synced_sign_up_fee';
		}

		return $hidden_meta_keys;

	}

	/**
	 * Gets the number of sign-up grace period days.
	 *
	 
	 * @return int The number of days in the grace period. 0 will be returned if the stroe isn't charging the full recurring price on sign-up -- a prerequiste for setting a grace period.
	 */
	private static function get_number_of_grace_period_days() {
		return AWC_Settings::get_option('prorate_first_renewal') === 'recurring' && AWC_Settings::get_option('signup_grace_period') ? AWC_Settings::get_option('signup_grace_period') : 0;
	}

	/* Deprecated Functions */

	/**
	 * Automatically set the order's status to complete if all the subscriptions in an order
	 * are synced and the order total is zero.
	 *
	 
	 */
	public static function order_autocomplete( $new_order_status, $order_id ) {
		_deprecated_function( __METHOD__, '2.1.3', 'WC_Subscriptions_Order::maybe_autocomplete_order' );
		return WC_Subscriptions_Order::maybe_autocomplete_order( $new_order_status, $order_id );
	}

	/**
	 * Add the first payment date to the end of the subscription to clarify when the first payment will be processed
	 *
	 * Deprecated because the first renewal date is displayed by default now on recurring totals.
	 *
	 
	 * 
	 */
	public static function customise_subscription_price_string( $subscription_string ) {
		

		$cart_item = self::cart_contains_synced_subscription();

		if ( false !== $cart_item && '' !== AWC_Subscription_Products::awc_get_period( $cart_item['data'] ) && ( 'year' != AWC_Subscription_Products::awc_get_period( $cart_item['data'] ) || AWC_Subscription_Products::awc_get_trial_length( $cart_item['data'] ) > 0 ) ) {

			$first_payment_date = self::get_products_first_payment_date( $cart_item['data'] );

			if ( '' != $first_payment_date ) {

				$price_and_start_date = sprintf( '%s <br/><span class="first-payment-date">%s</span>', $subscription_string, $first_payment_date );

				$subscription_string  = apply_filters( 'woocommerce_subscriptions_synced_start_date_string', $price_and_start_date, $subscription_string, $cart_item );
			}
		}

		return $subscription_string;
	}

	/**
	 * Hid the trial period for a synchronised subscription unless the related product actually has a trial period (because
	 * we use a trial period to set the original order totals to 0).
	 *
	 * Deprecated because free trials are no longer displayed on cart totals, only the first renewal date is displayed.
	 *
	 
	 * 
	 */
	public static function maybe_hide_free_trial( $subscription_details ) {
		

		$cart_item = self::cart_contains_synced_subscription();

		if ( false !== $cart_item && ! self::is_product_prorated( $cart_item['data'] ) ) { // cart contains a sync

			$product_id = AWC_Subscription_Cart::get_items_product_id( $cart_item );

			if ( wc_price( 0 ) == $subscription_details['initial_amount'] && 0 == $subscription_details['trial_length'] ) {
				$subscription_details['initial_amount'] = '';
			}
		}

		return $subscription_details;
	}

	/**
	 * Let other functions know shipping should not be charged on the initial order when
	 * the cart contains a synchronised subscription and no other items which need shipping.
	 *
	 
	 * 
	 */
	public static function charge_shipping_up_front( $charge_shipping_up_front ) {
		

		// the cart contains only the synchronised subscription
		if ( true === $charge_shipping_up_front && self::cart_contains_synced_subscription() ) {

			// the cart contains only a subscription, see if the payment date is today and if not, then it doesn't need shipping
			if ( 1 == count( WC()->cart->cart_contents ) ) {

				foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
					if ( self::is_product_synced( $cart_item['data'] ) && ! self::is_product_prorated( $cart_item['data'] ) && ! self::is_today( self::calculate_first_payment_date( $cart_item['data'], 'timestamp' ) ) ) {
						$charge_shipping_up_front = false;
						break;
					}
				}

			// cart contains other items, see if any require shipping
			} else {

				$other_items_need_shipping = false;

				foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
					if ( ( ! AWC_Subscription_Products::awc_is_subscription( $cart_item['data'] ) || self::is_product_prorated( $cart_item['data'] ) ) && $cart_item['data']->needs_shipping() ) {
						$other_items_need_shipping = true;
					}
				}

				if ( false === $other_items_need_shipping ) {
					$charge_shipping_up_front = false;
				}
			}
		}

		return $charge_shipping_up_front;
	}

	/**
	 * Make sure anything requesting the first payment date for a synced subscription on the front-end receives
	 * a date which takes into account the day on which payments should be processed.
	 *
	 * This is necessary as the self::calculate_first_payment_date() is not called when the subscription is active
	 * (which it isn't until the first payment is completed and the subscription is activated).
	 *
	 
	 * 
	 */
	public static function get_first_payment_date( $first_payment_date, $order, $product_id, $type ) {
		

		$subscription = awc_get_subscription_from_key( $order . '_' . $product_id );

		if ( self::order_contains_synced_subscription( awc_get_objects_property( $order, 'id' ) ) && 1 >= $subscription->get_payment_count() ) {

			// Don't prematurely set the first payment date when manually adding a subscription from the admin
			if ( ! is_admin() || 'active' == $subscription->get_status() ) {

				$first_payment_timestamp = self::calculate_first_payment_date( $product_id, 'timestamp', awc_get_datetime_utc_string( awc_get_objects_property( $order, 'date_created' ) ) );

				if ( 0 != $first_payment_timestamp ) {
					$first_payment_date = ( 'mysql' == $type ) ? gmdate( 'Y-m-d H:i:s', $first_payment_timestamp ) : $first_payment_timestamp;
				}
			}
		}

		return $first_payment_date;
	}

	/**
	 * Tell anything hooking to 'woocommerce_subscriptions_calculated_next_payment_date'
	 * to use the synchronised first payment date as the next payment date (if the first
	 * payment date isn't today, meaning the first payment won't be charged today).
	 *
	 
	 * 
	 */
	public static function maybe_set_payment_date( $payment_date, $order, $product_id, $type ) {

		

		$first_payment_date = self::get_first_payment_date( $payment_date, $order, $product_id, 'timestamp' );

		if ( ! self::is_today( $first_payment_date ) ) {
			$payment_date = ( 'timestamp' == $type ) ? $first_payment_date : gmdate( 'Y-m-d H:i:s', $first_payment_date );
		}

		return $payment_date;
	}

	/**
	 * Check if a given order included a subscription that is synced to a certain day.
	 *
	 * Deprecated becasuse _order_contains_synced_subscription is no longer stored on the order @see self::subscription_contains_synced_product
	 *
	 * @param int $order_id The ID or a WC_Order item to check.
	 * @return bool Returns true if the order contains a synced subscription, otherwise, false.
	 
	 * 
	 */
	public static function order_contains_synced_subscription( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::subscription_contains_synced_product()' );

		if ( is_object( $order_id ) ) {
			$order_id = awc_get_objects_property( $order_id, 'id' );
		}

		return 'true' == get_post_meta( $order_id, '_order_contains_synced_subscription', true );
	}

	/**
	 * If the order being generated is for a synced subscription, keep a record of the syncing related meta data.
	 *
	 * Deprecated because _order_contains_synced_subscription is no longer stored on the order @see self::add_subscription_sync_meta
	 *
	 
	 * 
	 */
	public static function add_order_meta( $order_id, $posted ) {
		
		global $woocommerce;

		if ( $cart_item = self::cart_contains_synced_subscription() ) {
			update_post_meta( $order_id, '_order_contains_synced_subscription', 'true' );
		}
	}

	/**
	 * If the subscription being generated is synced, set the syncing related meta data correctly.
	 *
	 * Deprecated because editing a subscription's values is now done from the Edit Subscription screen.
	 *
	 
	 * 
	 */
	public static function prefill_order_item_meta( $item, $item_id ) {

		

		return $item;
	}

	/**
	 * Filters WC_Subscriptions_Order::get_sign_up_fee() to make sure the sign-up fee for a subscription product
	 * that is synchronised is returned correctly.
	 *
	 * @param float The initial sign-up fee charged when the subscription product in the order was first purchased, if any.
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return float The initial sign-up fee charged when the subscription product in the order was first purchased, if any.
	 
	 * 
	 */
	public static function get_sign_up_fee( $sign_up_fee, $order, $product_id, $non_subscription_total ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::get_synced_sign_up_fee' );

		if ( 'shop_order' == get_post_type( $order ) && self::order_contains_synced_subscription( awc_get_objects_property( $order, 'id' ) ) && WC_Subscriptions_Order::get_subscription_trial_length( $order ) < 1 ) {
			$sign_up_fee = max( WC_Subscriptions_Order::get_total_initial_payment( $order ) - $non_subscription_total, 0 );
		}

		return $sign_up_fee;
	}

	/**
	 * Check if the cart includes a subscription that needs to be prorated.
	 *
	 * @return bool Returns any item in the cart that is synced and requires proration, otherwise, false.
	 
	 * 
	 */
	public static function cart_contains_prorated_subscription() {
		
		$cart_contains_prorated_subscription = false;

		$synced_cart_item = self::cart_contains_synced_subscription();

		if ( false !== $synced_cart_item && self::is_product_prorated( $synced_cart_item['data'] ) ) {
			$cart_contains_prorated_subscription = $synced_cart_item;
		}

		return $cart_contains_prorated_subscription;
	}

	/**
	 * Maybe recalculate the trial end date for synced subscription products that contain the unnecessary
	 * "one day trial" period.
	 *
	 
	 * 
	 */
	public static function recalculate_trial_end_date( $trial_end_date, $recurring_cart, $product ) {
		_deprecated_function( __METHOD__, '2.0.14' );
		if ( self::is_product_synced( $product ) ) {
			$product_id  = awc_get_canonical_product_id( $product );
			$trial_end_date = AWC_Subscription_Products::get_trial_expiration_date( $product_id );
		}

		return $trial_end_date;
	}

	/**
	 * Maybe recalculate the end date for synced subscription products that contain the unnecessary
	 * "one day trial" period.
	 *
	 .9
	 * 
	 */
	public static function recalculate_end_date( $end_date, $recurring_cart, $product ) {
		_deprecated_function( __METHOD__, '2.0.14' );
		if ( self::is_product_synced( $product ) ) {
			$product_id  = awc_get_canonical_product_id( $product );
			$end_date = AWC_Subscription_Products::get_expiration_date( $product_id );
		}

		return $end_date;
	}

}
