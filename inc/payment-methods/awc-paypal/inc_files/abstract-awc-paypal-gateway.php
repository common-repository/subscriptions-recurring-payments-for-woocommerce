<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AWC_Paypal_Gateway
 */
abstract class AWC_Paypal_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields         = false;
		$this->supports[]         = 'refunds';
		$this->method_title       = __( 'Advance PayPal Checkout', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->method_description = __( 'Allow your customers to checkout via paypal.', 'subscriptions-recurring-payments-for-woocommerce' );

		awc_paypal_ec_gateway()->ips->maybe_received_credentials();

		$this->init_form_fields();
		$this->init_settings();

		$this->settings['use_spb'] = awc_paypal_ec_gateway()->settings->use_spb;

		$this->title        = $this->method_title;
		$this->description  = '';
		$this->enabled      = $this->get_option( 'enabled', 'yes' );
		$this->button_size  = $this->get_option( 'button_size', 'large' );
		$this->environment  = $this->get_option( 'environment', 'live' );
		$this->mark_enabled = 'yes' === $this->get_option( 'mark_enabled', 'no' );

		if ( 'live' === $this->environment ) {
			$this->api_username    = $this->get_option( 'api_username' );
			$this->api_password    = $this->get_option( 'api_password' );
			$this->api_signature   = $this->get_option( 'api_signature' );
			$this->api_certificate = $this->get_option( 'api_certificate' );
			$this->api_subject     = $this->get_option( 'api_subject' );
		} else {
			$this->api_username    = $this->get_option( 'sandbox_api_username' );
			$this->api_password    = $this->get_option( 'sandbox_api_password' );
			$this->api_signature   = $this->get_option( 'sandbox_api_signature' );
			$this->api_certificate = $this->get_option( 'sandbox_api_certificate' );
			$this->api_subject     = $this->get_option( 'sandbox_api_subject' );
		}

		$this->debug                      = 'yes' === $this->get_option( 'debug', 'no' );
		$this->invoice_prefix             = $this->get_option( 'invoice_prefix', '' );
		$this->instant_payments           = 'yes' === $this->get_option( 'instant_payments', 'no' );
		$this->require_billing            = 'yes' === $this->get_option( 'require_billing', 'no' );
		$this->paymentaction              = $this->get_option( 'paymentaction', 'sale' );
		$this->subtotal_mismatch_behavior = $this->get_option( 'subtotal_mismatch_behavior', 'add' );
		$this->use_ppc                    = false;

		if ( empty( $_GET['woo-paypal-return'] ) && 'yes' !== $this->get_option( 'use_spb', 'yes' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->order_button_text = __( 'Continue to payment', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Change gateway name if session is active
		if ( ! is_admin() ) {
			if ( awc_paypal_ec_gateway()->checkout->is_started_from_checkout_page() ) {
				$this->title       = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );
			}
		} else {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'pass_return_args_to_ajax' ), 10, 2 );

		if ( function_exists( 'add_image_size' ) ) {
			add_image_size( 'ppec_logo_image_size', 190, 60 );
			add_image_size( 'ppec_header_image_size', 750, 90 );
		}
	}

	/**
	 * Pass woo return args to AJAX endpoint when the checkout updates from the frontend
	 * so that the order button gets set correctly.
	 *
	 * @param  string $request Optional.
	 * @return string
	 */
	public function pass_return_args_to_ajax( $request ) {
		if ( isset( $_GET['woo-paypal-return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request .= '&woo-paypal-return=1';
		}

		return $request;
	}

	/**
	 * Enqueues admin scripts.
	 *
	 
	 */
	public function enqueue_scripts() {
		// Image upload.
		wp_enqueue_media();

		$screen = get_current_screen();

		// Only enqueue the setting scripts on the PayPal Checkout settings screen.
		if ( $screen && 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['tab'], $_GET['section'] ) && 'checkout' === $_GET['tab'] && 'awc_paypal_payment' === $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'awc-ppec-gateway-settings', awc_paypal_ec_gateway()->plugin_url . 'assets/js/awc-paypal-payment-gateway-settings.js', array( 'jquery' ), awc_paypal_ec_gateway()->version, true );
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = include AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'settings.php';
	}

	/**
	 * Process payments.
	 *
	 * @param int $order_id Order ID
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$checkout = awc_paypal_ec_gateway()->checkout;
		$order    = wc_get_order( $order_id );
		$session  = WC()->session->get( 'paypal' );

		// Redirect them over to PayPal if they have no current session (this
		// is for PayPal Mark).
		if ( $checkout->is_started_from_checkout_page() ) {
			try {
				return array(
					'result'   => 'success',
					'redirect' => $checkout->start_checkout_from_order( $order_id, $this->use_ppc ),
				);
			} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
		} else {
			try {
				// Get details
				$checkout_details = $checkout->get_checkout_details( $session->token );

				$checkout_context = array(
					'order_id' => $order_id,
				);
				if ( $checkout->needs_billing_agreement_creation( $checkout_context ) ) {
					$checkout->create_billing_agreement( $order, $checkout_details );
				}

				// Complete the payment now if there's an amount to process.
				if ( $order->get_total() > 0 ) {
					$checkout->do_payment( $order, $session->token, $session->payer_id );
				} else {
					$order->payment_complete();
				}

				// Clear Cart
				WC()->cart->empty_cart();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			} catch ( AWC_Paypal_Payment_Gateway_Missing_Session_Exception $e ) {

				// For some reason, our session data is missing. Generally,
				// if we've made it this far, this shouldn't happen.
				wc_add_notice( __( 'Sorry, an error occurred while trying to process your payment. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {

				// Did we get a 10486 or 10422 back from PayPal?  If so, this
				// means we need to send the buyer back over to PayPal to have
				// them pick out a new funding method.
				$error_codes = wp_list_pluck( $e->errors, 'error_code' );

				if ( in_array( '10486', $error_codes ) || in_array( '10422', $error_codes ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
					$session->checkout_completed = false;
					$session->source             = 'order';
					$session->order_id           = $order_id;
					WC()->session->set( 'paypal', $session );

					return array(
						'result'   => 'success',
						'redirect' => awc_paypal_ec_gateway()->settings->get_paypal_redirect_url( $session->token, true ),
					);
				} else {
					do_action( 'wc_gateway_ppec_process_payment_error', $e, $order );
					wc_add_notice( $e->getMessage(), 'error' );
				}
			}
		}
	}

	/**
	 * Get info about uploaded certificate.
	 * @param  string $cert_string
	 * @return string
	 */
	private function get_certificate_info( $cert_string ) {
		if ( ! strlen( $cert_string ) ) {
			return __( 'No API certificate on file.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		$cert      = @openssl_x509_read( $cert_string ); // @codingStandardsIgnoreLine
		$cert_info = $cert ? openssl_x509_parse( $cert ) : null;
		$output    = '';

		if ( $cert_info ) {
			$valid_until = $cert_info['validTo_time_t'];
			// Translators: placeholders are a date in local format and its timezone.
			$expires = __( 'expires on %1$s (%2$s)', 'subscriptions-recurring-payments-for-woocommerce' );

			if ( $valid_until < time() ) {
				// Display in red if the cert is already expired
				// Translators: placeholders are a date in local format and its timezone.
				$expires = '<span style="color: red;">' . __( 'expired on %1$s (%2$s)', 'subscriptions-recurring-payments-for-woocommerce' ) . '</span>';
			} elseif ( $valid_until < ( time() - ( 30 * DAY_IN_SECONDS ) ) ) {
				// Also display in red if the cert is going to expire in the next 30 days
				$expires = '<span style="color: red;">' . $expires . '</span>';
			}

			$expiry_date = new WC_DateTime( "@{$valid_until}", new DateTimeZone( 'UTC' ) );
			$timestamp   = $expiry_date->getTimestamp();

			// If there's support for wp_timezone(), display the expiry date in server time. Otherwise, use UTC.
			if ( function_exists( 'wp_timezone' ) ) {
				$timestamp += $expiry_date->getOffset();
				$expiry_date->setTimezone( wp_timezone() );
			}

			$expires = sprintf( $expires, date_i18n( get_option( 'date_format' ), $timestamp ), $expiry_date->format( 'T' ) );
			// Translators: 1) is a certificate's CN, 2) is the expiration date.
			$output = sprintf( __( 'Certificate belongs to API username %1$s; %2$s.', 'subscriptions-recurring-payments-for-woocommerce' ), $cert_info['subject']['CN'], $expires );
		} else {
			$output = __( 'The certificate on file is not valid.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		return $output;
	}

	/**
	 * Do some additonal validation before saving options via the API.
	 */
	public function process_admin_options() {
		// If a certificate has been uploaded, read the contents and save that string instead.
		if ( array_key_exists( 'woocommerce_awc_paypal_payment_api_certificate', $_FILES )
			&& array_key_exists( 'tmp_name', $_FILES['woocommerce_awc_paypal_payment_api_certificate'] )
			&& array_key_exists( 'size', $_FILES['woocommerce_awc_paypal_payment_api_certificate'] )
			&& $_FILES['woocommerce_awc_paypal_payment_api_certificate']['size'] ) { 

			$_POST['woocommerce_awc_paypal_payment_api_certificate'] = base64_encode( file_get_contents( $_FILES['woocommerce_awc_paypal_payment_api_certificate']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			unlink( $_FILES['woocommerce_awc_paypal_payment_api_certificate']['tmp_name'] ); 
			unset( $_FILES['woocommerce_awc_paypal_payment_api_certificate'] );
		} elseif ( isset( $_POST['woocommerce_ppec_delete_live_api_certificate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$_POST['woocommerce_awc_paypal_payment_api_certificate'] = '';
			unset( $_POST['woocommerce_ppec_delete_live_api_certificate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} else {
			$_POST['woocommerce_awc_paypal_payment_api_certificate'] = $this->get_option( 'api_certificate' );
		}

		if ( array_key_exists( 'woocommerce_awc_paypal_payment_sandbox_api_certificate', $_FILES )
			&& array_key_exists( 'tmp_name', $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate'] )
			&& array_key_exists( 'size', $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate'] )
			&& $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate']['size'] ) { 

			$_POST['woocommerce_awc_paypal_payment_sandbox_api_certificate'] = base64_encode( file_get_contents( $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			unlink( $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate']['tmp_name'] ); 
			unset( $_FILES['woocommerce_awc_paypal_payment_sandbox_api_certificate'] );
		} elseif ( isset( $_POST['woocommerce_ppec_delete_sandbox_api_certificate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$_POST['woocommerce_awc_paypal_payment_sandbox_api_certificate'] = '';
			unset( $_POST['woocommerce_ppec_delete_sandbox_api_certificate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} else {
			$_POST['woocommerce_awc_paypal_payment_sandbox_api_certificate'] = $this->get_option( 'sandbox_api_certificate' );
		}

		parent::process_admin_options();

		// Validate credentials.
		$this->validate_active_credentials();
	}

	/**
	 * Validate the provided credentials.
	 */
	protected function validate_active_credentials() {
		$settings = awc_paypal_ec_gateway()->settings->load( true );
		$creds    = $settings->get_active_api_credentials();

		$has_username   = (bool) $creds->get_username();
		$has_password   = (bool) $creds->get_password();
		$has_credential = is_a( $creds, 'AWC_Paypal_Payment_Gateway_Credential_Signature' ) ? (bool) $creds->get_signature() : (bool) $creds->get_certificate();
		$errors         = array();

		// Attempt to validate the credentials if any one of them has been set.
		if ( ! $has_username && ! $has_password && ! $has_credential ) {
			return;
		}

		if ( ! $has_username ) {
			$errors[] = __( 'Error: You must enter API username.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		if ( ! $has_password ) {
			$errors[] = __( 'Error: You must enter API password.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		if ( ! $has_credential ) {
			$errors[] = __( 'Error: You must provide API signature or certificate.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		// Only attempt to validate the credential (signature or cert), if all fields are set.
		if ( $has_username && $has_password && $has_credential ) {
			if ( is_a( $creds, 'AWC_Paypal_Payment_Gateway_Credential_Signature' ) ) {
				try {
					$payer_id = awc_paypal_ec_gateway()->client->test_api_credentials( $creds, $settings->get_environment() );

					if ( ! $payer_id ) {
						$errors[] = __( 'Error: The API credentials you provided are not valid.  Please double-check that you entered them correctly and try again.', 'subscriptions-recurring-payments-for-woocommerce' );
					}
				} catch ( AWC_Paypal_Payment_Gateway_Exception $ex ) {

					$errors[] = __( 'An error occurred while trying to validate your API credentials. Unable to verify that your API credentials are correct.', 'subscriptions-recurring-payments-for-woocommerce' );
				}
			} elseif ( is_a( $creds, 'AWC_Paypal_Payment_Gateway_Client_Credential_Certificate' ) ) {

				$cert = @openssl_x509_read( $creds->get_certificate() ); // @codingStandardsIgnoreLine

				if ( false === $cert ) {
					$errors[] = __( 'Error: The API certificate is not valid.', 'subscriptions-recurring-payments-for-woocommerce' );
				}

				$cert_info   = openssl_x509_parse( $cert );
				$valid_until = $cert_info['validTo_time_t'];

				if ( $valid_until < time() ) {
					$errors[] = __( 'Error: The API certificate has expired.', 'subscriptions-recurring-payments-for-woocommerce' );
				} elseif ( $cert_info['subject']['CN'] !== $creds->get_username() ) {
					$errors[] = __( 'Error: The API username does not match the name in the API certificate. Make sure that you have the correct API certificate.', 'subscriptions-recurring-payments-for-woocommerce' );
				}

				try {
					$payer_id = awc_paypal_ec_gateway()->client->test_api_credentials( $creds, $settings->get_environment() );

					if ( ! $payer_id ) {
						$errors[] = __( 'Error: The API credentials you provided are not valid.  Please double-check that you entered them correctly and try again.', 'subscriptions-recurring-payments-for-woocommerce' );
					}
				} catch ( AWC_Paypal_Payment_Gateway_Exception $ex ) {
					$errors[] = __( 'An error occurred while trying to validate your API credentials.  Unable to verify that your API credentials are correct.', 'subscriptions-recurring-payments-for-woocommerce' );
				}
			}
		}

		$settings_array = (array) get_option( 'woocommerce_awc_paypal_payment_settings', array() );

		if ( 'yes' === $settings_array['require_billing'] ) {
			$is_account_enabled_for_billing_address = false;

			try {
				$is_account_enabled_for_billing_address = awc_paypal_ec_gateway()->client->test_for_billing_address_enabled( $creds, $settings->get_environment() );
			} catch ( AWC_Paypal_Payment_Gateway_Exception $ex ) {
				$is_account_enabled_for_billing_address = false;
			}

			if ( ! $is_account_enabled_for_billing_address ) {
				$settings_array['require_billing'] = 'no';
				update_option( 'woocommerce_awc_paypal_payment_settings', $settings_array );
				$errors[] = __( 'The "require billing address" option is not enabled by your account and has been disabled.', 'subscriptions-recurring-payments-for-woocommerce' );
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $message ) {
				WC_Admin_Settings::add_error( $message );
			}
			return false;
		}
	}

	/**
	 * Process refund.
	 *
	 * @param int    $order_id Order ID
	 * @param float  $amount   Order amount
	 * @param string $reason   Refund reason
	 *
	 * @return boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( 0 == $amount || null == $amount ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			return new WP_Error( 'paypal_refund_error', __( 'Refund Error: You need to specify a refund amount.', 'subscriptions-recurring-payments-for-woocommerce' ) );
		}

		// load up refundable_txns from Post Meta
		// loop through each transaction to compile list of txns that are able to be refunded
		// process refunds against each txn in the list until full amount of refund is reached
		// first loop through, try to find a transaction that equals the refund amount being requested
		$old_wc         = version_compare( WC_VERSION, '3.0', '<' );
		$txn_data       = $old_wc ? get_post_meta( $order_id, '_woo_pp_txnData', true ) : $order->get_meta( '_woo_pp_txnData', true );
		$order_currency = $old_wc ? $order->order_currency : $order->get_currency();

		if ( ! isset( $txn_data['refundable_txns'] ) ) {
			return new WP_Error( 'paypal_refund_error', __( 'Refund Error: Sorry! This is not a refundable transaction.', 'subscriptions-recurring-payments-for-woocommerce' ) );
		}

		foreach ( $txn_data['refundable_txns'] as $key => $value ) {
			$refundable_amount = $value['amount'] - $value['refunded_amount'];

			if ( $amount == $refundable_amount ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				$refund_type = ( 0 == $value['refunded_amount'] ) ? 'Full' : 'Partial'; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

				try {
					$refund_txn_id = WC_Gateway_PPEC_Refund::refund_order( $order, $amount, $refund_type, $reason, $order_currency );
					$txn_data['refundable_txns'][ $key ]['refunded_amount'] += $amount;
					// Translators: placeholder is a transaction ID.
					$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'subscriptions-recurring-payments-for-woocommerce' ), $refund_txn_id ) );
					if ( $old_wc ) {
						update_post_meta( $order_id, '_woo_pp_txnData', $txn_data );
					} else {
						$order->update_meta_data( '_woo_pp_txnData', $txn_data );
						$order->save();
					}

					return true;

				} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
					return new WP_Error( 'paypal_refund_error', $e->getMessage() );
				}
			}
		}

		foreach ( $txn_data['refundable_txns'] as $key => $value ) {
			$refundable_amount = $value['amount'] - $value['refunded_amount'];

			if ( $amount < $refundable_amount ) {

				try {
					$refund_txn_id = WC_Gateway_PPEC_Refund::refund_order( $order, $amount, 'Partial', $reason, $order_currency );
					$txn_data['refundable_txns'][ $key ]['refunded_amount'] += $amount;
					// Translators: placeholder is a transaction ID.
					$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'subscriptions-recurring-payments-for-woocommerce' ), $refund_txn_id ) );
					if ( $old_wc ) {
						update_post_meta( $order_id, '_woo_pp_txnData', $txn_data );
					} else {
						$order->update_meta_data( '_woo_pp_txnData', $txn_data );
						$order->save();
					}

					return true;

				} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
					return new WP_Error( 'paypal_refund_error', $e->getMessage() );
				}
			}
		}

		$total_refundable_amount = 0;
		foreach ( $txn_data['refundable_txns'] as $key => $value ) {
			$refundable_amount        = $value['amount'] - $value['refunded_amount'];
			$total_refundable_amount += $refundable_amount;
		}

		if ( $total_refundable_amount < $amount ) {
			if ( 0 == $total_refundable_amount ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				return new WP_Error( 'paypal_refund_error', __( 'Refund Error: All transactions have been fully refunded. There is no amount left to refund', 'subscriptions-recurring-payments-for-woocommerce' ) );
			} else {
				// Translators: placeholder is an amount (with currency symbol).
				return new WP_Error( 'paypal_refund_error', sprintf( __( 'Refund Error: The requested refund amount is too large. The refund amount must be less than or equal to %s.', 'subscriptions-recurring-payments-for-woocommerce' ), html_entity_decode( get_woocommerce_currency_symbol() ) . $total_refundable_amount ) );
			}
		} else {
			$total_to_refund = $amount;

			foreach ( $txn_data['refundable_txns'] as $key => $value ) {
				$refundable_amount = $value['amount'] - $value['refunded_amount'];

				if ( $refundable_amount > $total_to_refund ) {
					$amount_to_refund = $total_to_refund;
				} else {
					$amount_to_refund = $refundable_amount;
				}

				if ( 0 < $amount_to_refund ) {
					$refund_type = 'Partial';
					if ( 0 == $value['refunded_amount'] && $amount_to_refund == $value['amount'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
						$refund_type = 'Full';
					}

					try {
						$refund_txn_id    = WC_Gateway_PPEC_Refund::refund_order( $order, $amount_to_refund, $refund_type, $reason, $order_currency );
						$total_to_refund -= $amount_to_refund;
						$txn_data['refundable_txns'][ $key ]['refunded_amount'] += $amount_to_refund;
						// Translators: placeholder is a transaction ID.
						$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'subscriptions-recurring-payments-for-woocommerce' ), $refund_txn_id ) );
						if ( $old_wc ) {
							update_post_meta( $order_id, '_woo_pp_txnData', $txn_data );
						} else {
							$order->update_meta_data( '_woo_pp_txnData', $txn_data );
							$order->save();
						}

						return true;
					} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
						return new WP_Error( 'paypal_refund_error', $e->getMessage() );
					}
				}
			}
		}
	}

	/**
	 * Get the transaction URL.
	 *
	 * @param  WC_Order $order
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		if ( 'sandbox' === $this->environment ) {
			$this->view_transaction_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
		} else {
			$this->view_transaction_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
		}
		return parent::get_transaction_url( $order );
	}

	/**
	 * Check if this gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_available() {
		return 'yes' === $this->enabled;
	}

	/**
	 * Generate Image HTML.
	 *
	 * @param  mixed $key
	 * @param  mixed $data
	 * @return string
	 */
	public function generate_image_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);

		$data  = wp_parse_args( $data, $defaults );
		$value = $this->get_option( $key );

		// Hide show add remove buttons.
		$maybe_hide_add_style    = '';
		$maybe_hide_remove_style = '';

		// For backwards compatibility (customers that already have set a url)
		$value_is_url = filter_var( $value, FILTER_VALIDATE_URL ) !== false;

		if ( empty( $value ) || $value_is_url ) {
			$maybe_hide_remove_style = 'display: none;';
		} else {
			$maybe_hide_add_style = 'display: none;';
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>

			<td class="image-component-wrapper">
				<div class="image-preview-wrapper">
					<?php
					if ( ! $value_is_url ) {
						echo wp_get_attachment_image( $value, 'logo_image_url' === $key ? 'ppec_logo_image_size' : 'ppec_header_image_size' );
					} else {
						// Translators: placeholder is an image's URL.
						echo sprintf( esc_html__( 'Already using URL as image: %s', 'subscriptions-recurring-payments-for-woocommerce' ), esc_attr( $value ) );
					}
					?>
				</div>

				<button
					class="button image_upload"
					data-field-id="<?php echo esc_attr( $field_key ); ?>"
					data-media-frame-title="<?php echo esc_attr( __( 'Select a image to upload', 'subscriptions-recurring-payments-for-woocommerce' ) ); ?>"
					data-media-frame-button="<?php echo esc_attr( __( 'Use this image', 'subscriptions-recurring-payments-for-woocommerce' ) ); ?>"
					data-add-image-text="<?php echo esc_attr( __( 'Add image', 'subscriptions-recurring-payments-for-woocommerce' ) ); ?>"
					style="<?php echo esc_attr( $maybe_hide_add_style ); ?>"
				>
					<?php echo esc_html__( 'Add image', 'subscriptions-recurring-payments-for-woocommerce' ); ?>
				</button>

				<button
					class="button image_remove"
					data-field-id="<?php echo esc_attr( $field_key ); ?>"
					style="<?php echo esc_attr( $maybe_hide_remove_style ); ?>"
				>
					<?php echo esc_html__( 'Remove image', 'subscriptions-recurring-payments-for-woocommerce' ); ?>
				</button>

				<input type="hidden"
					name="<?php echo esc_attr( $field_key ); ?>"
					id="<?php echo esc_attr( $field_key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
				/>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Gets the description for an environment's certificate setting.
	 *
	 * Includes information about the certificate on file and remove link.
	 *
	 * @param string $environment The environment. Optional. Can be 'live' or 'sandbox'. Default is 'live'.
	 * @return string The HTML string for an environment's certificate including a remove link if one is on file.
	 */
	private function get_certificate_setting_description( $environment = 'live' ) {
		if ( 'live' === $environment ) {
			$credentials = awc_paypal_ec_gateway()->settings->get_live_api_credentials();
		} else {
			$credentials = awc_paypal_ec_gateway()->settings->get_sandbox_api_credentials();
		}

		// If we don't have a certificate credential return the empty certificate info.
		if ( ! is_callable( array( $credentials, 'get_certificate' ) ) ) {
			return $this->get_certificate_info( '' );
		}

		return sprintf(
			'%1$s <a href="#" class="awc_paypal_remove_certificate" data-environment="%2$s">%3$s</a>',
			$this->get_certificate_info( $credentials->get_certificate() ),
			esc_attr( $environment ),
			__( 'Remove', 'subscriptions-recurring-payments-for-woocommerce' )
		);
	}
}
