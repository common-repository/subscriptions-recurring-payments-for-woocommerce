<?php
/**
 * Subscriptions Admin Class
 *
 */
class AWC_Subscriptions_Admin {

	/**
	 * The prefix for subscription settings
	 *
	 */
	public static $option_prefix = 'woocommerce_subscriptions';


	/**
     * The token.
     *
     * @var     string
     * @access  public
     */
    public static $token;

	/**
	 * Asset URL 
	 * @var 	string
	 * @access	public
	 */
	public static $assets_url;

	/**
	 * Store an instance of the list table
	 *
	 */
	private static $subscriptions_list_table;

	/**
	 * Store an instance of the list table
	 *
	 */
	private static $found_related_orders = false;

	/**
	 * Is meta boxes saved once?
	 *
	 * @var boolean
	 */
	private static $saved_product_meta = false;

	
	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 */
	public static function init() {
		self::$token = AWC_TOKEN;
		self::$assets_url =  plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/';

		// Add subscriptions to the product select box
		add_filter( 'product_type_selector', __CLASS__ . '::add_subscription_products_to_select' );

		// Special handling of downloadable and virtual products on the WooCommerce > Products screen.
		add_filter( 'product_type_selector', array( __CLASS__, 'add_downloadable_and_virtual_filters' ) );
		add_filter( 'request', array( __CLASS__, 'modify_downloadable_and_virtual_product_queries' ), 11 );

		// Add subscription pricing fields on edit product page
		add_action( 'woocommerce_product_options_general_product_data', __CLASS__ . '::subscription_pricing_fields' );

		// Add listener to clear our own transients when WooCommerce -> Clear Transients is
		// triggered from the admin panel
		add_action( 'woocommerce_page_wc-status', __CLASS__ . '::clear_subscriptions_transients' );

		// Add subscription shipping options on edit product page
		add_action( 'woocommerce_product_options_shipping', __CLASS__ . '::subscription_shipping_fields' );

		// And also on the variations section
		add_action( 'woocommerce_product_after_variable_attributes', __CLASS__ . '::variable_subscription_pricing_fields', 10, 3 );

		// Add bulk edit actions for variable subscription products
		add_action( 'woocommerce_variable_product_bulk_edit_actions', __CLASS__ . '::variable_subscription_bulk_edit_actions', 10 );

		// Save subscription meta when a subscription product is changed via bulk edit
		add_action( 'woocommerce_product_bulk_edit_save', __CLASS__ . '::bulk_edit_save_subscription_meta', 10 );

		// Save subscription meta only when a subscription product is saved, can't run on the "'woocommerce_process_product_meta_' . $product_type" action because we need to override some WC defaults
		add_action( 'save_post', __CLASS__ . '::save_subscription_meta', 11 );
		add_action( 'save_post', __CLASS__ . '::save_variable_subscription_meta', 11 );

		// Save variable subscription meta
		add_action( 'woocommerce_process_product_meta_variable-subscription', __CLASS__ . '::process_product_meta_variable_subscription' );
		add_action( 'woocommerce_save_product_variation', __CLASS__ . '::save_product_variation', 20, 2 );

		add_action( 'woocommerce_subscription_pre_update_status', __CLASS__ . '::check_customer_is_set', 10, 3 );

		add_action( 'product_variation_linked', __CLASS__ . '::set_variation_meta_defaults_on_bulk_add' );		

		add_filter( 'manage_users_columns', __CLASS__ . '::add_user_columns', 11, 1 );

		add_filter( 'manage_users_custom_column', __CLASS__ . '::user_column_values', 11, 3 ); // Fire after default to avoid being broken by plugins #doing_it_wrong

		add_action( 'admin_enqueue_scripts', __CLASS__ . '::enqueue_styles_scripts' );

		add_action( 'woocommerce_admin_field_informational', __CLASS__ . '::add_informational_admin_field' );

		add_filter( 'posts_where', array( __CLASS__, 'filter_orders' ) );

		add_filter( 'posts_where', array( __CLASS__, 'filter_orders_and_subscriptions_from_list' ) );

		add_filter( 'posts_where', array( __CLASS__, 'filter_paid_subscription_orders_for_user' ) );

		add_action( 'admin_notices', __CLASS__ . '::display_renewal_filter_notice' );

		add_shortcode( 'subscriptions', __CLASS__ . '::do_subscriptions_shortcode' );

		add_filter( 'set-screen-option', __CLASS__ . '::set_manage_subscriptions_screen_option', 10, 3 );

		add_filter( 'woocommerce_payment_gateways_setting_columns', array( __CLASS__, 'payment_gateways_renewal_column' ) );

		add_action( 'woocommerce_payment_gateways_setting_column_renewals', array( __CLASS__, 'payment_gateways_renewal_support' ) );

		// Do not display formatted order total on the Edit Order administration screen
		add_filter( 'woocommerce_get_formatted_order_total', __CLASS__ . '::maybe_remove_formatted_order_total_filter', 0, 2 );

		add_action( 'woocommerce_payment_gateways_settings', __CLASS__ . '::add_recurring_payment_gateway_information', 10, 1 );

		// Change text for when order items cannot be edited
		awc_add_woocommerce_dependent_action( 'woocommerce_admin_order_totals_after_total', array( __CLASS__, 'maybe_attach_gettext_callback' ), '4.0.0', '>' );
		awc_add_woocommerce_dependent_action( 'woocommerce_admin_order_totals_after_refunded', array( __CLASS__, 'maybe_attach_gettext_callback' ), '4.0.0', '<' );

		// Unhook gettext callback to prevent extra call impact
		add_action( 'woocommerce_order_item_add_action_buttons', __CLASS__ . '::maybe_unattach_gettext_callback', 10, 1 );

		// Add a reminder on the enable guest checkout setting that subscriptions still require an account
		add_filter( 'woocommerce_payment_gateways_settings', array( __CLASS__, 'add_guest_checkout_setting_note' ), 10, 1 );
		add_filter( 'woocommerce_account_settings', array( __CLASS__, 'add_guest_checkout_setting_note' ), 10, 1 );

		// Validate the product type change before other product changes are saved.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'validate_product_type_change' ), 5 );
	}

	/**
	 * Clear all transients data we have when the WooCommerce::Tools::Clear Transients action is triggered.
	 *
	 *
	 * @return null
	 */
	public static function clear_subscriptions_transients() {
		global $wpdb;
		if ( empty( $_GET['action'] ) || empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'debug_action' ) ) {
			return;
		}

		if ( wc_clean( $_GET['action'] ) === 'clear_transients' ) {
			$transients_to_delete = array(
				'wc_report_subscription_by_product',
				'wc_report_subscription_by_customer',
				'wc_report_subscription_events_by_date',
				'awc_report_subscription_by_product',
				'awc_report_subscription_by_customer',
				'awc_report_subscription_events_by_date',
				'awc_report_upcoming_recurring_revenue',
			);

			// Get all related order and subscription ranges transients
			$results = $wpdb->get_col( "SELECT DISTINCT `option_name`
				FROM `$wpdb->options`
				WHERE `option_name` LIKE '%asub-related-orders-to-%' OR `option_name` LIKE '%asub-sub-ranges-%'" );

			foreach ( $results as $column ) {
				$name = explode( 'transient_', $column, 2 );
				$transients_to_delete[] = $name[1];
			}

			foreach ( $transients_to_delete as $transient_to_delete ) {
				delete_transient( $transient_to_delete );
			}
		}
	}


	/**
	 * Add the 'subscriptions' product type to the WooCommerce product type select box.
	 *
	 * @param array
	 */
	public static function add_subscription_products_to_select( $product_types ) {
		$product_types['subscription']          = __( 'Simple subscription', 'subscriptions-recurring-payments-for-woocommerce' );
		$product_types['variable-subscription'] = __( 'Variable subscription (Pro Feature)', 'subscriptions-recurring-payments-for-woocommerce' );

		return $product_types;
	}

	/**
	 * Add options for downloadable and virtual subscription products to the product type selector on the WooCommerce products screen.
	 *
	 * @param  array 
	 * @return array
	 */
	public static function add_downloadable_and_virtual_filters( $product_types ) {
		global $typenow;

		if ( ! is_admin() || ! doing_action( 'restrict_manage_posts' ) || 'product' !== $typenow ) {
			return $product_types;
		}

		$product_options = array_reverse(
			array(
				'downloadable_subscription' => ( is_rtl() ? '&larr;' : '&rarr;' ) . ' ' . __( 'Downloadable', 'subscriptions-recurring-payments-for-woocommerce' ),
				'virtual_subscription'      => ( is_rtl() ? '&larr;' : '&rarr;' ) . ' ' . __( 'Virtual', 'subscriptions-recurring-payments-for-woocommerce' ),
			)
		);
		foreach ( $product_options as $key => $label ) {
			$product_types = awc_array_insert_after( 'subscription', $product_types, $key, $label );
		}

		return $product_types;
	}

