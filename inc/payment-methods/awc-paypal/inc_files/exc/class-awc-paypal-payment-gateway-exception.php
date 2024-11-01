<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


if(!class_exists('AWC_Paypal_Payment_Gateway_Exception')){
class AWC_Paypal_Payment_Gateway_Exception extends Exception {

	/**
	 * List of errors from PayPal API.
	 *
	 * @var array
	 */
	public $errors;

	/**
	 * Unique identifier of PayPal transaction.
	 *
	 *
	 * @var string
	 */
	public $correlation_id;

	/**
	 * Constructor.
	 *
	 * This constructor takes the API response received from PayPal, parses out the
	 * errors in the response, then places those errors into the $errors property.
	 * It also captures correlation ID and places that in the $correlation_id property.
	 *
	 * @param array $response Response from PayPal API
	 */
	public function __construct( $response ) {
		parent::__construct( __( 'An error occurred while calling the PayPal API.', 'subscriptions-recurring-payments-for-woocommerce' ) );

		$errors = array();
		foreach ( $response as $index => $value ) {
			if ( preg_match( '/^L_ERRORCODE(\d+)$/', $index, $matches ) ) {
				$errors[ $matches[1] ]['code'] = $value;
			} elseif ( preg_match( '/^L_SHORTMESSAGE(\d+)$/', $index, $matches ) ) {
				$errors[ $matches[1] ]['message'] = $value;
			} elseif ( preg_match( '/^L_LONGMESSAGE(\d+)$/', $index, $matches ) ) {
				$errors[ $matches[1] ]['long'] = $value;
			} elseif ( preg_match( '/^L_SEVERITYCODE(\d+)$/', $index, $matches ) ) {
				$errors[ $matches[1] ]['severity'] = $value;
			} elseif ( 'CORRELATIONID' === $index ) {
				$this->correlation_id = $value;
			}
		}

		$this->errors   = array();
		$error_messages = array();
		foreach ( $errors as $value ) {
			$error          = new AWC_PayPal_API_Error( $value['code'], $value['message'], $value['long'], $value['severity'] );
			$this->errors[] = $error;

			/* translators: placeholders are error code and message from PayPal */
			$error_messages[] = sprintf( __( 'PayPal error (%1$s): %2$s', 'subscriptions-recurring-payments-for-woocommerce' ), $error->error_code, $error->maptoBuyerFriendlyError() );
		}

		if ( empty( $error_messages ) ) {
			$error_messages[] = __( 'An error occurred while calling the PayPal API.', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		$this->message = implode( PHP_EOL, $error_messages );
	}
}
}