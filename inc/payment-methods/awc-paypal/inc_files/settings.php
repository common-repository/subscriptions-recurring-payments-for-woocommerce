<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


$live_credentials       = awc_paypal_ec_gateway()->settings->get_live_api_credentials();
$sandbox_credentials    = awc_paypal_ec_gateway()->settings->get_sandbox_api_credentials();
$has_live_credential    = is_a( $live_credentials, 'AWC_Paypal_Payment_Gateway_Credential_Signature' ) ? (bool) $live_credentials->get_signature() : (bool) $live_credentials->get_certificate();
$has_sandbox_credential = is_a( $sandbox_credentials, 'AWC_Paypal_Payment_Gateway_Credential_Signature' ) ? (bool) $sandbox_credentials->get_signature() : (bool) $sandbox_credentials->get_certificate();

$needs_creds         = ! $has_live_credential && ! (bool) $live_credentials->get_username() && ! (bool) $live_credentials->get_password();
$needs_sandbox_creds = ! $has_sandbox_credential && ! (bool) $sandbox_credentials->get_username() && ! (bool) $sandbox_credentials->get_password();
$enable_ips          = awc_paypal_ec_gateway()->ips->is_supported();

if ( $enable_ips && $needs_creds ) {
	$ips_button = '<a href="' . esc_url( awc_paypal_ec_gateway()->ips->get_signup_url( 'live' ) ) . '" class="button button-primary">' . __( 'Setup or link an existing PayPal account', 'subscriptions-recurring-payments-for-woocommerce' ) . '</a>';
	// Translators: placeholder is the button "Setup or link an existing PayPal account".
	$api_creds_text = sprintf( __( '%s or <a href="#" class="ppec-toggle-settings">click here to toggle manual API credential input</a>.', 'subscriptions-recurring-payments-for-woocommerce' ), $ips_button );
} else {
	$reset_link = add_query_arg(
		array(
			'reset_ppec_api_credentials' => 'true',
			'environment'                => 'live',
			'reset_nonce'                => wp_create_nonce( 'reset_ppec_api_credentials' ),
		),
		awc_paypal_ec_gateway()->get_admin_setting_link()
	);

	$api_creds_text = __( 'To reset current credentials and use another account.', 'subscriptions-recurring-payments-for-woocommerce' );
}

if ( $enable_ips && $needs_sandbox_creds ) {
	$sandbox_ips_button = '<a href="' . esc_url( awc_paypal_ec_gateway()->ips->get_signup_url( 'sandbox' ) ) . '" class="button button-primary">' . __( 'Setup or link an existing PayPal Sandbox account', 'subscriptions-recurring-payments-for-woocommerce' ) . '</a>';
	// Translators: placeholder is the button "Setup or link an existing PayPal sandbox account".
	$sandbox_api_creds_text = sprintf( __( '%s or <a href="#" class="awc-paypal-settings-sandbox-toggle-settings">click here to toggle manual API credential input</a>.', 'subscriptions-recurring-payments-for-woocommerce' ), $sandbox_ips_button );
} else {
	$reset_link = add_query_arg(
		array(
			'reset_ppec_api_credentials' => 'true',
			'environment'                => 'sandbox',
			'reset_nonce'                => wp_create_nonce( 'reset_ppec_api_credentials' ),
		),
		awc_paypal_ec_gateway()->get_admin_setting_link()
	);

	$sandbox_api_creds_text = __( 'Your account setting is set to sandbox, no real charging takes place. To accept live payments, switch your environment to live and connect your PayPal account. To reset current credentials and use other sandbox account.', 'subscriptions-recurring-payments-for-woocommerce' );
}

$credit_enabled_label = __( 'Enable PayPal Credit to eligible customers', 'subscriptions-recurring-payments-for-woocommerce' );
if ( ! wc_gateway_ppec_is_credit_supported() ) {
	$credit_enabled_label .= '<p><em>' . __( 'This option is disabled. Currently PayPal Credit only available for U.S. merchants using USD currency.', 'subscriptions-recurring-payments-for-woocommerce' ) . '</em></p>';
}

