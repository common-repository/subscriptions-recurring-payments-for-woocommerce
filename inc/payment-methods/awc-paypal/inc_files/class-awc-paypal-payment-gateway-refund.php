<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class WC_Gateway_PPEC_Refund {

	/**
	 * Refund an order.
	 *
	 * @throws \AWC_Paypal_Payment_Gateway_Exception
	 *
	 * @param WC_Order $order      Order to refund
	 * @param float    $amount     Amount to refund
	 * @param string   $refundType Type of refund (Partial or Full)
	 * @param string   $reason     Reason to refund
	 * @param string   $current    Currency of refund
	 *
	 * @return null|string If exception is thrown, null is returned. Otherwise
	 *                     ID of refund transaction is returned.
	 */
	public static function refund_order( $order, $amount, $refundType, $reason, $currency ) {

		// add refund params
		$params['TRANSACTIONID'] = $order->get_transaction_id();
		$params['REFUNDTYPE']    = $refundType;
		$params['AMT']           = $amount;
		$params['CURRENCYCODE']  = $currency;
		$params['NOTE']          = $reason;

		// do API call
		$response = awc_paypal_ec_gateway()->client->refund_transaction( $params );

		// look at ACK to see if success or failure
		// if success return the transaction ID of the refund
		// if failure then do 'throw new AWC_Paypal_Payment_Gateway_Exception( $response );'

		if ( 'Success' === $response['ACK'] || 'SuccessWithWarning' === $response['ACK'] ) {
			return $response['REFUNDTRANSACTIONID'];
		} else {
			throw new AWC_Paypal_Payment_Gateway_Exception( $response );
		}
	}

}
