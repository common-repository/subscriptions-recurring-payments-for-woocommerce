<?php
/**
 * PayPal Checkout Plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Paypal_Payment_Methods_for_Subscription {

	const ALREADY_BOOTSTRAPED      = 1;
	const DEPENDENCIES_UNSATISFIED = 2;
	const NOT_CONNECTED            = 3;

	/**
	 * Filepath of main plugin file.
	 *
	 * @var string
	 */
	public $file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Absolute plugin path.
	 *
	 * @var string
	 */
	public static $plugin_path;

	/**
	 * Absolute plugin URL.
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Absolute path to plugin includes dir.
	 *
	 * @var string
	 */
	public static $includes_path;

	/**
	 * Flag to indicate the plugin has been boostrapped.
	 *
	 * @var bool
	 */
	private $_bootstrapped = false;

	/**
	 * Instance of AWC_Paypal_Payment_Gateway_Settings.
	 *
	 * @var AWC_Paypal_Payment_Gateway_Settings
	 */
	public $settings;

	/**
	 * Constructor.
	 *
	 * @param string $file    Filepath of main plugin file
	 * @param string $version Plugin version
	 */
	public function __construct( $file, $version ) {
		$this->file    = $file;
		$this->version = $version;

		// Path.
		self::$plugin_path   = trailingslashit( plugin_dir_path( $this->file ) );
		$this->plugin_url    = trailingslashit( plugin_dir_url( $this->file ) );
		self::$includes_path = self::$plugin_path . trailingslashit( 'inc_files' );
	}

	/**
	 * Handle updates.
	 *
	 * @param string $new_version The plugin's new version.
	 */
	private function run_updater( $new_version ) {
		// Map old settings to settings API
		if ( get_option( 'pp_woo_enabled' ) ) {
			$settings_array                               = (array) get_option( 'woocommerce_awc_paypal_payment_settings', array() );
			$settings_array['enabled']                    = get_option( 'pp_woo_enabled' ) ? 'yes' : 'no';
			$settings_array['logo_image_url']             = get_option( 'pp_woo_logoImageUrl' );
			$settings_array['paymentAction']              = strtolower( get_option( 'pp_woo_paymentAction', 'sale' ) );
			$settings_array['subtotal_mismatch_behavior'] = 'addLineItem' === get_option( 'pp_woo_subtotalMismatchBehavior' ) ? 'add' : 'drop';
			$settings_array['environment']                = get_option( 'pp_woo_environment' );
			$settings_array['button_size']                = get_option( 'pp_woo_button_size' );
			$settings_array['instant_payments']           = get_option( 'pp_woo_blockEChecks' );
			$settings_array['require_billing']            = get_option( 'pp_woo_requireBillingAddress' );
			$settings_array['debug']                      = get_option( 'pp_woo_logging_enabled' ) ? 'yes' : 'no';

			// Make sure button size is correct.
			if ( ! in_array( $settings_array['button_size'], array( 'small', 'medium', 'large' ), true ) ) {
				$settings_array['button_size'] = 'medium';
			}

			// Load client classes before `is_a` check on credentials instance.
			$this->_load_client();

			$live    = get_option( 'pp_woo_liveApiCredentials' );
			$sandbox = get_option( 'pp_woo_sandboxApiCredentials' );

			if ( $live && is_a( $live, 'AWC_Paypal_Payment_Gateway_Client_Credential' ) ) {
				$settings_array['api_username']    = $live->get_username();
				$settings_array['api_password']    = $live->get_password();
				$settings_array['api_signature']   = is_callable( array( $live, 'get_signature' ) ) ? $live->get_signature() : '';
				$settings_array['api_certificate'] = is_callable( array( $live, 'get_certificate' ) ) ? $live->get_certificate() : '';
				$settings_array['api_subject']     = $live->get_subject();
			}

			if ( $sandbox && is_a( $sandbox, 'AWC_Paypal_Payment_Gateway_Client_Credential' ) ) {
				$settings_array['sandbox_api_username']    = $sandbox->get_username();
				$settings_array['sandbox_api_password']    = $sandbox->get_password();
				$settings_array['sandbox_api_signature']   = is_callable( array( $sandbox, 'get_signature' ) ) ? $sandbox->get_signature() : '';
				$settings_array['sandbox_api_certificate'] = is_callable( array( $sandbox, 'get_certificate' ) ) ? $sandbox->get_certificate() : '';
				$settings_array['sandbox_api_subject']     = $sandbox->get_subject();
			}

			update_option( 'woocommerce_awc_paypal_payment_settings', $settings_array );
			delete_option( 'pp_woo_enabled' );
		}

		$previous_version = get_option( 'awc_paypal_payment_gateway_version' );

		// Check the the WC version on plugin update to determine if we need to display a warning.
		// The option was added in 1.6.19 so we only need to check stores updating from before that version. Updating from 1.6.19 or greater would already have it set.
		if ( version_compare( $previous_version, '1.6.19', '<' ) && version_compare( WC_VERSION, '3.0', '<' ) ) {
			update_option( 'wc_ppec_display_wc_3_0_warning', 'true' );
		}

		// Credit messaging is disabled by default for merchants upgrading from < 2.1.
		if ( $previous_version && version_compare( $previous_version, '2.1.0', '<' ) ) {
			$settings = get_option( 'woocommerce_awc_paypal_payment_settings', array() );

			if ( is_array( $settings ) ) {
				$settings['credit_message_enabled']                = 'no';
				$settings['single_product_credit_message_enabled'] = 'no';
				$settings['mark_credit_message_enabled']           = 'no';

				update_option( 'woocommerce_awc_paypal_payment_settings', $settings );
			}
		}

		if ( function_exists( 'add_woocommerce_inbox_variant' ) ) {
			add_woocommerce_inbox_variant();
		}

		update_option( 'awc_paypal_payment_gateway_version', $new_version );
	}

	/**
	 * Maybe run the plugin.
	 */
	public function maybe_run() {
		register_activation_hook( $this->file, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'whitelist_paypal_domains_for_redirect' ) );

		
		add_action( 'wp_ajax_awc_paypal_payment_dismiss_notice_message', array( $this, 'ajax_dismiss_notice' ) );

		
		add_action( 'wp_ajax_awc_paypal_payment_gateway_dismiss_upgrade_notice', array( $this, 'awc_paypal_payment_upgrade_notice_dismiss_ajax_callback' ) );
	}

	public function bootstrap() {
		try {
			if ( $this->_bootstrapped ) {
				throw new Exception( __( 'bootstrap() in WooCommerce Gateway PayPal Checkout plugin can only be called once', 'subscriptions-recurring-payments-for-woocommerce' ), self::ALREADY_BOOTSTRAPED );
			}

			$this->_check_dependencies();

			if ( $this->needs_update() ) {
				$this->run_updater( $this->version );
			}

			$this->_run();
			$this->_check_credentials();

			$this->_bootstrapped = true;
		} catch ( Exception $e ) {
			if ( in_array( $e->getCode(), array( self::ALREADY_BOOTSTRAPED, self::DEPENDENCIES_UNSATISFIED ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
				$this->bootstrap_warning_message = $e->getMessage();
			}

			if ( self::NOT_CONNECTED === $e->getCode() ) {
				$this->prompt_to_connect = $e->getMessage();
			}

			add_action( 'admin_notices', array( $this, 'show_bootstrap_warning' ) );
		}
	}


	/**
	 * @access	public
	 */
	public function show_bootstrap_warning() {
		$dependencies_message = isset( $this->bootstrap_warning_message ) ? $this->bootstrap_warning_message : null;
		if ( ! empty( $dependencies_message ) && 'yes' !== get_option( 'wc_gateway_ppec_bootstrap_warning_message_dismissed', 'no' ) ) {
			?>
			<div class="notice notice-warning is-dismissible awc-paypal-payment-dismiss-warning-message">
				<p>
					<strong><?php echo esc_html( $dependencies_message ); ?></strong>
				</p>
			</div>
			<script>
			( function( $ ) {
				$( '.awc-paypal-payment-dismiss-warning-message' ).on( 'click', '.notice-dismiss', function() {
					jQuery.post( "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", {
						action: "awc_paypal_payment_dismiss_notice_message",
						dismiss_action: "ppec_dismiss_bootstrap_warning_message",
						nonce: "<?php echo esc_js( wp_create_nonce( 'ppec_dismiss_notice' ) ); ?>"
					} );
				} );
			} )( jQuery );
			</script>
			<?php
		}

		$prompt_connect = isset( $this->prompt_to_connect ) ? $this->prompt_to_connect : null;
		if ( ! empty( $prompt_connect ) && 'yes' !== get_option( 'wc_gateway_ppec_prompt_to_connect_message_dismissed', 'no' ) ) {
			?>
			<div class="notice notice-warning is-dismissible ppec-dismiss-prompt-to-connect-message">
				<p>
					<strong><?php echo wp_kses( $prompt_connect, array( 'a' => array( 'href' => array() ) ) ); ?></strong>
				</p>
			</div>
			<script>
			( function( $ ) {
				$( '.ppec-dismiss-prompt-to-connect-message' ).on( 'click', '.notice-dismiss', function() {
					jQuery.post( "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", {
						action: "awc_paypal_payment_dismiss_notice_message",
						dismiss_action: "ppec_dismiss_prompt_to_connect",
						nonce: "<?php echo esc_js( wp_create_nonce( 'ppec_dismiss_notice' ) ); ?>"
					} );
				} );
			} )( jQuery );
			</script>
			<?php
		}
	}

	/**
	 * AJAX handler for dismiss notice action.
	 *
	 */
	public function ajax_dismiss_notice() {
		if ( empty( $_POST['dismiss_action'] ) ) {
			return;
		}

		check_ajax_referer( 'ppec_dismiss_notice', 'nonce' );
		switch ( $_POST['dismiss_action'] ) {
			case 'ppec_dismiss_bootstrap_warning_message':
				update_option( 'wc_gateway_ppec_bootstrap_warning_message_dismissed', 'yes' );
				break;
			case 'ppec_dismiss_prompt_to_connect':
				update_option( 'wc_gateway_ppec_prompt_to_connect_message_dismissed', 'yes' );
				break;
		}
		wp_die();
	}

	/**
	 * Check dependencies.
	 *
	 * @throws Exception
	 */
	protected function _check_dependencies() {
		if ( ! function_exists( 'WC' ) ) {
			throw new Exception( __( 'WooCommerce Gateway PayPal Checkout requires WooCommerce to be activated', 'subscriptions-recurring-payments-for-woocommerce' ), self::DEPENDENCIES_UNSATISFIED );
		}

		if ( version_compare( WC()->version, '3.2.0', '<' ) ) {
			throw new Exception( __( 'WooCommerce Gateway PayPal Checkout requires WooCommerce version 3.2.0 or greater', 'subscriptions-recurring-payments-for-woocommerce' ), self::DEPENDENCIES_UNSATISFIED );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			throw new Exception( __( 'WooCommerce Gateway PayPal Checkout requires cURL to be installed on your server', 'subscriptions-recurring-payments-for-woocommerce' ), self::DEPENDENCIES_UNSATISFIED );
		}

		$openssl_warning = __( 'WooCommerce Gateway PayPal Checkout requires OpenSSL >= 1.0.1 to be installed on your server', 'subscriptions-recurring-payments-for-woocommerce' );
		if ( ! defined( 'OPENSSL_VERSION_TEXT' ) ) {
			throw new Exception( $openssl_warning, self::DEPENDENCIES_UNSATISFIED );
		}

		preg_match( '/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches );
		if ( empty( $matches[1] ) ) {
			throw new Exception( $openssl_warning, self::DEPENDENCIES_UNSATISFIED );
		}

		if ( ! version_compare( $matches[1], '1.0.1', '>=' ) ) {
			throw new Exception( $openssl_warning, self::DEPENDENCIES_UNSATISFIED );
		}
	}

	/**
	 * Check credentials. If it's not client credential it means it's not set
	 * and will prompt admin to connect.
	 *
	 * @see https://github.com/woothemes/aco-woo-subscriptions/issues/112
	 *
	 * @throws Exception
	 */
	protected function _check_credentials() {
		$credential = $this->settings->get_active_api_credentials();
		if ( ! is_a( $credential, 'AWC_Paypal_Payment_Gateway_Client_Credential' ) || '' === $credential->get_username() ) {
			$setting_link = $this->get_admin_setting_link();
			// Translators: placeholder is the URL of the gateway settings page.
			throw new Exception( sprintf( __( 'PayPal Checkout is almost ready. To get started, <a href="%s">connect your PayPal account</a>.', 'subscriptions-recurring-payments-for-woocommerce' ), esc_url( $setting_link ) ), self::NOT_CONNECTED );
		}
	}

	/**
	 * Run the plugin.
	 */
	protected function _run() {
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'awc-functions.php';
		$this->_load_handlers();
	}

	/**
	 * Callback for activation hook.
	 */
	public function activate() {
		if ( ! isset( $this->settings ) ) {
			require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-settings.php';
			$settings = new AWC_Paypal_Payment_Gateway_Settings();
		} else {
			$settings = $this->settings;
		}

		// Force zero decimal on specific currencies.
		if ( $settings->currency_has_decimal_restriction() ) {
			update_option( 'woocommerce_price_num_decimals', 0 );
			update_option( 'wc_gateway_ppce_display_decimal_msg', true );
		}
	}

	/**
	 * Load handlers.
	 */
	protected function _load_handlers() {

		// Load handlers.
		foreach(
			array(
				'abstract-class-awc-paypal-payment-gateway-client-credential',
				'class-awc-paypal-payment-gateway-client-credential-certificate',
				'class-awc-paypal-payment-gateway-credential-signature',
				'class-awc-paypal-payment-gateway-client',

				'class-awc-paypal-payment-gateway-settings', 
				'class-awc-privacy-for-paypal-payment-gateway', 
				'class-awc-paypal-payment-gateway-loader',
				'class-awc-paypal-payment-gateway-admin-handler',
				'class-awc-paypal-payment-gateway-checkout-handler',
				'class-awc-paypal-payment-gateway-cart-handler',
				'class-awc-paypal-payment-gateway-ips-handler',
				'abstract-awc-paypal-payment-gateway-request-handler',
				'class-awc-paypal-payment-gateway-ipn-handler'
			) as $file
		){
			awc_include_files($file);
		}

		

		$this->settings       = new AWC_Paypal_Payment_Gateway_Settings();
		$this->gateway_loader = new AWC_Paypal_Payment_Gateway_Loader();
		$this->admin          = new AWC_Paypal_Payment_Gateway_Admin_Handler();
		$this->checkout       = new AWC_Paypal_Payment_Gateway_Checkout_Handler();
		$this->cart           = new AWC_Paypal_Payment_Gateway_Cart_Handler();
		$this->ips            = new AWC_Paypal_Payment_Gateway_IPS_Handler();
		$this->client         = new AWC_Paypal_Payment_Gateway_Client( $this->settings->get_active_api_credentials(), $this->settings->environment );
	}



	/**
	 * Checks if the plugin needs to record an update.
	 *
	 * @return bool Whether the plugin needs to be updated.
	 */
	protected function needs_update() {
		return version_compare( $this->version, get_option( 'awc_paypal_payment_gateway_version' ), '>' );
	}

	/**
	 * Link to settings screen.
	 */
	public function get_admin_setting_link() {
		if ( version_compare( WC()->version, '2.6', '>=' ) ) {
			$section_slug = 'awc_paypal_payment';
		} else {
			$section_slug = strtolower( 'AWC_Paypal_Payment_Gateway' );
		}
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section_slug );
	}



	/**
	 * Allow PayPal domains for redirect.
	 *
	 * @param array $domains Whitelisted domains for `wp_safe_redirect`
	 *
	 * @return array $domains Whitelisted domains for `wp_safe_redirect`
	 */
	public function whitelist_paypal_domains_for_redirect( $domains ) {
		$domains[] = 'www.paypal.com';
		$domains[] = 'paypal.com';
		$domains[] = 'www.sandbox.paypal.com';
		$domains[] = 'sandbox.paypal.com';
		return $domains;
	}


	/**
	 * Check if shipping is needed for PayPal. This only checks for virtual products (#286),
	 * but skips the check if there are no shipping methods enabled (#249).
	 *
	 *
	 * @return bool
	 */
	public static function needs_shipping() {
		$needs_shipping = false;

		if ( ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
				if ( $values['data']->needs_shipping() ) {
					$needs_shipping = true;
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );
	}



	
	public function awc_paypal_payment_upgrade_notice_dismiss_ajax_callback() {
		check_ajax_referer( 'ppec-upgrade-notice-dismiss' );
		set_transient( 'ppec-upgrade-notice-dismissed', 'yes', MONTH_IN_SECONDS );
		wp_send_json_success();
	}

	/* Deprecated Functions */

	public function show_spb_notice() {
		_deprecated_function( __METHOD__, '1.7.0' );

		// Should only show when PPEC is enabled but not in SPB mode.
		if ( 'yes' !== $this->settings->enabled || 'yes' === $this->settings->use_spb ) {
			return;
		}

		// Should only show on WooCommerce screens, the main dashboard, and on the plugins screen (as in WC_Admin_Notices).
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		if ( ! in_array( $screen_id, wc_get_screen_ids(), true ) && 'dashboard' !== $screen_id && 'plugins' !== $screen_id ) {
			return;
		}

		$setting_link = $this->get_admin_setting_link();
		// Translators: placeholder is the URL of the gateway settings page.
		$message = sprintf( __( '<p>PayPal Checkout with new <strong>Smart Payment Buttons™</strong> gives your customers the power to pay the way they want without leaving your site.</p><p>The <strong>existing buttons will be removed</strong> in the <strong>next release</strong>. Please upgrade to Smart Payment Buttons on the <a href="%s">PayPal Checkout settings page</a>.</p>', 'subscriptions-recurring-payments-for-woocommerce' ), esc_url( $setting_link ) );
		?>
		<div class="notice notice-error">
			<?php echo wp_kses( $message, array( 'a' => array( 'href' => array() ), 'strong' => array(), 'p' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
		</div>
		<?php
	}
}
