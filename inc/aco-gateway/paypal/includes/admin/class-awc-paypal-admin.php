<?php
/**
 * Aco WooCommerce Subscriptions PayPal Administration Class.
 *
 * Hooks into WooCommerce's core PayPal class to display fields and notices relating to subscriptions.
 *
 * @package     Aco WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * 
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_PayPal_Admin {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {

		// Add PayPal API fields to PayPal form fields as required
		add_action( 'woocommerce_settings_start', __CLASS__ . '::add_form_fields', 100 );
		add_action( 'woocommerce_api_wc_gateway_paypal', __CLASS__ . '::add_form_fields', 100 );

		// Handle requests to check whether a PayPal account has Reference Transactions enabled
		add_action( 'admin_init', __CLASS__ . '::maybe_check_account' );


		// Add the PayPal subscription information to the billing information
		add_action( 'woocommerce_admin_order_data_after_billing_address', __CLASS__ . '::profile_link' );

		// Before WC updates the PayPal settings remove credentials error flag
		add_action( 'load-woocommerce_page_wc-settings', __CLASS__ . '::maybe_update_credentials_error_flag', 9 );

		// Add an enable for subscription purchases setting.
		add_action( 'woocommerce_settings_api_form_fields_paypal', array( __CLASS__, 'add_enable_for_subscriptions_setting' ) );
	}

	/**
	 * Adds extra PayPal credential fields required to manage subscriptions.
	 *
	 
	 */
	public static function add_form_fields() {

		foreach ( WC()->payment_gateways->payment_gateways as $key => $gateway ) {

			if ( WC()->payment_gateways->payment_gateways[ $key ]->id !== 'paypal' ) {
				continue;
			}

			// Warn store managers not to change their PayPal Email address as it can break existing Subscriptions in WC2.0+
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['desc_tip']     = false;
			// translators: $1 and $2 are opening and closing strong tags, respectively.
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['description'] .= ' </p><p class="description">' . sprintf( __( 'It is %1$sstrongly recommended you do not change the Receiver Email address%2$s if you have active subscriptions with PayPal. Doing so can break existing subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' ), '<strong>', '</strong>' );
		}
	}

	/**
	 * Handle requests to check whether a PayPal account has Reference Transactions enabled
	 *
	 
	 */
	public static function maybe_check_account() {

		if ( isset( $_GET['awc_paypal'] ) && 'check_reference_transaction_support' === $_GET['awc_paypal'] && wp_verify_nonce( $_GET['_wpnonce'], __CLASS__ ) ) {

			$redirect_url = remove_query_arg( array( 'awc_paypal', '_wpnonce' ) );

			if ( AWC_PayPal::are_reference_transactions_enabled( 'bypass_cache' ) ) {
				$redirect_url = add_query_arg( array( 'awc_paypal' => 'rt_enabled' ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( array( 'awc_paypal' => 'rt_not_enabled' ), $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
		}
	}



	/**
	 * Disable the invalid profile notice when requested.
	 *
	 */
	protected static function maybe_disable_invalid_profile_notice() {
		if ( isset( $_GET['awc_disable_paypal_invalid_profile_id_notice'] ) ) {
			update_option( 'awc_paypal_invalid_profile_id', 'disabled' );
		}

		if ( isset( $_GET['awc_ipn_error_notice'] ) ) {
			update_option( 'awc_fatal_error_handling_ipn_ignored', true );
		}
	}

	/**
	 * Remove the invalid credentials error flag whenever a new set of API credentials are saved.
	 *
	 
	 */
	public static function maybe_update_credentials_error_flag() {

		// Check if the API credentials are being saved - we can't do this on the 'woocommerce_update_options_payment_gateways_paypal' hook because it is triggered after 'admin_notices'
		if ( ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'woocommerce-settings' ) && isset( $_POST['woocommerce_paypal_api_username'] ) || isset( $_POST['woocommerce_paypal_api_password'] ) || isset( $_POST['woocommerce_paypal_api_signature'] ) ) {

			$credentials_updated = false;

			if ( isset( $_POST['woocommerce_paypal_api_username'] ) && awc_PayPal::get_option( 'api_username' ) != $_POST['woocommerce_paypal_api_username'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_password'] ) && awc_PayPal::get_option( 'api_password' ) != $_POST['woocommerce_paypal_api_password'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_signature'] ) && awc_PayPal::get_option( 'api_signature' ) != $_POST['woocommerce_paypal_api_signature'] ) {
				$credentials_updated = true;
			}

			if ( $credentials_updated ) {
				delete_option( 'awc_paypal_credentials_error' );
			}
		}

		do_action( 'awc_paypal_admin_update_credentials' );
	}


	/**
	 * Prints link to the PayPal's profile related to the provided subscription
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function profile_link( $subscription ) {
		if ( ! awc_is_subscription( $subscription ) || $subscription->is_manual() || 'paypal' != $subscription->get_payment_method() ) {
			return;
		}

		$paypal_profile_id = awc_get_paypal_id( $subscription );

		if ( empty( $paypal_profile_id ) ) {
			return;
		}

		$url    = '';
		$domain = AWC_PayPal::get_option( 'testmode' ) === 'yes' ? 'sandbox.paypal' : 'paypal';

		if ( false === awc_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			// Standard subscription
			$url = "https://www.{$domain}.com/?cmd=_profile-recurring-payments&encrypted_profile_id={$paypal_profile_id}";
		} elseif ( awc_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			// Reference Transaction subscription
			$url = "https://www.{$domain}.com/?cmd=_profile-merchant-pull&encrypted_profile_id={$paypal_profile_id}&mp_id={$paypal_profile_id}&return_to=merchant&flag_flow=merchant";
		}

		echo '<div class="address">';
		echo '<p class="paypal_subscription_info"><strong>';
		echo esc_html( __( 'PayPal Subscription ID:', 'subscriptions-recurring-payments-for-woocommerce' ) );
		echo '</strong>';

		if ( ! empty( $url ) ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $paypal_profile_id ) . '</a>';
		} else {
			echo esc_html( $paypal_profile_id );
		}

		echo '</p></div>';
	}

	/**
	 * Add the enabled or subscriptions setting.
	 *
	 * @param array $settings The WooCommerce PayPal Settings array.
	 * @return array
	 
	 */
	public static function add_enable_for_subscriptions_setting( $settings ) {
		if ( AWC_PayPal::are_reference_transactions_enabled() ) {
			return $settings;
		}

		$setting = array(
			'type'    => 'checkbox',
			'label'   => __( 'Enable PayPal for Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ),
			'default' => 'no',
		);


		$settings = awc_array_insert_after( 'enabled', $settings, 'enabled_for_subscriptions', $setting );

		return $settings;
	}
}
