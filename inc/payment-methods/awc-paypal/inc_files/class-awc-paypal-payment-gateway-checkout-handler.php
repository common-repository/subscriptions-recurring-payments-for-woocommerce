<?php
/**
 * Cart handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * File include
 */
foreach(
	array(
		'class-awc-paypal-payment-gateway-settings', 
		'class-awc-paypal-payment-gateway-session-data', 
		'class-awc-paypal-payment-gateway-checkout-details',
		'class-awc-paypal-api-error',
		'exc/class-awc-paypal-payment-gateway-exception',
		'exc/class-awc-paypal-payment-gateway-missing-session-exception',
		'class-awc-paypal-payment-gateway-payment-details',
		'class-awc-address-for-paypal'
	) as $file
){
	awc_include_files($file);
}
	

if(!class_exists('AWC_Paypal_Payment_Gateway_Checkout_Handler')){
class AWC_Paypal_Payment_Gateway_Checkout_Handler {

	/**
	 * Cached result from self::get_checkout_defails.
	 *
	 */
	protected $_checkout_details;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'the_title', array( $this, 'endpoint_page_titles' ) );
		add_action( 'woocommerce_checkout_init', array( $this, 'checkout_init' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'copy_checkout_details_to_post' ) );

		add_action( 'wp', array( $this, 'maybe_return_from_paypal' ) );
		add_action( 'wp', array( $this, 'awc_maybe_cancel_checkout_with_paypal' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'maybe_clear_session_data' ) );

		add_action( 'woocommerce_available_payment_gateways', array( $this, 'maybe_disable_other_gateways' ) );
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'maybe_render_cancel_link' ) );

		add_action( 'woocommerce_cart_shipping_packages', array( $this, 'maybe_add_shipping_information' ) );
	}

	/**
	 * If the buyer clicked on the "Check Out with PayPal" button, we need to wait for the cart
	 * @access	public
	 */
	public function init() {
		if ( version_compare( WC_VERSION, '3.3', '<' ) ) {
			add_filter( 'wc_checkout_params', array( $this, 'filter_wc_checkout_params' ), 10, 1 );
		} else {
			add_filter( 'woocommerce_get_script_data', array( $this, 'filter_wc_checkout_params' ), 10, 2 );
		}
		if ( isset( $_GET['startcheckout'] ) && 'true' === $_GET['startcheckout'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			ob_start();
		}
	}

	/**
	 * Handle endpoint page title
	 * @param  string $title
	 * @return string
	 */
	public function endpoint_page_titles( $title ) {
		if ( ! is_admin() && is_main_query() && in_the_loop() && is_page() && is_checkout() && $this->has_active_session() ) {
			$title = __( 'Confirm your PayPal order', 'subscriptions-recurring-payments-for-woocommerce' );
			remove_filter( 'the_title', array( $this, 'endpoint_page_titles' ) );
		}
		return $title;
	}

	/**
	 * If there's an active PayPal session during checkout (e.g. if the customer started checkout  with PayPal from the cart), import billing and shipping details from PayPal using the token we have for the customer.
	 *
	 * Hooked to the woocommerce_checkout_init action
	 *
	 * @param WC_Checkout $checkout
	 */
	public function checkout_init( $checkout ) {
		if ( ! $this->has_active_session() ) {
			return;
		}

		
		remove_action( 'woocommerce_checkout_billing', array( $checkout, 'checkout_form_billing' ) );
		remove_action( 'woocommerce_checkout_shipping', array( $checkout, 'checkout_form_shipping' ) );

		add_action( 'woocommerce_checkout_billing', array( $this, 'paypal_billing_details' ) );
		add_action( 'woocommerce_checkout_billing', array( $this, 'account_registration' ) );
		add_action( 'woocommerce_checkout_shipping', array( $this, 'paypal_shipping_details' ) );

		// Lastly make address fields optional depending on PayPal settings.
		add_filter( 'woocommerce_default_address_fields', array( $this, 'filter_default_address_fields' ) );
		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ) );
	}

	/**
	 * If the cart doesn't need shipping at all, don't require the address fields
	 * (this is unique to PPEC). This is one of two places we need to filter fields.
	 * See also filter_billing_fields below.
	 *
	 * @param $fields array
	 *
	 * @return array
	 */
	public function filter_default_address_fields( $fields ) {
		if ( 'yes' !== awc_paypal_ec_gateway()->settings->enabled ) {
			return $fields;
		}

		// Regardless of shipping, PP doesn't have the county required (e.g. using Ireland without a county is acceptable)
		if ( array_key_exists( 'state', $fields ) ) {
			$fields['state']['required'] = false;
		}

		if ( ! apply_filters( 'woocommerce_paypal_express_checkout_address_not_required', ! AWC_Paypal_Payment_Methods_for_Subscription::needs_shipping() ) ) {
			return $fields;
		}

		if ( is_callable( array( WC()->cart, 'needs_shipping' ) ) && ! WC()->cart->needs_shipping() && 'no' === awc_paypal_ec_gateway()->settings->require_billing ) {
			$not_required_fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country' );
			foreach ( $not_required_fields as $not_required_field ) {
				if ( array_key_exists( $not_required_field, $fields ) ) {
					$fields[ $not_required_field ]['required'] = false;
				}
			}
		}

		return $fields;

	}

	/**
	 *
	 * This is one of two places we need to filter fields. See also filter_default_address_fields above.
	 *
	 * @param $billing_fields array
	 *
	 * @return array
	 */
	public function filter_billing_fields( $billing_fields ) {
		if ( 'yes' !== awc_paypal_ec_gateway()->settings->enabled ) {
			return $billing_fields;
		}

		$require_phone_number = awc_paypal_ec_gateway()->settings->require_phone_number;

		if ( array_key_exists( 'billing_phone', $billing_fields ) ) {
			$billing_fields['billing_phone']['required'] = 'yes' === $require_phone_number;
		}

		return $billing_fields;
	}

	/**
	 * When an active session is present, gets (from PayPal) the buyer details
	 * and replaces the appropriate checkout fields in $_POST
	 *
	 * Hooked to woocommerce_checkout_process
	 *
	 
	 */
	public function copy_checkout_details_to_post() {
		if ( ! $this->has_active_session() ) {
			return;
		}

		// Make sure the selected payment method is awc_paypal_payment
		if ( ! isset( $_POST['payment_method'] ) || ( 'awc_paypal_payment' !== $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Get the buyer details from PayPal
		try {
			$session          = WC()->session->get( 'paypal' );
			$token            = isset( $_GET['token'] ) ? $_GET['token'] : $session->token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$checkout_details = $this->get_checkout_details( $token );
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return;
		}

		$shipping_details = $this->get_mapped_shipping_address( $checkout_details );
		$billing_details  = $this->get_mapped_billing_address( $checkout_details );

		// If the billing address is empty, copy address from shipping
		if ( empty( $billing_details['address_1'] ) ) {
			// Set flag so that WC copies billing to shipping
			$_POST['ship_to_different_address'] = 0;

			$copyable_keys = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
			foreach ( $copyable_keys as $copyable_key ) {
				if ( array_key_exists( $copyable_key, $shipping_details ) ) {
					$billing_details[ $copyable_key ] = $shipping_details[ $copyable_key ];
				}
			}
		} else {
			// Shipping may be different from billing, so set flag to not copy address from billing
			$_POST['ship_to_different_address'] = 1;
		}

		foreach ( $shipping_details as $key => $value ) {
			$_POST[ 'shipping_' . $key ] = $value;
		}

		foreach ( $billing_details as $key => $value ) {
			$_POST[ 'billing_' . $key ] = $value;
		}
	}

	/**
	 * Show billing information obtained from PayPal. This replaces the billing fields
	 * that the customer would ordinarily fill in. Should only happen if we have an active
	 * session (e.g. if the customer started checkout with PayPal from their cart.)
	 *
	 * Is hooked to woocommerce_checkout_billing action by checkout_init
	 */
	public function paypal_billing_details() {
		$session = WC()->session->get( 'paypal' );
		$token   = isset( $_GET['token'] ) ? $_GET['token'] : $session->token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		try {
			$checkout_details = $this->get_checkout_details( $token );
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return;
		}

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$fields = WC()->checkout->checkout_fields['billing'];
		} else {
			$fields = WC()->checkout->get_checkout_fields( 'billing' );
		}
		?>
		<h3><?php esc_html_e( 'Billing details', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h3>
		<ul>
			<?php if ( ! empty( $checkout_details->payer_details->billing_address ) ) : ?>
				<li><strong><?php esc_html_e( 'Address:', 'subscriptions-recurring-payments-for-woocommerce' ); ?></strong></br><?php echo WC()->countries->get_formatted_address( $this->get_mapped_billing_address( $checkout_details ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
			<?php elseif ( ! empty( $checkout_details->payer_details->first_name ) && ! empty( $checkout_details->payer_details->last_name ) ) : ?>
				<li><strong><?php esc_html_e( 'Name:', 'subscriptions-recurring-payments-for-woocommerce' ); ?></strong> <?php echo esc_html( $checkout_details->payer_details->first_name . ' ' . $checkout_details->payer_details->last_name ); ?></li>
			<?php endif; ?>

			<?php if ( ! empty( $checkout_details->payer_details->email ) ) : ?>
				<li><strong><?php esc_html_e( 'Email:', 'subscriptions-recurring-payments-for-woocommerce' ); ?></strong> <?php echo esc_html( $checkout_details->payer_details->email ); ?></li>
			<?php else : ?>
				<li><?php woocommerce_form_field( 'billing_email', $fields['billing_email'], WC()->checkout->get_value( 'billing_email' ) ); ?></li>
			<?php endif; ?>

			<?php if ( ! empty( $checkout_details->payer_details->phone_number ) ) : ?>
				<li><strong><?php esc_html_e( 'Phone:', 'subscriptions-recurring-payments-for-woocommerce' ); ?></strong> <?php echo esc_html( $checkout_details->payer_details->phone_number ); ?></li>
			<?php elseif ( 'yes' === awc_paypal_ec_gateway()->settings->require_phone_number ) : ?>
				<li><?php woocommerce_form_field( 'billing_phone', $fields['billing_phone'], WC()->checkout->get_value( 'billing_phone' ) ); ?></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * If there is an active session (e.g. the customer initiated checkout from the cart), since we
	 * removed the checkout_form_billing action, we need to put a registration form back in to
	 * allow the customer to create an account.
	 *
	 *  Is hooked to woocommerce_checkout_billing action by checkout_init
	 
	 */
	public function account_registration() {
		$checkout = WC()->checkout();

		if ( ! is_user_logged_in() && $checkout->enable_signup ) {

			if ( $checkout->enable_guest_checkout ) {
				?>
				<p class="form-row form-row-wide create-account">
					<input class="input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ); ?> type="checkbox" name="createaccount" value="1" /> <label for="createaccount" class="checkbox"><?php esc_html_e( 'Create an account?', 'subscriptions-recurring-payments-for-woocommerce' ); ?></label>
				</p>
				<?php
			}

			if ( ! empty( $checkout->checkout_fields['account'] ) ) {
				?>
				<div class="create-account">

					<p><?php esc_html_e( 'Create an account by entering the information below. If you are a returning customer please login at the top of the page.', 'subscriptions-recurring-payments-for-woocommerce' ); ?></p>

					<?php foreach ( $checkout->checkout_fields['account'] as $key => $field ) : ?>

						<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>

					<?php endforeach; ?>

					<div class="clear"></div>

				</div>
				<?php
			}
		}
	}

	/**
	 * Show shipping information obtained from PayPal. This replaces the shipping fields
	 * that the customer would ordinarily fill in. Should only happen if we have an active
	 * session (e.g. if the customer started checkout with PayPal from their cart.)
	 *
	 * Is hooked to woocommerce_checkout_shipping action by checkout_init
	 */
	public function paypal_shipping_details() {
		$session = WC()->session->get( 'paypal' );
		$token   = isset( $_GET['token'] ) ? $_GET['token'] : $session->token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		try {
			$checkout_details = $this->get_checkout_details( $token );
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return;
		}

		if ( ! AWC_PPEC_GAWC_Paypal_Payment_Methods_for_Subscriptionateway::needs_shipping() ) {
			return;
		}

		?>
		<h3><?php esc_html_e( 'Shipping details', 'subscriptions-recurring-payments-for-woocommerce' ); ?></h3>
		<?php
		echo WC()->countries->get_formatted_address( $this->get_mapped_shipping_address( $checkout_details ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}



	/**
	 * Map PayPal billing address to WC shipping address
	 * NOTE: Not all PayPal_Checkout_Payer_Details objects include a billing address
	 * @param  object $checkout_details
	 * @return array
	 */
	public function get_mapped_billing_address( $checkout_details ) {
		if ( empty( $checkout_details->payer_details ) ) {
			return array();
		}

		$phone = '';

		if ( ! empty( $checkout_details->payer_details->phone_number ) ) {
			$phone = $checkout_details->payer_details->phone_number;
		} elseif ( 'yes' === awc_paypal_ec_gateway()->settings->require_phone_number && ! empty( $_POST['billing_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$phone = wc_clean( $_POST['billing_phone'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		return array(
			'first_name' => $checkout_details->payer_details->first_name,
			'last_name'  => $checkout_details->payer_details->last_name,
			'company'    => $checkout_details->payer_details->business_name,
			'address_1'  => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getStreet1() : '',
			'address_2'  => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getStreet2() : '',
			'city'       => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getCity() : '',
			'state'      => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getState() : '',
			'postcode'   => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getZip() : '',
			'country'    => $checkout_details->payer_details->billing_address ? $checkout_details->payer_details->billing_address->getCountry() : $checkout_details->payer_details->country,
			'phone'      => $phone,
			'email'      => $checkout_details->payer_details->email,
		);
	}

	/**
	 * Map PayPal shipping address to WC shipping address.
	 *
	 * @param  object $checkout_details Checkout details
	 * @return array
	 */
	public function get_mapped_shipping_address( $checkout_details ) {
		if ( empty( $checkout_details->payments[0] ) || empty( $checkout_details->payments[0]->shipping_address ) ) {
			return array();
		}

		$name       = explode( ' ', $checkout_details->payments[0]->shipping_address->getName() );
		$first_name = array_shift( $name );
		$last_name  = implode( ' ', $name );
		$result     = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'address_1'  => $checkout_details->payments[0]->shipping_address->getStreet1(),
			'address_2'  => $checkout_details->payments[0]->shipping_address->getStreet2(),
			'city'       => $checkout_details->payments[0]->shipping_address->getCity(),
			'state'      => $checkout_details->payments[0]->shipping_address->getState(),
			'postcode'   => $checkout_details->payments[0]->shipping_address->getZip(),
			'country'    => $checkout_details->payments[0]->shipping_address->getCountry(),
		);
		if ( ! empty( $checkout_details->payer_details ) && property_exists( $checkout_details->payer_details, 'business_name' ) ) {
			$result['company'] = $checkout_details->payer_details->business_name;
		}
		return $result;
	}

	/**
	 * Checks data is correctly set when returning from PayPal Checkout
	 */
	public function maybe_return_from_paypal() {
		if (
			isset( $_GET['woo-paypal-return'] )
			&& isset( $_GET['update_subscription_payment_method'] )
			&& 'true' === $_GET['update_subscription_payment_method']
		) {
			$this->handle_subscription_payment_change_success();
			return;
		}

		if ( empty( $_GET['woo-paypal-return'] ) || empty( $_GET['token'] ) ) {
			return;
		}

		$token                    = $_GET['token']; 
		$create_billing_agreement = ! empty( $_GET['create-billing-agreement'] );
		$session                  = WC()->session->get( 'paypal' );

		if ( empty( $session ) || $this->session_has_expired( $token ) ) {
			wc_add_notice( __( 'Your PayPal checkout session has expired. Please check out again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			return;
		}

		// Store values in session.
		$session->checkout_completed = true;
		$session->token              = $token;

		if ( ! empty( $_GET['PayerID'] ) ) {
			$session->payer_id = $_GET['PayerID']; 
		} elseif ( $create_billing_agreement ) {
			$session->create_billing_agreement = true;
		} else {
			return;
		}

		// Update customer addresses here from PayPal selection so they can be used to calculate local taxes.
		$this->update_customer_addresses_from_paypal( $token );

		WC()->session->set( 'paypal', $session );

		try {
			// If commit was true, take payment right now
			if ( 'order' === $session->source && $session->order_id ) {
				$checkout_details = $this->get_checkout_details( $token );

				// Get order
				$order = wc_get_order( $session->order_id );

				// Maybe create billing agreement.
				if ( $create_billing_agreement ) {
					$this->create_billing_agreement( $order, $checkout_details );
				}

				// Complete the payment now.
				if ( ! empty( $session->payer_id ) ) {
					$this->do_payment( $order, $session->token, $session->payer_id );
				} elseif ( $order->get_total() <= 0 ) {
					$order->payment_complete();
				}

				// Clear Cart
				WC()->cart->empty_cart();

				// Redirect
				wp_redirect( $order->get_checkout_order_received_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_add_notice( __( 'Sorry, an error occurred while trying to retrieve your information from PayPal. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			$this->maybe_clear_session_data();
			wp_safe_redirect( wc_get_page_permalink( 'cart' ) );
			exit;
		} catch ( AWC_Paypal_Payment_Gateway_Missing_Session_Exception $e ) {
			wc_add_notice( __( 'Your PayPal checkout session has expired. Please check out again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			$this->maybe_clear_session_data();
			wp_safe_redirect( wc_get_page_permalink( 'cart' ) );
			exit;
		}
	}

	/**
	 * Updates shipping and billing addresses.
	 *
	 * Retrieves shipping and billing addresses from PayPal session.
	 * @param string $token Token
	 *
	 * @return void
	 */
	private function update_customer_addresses_from_paypal( $token ) {
		// Get the buyer details from PayPal.
		try {
			$checkout_details = $this->get_checkout_details( $token );
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return;
		}
		$shipping_details = $this->get_mapped_shipping_address( $checkout_details );
		$billing_details  = $this->get_mapped_billing_address( $checkout_details );

		$customer = WC()->customer;

		// Update billing/shipping addresses.
		if ( ! empty( $billing_details ) ) {
			$customer->set_billing_address( $billing_details['address_1'] );
			$customer->set_billing_address_2( $billing_details['address_2'] );
			$customer->set_billing_city( $billing_details['city'] );
			$customer->set_billing_postcode( $billing_details['postcode'] );
			$customer->set_billing_state( $billing_details['state'] );
			$customer->set_billing_country( $billing_details['country'] );
		}

		if ( ! empty( $shipping_details ) ) {
			$customer->set_shipping_address( $shipping_details['address_1'] );
			$customer->set_shipping_address_2( $shipping_details['address_2'] );
			$customer->set_shipping_city( $shipping_details['city'] );
			$customer->set_shipping_postcode( $shipping_details['postcode'] );
			$customer->set_shipping_state( $shipping_details['state'] );
			$customer->set_shipping_country( $shipping_details['country'] );
		}
	}

	/**
	 * Maybe disable this or other gateways.
	 *
	 *
	 * @param array $gateways Available gateways
	 *
	 * @return array Available gateways
	 */
	public function maybe_disable_other_gateways( $gateways ) {
		// Unset all other gateways after checking out from cart.
		if ( $this->has_active_session() ) {
			foreach ( $gateways as $id => $gateway ) {
				if ( 'awc_paypal_payment' !== $id ) {
					unset( $gateways[ $id ] );
				}
			}
		} elseif ( is_checkout() && ( isset( $gateways['paypal'] ) || 'no' === awc_paypal_ec_gateway()->settings->mark_enabled ) ) {
			// If using PayPal standard (this is admin choice) we don't need to also show PayPal EC on checkout.
			unset( $gateways['awc_paypal_payment'] );
		}

		// If the cart total is zero (e.g. because of a coupon), don't allow this gateway.
		// We do this only if we're on the checkout page (is_checkout), but not on the order-pay page (is_checkout_pay_page)
		if ( is_cart() || ( is_checkout() && ! is_checkout_pay_page() ) ) {
			if ( isset( $gateways['awc_paypal_payment'] ) && ! WC()->cart->needs_payment() ) {
				unset( $gateways['awc_paypal_payment'] );
			}
		}

		return $gateways;
	}

	/**
	 * When cart based Checkout with PPEC is in effect, we need to include
	 * a Cancel button on the checkout form to give the user a means to throw
	 * away the session provided and possibly select a different payment
	 * gateway.
	 *
	 
	 *
	 * @return void
	 */
	public function maybe_render_cancel_link() {
		if ( $this->has_active_session() ) {
			printf(
				'<a href="%s" class="wc-gateway-ppec-cancel">%s</a>',
				esc_url( add_query_arg( 'wc-gateway-ppec-clear-session', true, wc_get_cart_url() ) ),
				esc_html__( 'Cancel', 'subscriptions-recurring-payments-for-woocommerce' )
			);
		}
	}

	/**
	 * Buyer cancels checkout with PayPal.
	 *
	 * Clears the session data and display notice.
	 */
	public function awc_maybe_cancel_checkout_with_paypal() {
		if (
			isset( $_GET['update_subscription_payment_method'] )
			&& 'true' === $_GET['update_subscription_payment_method']
			&& isset( $_GET['woo-paypal-cancel'] )
		) {
			$this->handle_subscription_payment_change_failure();
			return;
		}
		// phpcs:enable

		if ( is_cart() && ! empty( $_GET['wc-gateway-ppec-clear-session'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->maybe_clear_session_data();

			$notice = __( 'You have cancelled Checkout with PayPal. Please try to process your order again.', 'subscriptions-recurring-payments-for-woocommerce' );
			if ( ! wc_has_notice( $notice, 'notice' ) ) {
				wc_add_notice( $notice, 'notice' );
			}
		}
	}

	/**
	 * Used when cart based Checkout with PayPal is in effect. Hooked to woocommerce_cart_emptied
	 * Also called by WC_PayPal_Braintree_Loader::possibly_cancel_checkout_with_paypal
	 *
	 
	 */
	public function maybe_clear_session_data() {
		if ( $this->has_active_session() ) {
			unset( WC()->session->paypal );
		}
	}

	/**
	 * Checks whether session with passed token has expired.
	 *
	 
	 *
	 * @param string $token Token
	 *
	 * @return bool
	 */
	public function session_has_expired( $token ) {
		$session = WC()->session->paypal;
		return ( ! $session || ! is_a( $session, 'WC_Gateway_PPEC_Session_Data' ) || $session->expiry_time < time() || $token !== $session->token );
	}

	/**
	 * Checks whether there's active session from cart-based checkout with PPEC.
	 *
	 
	 *
	 * @return bool Returns true if PPEC session exists and still valid
	 */
	public function has_active_session() {
		if ( ! WC()->session ) {
			return false;
		}

		$session = WC()->session->paypal;
		return ( is_a( $session, 'WC_Gateway_PPEC_Session_Data' ) && ( $session->payer_id || ! empty( $session->create_billing_agreement ) ) && $session->expiry_time > time() );
	}

	


	

	/**
	 * @deprecated
	 */
	protected function is_success( $response ) {

		$client = awc_paypal_ec_gateway()->client;
		return $client->response_has_success_status( $response );
	}

	/**
	 * Generic checkout handler.
	 *
	 * @param array $context_args Context parameters for checkout.
	 * @param array $session_data_args Session parameters (token pre-populated).
	 *
	 * @throws AWC_Paypal_Payment_Gateway_Exception
	 * @return string Redirect URL.
	 */
	protected function start_checkout( $context_args, $session_data_args ) {
		$settings = awc_paypal_ec_gateway()->settings;
		$client   = awc_paypal_ec_gateway()->client;

		$context_args['create_billing_agreement'] = $this->needs_billing_agreement_creation( $context_args );

		$params   = $client->get_set_express_checkout_params( $context_args );
		$response = $client->set_express_checkout( $params );

		if ( $client->response_has_success_status( $response ) ) {
			$session_data_args['token'] = $response['TOKEN'];

			WC()->session->paypal = new WC_Gateway_PPEC_Session_Data( $session_data_args );

			return $settings->get_paypal_redirect_url( $response['TOKEN'], true, $session_data_args['use_paypal_credit'] );
		} else {
			throw new AWC_Paypal_Payment_Gateway_Exception( $response );
		}
	}

	/**
	 * Handler when buyer is checking out prior to order creation.
	 *
	 * @return string Redirect URL.
	 */
	public function start_checkout_from_cart( $skip_checkout = true ) {
		$settings = awc_paypal_ec_gateway()->settings;

		$context_args = array(
			'skip_checkout' => $skip_checkout,
		);

		$session_data_args = array(
			'source'            => 'cart',
			'expires_in'        => $settings->get_token_session_length(),
			'use_paypal_credit' => wc_gateway_ppec_is_using_credit(),
		);

		return $this->start_checkout( $context_args, $session_data_args );
	}

	/**
	 * Handler when buyer is checking out after order is created (i.e. from checkout page with Smart Payment Buttons disabled).
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $use_ppc  Whether to use PayPal credit.
	 *
	 * @return string Redirect URL.
	 */
	public function start_checkout_from_order( $order_id, $use_ppc ) {
		$settings = awc_paypal_ec_gateway()->settings;

		$context_args = array(
			'skip_checkout' => false,
			'order_id'      => $order_id,
		);

		$session_data_args = array(
			'source'            => 'order',
			'order_id'          => $order_id,
			'expires_in'        => $settings->get_token_session_length(),
			'use_paypal_credit' => $use_ppc,
		);

		return $this->start_checkout( $context_args, $session_data_args );
	}

	/**
	 * Checks whether buyer checkout from checkout page.
	 *
	 
	 *
	 * @return bool Returns true if buyer checkout from checkout page
	 */
	public function is_started_from_checkout_page() {
		if ( ! is_object( WC()->session ) ) {
			return false;
		}

		$session = WC()->session->get( 'paypal' );

		return (
			! $this->has_active_session()
			||
			! $session->checkout_completed
		);
	}


	/**
	 * Get checkout details from token.
	 *
	 
	 *
	 * @throws \Exception
	 *
	 * @param bool|string $token Express Checkout token
	 */
	public function get_checkout_details( $token = false ) {
		if ( is_a( $this->_checkout_details, 'PayPal_Checkout_Details' ) ) {
			return $this->_checkout_details;
		}

		if ( false === $token && ! empty( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = $_GET['token']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		$client   = awc_paypal_ec_gateway()->client;
		$response = $client->get_express_checkout_details( $token );

		if ( $client->response_has_success_status( $response ) ) {
			$checkout_details = new PayPal_Checkout_Details();
			$checkout_details->loadFromGetECResponse( $response );

			$this->_checkout_details = $checkout_details;
			return $checkout_details;
		} else {
			throw new AWC_Paypal_Payment_Gateway_Exception( $response );
		}
	}

	/**
	 * Creates billing agreement and stores the billing agreement ID in order's
	 * meta and subscriptions meta.
	 *
	 *
	 * @throws \Exception
	 *
	 * @param WC_Order                $order            Order object
	 * @param PayPal_Checkout_Details $checkout_details Checkout details
	 */
	public function create_billing_agreement( $order, $checkout_details ) {
		if ( 1 !== intval( $checkout_details->billing_agreement_accepted ) ) {
			throw new AWC_Paypal_Payment_Gateway_Exception( $checkout_details->raw_response );
		}

		$client = awc_paypal_ec_gateway()->client;
		$resp   = $client->create_billing_agreement( $checkout_details->token );

		if ( ! $client->response_has_success_status( $resp ) || empty( $resp['BILLINGAGREEMENTID'] ) ) {
			throw new AWC_Paypal_Payment_Gateway_Exception( $resp );
		}

		$old_wc   = version_compare( WC_VERSION, '3.0', '<' );
		$order_id = $old_wc ? $order->id : $order->get_id();
		if ( $old_wc ) {
			update_post_meta( $order_id, '_ppec_billing_agreement_id', $resp['BILLINGAGREEMENTID'] );
		} else {
			$order->update_meta_data( '_ppec_billing_agreement_id', $resp['BILLINGAGREEMENTID'] );
		}

		$subscriptions = array();
		if ( function_exists( 'awc_order_contains_subscription' ) && awc_order_contains_subscription( $order_id ) ) {
			$subscriptions = awc_get_subscriptions_for_order( $order_id );
		} elseif ( function_exists( 'awc_order_contains_renewal' ) && awc_order_contains_renewal( $order_id ) ) {
			$subscriptions = awc_get_subscriptions_for_renewal_order( $order_id );
		}

		$billing_agreement_id = $old_wc ? get_post_meta( $order_id, '_ppec_billing_agreement_id', true ) : $order->get_meta( '_ppec_billing_agreement_id', true );

		foreach ( $subscriptions as $subscription ) {
			update_post_meta( is_callable( array( $subscription, 'get_id' ) ) ? $subscription->get_id() : $subscription->id, '_ppec_billing_agreement_id', $billing_agreement_id );
		}
	}

	/**
	 * Complete a payment that has been authorized via PPEC.
	 */
	public function do_payment( $order, $token, $payer_id ) {
		$session_data = WC()->session->get( 'paypal', null );

		if ( ! $order || null === $session_data || $this->session_has_expired( $token ) || empty( $payer_id ) ) {
			throw new AWC_Paypal_Payment_Gateway_Missing_Session_Exception();
		}

		$client   = awc_paypal_ec_gateway()->client;
		$old_wc   = version_compare( WC_VERSION, '3.0', '<' );
		$order_id = $old_wc ? $order->id : $order->get_id();
		$params   = $client->get_do_express_checkout_params(
			array(
				'order_id' => $order_id,
				'token'    => $token,
				'payer_id' => $payer_id,
			)
		);

		$response = $client->do_express_checkout_payment( $params );

		if ( $client->response_has_success_status( $response ) ) {
			$payment_details = new AWC_Paypal_Payment_Gateway_Payment_Details();
			$payment_details->loadFromDoECResponse( $response );

			awc_paypal_gateway_save_transaction_data( $order, $response, 'PAYMENTINFO_0_' );

			// Payment was taken so clear session
			$this->maybe_clear_session_data();

			// Handle order
			$this->handle_payment_response( $order, $payment_details->payments[0] );
		} else {
			throw new AWC_Paypal_Payment_Gateway_Exception( $response );
		}
	}

	/**
	 * Handle result of do_payment
	 */
	public function handle_payment_response( $order, $payment ) {
		// Store meta data to order
		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		update_post_meta( $old_wc ? $order->id : $order->get_id(), '_paypal_status', strtolower( $payment->payment_status ) );
		update_post_meta( $old_wc ? $order->id : $order->get_id(), '_transaction_id', $payment->transaction_id );

		// Handle $payment response
		if ( 'completed' === strtolower( $payment->payment_status ) ) {
			$order->payment_complete( $payment->transaction_id );
			if ( isset( $payment->fee_amount ) ) {
				awc_paypal_payment_gateway_set_transaction_fee( $order, $payment->fee_amount );
			}
		} else {
			if ( 'authorization' === $payment->pending_reason ) {
				$order->update_status( 'on-hold', __( 'Payment authorized. Change payment status to processing or complete to capture funds.', 'subscriptions-recurring-payments-for-woocommerce' ) );
			} else {
				// Translators: placeholder is a reason (from PayPal) for the payment to be pending.
				$order->update_status( 'on-hold', sprintf( __( 'Payment pending (%s).', 'subscriptions-recurring-payments-for-woocommerce' ), $payment->pending_reason ) );
			}
			if ( $old_wc ) {
				if ( ! get_post_meta( $order->id, '_order_stock_reduced', true ) ) {
					$order->reduce_order_stock();
				}
			} else {
				wc_maybe_reduce_stock_levels( $order->get_id() );
			}
		}
	}

	/**
	 * This function filter the packages adding shipping information from PayPal on the checkout page
	 * after the user is authenticated by PayPal.
	 *
	 * @param array $packages
	 *
	 * @return mixed
	 */
	public function maybe_add_shipping_information( $packages ) {
		if ( empty( $_GET['woo-paypal-return'] ) || empty( $_GET['token'] ) || empty( $_GET['PayerID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $packages;
		}

		// Shipping details from PayPal
		try {
			$checkout_details = $this->get_checkout_details( wc_clean( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			return $packages;
		}

		$destination = $this->get_mapped_shipping_address( $checkout_details );

		if ( ! empty( $destination ) ) {
			// WC Subscriptions uses string package keys so we need to get the package key dynamically.
			$package_key = key( $packages );

			$packages[ $package_key ]['destination']['country']   = $destination['country'];
			$packages[ $package_key ]['destination']['state']     = $destination['state'];
			$packages[ $package_key ]['destination']['postcode']  = $destination['postcode'];
			$packages[ $package_key ]['destination']['city']      = $destination['city'];
			$packages[ $package_key ]['destination']['address']   = $destination['address_1'];
			$packages[ $package_key ]['destination']['address_2'] = $destination['address_2'];
		}

		return $packages;
	}

	/**
	 * Checks whether checkout needs billing agreement creation.
	 *
	 * @return bool Returns true if billing agreement is needed in the purchase
	 */
	public function needs_billing_agreement_creation( $args ) {
		$needs_billing_agreement = false;

		if ( empty( $args['order_id'] ) ) {
			if ( class_exists( 'AWC_Subscription_Cart' ) && function_exists( 'awc_cart_contains_renewal' ) ) {
				// Needs a billing agreement if the cart contains a subscription
				// or a renewal of a subscription
				$needs_billing_agreement = (
					AWC_Subscription_Cart::cart_contains_subscription()
					|| awc_cart_contains_renewal()
				);
			}
		} else {
			if ( function_exists( 'awc_order_contains_subscription' ) ) {
				$needs_billing_agreement = awc_order_contains_subscription( $args['order_id'] );
			}
			if ( function_exists( 'awc_order_contains_renewal' ) ) {
				$needs_billing_agreement = ( $needs_billing_agreement || awc_order_contains_renewal( $args['order_id'] ) );
			}
			// If the order is a subscription, we're updating the payment method.
			if ( function_exists( 'awc_is_subscription' ) ) {
				$needs_billing_agreement = ( $needs_billing_agreement || awc_is_subscription( $args['order_id'] ) );
			}
		}

		return apply_filters( 'woocommerce_paypal_express_checkout_needs_billing_agreement', $needs_billing_agreement );
	}

	/**
	 * Filter checkout AJAX endpoint so it carries the query string after buyer is
	 * redirected from PayPal.
	 *
	 * @param array  $params
	 * @param string $handle
	 *
	 * @return string URL.
	 */
	public function filter_wc_checkout_params( $params, $handle = '' ) {
		if ( 'wc-checkout' !== $handle && ! doing_action( 'wc_checkout_params' ) ) {
			return $params;
		}

		$fields = array( 'woo-paypal-return', 'token', 'PayerID' );

		$params['wc_ajax_url'] = remove_query_arg( 'wc-ajax', $params['wc_ajax_url'] );

		foreach ( $fields as $field ) {
			if ( ! empty( $_GET[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$params['wc_ajax_url'] = add_query_arg( $field, $_GET[ $field ], $params['wc_ajax_url'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			}
		}

		$params['wc_ajax_url'] = add_query_arg( 'wc-ajax', '%%endpoint%%', $params['wc_ajax_url'] );

		return $params;
	}

	/**
	 * Handles a success payment method change for a WooCommerce Subscription.
	 *
	 * The user has returned back from PayPal after confirming the payment method change.
	 * This updates the payment method for the subscription and redirects the user back to the
	 * subscription update page.
	 *
	 */
	public function handle_subscription_payment_change_success() {
		try {
			$session = WC()->session->get( 'paypal' );

			if ( isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( isset( $session->token ) ) {
				$token = $session->token;
			}

			if ( ! isset( $token ) ) {
				return;
			}

			if ( empty( $session ) || $this->session_has_expired( $token ) ) {
				wc_add_notice( __( 'Your PayPal checkout session has expired. Please check out again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
				return;
			}

			// Get the info we need and create the billing agreement.
			$order            = wc_get_order( $session->order_id );
			$checkout_details = $this->get_checkout_details( $token );
			$this->create_billing_agreement( $order, $checkout_details );

			// Update the payment method for the current subscription.
			AWC_Subscription_Change_Payment_Gateway::update_payment_method( $order, 'awc_paypal_payment' );
			$success_notice = __( 'The payment method was updated for this subscription.', 'subscriptions-recurring-payments-for-woocommerce' );

			// Update the payment method for all subscriptions if that checkbox was checked.
			if ( awc_is_subscription( $order ) && AWC_Subscription_Change_Payment_Gateway::will_subscription_update_all_payment_methods( $order ) ) {
				AWC_Subscription_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $order, $payment_method->id );
				$success_notice = __( 'The payment method was updated for all your current subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' );
			}

			wc_clear_notices();
			wc_add_notice( $success_notice );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
			wc_clear_notices();
			wc_add_notice( __( 'There was a problem updating your payment method. Please try again later or use a different payment method.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}
	}

	/**
	 * Handles the cancellation of a WooCommerce Subscription payment method change.
	 *
	 * The user has returned back from PayPal after cancelling the payment method change.
	 * This redirects the user back to the subscription page with an error message.
	 *
	 */
	public function handle_subscription_payment_change_failure() {
		$session      = WC()->session->get( 'paypal' );
		$order        = isset( $session->order_id )
			? wc_get_order( $session->order_id )
			: false;
		$redirect_url = is_callable( array( $order, 'get_view_order_url' ) )
			? $order->get_view_order_url()
			: false;
		wc_clear_notices();
		wc_add_notice( __( 'You have cancelled Checkout with PayPal. The payment method was not updated.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		if ( $redirect_url ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}
}