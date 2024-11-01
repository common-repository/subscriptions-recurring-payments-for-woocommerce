<?php 

/**
 * WooCommerce Subscriptions Temporal Functions
 *
 * Functions for time values and ranges
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


$fileName = array(
	'awc-paypal-functions', 
	'awc-deprecated-functions', 
	'awc-compatibility-functions', 
	'awc-formatting-functions', 
	'awc-product-functions', 
	'awc-cart-functions', 
	'awc-order-functions', 
	'awc-time-functions', 
	'awc-user-functions',
	'awc-helper-functions',
	'awc-renewal-functions',
	'awc-resubscribe-functions',
	'awc-switch-functions',
	'awc-limitation-function',
	'awc-early-renewal-functions',
	'awc-subscription-functions', 
	'class-awc-autoloader'
);


foreach($fileName as $file)
	require_once( AWC_PATH . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR .'inependent-functions'. DIRECTORY_SEPARATOR . $file . '.php' );

// Load libraries manually.
require_once( AWC_PATH . '/inc/libraries/action-scheduler/action-scheduler.php' );