$credit_enabled_description = __( 'This enables PayPal Credit, which displays a PayPal Credit button next to the primary PayPal Checkout button. PayPal Checkout lets you give customers access to financing through PayPal Credit® - at no additional cost to you. You get paid up front, even though customers have more time to pay. A pre-integrated payment button shows up next to the PayPal Button, and lets customers pay quickly with PayPal Credit®. (Should be unchecked for stores involved in Real Money Gaming.)', 'subscriptions-recurring-payments-for-woocommerce' );

/**
 * Settings for PayPal Gateway.
 */
$settings = array(
	'enabled' => array(
		'title'   => __( 'Enable/Disable', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable PayPal Checkout', 'subscriptions-recurring-payments-for-woocommerce' ),
		'description' => __( 'This enables PayPal Checkout which allows customers to checkout directly via PayPal from your cart page.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'yes',
	),

	'title' => array(
		'title'       => __( 'Title', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => __( 'PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( 'Description', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'desc_tip'    => true,
		'description' => __( 'This controls the description which the user sees during checkout.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => __( 'Pay via PayPal; you can pay with your credit card if you don\'t have a PayPal account.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),

	'account_settings' => array(
		'title'       => __( 'Account Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => '',
	),
	'environment' => array(
		'title'       => __( 'Environment', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select',
		'description' => __( 'This setting specifies whether you will process live transactions, or whether you will process simulated transactions using the PayPal Sandbox.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'live',
		'desc_tip'    => true,
		'options'     => array(
			'live'    => __( 'Live', 'subscriptions-recurring-payments-for-woocommerce' ),
			'sandbox' => __( 'Sandbox', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),

	'api_credentials' => array(
		'title'       => __( 'API Credentials', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => $api_creds_text,
	),
	'api_username' => array(
		'title'       => __( 'Live API Username', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
	),
	'api_password' => array(
		'title'       => __( 'Live API Password', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'password',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
	),
	'api_signature' => array(
		'title'       => __( 'Live API Signature', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional if you provide a certificate below', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'api_certificate' => array(
		'title'       => __( 'Live API Certificate', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'file',
		'description' => $this->get_certificate_setting_description(),
		'default'     => '',
	),
	'api_subject' => array(
		'title'       => __( 'Live API Subject', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'If you\'re processing transactions on behalf of someone else\'s PayPal account, enter their email address or Secure Merchant Account ID (also known as a Payer ID) here. Generally, you must have API permissions in place with the other account in order to process anything other than "sale" transactions for them.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'sandbox_api_credentials' => array(
		'title'       => __( 'Sandbox API Credentials', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => $sandbox_api_creds_text,
	),
	'sandbox_api_username' => array(
		'title'       => __( 'Sandbox API Username', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
	),
	'sandbox_api_password' => array(
		'title'       => __( 'Sandbox API Password', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'password',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
	),
	'sandbox_api_signature' => array(
		'title'       => __( 'Sandbox API Signature', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Get your API credentials from PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional if you provide a certificate below', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'sandbox_api_certificate' => array(
		'title'       => __( 'Sandbox API Certificate', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'file',
		'description' => $this->get_certificate_setting_description( 'sandbox' ),
		'default'     => '',
	),
	'sandbox_api_subject' => array(
		'title'       => __( 'Sandbox API Subject', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'If you\'re processing transactions on behalf of someone else\'s PayPal account, enter their email address or Secure Merchant Account ID (also known as a Payer ID) here. Generally, you must have API permissions in place with the other account in order to process anything other than "sale" transactions for them.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'paypal_hosted_settings' => array(
		'title'       => __( 'PayPal-hosted Checkout Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => __( 'Customize the appearance of PayPal Checkout on the PayPal side.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'brand_name' => array(
		'title'       => __( 'Brand Name', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A label that overrides the business name in the PayPal account on the PayPal hosted checkout pages.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => get_bloginfo( 'name', 'display' ),
		'desc_tip'    => true,
	),
	'logo_image_url' => array(
		'title'       => __( 'Logo Image (190×60)', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'image',
		'description' => __( 'If you want PayPal to co-brand the checkout page with your logo, enter the URL of your logo image here.<br/>The image must be no larger than 190x60, GIF, PNG, or JPG format, and should be served over HTTPS.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'header_image_url' => array(
		'title'       => __( 'Header Image (750×90)', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'image',
		'description' => __( 'If you want PayPal to co-brand the checkout page with your header, enter the URL of your header image here.<br/>The image must be no larger than 750x90, GIF, PNG, or JPG format, and should be served over HTTPS.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'page_style' => array(
		'title'       => __( 'Page Style', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Optionally enter the name of the page style you wish to use. These are defined within your PayPal account.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => '',
		'desc_tip'    => true,
		'placeholder' => __( 'Optional', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'landing_page' => array(
		'title'       => __( 'Landing Page', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select',
		'description' => __( 'Type of PayPal page to display.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'Login',
		'desc_tip'    => true,
		'options'     => array(
			'Billing' => _x( 'Billing (Non-PayPal account)', 'Type of PayPal page', 'subscriptions-recurring-payments-for-woocommerce' ),
			'Login'   => _x( 'Login (PayPal account login)', 'Type of PayPal page', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),

	'advanced' => array(
		'title'       => __( 'Advanced Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => '',
	),
	'debug' => array(
		'title'       => __( 'Debug Log', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Enable Logging', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'no',
		'desc_tip'    => true,
		'description' => __( 'Log PayPal events, such as IPN requests.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'invoice_prefix' => array(
		'title'       => __( 'Invoice Prefix', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Please enter a prefix for your invoice numbers. If you use your PayPal account for multiple stores ensure this prefix is unique as PayPal will not allow orders with the same invoice number.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'WC-',
		'desc_tip'    => true,
	),
	'require_billing' => array(
		'title'       => __( 'Billing Addresses', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Require Billing Address', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'no',
		'description' => sprintf(
			/* Translators: 1) is an <a> tag linking to PayPal's contact info, 2) is the closing </a> tag. */
			__( 'PayPal only returns a shipping address back to the website. To make sure billing address is returned as well, please enable this functionality on your PayPal account by calling %1$sPayPal Technical Support%2$s.', 'subscriptions-recurring-payments-for-woocommerce' ),
			'<a href="https://www.paypal.com/us/selfhelp/contact/call">',
			'</a>'
		),
	),
	'require_phone_number' => array(
		'title'       => __( 'Require Phone Number', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Require Phone Number', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'no',
		'description' => __( 'Require buyer to enter their telephone number during checkout if none is provided by PayPal. Disabling this option doesn\'t affect direct Debit or Credit Card payments offered by PayPal.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'paymentaction' => array(
		'title'       => __( 'Payment Action', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select',
		'description' => __( 'Choose whether you wish to capture funds immediately or authorize payment only.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'sale',
		'desc_tip'    => true,
		'options'     => array(
			'sale'          => __( 'Sale', 'subscriptions-recurring-payments-for-woocommerce' ),
			'authorization' => __( 'Authorize', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'instant_payments' => array(
		'title'       => __( 'Instant Payments', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Require Instant Payment', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'no',
		'desc_tip'    => true,
		'description' => __( 'If you enable this setting, PayPal will be instructed not to allow the buyer to use funding sources that take additional time to complete (for example, eChecks). Instead, the buyer will be required to use an instant funding source, such as an instant transfer, a credit/debit card, or PayPal Credit.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'subtotal_mismatch_behavior' => array(
		'title'       => __( 'Subtotal Mismatch Behavior', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select',
		'description' => __( 'Internally, WC calculates line item prices and taxes out to four decimal places; however, PayPal can only handle amounts out to two decimal places (or, depending on the currency, no decimal places at all). Occasionally, this can cause discrepancies between the way WooCommerce calculates prices versus the way PayPal calculates them. If a mismatch occurs, this option controls how the order is dealt with so payment can still be taken.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'default'     => 'add',
		'desc_tip'    => true,
		'options'     => array(
			'add'  => __( 'Add another line item', 'subscriptions-recurring-payments-for-woocommerce' ),
			'drop' => __( 'Do not send line items to PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),

	'button_settings' => array(
		'title'       => __( 'Button Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'title',
		'description' => __( 'Customize the appearance of PayPal Checkout on your site.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'use_spb' => array(
		'title'       => __( 'Smart Payment Buttons', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'default'     => $this->get_option( 'button_size' ) ? 'no' : 'yes', // A 'button_size' value having been set indicates that settings have been initialized before, requiring merchant opt-in to SPB.
		'label'       => __( 'Use Smart Payment Buttons', 'subscriptions-recurring-payments-for-woocommerce' ),
		'description' => sprintf(
			/* Translators: %s is the URL of the Smart Payment Buttons integration docs. */
			__( 'PayPal Checkout\'s Smart Payment Buttons provide a variety of button customization options, such as color, language, shape, and multiple button layout. <a href="%s">Learn more about Smart Payment Buttons</a>.', 'subscriptions-recurring-payments-for-woocommerce' ),
			'https://developer.paypal.com/docs/integration/direct/express-checkout/integration-jsv4/#smart-payment-buttons'
		),
	),
	'button_color' => array(
		'title'       => __( 'Button Color', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes',
		'default'     => 'gold',
		'desc_tip'    => true,
		'description' => __( 'Controls the background color of the primary button. Use "Gold" to leverage PayPal\'s recognition and preference, or change it to match your site design or aesthetic.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'gold'   => __( 'Gold (Recommended)', 'subscriptions-recurring-payments-for-woocommerce' ),
			'blue'   => __( 'Blue', 'subscriptions-recurring-payments-for-woocommerce' ),
			'silver' => __( 'Silver', 'subscriptions-recurring-payments-for-woocommerce' ),
			'black'  => __( 'Black', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'button_shape' => array(
		'title'       => __( 'Button Shape', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes',
		'default'     => 'rect',
		'desc_tip'    => true,
		'description' => __( 'The pill-shaped button\'s unique and powerful shape signifies PayPal in people\'s minds. Use the rectangular button as an alternative when pill-shaped buttons might pose design challenges.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'pill' => __( 'Pill', 'subscriptions-recurring-payments-for-woocommerce' ),
			'rect' => __( 'Rectangle', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'button_label' => array(
		'title'       => __( 'Button Label', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes',
		'default'     => 'paypal',
		'desc_tip'    => true,
		'description' => __( 'This controls the label on the primary button.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'paypal'   => __( 'PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
			'checkout' => __( 'PayPal Checkout', 'subscriptions-recurring-payments-for-woocommerce' ),
			'buynow'   => __( 'PayPal Buy Now', 'subscriptions-recurring-payments-for-woocommerce' ),
			'pay'      => __( 'Pay with PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
);

/**
 * Settings that are copied to context-specific sections.
 */
$per_context_settings = array(
	'button_layout' => array(
		'title'       => __( 'Button Layout', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes woocommerce_awc_paypal_payment_button_layout',
		'default'     => 'vertical',
		'desc_tip'    => true,
		'description' => __( 'If additional funding sources are available to the buyer through PayPal, such as Venmo, then multiple buttons are displayed in the space provided. Choose "vertical" for a dynamic list of alternative and local payment options, or "horizontal" when space is limited.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'vertical'   => __( 'Vertical', 'subscriptions-recurring-payments-for-woocommerce' ),
			'horizontal' => __( 'Horizontal', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'button_size' => array(
		'title'       => __( 'Button Size', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select woocommerce_awc_paypal_payment_button_size',
		'default'     => 'yes' === $this->get_option( 'use_spb', 'yes' ) ? 'responsive' : 'large',
		'desc_tip'    => true,
		'description' => __( 'PayPal offers different sizes of the "PayPal Checkout" buttons, allowing you to select a size that best fits your site\'s theme. This setting will allow you to choose which size button(s) appear on your cart page. (The "Responsive" option adjusts to container size, and is available and recommended for Smart Payment Buttons.)', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'responsive' => __( 'Responsive', 'subscriptions-recurring-payments-for-woocommerce' ),
			'small'      => __( 'Small', 'subscriptions-recurring-payments-for-woocommerce' ),
			'medium'     => __( 'Medium', 'subscriptions-recurring-payments-for-woocommerce' ),
			'large'      => __( 'Large', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'button_label' => array(
		'title'       => __( 'Button Label', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'select',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes',
		'default'     => 'paypal',
		'desc_tip'    => true,
		'description' => __( 'PayPal offers different labels on the "PayPal Checkout" buttons, allowing you to select a suitable label.)', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'paypal'   => __( 'PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
			'checkout' => __( 'PayPal Checkout', 'subscriptions-recurring-payments-for-woocommerce' ),
			'buynow'   => __( 'PayPal Buy Now', 'subscriptions-recurring-payments-for-woocommerce' ),
			'pay'      => __( 'Pay with PayPal', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'hide_funding_methods' => array(
		'title'       => 'Hide Funding Method(s)',
		'type'        => 'multiselect',
		'class'       => 'wc-enhanced-select acowcppal_paypal_classes woocommerce_ppec_funding_methods_select woocommerce_awc_paypal_payment_vertical',
		'default'     => array( 'CARD' ),
		'desc_tip'    => true,
		'description' => __( 'Hides the specified funding methods.', 'subscriptions-recurring-payments-for-woocommerce' ),
		'options'     => array(
			'CARD'        => __( 'Credit or debit cards', 'subscriptions-recurring-payments-for-woocommerce' ),
			'CREDIT'      => __( 'PayPal Credit', 'subscriptions-recurring-payments-for-woocommerce' ),
			'BANCONTACT'  => __( 'Bancontact', 'subscriptions-recurring-payments-for-woocommerce' ),
			'BLIK'        => __( 'BLIK', 'subscriptions-recurring-payments-for-woocommerce' ),
			'ELV'         => __( 'ELV', 'subscriptions-recurring-payments-for-woocommerce' ),
			'EPS'         => __( 'eps', 'subscriptions-recurring-payments-for-woocommerce' ),
			'GIROPAY'     => __( 'giropay', 'subscriptions-recurring-payments-for-woocommerce' ),
			'IDEAL'       => __( 'iDEAL', 'subscriptions-recurring-payments-for-woocommerce' ),
			'MERCADOPAGO' => __( 'MercadoPago', 'subscriptions-recurring-payments-for-woocommerce' ),
			'MYBANK'      => __( 'MyBank', 'subscriptions-recurring-payments-for-woocommerce' ),
			'P24'         => __( 'Przelewy24', 'subscriptions-recurring-payments-for-woocommerce' ),
			'SEPA'        => __( 'SEPA-Lastschrift', 'subscriptions-recurring-payments-for-woocommerce' ),
			'SOFORT'      => __( 'Sofort', 'subscriptions-recurring-payments-for-woocommerce' ),
			'VENMO'       => __( 'Venmo', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
	),
	'credit_enabled' => array(
		'title'       => __( 'Enable PayPal Credit to eligible customers', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => $credit_enabled_label,
		'disabled'    => ! wc_gateway_ppec_is_credit_supported(),
		'class'       => 'woocommerce_awc_paypal_payment_horizontal',
		'default'     => 'yes',
		'desc_tip'    => true,
		'description' => $credit_enabled_description,
	),

	'credit_message_enabled' => array(
		'title'       => 'Enable PayPal Credit messages',
		'type'        => 'checkbox',
		'class'       => '',
		'disabled'    => ! wc_gateway_ppec_is_credit_supported(),
		'default'     => 'yes',
		'label'       => __( 'Enable PayPal Credit messages', 'subscriptions-recurring-payments-for-woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Display credit messages on your website to promote special financing offers, which help increase sales.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_layout' => array(
		'title'   => __( 'Credit Messaging Layout', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => 'text',
		'options' => array(
			'text' => __( 'Text', 'subscriptions-recurring-payments-for-woocommerce' ),
			'flex' => __( 'Graphic', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'The layout of the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_logo' => array(
		'title'   => __( 'Credit Messaging logo', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => 'primary',
		'options' => array(
			'primary'     => __( 'Primary', 'subscriptions-recurring-payments-for-woocommerce' ),
			'alternative' => __( 'Alternative', 'subscriptions-recurring-payments-for-woocommerce' ),
			'inline'      => __( 'In-Line', 'subscriptions-recurring-payments-for-woocommerce' ),
			'none'        => __( 'None', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'PayPal Credit logo used in the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_logo_position' => array(
		'title'  => __( 'Credit Messaging logo position', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'   => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => 'left',
		'options' => array(
			'left'  => __( 'Left', 'subscriptions-recurring-payments-for-woocommerce' ),
			'right' => __( 'Right', 'subscriptions-recurring-payments-for-woocommerce' ),
			'top'   => __( 'Top', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'Position of the PayPal logo in the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_text_color' => array(
		'title'   => __( 'Credit Messaging text color', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => 'black',
		'options' => array(
			'black'      => __( 'Black', 'subscriptions-recurring-payments-for-woocommerce' ),
			'white'      => __( 'White', 'subscriptions-recurring-payments-for-woocommerce' ),
			'monochrome' => __( 'Monochrome', 'subscriptions-recurring-payments-for-woocommerce' ),
			'grayscale'  => __( 'Grayscale', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'Text and logo color of the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_flex_color' => array(
		'title' => __( 'Credit Messaging color', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type' => 'select',
		'class' => 'wc-enhanced-select',
		'default' => 'black',
		'options' => array(
			'black'           => __( 'Black', 'subscriptions-recurring-payments-for-woocommerce' ),
			'blue'            => __( 'Blue', 'subscriptions-recurring-payments-for-woocommerce' ),
			'monochrome'      => __( 'Monochrome', 'subscriptions-recurring-payments-for-woocommerce' ),
			'gray'            => __( 'Gray', 'subscriptions-recurring-payments-for-woocommerce' ),
			'grayscale'       => __( 'Grayscale', 'subscriptions-recurring-payments-for-woocommerce' ),
			'white'           => __( 'White', 'subscriptions-recurring-payments-for-woocommerce' ),
			'white-no-border' => __( 'White no border', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'Color of the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),
	'credit_message_flex_ratio' => array(
		'title' => __( 'Credit Messaging ratio', 'subscriptions-recurring-payments-for-woocommerce' ),
		'type' => 'select',
		'class' => 'wc-enhanced-select',
		'default' => '1x1',
		'options' => array(
			'1x1'  => __( '1x1', 'subscriptions-recurring-payments-for-woocommerce' ),
			'1x4'  => __( '1x4', 'subscriptions-recurring-payments-for-woocommerce' ),
			'8x1'  => __( '8x1', 'subscriptions-recurring-payments-for-woocommerce' ),
			'20x1' => __( '20x1', 'subscriptions-recurring-payments-for-woocommerce' ),
		),
		'disabled' => ! wc_gateway_ppec_is_credit_supported(),
		'desc_tip' => true,
		'description' => __( 'Shape and size of the message.', 'subscriptions-recurring-payments-for-woocommerce' ),
	),

);


/**
 * Cart / global button settings.
 */
$settings = array_merge( $settings, $per_context_settings );

$per_context_settings['button_size']['class']    .= ' acowcppal_paypal_classes';
$per_context_settings['credit_enabled']['class'] .= ' acowcppal_paypal_classes';

$settings['cart_checkout_enabled'] = array(
	'title'       => __( 'Enable on the cart page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'woocommerce_awc_paypal_payment_visibility_toggle',
	'label'       => __( 'Enable PayPal Checkout buttons on the cart page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'desc_tip'    => true,
	'default'     => 'yes',
);

/**
 * Mini-cart button settings.
 */
$settings['mini_cart_settings'] = array(
	'title' => __( 'Mini-cart Button Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'  => 'title',
	'class' => 'acowcppal_paypal_classes',
);

$settings['mini_cart_settings_toggle'] = array(
	'title'       => __( 'Configure Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'label'       => __( 'Configure settings specific to mini-cart', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'acowcppal_paypal_classes woocommerce_awc_paypal_payment_visibility_toggle',
	'default'     => 'no',
	'desc_tip'    => true,
	'description' => __( 'Optionally override global button settings above and configure buttons for this context.', 'subscriptions-recurring-payments-for-woocommerce' ),
);
foreach ( $per_context_settings as $key => $value ) {
	// No PayPal Credit messaging settings for mini-cart.
	if ( 0 === strpos( $key, 'credit_message_' ) ) {
		continue;
	}

	$value['class']                 .= ' woocommerce_awc_paypal_payment_mini_cart';
	$settings[ 'mini_cart_' . $key ] = $value;
}

/**
 * Single product button settings.
 */
$settings['single_product_settings'] = array(
	'title' => __( 'Single Product Button Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'  => 'title',
	'class' => 'acowcppal_paypal_classes',
);

$settings['checkout_on_single_product_enabled'] = array(
	'title'       => __( 'Enable on the single product page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'woocommerce_awc_paypal_payment_visibility_toggle',
	'label'       => __( 'Enable PayPal Checkout buttons on the single product page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'default'     => 'yes',
	'desc_tip'    => true,
);

$settings['single_product_settings_toggle'] = array(
	'title'       => __( 'Configure Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'label'       => __( 'Configure settings specific to Single Product view', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'acowcppal_paypal_classes woocommerce_awc_paypal_payment_visibility_toggle',
	'default'     => 'yes',
	'desc_tip'    => true,
	'description' => __( 'Optionally override global button settings above and configure buttons for this context.', 'subscriptions-recurring-payments-for-woocommerce' ),
);
foreach ( $per_context_settings as $key => $value ) {
	$value['class']                      .= ' woocommerce_awc_paypal_payment_single_product';
	$settings[ 'single_product_' . $key ] = $value;
}
$settings['single_product_button_layout']['default'] = 'horizontal';

/**
 * Regular checkout button settings.
 */
$settings['mark_settings'] = array(
	'title' => __( 'Regular Checkout Button Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'  => 'title',
	'class' => 'acowcppal_paypal_classes',
);

$settings['mark_enabled'] = array(
	'title'       => __( 'Enable on the checkout page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'woocommerce_awc_paypal_payment_visibility_toggle',
	'label'       => __( 'Enable PayPal Checkout buttons on the regular checkout page', 'subscriptions-recurring-payments-for-woocommerce' ),
	'desc_tip'    => true,
	'default'     => 'yes',
);

$settings['mark_settings_toggle'] = array(
	'title'       => __( 'Configure Settings', 'subscriptions-recurring-payments-for-woocommerce' ),
	'label'       => __( 'Configure settings specific to regular checkout', 'subscriptions-recurring-payments-for-woocommerce' ),
	'type'        => 'checkbox',
	'class'       => 'acowcppal_paypal_classes woocommerce_awc_paypal_payment_visibility_toggle',
	'default'     => 'no',
	'desc_tip'    => true,
	'description' => __( 'Optionally override global button settings above and configure buttons for this context.', 'subscriptions-recurring-payments-for-woocommerce' ),
);
foreach ( $per_context_settings as $key => $value ) {
	$value['class']            .= ' woocommerce_awc_paypal_payment_mark';
	$settings[ 'mark_' . $key ] = $value;
}

return apply_filters( 'woocommerce_paypal_express_checkout_settings', $settings );

// phpcs:enable
