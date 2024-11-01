<?php

/**
 * Plugin Name: Subscriptions & Recurring Payments for WooCommerce
 * Version: 1.0.0
 * Description: "Aco Woo Subscriptions for WooCommerce" is a WooCommerce addons in WooCommerce store and it's easy to use. We don't like to user with tons of settings and options so we implemented very clean and easy understable setting panel where user can easily sell subscription product.
 * Author: WPPath
 * Author URI: http://wppath.com
 * Requires at least: 4.4.0
 * Tested up to: 6.0.0
 * Text Domain: subscriptions-recurring-payments-for-woocommerce
 * WC requires at least: 3.0.0
 * WC tested up to: 5.9.0
 */

 
define('AWC_TOKEN', 'awc');
define('AWC_VERSION', '1.0.0');
define('AWC_FILE', __FILE__);
define('AWC_PLUGIN_NAME', 'Subscriptions & Recurring Payments for WooCommerce');
define('AWC_INIT_TIMESTAMP', gmdate( 'U' ) );
define('AWC_PATH', realpath(plugin_dir_path(__FILE__)));


// All independent functions.
require_once(AWC_PATH . DIRECTORY_SEPARATOR . 'inc/inependent-functions/awc-helpers.php');


// Load and set up the Autoloader class
$awc_autoloader = new AWC_Autoloader( dirname( __FILE__ ) );
$awc_autoloader->register();

if(AWC_Settings::get_option('payment_methods') && is_array(AWC_Settings::get_option('payment_methods')) && in_array('paypal', AWC_Settings::get_option('payment_methods')) )
	require_once realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'inc/payment-methods/awc-paypal/awc-paypal.php';


// Is woocommerce is not activated
if(!AWC_Backend::isWoocommerceActivated()){
	add_action('admin_notices', array('AWC_Backend', 'awc_notice_need_woocommerce'));   
	return;
}


// Initialize our classes.
if(AWC_Settings::get_option( 'allow_coupon_discount' ))
	AWC_Subscriptions_Coupon::init();
	
AWC_Subscription_Products::init();

//Conditional Class 
if(AWC_Settings::get_option('enable_subscription'))
	AWC_Subscriptions_Admin::init();
	
AWC_Backend::instance(AWC_FILE);
AWC_Subscription_Manager::init();
AWC_Subscriptions_Order::init();
AWC_Subscription_Renewal_Order::init();
AWC_Subscriptions_Email::init();
AWC_Subscription_Addresses::init();
AWC_Subscription_Change_Payment_Gateway::init();
AWC_Subscription_Payment_Gateways::init();
AWC_PayPal_Standard_Change_Payment_Method::init();
AWC_Payment_Retry_Manager::init();
AWC_Limiter::init();
AWC_Staging::init();
AWC_Manage_Parmalink::init();
AWC_Custom_Order_Item_Manager::init();
AWC_Early_Renewal_Modal_Handler::init();
AWC_Dependent_Hook_Manager::init();
AWC_Public::instance(AWC_FILE);
new AWC_Restapi();
AWC_Subscriptions::init($awc_autoloader);

// Some classes run init on a particular hook.
add_action( 'init', array( 'WC_Subscriptions_Synchroniser', 'init' ) );
add_action( 'init', array( 'WC_PayPal_Standard_Subscriptions', 'init' ), 11 );
