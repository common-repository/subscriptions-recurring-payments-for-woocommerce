<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}



class AWC_Paypal_Payment_Gateway_with_Credit extends AWC_Paypal_Payment_Gateway {
	public function __construct() {
		$this->icon = 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/ppc-acceptance-small.png';

		parent::__construct();

		if ( ! is_admin() ) {
			if ( awc_paypal_ec_gateway()->checkout->is_started_from_checkout_page() ) {
				$this->title = __( 'PayPal Credit', 'subscriptions-recurring-payments-for-woocommerce' );
			}
		}

		$this->use_ppc = true;
	}
}