	/**
	 * Modifies the main query on the WooCommerce products screen to correctly handle filtering by virtual and downloadable product types.
	 *
	 * @param  array 
	 * @return array 
	 */
	public static function modify_downloadable_and_virtual_product_queries( $query_vars ) {
		global $pagenow, $typenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || 'product' !== $typenow ) {
			return $query_vars;
		}

		$current_product_type = isset( $_REQUEST['product_type'] ) ? wc_clean( wp_unslash( $_REQUEST['product_type'] ) ) : false;

		if ( ! $current_product_type ) {
			return $query_vars;
		}

		if ( in_array( $current_product_type, array( 'downloadable', 'virtual' ) ) && ! isset( $query_vars['tax_query'] ) ) {
			// Do not include subscriptions when the default "Downloadable" or "Virtual" query for simple products is being executed.
			$query_vars['tax_query'] = array(
				array(
					'taxonomy' => 'product_type',
					'terms'    => array( 'subscription' ),
					'field'    => 'slug',
					'operator' => 'NOT IN',
				),
			);
		} elseif ( in_array( $current_product_type, array( 'downloadable_subscription', 'virtual_subscription' ) ) ) {
			// Limit query to subscription products when the "Downloadable" or "Virtual" choices under "Simple Subscription" are being used.
			$query_vars['meta_value'] = 'yes';
			$query_vars['meta_key'] = '_' . str_replace( '_subscription', '', $current_product_type );
			$query_vars['product_type'] = 'subscription';
		}

