<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Paypal_Payment_Gateway extends AWC_Paypal_Gateway {
	public function __construct() {
		$this->id   = 'awc_paypal_payment';
		$this->icon = 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/pp-acceptance-small.png';

		parent::__construct();

		if ( $this->is_available() ) {
			
			$ipn_handler = new WC_Gateway_PPEC_IPN_Handler( $this );
			$ipn_handler->handle();
		}
	}
}
