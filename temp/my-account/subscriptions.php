<?php
/**
 * My Account > Subscriptions page
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

AWC_Subscriptions::get_my_subscriptions_template( $current_page );