		return $query_vars;
	}

	/**
	 * Output the subscription specific pricing fields on the "Edit Product" admin page.
	 *
	 */
	public static function subscription_pricing_fields() {
		global $post;

		$chosen_price        = get_post_meta( $post->ID, '_subscription_price', true );
		$chosen_interval     = get_post_meta( $post->ID, '_subscription_period_interval', true );
		$chosen_trial_length = AWC_Subscription_Products::awc_get_trial_length( $post->ID );
		$chosen_trial_period = AWC_Subscription_Products::awc_get_trial_period( $post->ID );

		$price_tooltip = __( 'Choose the subscription price, billing interval and period.', 'subscriptions-recurring-payments-for-woocommerce' );
		
		$trial_tooltip = sprintf( _x( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'Trial period field tooltip on Edit Product administration screen', 'subscriptions-recurring-payments-for-woocommerce' ), self::awc_get_trial_period_validation_message() );

		// Set month as the default billing period
		if ( ! $chosen_period = get_post_meta( $post->ID, '_subscription_period', true ) ) {
			$chosen_period = 'month';
		}

		echo '<div class="options_group subscription_pricing show_if_subscription hidden">';

		// Subscription Price, Interval and Period
		?><p class="form-field _subscription_price_fields _subscription_price_field">
			<label for="_subscription_price">
				<?php
				// translators: %s: currency symbol.
				printf( esc_html__( 'Subscription price (%s)', 'subscriptions-recurring-payments-for-woocommerce' ), esc_html( get_woocommerce_currency_symbol() ) );
				?>
			</label>
			<span class="wrap">
				<input type="text" id="_subscription_price" name="_subscription_price" class="wc_input_price wc_input_subscription_price" placeholder="<?php echo esc_attr_x( 'e.g. 5.90', 'example price', 'subscriptions-recurring-payments-for-woocommerce' ); ?>" step="any" min="0" value="<?php echo esc_attr( wc_format_localized_price( $chosen_price ) ); ?>" />
				<label for="_subscription_period_interval" class="awc_hidden_label"><?php esc_html_e( 'Subscription interval', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
				<select id="_subscription_period_interval" name="_subscription_period_interval" class="wc_input_subscription_period_interval">
				<?php foreach ( awc_get_subscription_period_interval_strings() as $value => $label ) { ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $chosen_interval, true ) ?>><?php echo esc_html( $label ); ?></option>
				<?php } ?>
				</select>
				<label for="_subscription_period" class="awc_hidden_label"><?php esc_html_e( 'Subscription period', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
				<select id="_subscription_period" name="_subscription_period" class="wc_input_subscription_period last" >
				<?php foreach ( awc_get_subscription_period_strings() as $value => $label ) { ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $chosen_period, true ) ?>><?php echo esc_html( $label ); ?></option>
				<?php } ?>
				</select>
			</span>
			<?php echo awc_help_tip( $price_tooltip ); ?>
		</p><?php

		// Subscription Length
		woocommerce_wp_select(
			array(
				'id'          => '_subscription_length',
				'class'       => 'wc_input_subscription_length select short',
				'label'       => __( 'Expire after', 'subscriptions-recurring-payments-for-woocommerce' ),
				'options'     => awc_get_subscription_ranges( $chosen_period ),
				'desc_tip'    => true,
				'description' => __( 'Automatically expire the subscription after this length of time. This length is in addition to any free trial or amount of time provided before a synchronised first renewal date.', 'subscriptions-recurring-payments-for-woocommerce' ),
			)
		);

		// Sign-up Fee
		woocommerce_wp_text_input( array(
			'id'                => '_subscription_sign_up_fee',
			// Keep wc_input_subscription_intial_price for backward compatibility.
			'class'             => 'wc_input_subscription_intial_price wc_input_subscription_initial_price wc_input_price  short',
			// translators: %s is a currency symbol / code
			'label'             => sprintf( __( 'Sign-up fee (%s)', 'subscriptions-recurring-payments-for-woocommerce' ), get_woocommerce_currency_symbol() ),
			'placeholder'       => _x( 'e.g. 9.90', 'example price', 'subscriptions-recurring-payments-for-woocommerce' ),
			'description'       => __( 'Optionally include an amount to be charged at the outset of the subscription. The sign-up fee will be charged immediately, even if the product has a free trial or the payment dates are synced.', 'subscriptions-recurring-payments-for-woocommerce' ),
			'desc_tip'          => true,
			'type'              => 'text',
			'data_type'         => 'price',
			'custom_attributes' => array(
				'step' => 'any',
				'min'  => '0',
			),
		) );

		// Trial Length
		?><p class="form-field _subscription_trial_length_field">
			<label for="_subscription_trial_length"><?php esc_html_e( 'Free trial', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
			<span class="wrap">
				<input type="text" id="_subscription_trial_length" name="_subscription_trial_length" class="wc_input_subscription_trial_length" value="<?php echo esc_attr( $chosen_trial_length ); ?>" />
				<label for="_subscription_trial_period" class="awc_hidden_label"><?php esc_html_e( 'Subscription Trial Period', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
				<select id="_subscription_trial_period" name="_subscription_trial_period" class="wc_input_subscription_trial_period last" >
					<?php foreach ( awc_get_available_time_periods() as $value => $label ) { ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $chosen_trial_period, true ) ?>><?php echo esc_html( $label ); ?></option>
					<?php } ?>
				</select>
			</span>
			<?php echo awc_help_tip( $trial_tooltip ); ?>
		</p><?php

		do_action( 'woocommerce_subscriptions_product_options_pricing' );

		wp_nonce_field( 'awc_subscription_meta', '_asubnonce' );

		echo '</div>';
		echo '<div class="show_if_subscription clear"></div>';
	}

	/**
	 * Output subscription shipping options on the "Edit Product" admin screen
	 *
	 */
	public static function subscription_shipping_fields() {
		global $post;

		echo '</div>';
		echo '<div class="options_group subscription_one_time_shipping show_if_subscription show_if_variable-subscription omar1 hidden">';

		// Only one Subscription per customer
		woocommerce_wp_checkbox( array(
			'id'          => '_subscription_one_time_shipping',
			'label'       => __( 'One time shipping', 'subscriptions-recurring-payments-for-woocommerce' ),
			'description' => __( 'Shipping for subscription products is normally charged on the initial order and all renewal orders. Enable this to only charge shipping once on the initial order. Note: for this setting to be enabled the subscription must not have a free trial or a synced renewal date.', 'subscriptions-recurring-payments-for-woocommerce' ),
			'desc_tip'    => true,
		) );

		do_action( 'woocommerce_subscriptions_product_options_shipping' );

	}


	/**
	 * Output the subscription specific pricing fields on the "Edit Product" admin page.
	 *
	 */
	public static function variable_subscription_pricing_fields( $loop, $variation_data, $variation ) {
		global $thepostid;

		// When called via Ajax
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once( WC()->plugin_path() . '/admin/post-types/writepanels/writepanels-init.php' );
		}

		if ( ! isset( $thepostid ) ) {
			$thepostid = $variation->post_parent;
		}

		$variation_product = wc_get_product( $variation );
		$billing_period    = AWC_Subscription_Products::awc_get_period( $variation_product );

		if ( empty( $billing_period ) ) {
			$billing_period = 'month';
		}

		include( AWC_PATH . '/temp/variation-html.php' );

		wp_nonce_field( 'awc_subscription_variations', '_asubnonce_save_variations', false );

		do_action( 'woocommerce_variable_subscription_pricing', $loop, $variation_data, $variation );
		
		wp_enqueue_script( self::$token . '-productmeta' );
		wp_enqueue_style( self::$token . '-productmetaCSS' );
	}

	/**
	 * Output extra options in the Bulk Edit select box for editing Subscription terms.
	 *
	 */
	public static function variable_subscription_bulk_edit_actions() {
		global $post;

		if ( AWC_Subscription_Products::awc_is_subscription( $post->ID ) ) : ?>
			<optgroup label="<?php esc_attr_e( 'Subscription pricing', 'subscriptions-recurring-payments-for-woocommerce' ); ?>">
				<option value="variable_subscription_sign_up_fee"><?php esc_html_e( 'Subscription sign-up fee', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
				<option value="variable_subscription_period_interval"><?php esc_html_e( 'Subscription billing interval', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
				<option value="variable_subscription_period"><?php esc_html_e( 'Subscription period', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
				<option value="variable_subscription_length"><?php esc_html_e( 'Expire after', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
				<option value="variable_subscription_trial_length"><?php esc_html_e( 'Free trial length', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
				<option value="variable_subscription_trial_period"><?php esc_html_e( 'Free trial period', 'subscriptions-recurring-payments-for-woocommerce' ); ?></option>
			</optgroup>
		<?php endif;
	}

	/**
	 * Save meta data for simple subscription product type when the "Edit Product" form is submitted.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 */
	public static function save_subscription_meta( $post_id ) {

		if ( empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) || false === self::is_subscription_product_save_request( $post_id, apply_filters( 'woocommerce_subscription_product_types', array( AWC_Subscriptions::$name ) ) ) ) {
			return;
		}

		$subscription_price = isset( $_REQUEST['_subscription_price'] ) ? wc_format_decimal( $_REQUEST['_subscription_price'] ) : '';
		$sale_price         = wc_format_decimal( $_REQUEST['_sale_price'] );

		update_post_meta( $post_id, '_subscription_price', $subscription_price );

		// Set sale details - these are ignored by WC core for the subscription product type
		update_post_meta( $post_id, '_regular_price', $subscription_price );
		update_post_meta( $post_id, '_sale_price', $sale_price );

		$site_offset = get_option( 'gmt_offset' ) * 3600;

		// Save the timestamps in UTC time, the way WC does it.
		$date_from = ( ! empty( $_POST['_sale_price_dates_from'] ) ) ? awc_date_to_time( $_POST['_sale_price_dates_from'] ) - $site_offset : '';
		$date_to   = ( ! empty( $_POST['_sale_price_dates_to'] ) ) ? awc_date_to_time( $_POST['_sale_price_dates_to'] ) - $site_offset : '';

		$now = gmdate( 'U' );

		if ( ! empty( $date_to ) && empty( $date_from ) ) {
			$date_from = $now;
		}

		update_post_meta( $post_id, '_sale_price_dates_from', $date_from );
		update_post_meta( $post_id, '_sale_price_dates_to', $date_to );

		// Update price if on sale
		if ( '' !== $sale_price && ( ( empty( $date_to ) && empty( $date_from ) ) || ( $date_from < $now && ( empty( $date_to ) || $date_to > $now ) ) ) ) {
			$price = $sale_price;
		} else {
			$price = $subscription_price;
		}

		update_post_meta( $post_id, '_price', stripslashes( $price ) );

		// Make sure trial period is within allowable range
		$subscription_ranges = awc_get_subscription_ranges();

		$max_trial_length = count( $subscription_ranges[ $_POST['_subscription_trial_period'] ] ) - 1;

		$_POST['_subscription_trial_length'] = absint( $_POST['_subscription_trial_length'] );

		if ( $_POST['_subscription_trial_length'] > $max_trial_length ) {
			$_POST['_subscription_trial_length'] = $max_trial_length;
		}

		update_post_meta( $post_id, '_subscription_trial_length', $_POST['_subscription_trial_length'] );

		$_REQUEST['_subscription_sign_up_fee']       = wc_format_decimal( $_REQUEST['_subscription_sign_up_fee'] );
		$_REQUEST['_subscription_one_time_shipping'] = isset( $_REQUEST['_subscription_one_time_shipping'] ) ? 'yes' : 'no';

		$subscription_fields = array(
			'_subscription_sign_up_fee',
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_trial_period',
			'_subscription_limit',
			'_subscription_one_time_shipping',
		);

		foreach ( $subscription_fields as $field_name ) {
			if ( isset( $_REQUEST[ $field_name ] ) ) {
				update_post_meta( $post_id, $field_name, stripslashes( $_REQUEST[ $field_name ] ) );
			}
		}

		// To prevent running this function on multiple save_post triggered events per update. Similar to WC_Admin_Meta_Boxes:$saved_meta_boxes implementation.
		self::$saved_product_meta = true;
	}

	/**
	 * Save meta data for variable subscription product type when the "Edit Product" form is submitted.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 */
	public static function save_variable_subscription_meta( $post_id ) {

		if ( empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) || false === self::is_subscription_product_save_request( $post_id, apply_filters( 'woocommerce_subscription_variable_product_types', array( 'variable-subscription' ) ) ) ) {
			return;
		}

		if ( isset( $_REQUEST['_subscription_limit'] ) ) {
			update_post_meta( $post_id, '_subscription_limit', stripslashes( $_REQUEST['_subscription_limit'] ) );
		}

		update_post_meta( $post_id, '_subscription_one_time_shipping', stripslashes( isset( $_REQUEST['_subscription_one_time_shipping'] ) ? 'yes' : 'no' ) );

		// To prevent running this function on multiple save_post triggered events per update. Similar to WC_Admin_Meta_Boxes:$saved_meta_boxes implementation.
		self::$saved_product_meta = true;
	}

	/**
	 * Calculate and set a simple subscription's prices when edited via the bulk edit
	 *
	 * @param object $product An instance of a WC_Product_* object.
	 * @return null
	 
	 */
	public static function bulk_edit_save_subscription_meta( $product ) {

		if ( ! $product->is_type( 'subscription' ) ) {
			return;
		}

		$price_changed = false;

		$old_regular_price = $product->get_regular_price();
		$old_sale_price    = $product->get_sale_price();

		if ( ! empty( $_REQUEST['change_regular_price'] ) ) {

			$change_regular_price = absint( $_REQUEST['change_regular_price'] );
			$regular_price = esc_attr( stripslashes( $_REQUEST['_regular_price'] ) );

			switch ( $change_regular_price ) {
				case 1:
					$new_price = $regular_price;
				break;
				case 2:
					if ( strstr( $regular_price, '%' ) ) {
						$percent = str_replace( '%', '', $regular_price ) / 100;
						$new_price = $old_regular_price + ( $old_regular_price * $percent );
					} else {
						$new_price = $old_regular_price + $regular_price;
					}
				break;
				case 3:
					if ( strstr( $regular_price, '%' ) ) {
						$percent = str_replace( '%', '', $regular_price ) / 100;
						$new_price = $old_regular_price - ( $old_regular_price * $percent );
					} else {
						$new_price = $old_regular_price - $regular_price;
					}
				break;
			}

			if ( isset( $new_price ) && $new_price != $old_regular_price ) {
				$price_changed = true;
				awc_set_objects_property( $product, 'regular_price', $new_price );
				awc_set_objects_property( $product, 'subscription_price', $new_price );
			}
		}

		if ( ! empty( $_REQUEST['change_sale_price'] ) ) {

			$change_sale_price = absint( $_REQUEST['change_sale_price'] );
			$sale_price = esc_attr( stripslashes( $_REQUEST['_sale_price'] ) );

			switch ( $change_sale_price ) {
				case 1:
					$new_price = $sale_price;
				break;
				case 2:
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $old_sale_price + ( $old_sale_price * $percent );
					} else {
						$new_price = $old_sale_price + $sale_price;
					}
				break;
				case 3:
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $old_sale_price - ( $old_sale_price * $percent );
					} else {
						$new_price = $old_sale_price - $sale_price;
					}
				break;
				case 4:
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $product->get_regular_price() - ( $product->get_regular_price() * $percent );
					} else {
						$new_price = $product->get_regular_price() - $sale_price;
					}
				break;
			}

			if ( isset( $new_price ) && $new_price != $old_sale_price ) {
				$price_changed = true;
				awc_set_objects_property( $product, 'sale_price', $new_price );
			}
		}

		if ( $price_changed ) {
			awc_set_objects_property( $product, 'sale_price_dates_from', '' );
			awc_set_objects_property( $product, 'sale_price_dates_to', '' );

			if ( $product->get_regular_price() < $product->get_sale_price() ) {
				awc_set_objects_property( $product, 'sale_price', '' );
			}

			if ( $product->get_sale_price() ) {
				awc_set_objects_property( $product, 'price', $product->get_sale_price() );
			} else {
				awc_set_objects_property( $product, 'price', $product->get_regular_price() );
			}
		}
	}

	/**
	 * Save a variable subscription's details when the edit product page is submitted for a variable
	 * subscription product type (or the bulk edit product is saved).
	 *
	 * @param int $post_id ID of the parent WC_Product_Variable_Subscription
	 * @return null
	 
	 */
	public static function process_product_meta_variable_subscription( $post_id ) {

		if ( ! AWC_Subscription_Products::awc_is_subscription( $post_id ) || empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) ) {
			return;
		}

		// Make sure WooCommerce calculates correct prices
		$_POST['variable_regular_price'] = isset( $_POST['variable_subscription_price'] ) ? $_POST['variable_subscription_price'] : 0;

		// Sync the min variation price
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			$variable_subscription = wc_get_product( $post_id );
			$variable_subscription->variable_product_sync();
		} else {
			WC_Product_Variable::sync( $post_id );
		}
	}

	/**
	 * Save meta info for subscription variations
	 *
	 * @param int $variation_id
	 * @param int $i
	 * return void
	 
	 */
	public static function save_product_variation( $variation_id, $index ) {

		// update_option( 'tests', array(
        //     'variation_id' => $variation_id,
        //     'is_subscribe' => AWC_Subscription_Products::awc_is_subscription( $variation_id ), 
        //     'empty_nonce' =>  $_POST['_asubnonce_save_variations'], 
        //     'varify_nonce' => wp_verify_nonce( $_POST['_asubnonce_save_variations'], 'awc_subscription_variations' ), 
		// 	'posts' => $_POST
        // ) );


		if ( ! AWC_Subscription_Products::awc_is_subscription( $variation_id ) || empty( $_POST['_asubnonce_save_variations'] ) || ! wp_verify_nonce( $_POST['_asubnonce_save_variations'], 'awc_subscription_variations' ) ) {
			return;
		}

		if ( isset( $_POST['variable_subscription_sign_up_fee'][ $index ] ) ) {
			$subscription_sign_up_fee = wc_format_decimal( $_POST['variable_subscription_sign_up_fee'][ $index ] );
			update_post_meta( $variation_id, '_subscription_sign_up_fee', $subscription_sign_up_fee );
		}

		if ( isset( $_POST['variable_subscription_price'][ $index ] ) ) {
			$subscription_price = wc_format_decimal( $_POST['variable_subscription_price'][ $index ] );
			update_post_meta( $variation_id, '_subscription_price', $subscription_price );
			update_post_meta( $variation_id, '_regular_price', $subscription_price );
		}

		// Make sure trial period is within allowable range
		$subscription_ranges = awc_get_subscription_ranges();
		$max_trial_length    = count( $subscription_ranges[ $_POST['variable_subscription_trial_period'][ $index ] ] ) - 1;

		$_POST['variable_subscription_trial_length'][ $index ] = absint( $_POST['variable_subscription_trial_length'][ $index ] );

		if ( $_POST['variable_subscription_trial_length'][ $index ] > $max_trial_length ) {
			$_POST['variable_subscription_trial_length'][ $index ] = $max_trial_length;
		}

		// Work around a WPML bug which means 'variable_subscription_trial_period' is not set when using "Edit Product" as the product translation interface
		if ( $_POST['variable_subscription_trial_length'][ $index ] < 0 ) {
			$_POST['variable_subscription_trial_length'][ $index ] = 0;
		}

		$subscription_fields = array(
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_trial_period',
			'_subscription_trial_length',
		);

		foreach ( $subscription_fields as $field_name ) {
			if ( isset( $_POST[ 'variable' . $field_name ][ $index ] ) ) {
				update_post_meta( $variation_id, $field_name, wc_clean( $_POST[ 'variable' . $field_name ][ $index ] ) );
			}
		}
	}

	/**
	 * Make sure when saving a subscription via the admin to activate it, it has a valid customer set on it.
	 *
	 * When you click "Add New Subscription", the status is already going to be pending to begin with. This will prevent
	 * changing the status to anything else besides pending if no customer is specified, or the customer specified is
	 * not a valid WP_User.
	 *
	 * Hooked into `woocommerce_subscription_pre_update_status`
	 *
	 * @param string $old_status Previous status of the subscription in update_status
	 * @param string $new_status New status of the subscription in update_status
	 * @param WC_Subscription $subscription The subscription being saved
	 *
	 * @return null
	 * @throws Exception in case there was no user found / there's no customer attached to it
	 */
	public static function check_customer_is_set( $old_status, $new_status, $subscription ) {
		global $post;

		if ( is_admin() && 'active' == $new_status && isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) && isset( $_POST['customer_user'] ) && ! empty( $post ) && 'shop_subscription' === $post->post_type ) {

			$user = new WP_User( absint( $_POST['customer_user'] ) );

			if ( 0 === $user->ID ) {
				// translators: %s: subscription status.
				throw new Exception( sprintf( __( 'Unable to change subscription status to "%s". Please assign a customer to the subscription to activate it.', 'subscriptions-recurring-payments-for-woocommerce' ), $new_status ) );
			}
		}
	}

	/**
	 * Set default values for subscription dropdown fields when bulk adding variations to fix issue #1342
	 *
	 * @param int $variation_id ID the post_id of the variation being added
	 * @return null
	 */
	public static function set_variation_meta_defaults_on_bulk_add( $variation_id ) {

		if ( ! empty( $variation_id ) ) {
			update_post_meta( $variation_id, '_subscription_period', 'month' );
			update_post_meta( $variation_id, '_subscription_period_interval', '1' );
			update_post_meta( $variation_id, '_subscription_length', '0' );
			update_post_meta( $variation_id, '_subscription_trial_period', 'month' );
		}
	}

	/**
	 * Adds all necessary admin styles.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 
	 */
	public static function enqueue_styles_scripts() {
		global $post;

		// Get admin screen id
		$screen = get_current_screen();

		$is_woocommerce_screen = in_array( $screen->id, array( 'product', 'edit-shop_order', 'shop_order', 'edit-shop_subscription', 'shop_subscription', 'users', 'woocommerce_page_wc-settings' ) );
		$is_activation_screen  = (bool) get_transient( AWC_Subscriptions::$activation_transient );
		wp_register_style( self::$token . '-productmetaCSS', esc_url(self::$assets_url) . 'css/awc_product_meta.css', array(), AWC_Subscriptions::$version, 'all' );
		wp_register_style( self::$token . '-subscriptionPaymentCSS', esc_url(self::$assets_url) . 'css/admin_payment.css', array(), AWC_Subscriptions::$version, 'all' );

		if ( $is_woocommerce_screen ) {

			$dependencies = array( 'jquery' );

			$woocommerce_admin_script_handle     = 'wc-admin-meta-boxes';
			$trashing_subscription_order_warning = __( 'Trashing this order will also trash the subscriptions purchased with the order.', 'subscriptions-recurring-payments-for-woocommerce' );

			if ( $screen->id == 'product' ) {
				$dependencies[] = $woocommerce_admin_script_handle;
				$dependencies[] = 'wc-admin-product-meta-boxes';
				$dependencies[] = 'wc-admin-variation-meta-boxes';

				$script_params = array(
					'productType'               => AWC_Subscriptions::$name,
					'trialPeriodSingular'       => awc_get_available_time_periods(),
					'trialPeriodPlurals'        => awc_get_available_time_periods( 'plural' ),
					'subscriptionLengths'       => awc_get_subscription_ranges(),
					'trialTooLongMessages'      => self::awc_get_trial_period_validation_message( 'separate' ),
					'bulkEditPeriodMessage'     => __( 'Enter the new period, either day, week, month or year:', 'subscriptions-recurring-payments-for-woocommerce' ),
					'bulkEditLengthMessage'     => __( 'Enter a new length (e.g. 5):', 'subscriptions-recurring-payments-for-woocommerce' ),
					'bulkEditIntervalhMessage'  => __( 'Enter a new interval as a single number (e.g. to charge every 2nd month, enter 2):', 'subscriptions-recurring-payments-for-woocommerce' ),
					'bulkDeleteOptionLabel'     => __( 'Delete all variations without a subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
					'oneTimeShippingCheckNonce' => wp_create_nonce( 'one_time_shipping' ),
					'productHasSubscriptions'   => ! awc_is_large_site() && awc_get_product_subscriptions( $post->ID, 'ids', array( 'limit' => 1 ) ) ? 'yes' : 'no',
					'productTypeWarning'        => self::get_change_product_type_warning(),
					'subscriptionProductCount'	=> AWC_Subscription_Products::awc_subscriptions_total('subscription')
				);
			} elseif ( 'edit-shop_order' == $screen->id ) {
				$script_params = array(
					'bulkTrashWarning' => __( "You are about to trash one or more orders which contain a subscription.\n\nTrashing the orders will also trash the subscriptions purchased with these orders.", 'subscriptions-recurring-payments-for-woocommerce' ),
					'trashWarning'     => $trashing_subscription_order_warning,
				);
			} elseif ( 'shop_order' == $screen->id ) {
				$dependencies[] = $woocommerce_admin_script_handle;
				$dependencies[] = 'wc-admin-order-meta-boxes';

				if ( AWC_Subscriptions::is_woocommerce_pre( '2.6' ) ) {
					$dependencies[] = 'wc-admin-order-meta-boxes-modal';
				}

				$script_params = array(
					'trashWarning'      => $trashing_subscription_order_warning,
					'changeMetaWarning' => __( "WARNING: Bad things are about to happen!\n\nThe payment gateway used to purchase this subscription does not support modifying a subscription's details.\n\nChanges to the billing period, recurring discount, recurring tax or recurring total may not be reflected in the amount charged by the payment gateway.", 'subscriptions-recurring-payments-for-woocommerce' ),
					'removeItemWarning' => __( 'You are deleting a subscription item. You will also need to manually cancel and trash the subscription on the Manage Subscriptions screen.', 'subscriptions-recurring-payments-for-woocommerce' ),
					'roundAtSubtotal'   => esc_attr( get_option( 'woocommerce_tax_round_at_subtotal' ) ),
					'EditOrderNonce'    => wp_create_nonce( 'subscriptions-recurring-payments-for-woocommerce' ),
					'postId'            => $post->ID,
				);
			} elseif ( 'users' == $screen->id ) {
				$script_params = array(
					'deleteUserWarning' => __( "Warning: Deleting a user will also delete the user's subscriptions. The user's orders will remain but be reassigned to the 'Guest' user.\n\nDo you want to continue to delete this user and any associated subscriptions?", 'subscriptions-recurring-payments-for-woocommerce' ),
				);
			} elseif ( 'woocommerce_page_wc-settings' === $screen->id ) {
				$script_params = array(
					'enablePayPalWarning' => __( 'PayPal Standard has a number of limitations and does not support all subscription features.', 'subscriptions-recurring-payments-for-woocommerce' ) . "\n\n" . __( 'Because of this, it is not recommended as a payment method for Subscriptions unless it is the only available option for your country.', 'subscriptions-recurring-payments-for-woocommerce' ),
				);
			}

			$script_params['ajaxLoaderImage'] = WC()->plugin_url() . '/assets/images/ajax-loader.gif';
			$script_params['ajaxUrl']         = admin_url( 'admin-ajax.php' );
			$script_params['isWCPre24']       = var_export( AWC_Subscriptions::is_woocommerce_pre( '2.4' ), true );

			wp_enqueue_script( 'woocommerce_subscriptions_admin', self::$assets_url . 'js/backend/awc_product_backend.js', $dependencies, time() );
			wp_localize_script( 'woocommerce_subscriptions_admin', 'asububscriptions', apply_filters( 'woocommerce_subscriptions_admin_script_parameters', $script_params ) );

			

			// Maybe add the pointers for first timers
			if ( isset( $_GET['subscription_pointers'] ) && self::show_user_pointers() ) {

				$dependencies[] = 'wp-pointer';

				$pointer_script_params = array(
					// translators: placeholders are for HTML tags. They are 1$: "<h3>", 2$: "</h3>", 3$: "<p>", 4$: "<em>", 5$: "</em>", 6$: "<em>", 7$: "</em>", 8$: "</p>"
					'typePointerContent'  => sprintf( _x( '%1$sChoose Subscription%2$s%3$sThe WooCommerce Subscriptions extension adds two new subscription product types - %4$sSimple subscription%5$s and %6$sVariable subscription%7$s.%8$s', 'used in admin pointer script params in javascript as type pointer content', 'subscriptions-recurring-payments-for-woocommerce' ), '<h3>', '</h3>', '<p>', '<em>', '</em>', '<em>', '</em>', '</p>' ),
					// translators: placeholders are for HTML tags. They are 1$: "<h3>", 2$: "</h3>", 3$: "<p>", 4$: "</p>"
					'pricePointerContent' => sprintf( _x( '%1$sSet a Price%2$s%3$sSubscription prices are a little different to other product prices. For a subscription, you can set a billing period, length, sign-up fee and free trial.%4$s', 'used in admin pointer script params in javascript as price pointer content', 'subscriptions-recurring-payments-for-woocommerce' ), '<h3>', '</h3>', '<p>', '</p>' ),
				);

				wp_enqueue_script( 'woocommerce_subscriptions_admin_pointers', self::$assets_url . 'js/admin/admin-pointers.js', $dependencies, AWC_Subscriptions::$version );

				wp_localize_script( 'woocommerce_subscriptions_admin_pointers', 'asubPointers', apply_filters( 'woocommerce_subscriptions_admin_pointer_script_parameters', $pointer_script_params ) );

				wp_enqueue_style( 'wp-pointer' );
			}
		}

		// Maybe add the admin notice
		if ( $is_activation_screen ) {

			$woocommerce_plugin_dir_file = self::get_woocommerce_plugin_dir_file();

			// check if subscription products exist in the store
			$subscription_product = wc_get_products(
				array(
					'type'   => array( 'subscription', 'variable-subscription' ),
					'limit'  => 1,
					'return' => 'ids',
				)
			);

			if ( ! empty( $woocommerce_plugin_dir_file ) && 0 == count( $subscription_product ) ) {

				wp_enqueue_style( 'woocommerce-activation', plugins_url( '/assets/css/activation.css', $woocommerce_plugin_dir_file ), array(), AWC_Subscriptions::$version );

				
			}
			delete_transient( AWC_Subscriptions::$activation_transient );
		}

		if ( $is_woocommerce_screen || $is_activation_screen || 'edit-product' == $screen->id || ( isset( $_GET['page'], $_GET['tab'] ) && 'wc-reports' === $_GET['page'] && 'subscriptions' === $_GET['tab'] ) ) {
			
			// echo 'hi omar f <br/>' . $screen->id . '<br/>';

			wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), AWC_Subscriptions::$version );
			
			if(in_array($screen->id, array('product', 'edit-product')))
				wp_enqueue_style( self::$token . '-productmetaCSS' );

			wp_enqueue_style( self::$token . '-subscriptionPaymentCSS' );

		}

		if ( in_array( $screen->id, array( 'shop_order', 'edit-shop_subscription', 'shop_subscription' ) ) && AWC_Subscriptions::is_woocommerce_pre( '3.3' ) ) {
			wp_enqueue_style( 'wc_subscriptions_statuses_admin', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/css/admin-order-statuses.css', array( 'woocommerce_admin_styles' ), AWC_Subscriptions::$version );
		}
	}

	/**
	 * Add the "Active Subscriber?" column to the User's admin table
	 */
	public static function add_user_columns( $columns ) {

		if ( current_user_can( 'manage_woocommerce' ) ) {
			// Move Active Subscriber before Orders for aesthetics
			$last_column = array_slice( $columns, -1, 1, true );
			array_pop( $columns );
			$columns['woocommerce_active_subscriber'] = __( 'Active subscriber?', 'subscriptions-recurring-payments-for-woocommerce' );
			$columns += $last_column;
		}

		return $columns;
	}

	/**
	 * Hooked to the users table to display a check mark if a given user has an active subscription.
	 *
	 * @param string $value The string to output in the column specified with $column_name
	 * @param string $column_name The string key for the current column in an admin table
	 * @param int $user_id The ID of the user to which this row relates
	 * @return string $value A check mark if the column is the active_subscriber column and the user has an active subscription.
	 
	 */
	public static function user_column_values( $value, $column_name, $user_id ) {

		if ( 'woocommerce_active_subscriber' == $column_name ) {
			if ( awc_user_has_subscription( $user_id, '', 'active' ) ) {
				$value = '<div class="active-subscriber"></div>';
			} else {
				$value = '<div class="inactive-subscriber">-</div>';
			}
		}

		return $value;
	}


	/**
	 * Outputs the Subscription Management admin page with a sortable @see WC_Subscriptions_List_Table used to
	 * display all the subscriptions that have been purchased.
	 *
	 * @uses WC_Subscriptions_List_Table
	 
	 */
	public static function subscriptions_management_page() {

		$subscriptions_table = self::get_subscriptions_list_table();
		$subscriptions_table->prepare_items(); ?>
<div class="wrap">
	<div id="icon-woocommerce" class="icon32-woocommerce-users icon32"><br/></div>
	<h2><?php esc_html_e( 'Manage Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h2>
	<?php $subscriptions_table->messages(); ?>
	<?php $subscriptions_table->views(); ?>
	<form id="subscriptions-search" action="" method="get"><?php // Don't send all the subscription meta across ?>
		<?php $subscriptions_table->search_box( __( 'Search Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ), 'subscription' ); ?>
		<input type="hidden" name="page" value="subscriptions" />
		<?php if ( isset( $_REQUEST['status'] ) ) { ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $_REQUEST['status'] ); ?>" />
		<?php } ?>
	</form>
	<form id="subscriptions-filter" action="" method="get">
		<?php $subscriptions_table->display(); ?>
	</form>
</div>
		<?php
	}

	/**
	 * Outputs the screen options on the Subscription Management admin page.
	 *
	 
	 */
	public static function add_manage_subscriptions_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ),
				'default' => 10,
				'option'  => self::$option_prefix . '_admin_per_page',
			)
		);
	}

	/**
	 * Sets the correct value for screen options on the Subscription Management admin page.
	 *
	 
	 */
	public static function set_manage_subscriptions_screen_option( $status, $option, $value ) {

		if ( self::$option_prefix . '_admin_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Returns the columns for the Manage Subscriptions table, specifically used for adding the
	 * show/hide column screen options.
	 *
	 
	 */
	public static function get_subscription_table_columns( $columns ) {

		$subscriptions_table = self::get_subscriptions_list_table();

		return array_merge( $subscriptions_table->get_columns(), $columns );
	}

	/**
	 * Returns the columns for the Manage Subscriptions table, specifically used for adding the
	 * show/hide column screen options.
	 *
	 
	 */
	public static function get_subscriptions_list_table() {

		if ( ! isset( self::$subscriptions_list_table ) ) {
			self::$subscriptions_list_table = new WC_Subscriptions_List_Table();
		}

		return self::$subscriptions_list_table;
	}



	/**
	 * Displays instructional information for a WooCommerce setting.
	 *
	 
	 */
	public static function add_informational_admin_field( $field_details ) {

		if ( isset( $field_details['name'] ) && $field_details['name'] ) {
			echo '<h3>' . esc_html( $field_details['name'] ) . '</h3>';
		}

		if ( isset( $field_details['desc'] ) && $field_details['desc'] ) {
			echo wp_kses_post( wpautop( wptexturize( $field_details['desc'] ) ) );
		}
	}



	/**
	 * Checks whether a user should be shown pointers or not, based on whether a user has previously dismissed pointers.
	 *
	 
	 */
	public static function show_user_pointers() {
		// Get dismissed pointers
		$dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );

		// Pointer has been dismissed
		if ( in_array( 'awc_pointer', $dismissed ) ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Returns a URL for adding/editing a subscription, which special parameters to define whether pointers should be shown.
	 *
	 * The 'select_subscription' flag is picked up by JavaScript to set the value of the product type to "Subscription".
	 *
	 
	 */
	public static function add_subscription_url( $show_pointers = true ) {
		$add_subscription_url = admin_url( 'post-new.php?post_type=product&select_subscription=true' );

		if ( true == $show_pointers ) {
			$add_subscription_url = add_query_arg( 'subscription_pointers', 'true', $add_subscription_url );
		}

		return $add_subscription_url;
	}


	
	/**
	 * Searches through the list of active plugins to find WooCommerce. Just in case
	 * WooCommerce resides in a folder other than /woocommerce/
	 *
	 */
	public static function get_woocommerce_plugin_dir_file() {

		$woocommerce_plugin_file = '';

		foreach ( get_option( 'active_plugins', array() ) as $plugin ) {
			if ( substr( $plugin, strlen( '/woocommerce.php' ) * -1 ) === '/woocommerce.php' ) {
				$woocommerce_plugin_file = $plugin;
				break;
			}
		}

		return $woocommerce_plugin_file;
	}

	/**
	 * Filter the "Orders" list to show only orders associated with a specific subscription.
	 *
	 * @param string $where
	 * @return string
	 */
	public static function filter_orders( $where ) {
		global $typenow, $wpdb;

		if ( is_admin() && 'shop_order' === $typenow ) {

			if ( isset( $_GET['_subscription_related_orders'] ) && $_GET['_subscription_related_orders'] > 0 ) {

				$subscription_id = absint( $_GET['_subscription_related_orders'] );

				$subscription = awc_get_subscription( $subscription_id );

				if ( ! awc_is_subscription( $subscription ) ) {
					// translators: placeholder is a number
					AWC_Backend::awc_add_admin_notice( sprintf( __( 'We can\'t find a subscription with ID #%d. Perhaps it was deleted?', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription_id ), 'error' );
					$where .= " AND {$wpdb->posts}.ID = 0";
				} else {
					self::$found_related_orders = true;
					$where .= sprintf( " AND {$wpdb->posts}.ID IN (%s)", implode( ',', array_map( 'absint', array_unique( $subscription->get_related_orders( 'ids' ) ) ) ) );
				}
			}
		}

		return $where;
	}

	/**
	 * Filters the Admin orders and subscriptions table results based on a list of IDs returned by a report query.
	 *
	 
	 *
	 * @param string $where The query WHERE clause.
	 * @return string $where
	 */
	public static function filter_orders_and_subscriptions_from_list( $where ) {
		global $typenow, $wpdb;

		if ( ! is_admin() || ! in_array( $typenow, array( 'shop_subscription', 'shop_order' ) ) || ! isset( $_GET['_report'] ) ) {
			return $where;
		}

		// Map the order or subscription type to their respective keys and type key.
		$object_type      = 'shop_order' === $typenow ? 'order' : 'subscription';
		$cache_report_key = isset( $_GET[ "_{$object_type}s_list_key" ] ) ? $_GET[ "_{$object_type}s_list_key" ] : '';

		// If the report key or report arg is empty exit early.
		if ( empty( $cache_report_key ) || empty( $_GET['_report'] ) ) {
			$where .= " AND {$wpdb->posts}.ID = 0";
			return $where;
		}

		$cache = get_transient( $_GET['_report'] );

		// Display an admin notice if we cannot find the report data requested.
		if ( ! isset( $cache[ $cache_report_key ] ) ) {
			$admin_notice = new awc_Admin_Notice( 'error' );
			$admin_notice->set_simple_content( sprintf(
				/* translators: Placeholders are opening and closing link tags. */
				__( 'We weren\'t able to locate the set of report results you requested. Please regenerate the link from the %1$sSubscription Reports screen%2$s.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date' ) ) . '">',
				'</a>'
			) );
			$admin_notice->display();

			$where .= " AND {$wpdb->posts}.ID = 0";
			return $where;
		}

		$results = $cache[ $cache_report_key ];

		// The current subscriptions count report will include the specific result (the subscriptions active on the last day) that should be used to generate the subscription list.
		if ( ! empty( $_GET['_data_key'] ) && isset( $results[ (int) $_GET['_data_key'] ] ) ) {
			$results = array( $results[ (int) $_GET['_data_key'] ] );
		}

		$ids = explode( ',', implode( ',', wp_list_pluck( $results, "{$object_type}_ids", true ) ) );

		// $format = '%d, %d, %d, %d, %d, [...]'
		$format = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID IN ($format)", $ids );

		return $where;
	}

	/**
	 * Filter the "Orders" list to show only paid subscription orders for a particular user
	 *
	 * @param string $where
	 * @return string
	 
	 */
	public static function filter_paid_subscription_orders_for_user( $where ) {
		global $typenow, $wpdb;

		if ( ! is_admin() || 'shop_order' !== $typenow || ! isset( $_GET['_paid_subscription_orders_for_customer_user'] ) || 0 == $_GET['_paid_subscription_orders_for_customer_user'] ) {
			return $where;
		}

		$user_id = $_GET['_paid_subscription_orders_for_customer_user'];

		// Unset the GET arg so that it doesn't interfere with the query for user's subscriptions.
		unset( $_GET['_paid_subscription_orders_for_customer_user'] );

		$users_subscriptions = awc_get_users_subscriptions( $user_id );

		$users_subscription_orders = array();

		foreach ( $users_subscriptions as $subscription ) {
			$users_subscription_orders = array_merge( $users_subscription_orders, $subscription->get_related_orders( 'ids' ) );
		}

		if ( empty( $users_subscription_orders ) ) {
			AWC_Backend::awc_add_admin_notice( sprintf( __( 'We can\'t find a paid subscription order for this user.', 'subscriptions-recurring-payments-for-woocommerce' ) ), 'error' );
			$where .= " AND {$wpdb->posts}.ID = 0";
		} else {
			// Orders with paid status
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_status IN ( 'wc-processing', 'wc-completed' )" );
			$where .= sprintf( " AND {$wpdb->posts}.ID IN (%s)", implode( ',', array_unique( $users_subscription_orders ) ) );
		}

		return $where;
	}

	/**
	 * Display a notice indicating that the "Orders" list is filtered.
	 * @see self::filter_orders()
	 */
	public static function display_renewal_filter_notice() {

		global $wp_version;

		$query_arg = '_subscription_related_orders';

		if ( isset( $_GET[ $query_arg ] ) && $_GET[ $query_arg ] > 0 && true === self::$found_related_orders ) {

			$initial_order = new WC_Order( absint( $_GET[ $query_arg ] ) );

			if ( version_compare( $wp_version, '4.2', '<' ) ) {
				echo '<div class="updated"><p>';
				printf(
					'<a href="%1$s" class="close-subscriptions-search">&times;</a>',
					esc_url( remove_query_arg( $query_arg ) )
				);
				// translators: placeholders are opening link tag, ID of sub, and closing link tag
				printf( esc_html__( 'Showing orders for %1$sSubscription %2$s%3$s', 'subscriptions-recurring-payments-for-woocommerce' ), '<a href="' . esc_url( get_edit_post_link( absint( $_GET[ $query_arg ] ) ) ) . '">', esc_html( $initial_order->get_order_number() ), '</a>' );
				echo '</p>';
			} else {
				echo '<div class="updated dismiss-subscriptions-search"><p>';
				// translators: placeholders are opening link tag, ID of sub, and closing link tag
				printf( esc_html__( 'Showing orders for %1$sSubscription %2$s%3$s', 'subscriptions-recurring-payments-for-woocommerce' ), '<a href="' . esc_url( get_edit_post_link( absint( $_GET[ $query_arg ] ) ) ) . '">', esc_html( $initial_order->get_order_number() ), '</a>' );
				echo '</p>';
				printf(
					'<a href="%1$s" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></a>',
					esc_url( remove_query_arg( $query_arg ) )
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Returns either a string or array of strings describing the allowable trial period range
	 * for a subscription.
	 *
	 
	 */
	public static function awc_get_trial_period_validation_message( $form = 'combined' ) {

		$subscription_ranges = awc_get_subscription_ranges();

		if ( 'combined' == $form ) {
			// translators: number of 1$: days, 2$: weeks, 3$: months, 4$: years
			$error_message = sprintf( __( 'The trial period can not exceed: %1$s, %2$s, %3$s or %4$s.', 'subscriptions-recurring-payments-for-woocommerce' ), array_pop( $subscription_ranges['day'] ), array_pop( $subscription_ranges['week'] ), array_pop( $subscription_ranges['month'] ), array_pop( $subscription_ranges['year'] ) );
		} else {
			$error_message = array();
			foreach ( awc_get_available_time_periods() as $period => $string ) {
				// translators: placeholder is a time period (e.g. "4 weeks")
				$error_message[ $period ] = sprintf( __( 'The trial period can not exceed %s.', 'subscriptions-recurring-payments-for-woocommerce' ), array_pop( $subscription_ranges[ $period ] ) );
			}
		}

		return apply_filters( 'woocommerce_subscriptions_trial_period_validation_message', $error_message );
	}

	/**
	 * Callback for the [subscriptions] shortcode that displays subscription names for a particular user.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	public static function do_subscriptions_shortcode( $attributes ) {
		$attributes = shortcode_atts(
			array(
				'user_id' => 0,
				'status'  => 'active',
			),
			$attributes,
			'subscriptions'
		);

		$subscriptions = awc_get_users_subscriptions( $attributes['user_id'] );

		// Limit subscriptions to the appropriate status if it's not "any" or "all".
		if ( 'all' !== $attributes['status'] && 'any' !== $attributes['status'] ) {
			/** @var WC_Subscription $subscription */
			foreach ( $subscriptions as $index => $subscription ) {
				if ( ! $subscription->has_status( $attributes['status'] ) ) {
					unset( $subscriptions[ $index ] );
				}
			}
		}

		// Load the subscription template, and return its content using Output Buffering.
		ob_start();
		wc_get_template(
			'my-account/my-subscriptions.php',
			array(
				'subscriptions' => $subscriptions,
				'user_id'       => $attributes['user_id'],
			),
			'',
			plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/'
		);

		return ob_get_clean();
	}

	/**
	 * Adds Subscriptions specific details to the WooCommerce System Status report.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return array
	 */
	public static function add_system_status_items( $debug_data ) {

		$is_awc_debug = defined( 'awc_DEBUG' ) ? awc_DEBUG : false;

		$debug_data['awc_debug'] = array(
			'name'    => _x( 'awc_DEBUG', 'label that indicates whether debugging is turned on for the plugin', 'subscriptions-recurring-payments-for-woocommerce' ),
			'note'    => ( $is_awc_debug ) ? __( 'Yes', 'subscriptions-recurring-payments-for-woocommerce' ) : __( 'No', 'subscriptions-recurring-payments-for-woocommerce' ),
			'success' => $is_awc_debug ? 0 : 1,
		);

		$debug_data['awc_staging'] = array(
			'name'    => _x( 'Subscriptions Mode', 'Live or Staging, Label on WooCommerce -> System Status page', 'subscriptions-recurring-payments-for-woocommerce' ),
			'note'    => '<strong>' . ( ( AWC_Subscriptions::is_duplicate_site() ) ? _x( 'Staging', 'refers to staging site', 'subscriptions-recurring-payments-for-woocommerce' ) : _x( 'Live', 'refers to live site', 'subscriptions-recurring-payments-for-woocommerce' ) ) . '</strong>',
			'success' => ( AWC_Subscriptions::is_duplicate_site() ) ? 0 : 1,
		);

		return $debug_data;
	}

	/**
	 * A WooCommerce version aware function for getting the Subscriptions admin settings tab URL.
	 *
	 * @return string
	 */
	public static function settings_tab_url() {

		$settings_tab_url = admin_url( 'admin.php?page=asub-subscriptions#/settings' );

		return apply_filters( 'awc_subscriptions_settings_tab_url', $settings_tab_url );
	}

	/**
	 * Add a column to the Payment Gateway table to show whether the gateway supports automated renewals.
	 *
	 * @param array $header
	 *
	 * @return array
	 */
	public static function payment_gateways_renewal_column( $header ) {
		$header_new = array_slice( $header, 0, count( $header ) - 1, true ) + array( 'renewals' => __( 'Automatic Recurring Payments', 'subscriptions-recurring-payments-for-woocommerce' ) ) // Ideally, we could add a link to the docs here, but the title is passed through esc_html()
			+ array_slice( $header, count( $header ) - 1, count( $header ) - ( count( $header ) - 1 ), true );

		return $header_new;
	}

	/**
	 * Add a column to the Payment Gateway table to show whether the gateway supports automated renewals.
	 *
	 * @return string
	 */
	public static function payment_gateways_rewewal_column( $header ) {
		return self::payment_gateways_renewal_column( $header );
	}

	/**
	 * Check whether the payment gateway passed in supports automated renewals or not.
	 * Automatically flag support for Paypal since it is included with subscriptions.
	 * Display in the Payment Gateway column.
	 *
	 * @param WC_Payment_Gateway $gateway
	 *
	 */
	public static function payment_gateways_renewal_support( $gateway ) {
		
		echo '<td class="renewals">';
		// echo '<pre>';
		// print_r($gateway->id);
		// echo '</pre>';
		if ( ( is_array( $gateway->supports ) && in_array( 'subscriptions', $gateway->supports ) ) || $gateway->id == 'paypal' ) {
			$status_html = '<span class="status-enabled tips" data-tip="' . esc_attr__( 'Supports automatic renewal payments with the WooCommerce Subscriptions extension.', 'subscriptions-recurring-payments-for-woocommerce' ) . '">' . esc_html__( 'Yes', 'subscriptions-recurring-payments-for-woocommerce' ) . '</span>';
		} else {
			$status_html = '-';
		}

		$allowed_html                     = wp_kses_allowed_html( 'post' );
		$allowed_html['span']['data-tip'] = true;

		/**
		 * Automatic Renewal Payments Support Status HTML Filter.
		 *
		 
		 *
		 * @param string              $status_html
		 * @param \WC_Payment_Gateway $gateway
		 */
		echo wp_kses( apply_filters( 'woocommerce_payment_gateways_renewal_support_status_html', $status_html, $gateway ), $allowed_html );

		echo '</td>';
	}

	/**
	 * Check whether the payment gateway passed in supports automated renewals or not.
	 * Automatically flag support for Paypal since it is included with subscriptions.
	 * Display in the Payment Gateway column.
	 *
	 
	 * 
	 */
	public static function payment_gateways_rewewal_support( $gateway ) {

		return self::payment_gateways_renewal_support( $gateway );
	}

	/**
	 * Do not display formatted order total on the Edit Order administration screen
	 *
	 
	 */
	public static function maybe_remove_formatted_order_total_filter( $formatted_total, $order ) {

		// Check if we're on the Edit Order screen - get_current_screen() only exists on admin pages so order of operations matters here
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {

			$screen = get_current_screen();

			if ( is_object( $screen ) && 'shop_order' == $screen->id ) {
				remove_filter( 'woocommerce_get_formatted_order_total', 'WC_Subscriptions_Order::get_formatted_order_total', 10 );
			}
		}

		return $formatted_total;
	}

	/**
	 * Only attach the gettext callback when on admin shop subscription screen
	 *
	 
	 */
	public static function maybe_attach_gettext_callback() {

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( is_object( $screen ) && 'shop_subscription' === $screen->id ) {
				add_filter( 'gettext', array( __CLASS__, 'change_order_item_editable_text' ), 10, 3 );
			}
		}
	}

	/**
	 * Only unattach the gettext callback when it was attached
	 *
	 
	 */
	public static function maybe_unattach_gettext_callback() {

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( is_object( $screen ) && 'shop_subscription' === $screen->id ) {
				remove_filter( 'gettext', array( __CLASS__, 'change_order_item_editable_text' ), 10 );
			}
		}
	}


	/**
	* When subscription items not editable (such as due to the payment gateway not supporting modifications),
	* change the text to explain why
	*
	
	*/
	public static function change_order_item_editable_text( $translated_text, $text, $domain ) {

		switch ( $text ) {
			case 'This order is no longer editable.':
				$translated_text = __( 'Subscription items can no longer be edited.', 'subscriptions-recurring-payments-for-woocommerce' );
				break;

			case 'To edit this order change the status back to "Pending"':
				$translated_text = __( 'This subscription is no longer editable because the payment gateway does not allow modification of recurring amounts.', 'subscriptions-recurring-payments-for-woocommerce' );
				break;
		}

		return $translated_text;
	}

	/**
	 * Add recurring payment gateway information after the Settings->Checkout->Payment Gateways table.
	 * This includes links to find additional gateways, information about manual renewals
	 * and a warning if no payment gateway which supports automatic recurring payments is enabled/setup correctly.
	 *
	 
	 */
	public static function add_recurring_payment_gateway_information( $settings ) {
		$available_gateways_description = '';

		if ( ! AWC_Subscription_Payment_Gateways::one_gateway_supports( 'subscriptions' ) ) {
			// translators: $1-2: opening and closing tags of a link that takes to Woo marketplace / Stripe product page
			$available_gateways_description = sprintf( __( 'No payment gateways capable of processing automatic subscription payments are enabled. If you would like to process automatic payments, we recommend the %1$sfree Stripe extension%2$s.', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>', '</strong>' );
		}

		$recurring_payment_settings = array(
			array(
				'name' => __( 'Subscription Payment Information', 'subscriptions-recurring-payments-for-woocommerce' ),
				'desc' => $available_gateways_description,
				'id'   => AWC_Subscriptions_Admin::$option_prefix . '_payment_gateways_available',
				'type' => 'informational',
			),

			array(
				// translators: placeholders are opening and closing link tags
				'desc' => sprintf( __( 'Payment gateways which don\'t support automatic recurring payments can be used to process %1$smanual subscription renewal payments%2$s.', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>', '</strong>' ),
				'id'   => AWC_Subscriptions_Admin::$option_prefix . '_payment_gateways_additional',
				'type' => 'informational',
			)
		);

		$insert_index = array_search(
			array(
				'type' => 'sectionend',
				'id'   => 'payment_gateways_options',
			),
			$settings
		);

		// reconstruct the settings array, inserting the new settings after the payment gatways table
		$checkout_settings = array();

		foreach ( $settings as $key => $value ) {

			$checkout_settings[ $key ] = $value;
			unset( $settings[ $key ] );

			if ( $key == $insert_index ) {
				$checkout_settings = array_merge( $checkout_settings, $recurring_payment_settings, $settings );
				break;
			}
		}

		return $checkout_settings;
	}

	/**
	 * Check if subscription product meta data should be saved for the current request.
	 *
	 * @param array Array of product types.
	 
	 */
	private static function is_subscription_product_save_request( $post_id, $product_types ) {

		if ( self::$saved_product_meta ) {
			$is_subscription_product_save_request = false;
		} elseif ( empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) ) {
			$is_subscription_product_save_request = false;
		} elseif ( ! isset( $_POST['product-type'] ) || ! in_array( $_POST['product-type'], $product_types ) ) {
			$is_subscription_product_save_request = false;
		} elseif ( empty( $_POST['post_ID'] ) || $_POST['post_ID'] != $post_id ) {
			$is_subscription_product_save_request = false;
		} else {
			$is_subscription_product_save_request = true;
		}

		return apply_filters( 'awc_admin_is_subscription_product_save_request', $is_subscription_product_save_request, $post_id, $product_types );
	}

	/**
	 * Insert a setting or an array of settings after another specific setting by its ID.
	 *
	 
	 * @param array  $settings                The original list of settings.
	 * @param string $insert_after_setting_id The setting id to insert the new setting after.
	 * @param array  $new_setting             The new setting to insert. Can be a single setting or an array of settings.
	 * @param string $insert_type             The type of insert to perform. Can be 'single_setting' or 'multiple_settings'. Optional. Defaults to a single setting insert.
	 */
	public static function insert_setting_after( &$settings, $insert_after_setting_id, $new_setting, $insert_type = 'single_setting' ) {
		if ( ! is_array( $settings ) ) {
			return;
		}

		$original_settings = $settings;
		$settings          = array();

		foreach ( $original_settings as $setting ) {
			$settings[] = $setting;

			if ( isset( $setting['id'] ) && $insert_after_setting_id === $setting['id'] ) {
				if ( 'single_setting' === $insert_type ) {
					$settings[] = $new_setting;
				} else {
					$settings = array_merge( $settings, $new_setting );
				}
			}
		}
	}

	/**
	 * Add a reminder on the enable guest checkout setting that subscriptions still require an account
	 
	 * @param array $settings The list of settings
	 */
	public static function add_guest_checkout_setting_note( $settings ) {
		$is_wc_pre_3_4_0 = AWC_Subscriptions::is_woocommerce_pre( '3.4.0' );
		$current_filter  = current_filter();

		if ( ( $is_wc_pre_3_4_0 && 'woocommerce_payment_gateways_settings' !== $current_filter ) || ( ! $is_wc_pre_3_4_0 && 'woocommerce_account_settings' !== $current_filter ) ) {
			return $settings;
		}

		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		foreach ( $settings as &$value ) {
			if ( isset( $value['id'] ) && 'woocommerce_enable_guest_checkout' === $value['id'] ) {
				$value['desc_tip']  = ! empty( $value['desc_tip'] ) ? $value['desc_tip'] . ' ' : '';
				$value['desc_tip'] .= __( 'Note that purchasing a subscription still requires an account.', 'subscriptions-recurring-payments-for-woocommerce' );
				break;
			}
		}
		return $settings;
	}

	/**
	 * Gets the product type warning message displayed for products associated with subscriptions
	 *
	 
	 * @return string The change product type warning message.
	 */
	private static function get_change_product_type_warning() {
		return __( 'The product type can not be changed because this product is associated with subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' );
	}

	/**
	 * Validates the product type change before other product data is saved.
	 *
	 * Subscription products associated with subscriptions cannot be changed. Doing so
	 * can cause issues. For example when customers who try to manually renew where the subscription
	 * products are placed in the cart.
	 *
	 
	 * @param int $product_id The product ID being saved.
	 */
	public static function validate_product_type_change( $product_id ) {

		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' ) || empty( $_POST['product-type'] ) ) {
			return;
		}

		$current_product_type = WC_Product_Factory::get_product_type( $product_id );

		// Only validate subscription product type changes.
		if ( 'subscription' !== $current_product_type && 'variable-subscription' !== $current_product_type ) {
			return;
		}

		$new_product_type = sanitize_title( wp_unslash( $_POST['product-type'] ) );

		// Display an error and don't save the product if the type is changing and it's linked to subscriptions.
		if ( $new_product_type !== $current_product_type && (bool) awc_get_product_subscriptions( $product_id, 'ids', array( 'limit' => 1 ) ) ) {
			AWC_Backend::awc_add_admin_notice( self::get_change_product_type_warning(), 'error' );
			wp_safe_redirect( get_admin_url( null, "post.php?post={$product_id}&action=edit" ) );
			exit;
		}
	}



	/**
	 * Outputs the contents of the "Renewal Orders" meta box.
	 *
	 * @param object $post Current post data.
	 */
	public static function related_orders_meta_box( $post ) {
		awc_Meta_Box_Related_Orders::output( $post );
	}

	/**
	 * Add users with subscriptions to the "Customers" report in WooCommerce -> Reports.
	 *
	 * @param WP_User_Query $user_query
	 */
	public static function add_subscribers_to_customers( $user_query ) {
		
	}

	/**
	 * Set a translation safe screen ID for Subcsription
	 *
	 
	 */
	public static function set_admin_screen_id() {
		
	}

	/**
	 * Once we have set a correct admin page screen ID, we can use it for adding the Manage Subscriptions table's columns.
	 *
	 
	 */
	public static function add_subscriptions_table_column_filter() {
		
	}

	/**
	 * Filter the "Orders" list to show only renewal orders associated with a specific parent order.
	 *
	 * @param array $request
	 * @return array
	 */
	public static function filter_orders_by_renewal_parent( $request ) {
		
		return $request;
	}

	/**
	 * Registers the "Renewal Orders" meta box for the "Edit Order" page.
	 */
	public static function add_meta_boxes() {}

	/**
	 * Output the metabox
	 */
	public static function recurring_totals_meta_box( $post ) {
		
	}

	/**
	 * Filters the Admin orders table results based on a list of IDs returned by a report query.
	 *
	 * 
	 *
	 * @param string $where The query WHERE clause.
	 * @return string $where
	 
	 */
	public static function filter_orders_from_list( $where ) {
		return AWC_Subscriptions_Admin::filter_orders_and_subscriptions_from_list( $where );
	}

	/**
	 * Filters the Admin subscriptions table results based on a list of IDs returned by a report query.
	 *
	 *
	 * @param string $where The query WHERE clause.
	 * @return string
	 */
	public static function filter_subscriptions_from_list( $where ) {
		return AWC_Subscriptions_Admin::filter_orders_and_subscriptions_from_list( $where );
	}
}
