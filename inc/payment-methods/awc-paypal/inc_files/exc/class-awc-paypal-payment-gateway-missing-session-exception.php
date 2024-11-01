<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


 
if(!class_exists('AWC_Paypal_Payment_Gateway_Missing_Session_Exception')){
	class AWC_Paypal_Payment_Gateway_Missing_Session_Exception extends Exception {

		/**
		 * Constructor.
		 *
		 * @param string $message Exception message
		 */
		public function __construct( $message = '' ) {
			if ( empty( $message ) ) {
				$message = __( 'The buyer\'s session information could not be found.', 'subscriptions-recurring-payments-for-woocommerce' );
			}

			parent::__construct( $message );
		}
}
}
