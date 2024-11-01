<?php

function aco_payment_process_start_checkout() {
	$checkout = awc_paypal_ec_gateway()->checkout;

	try {
		$redirect_url = $checkout->start_checkout_from_cart();
		wp_safe_redirect( $redirect_url );
		exit;
	} catch ( AWC_Paypal_Payment_Gateway_Exception $e ) {
		wc_add_notice( $e->getMessage(), 'error' );

		$redirect_url = wc_get_cart_url();
		$settings     = awc_paypal_ec_gateway()->settings;
		$client       = awc_paypal_ec_gateway()->client;

		if ( $settings->is_enabled() && $client->get_payer_id() ) {
			ob_end_clean();
			?>
			<script type="text/javascript">
				if( ( window.opener != null ) && ( window.opener !== window ) &&
						( typeof window.opener.paypal != "undefined" ) &&
						( typeof window.opener.paypal.checkout != "undefined" ) ) {
					window.opener.location.assign( "<?php echo $redirect_url; ?>" );
					window.close();
				} else {
					window.location.assign( "<?php echo $redirect_url; ?>" );
				}
			</script>
			<?php
			exit;
		} else {
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}


/**
 * Log a message via WC_Logger.
 *
 * @param string $message Message to log
 */
function awc_paypal_payment_gateway_log( $message ) {
	static $wc_ppec_logger;

	// No need to write to log file if logging is disabled.
	if ( ! awc_paypal_ec_gateway()->settings->is_logging_enabled() ) {
		return false;
	}

	if ( ! isset( $wc_ppec_logger ) ) {
		$wc_ppec_logger = new WC_Logger();
	}

	$wc_ppec_logger->add( 'wc_gateway_ppec', $message );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Whether PayPal credit is supported.
 *
 * @return bool Returns true if PayPal credit is supported
 */
function wc_gateway_ppec_is_credit_supported() {
	return awc_paypal_payment_gateway_is_US_based_store() && 'USD' === get_woocommerce_currency();
}



/**
 * Checks whether buyer is checking out with PayPal Credit.
 *
 *
 * @return bool Returns true if buyer is checking out with PayPal Credit
 */
function wc_gateway_ppec_is_using_credit() {
	return ! empty( $_GET['use-ppc'] ) && 'true' === $_GET['use-ppc']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

const AWC_FEE_META_OLD_NAME = 'PayPal Transaction Fee';
const AWC_FEE_META_NAME = '_paypal_transaction_fee';

/**
 * Sets the PayPal Fee in the order metadata
 *
 * @param object $order Order to modify
 * @param string $fee Fee to save
 */
function awc_paypal_payment_gateway_set_transaction_fee( $order, $fee ) {
	if ( empty( $fee ) ) {
		return;
	}
	$fee = wc_clean( $fee );
	if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		update_post_meta( $order->id, AWC_FEE_META_NAME, $fee );
	} else {
		$order->update_meta_data( AWC_FEE_META_NAME, $fee );
		$order->save_meta_data();
	}
}

/**
 * Gets the PayPal Fee from the order metadata, migrates if the fee was saved under a legacy key

 * @param object 
 * @return string 
 */
function wc_gateway_ppec_get_transaction_fee( $order ) {
	$old_wc = version_compare( WC_VERSION, '3.0', '<' );

	//retrieve the fee using the new key
	if ( $old_wc ) {
		$fee = get_post_meta( $order->id, AWC_FEE_META_NAME, true );
	} else {
		$fee = $order->get_meta( AWC_FEE_META_NAME, true );
	}

	//if the fee was found, return
	if ( is_numeric( $fee ) ) {
		return $fee;
	}

	//attempt to retrieve the old meta, delete its old key, and migrate it to the new one
	if ( $old_wc ) {
		$fee = get_post_meta( $order->id, AWC_FEE_META_OLD_NAME, true );
		delete_post_meta( $order->id, AWC_FEE_META_OLD_NAME );
	} else {
		$fee = $order->get_meta( AWC_FEE_META_OLD_NAME, true );
		$order->delete_meta_data( AWC_FEE_META_OLD_NAME );
		$order->save_meta_data();
	}

	if ( is_numeric( $fee ) ) {
		awc_paypal_payment_gateway_set_transaction_fee( $order, $fee );
	}

	return $fee;
}



/**
 * Checks whether the store is based in the US.
 *
 * Stores with a base location in the US, Puerto Rico, Guam, US Virgin Islands, American Samoa, or Northern Mariana Islands are considered US based stores.
 *
 * @return bool True if the store is located in the US or US Territory, otherwise false.
 */
function awc_paypal_payment_gateway_is_US_based_store() {
	$base_location = wc_get_base_location();
	return in_array( $base_location['country'], array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP' ), true );
}


function awc_include_files($file = false){
	if($file)
		require_once(AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . $file . '.php');
}


/**
 * Saves the transaction details from the transaction response into a post meta.
 *
 */
function awc_paypal_gateway_save_transaction_data( $order, $transaction_response, $prefix = '' ) {

	$settings = awc_paypal_ec_gateway()->settings;
	$old_wc   = version_compare( WC_VERSION, '3.0', '<' );
	$order_id = $old_wc ? $order->id : $order->get_id();
	$meta     = $old_wc ? get_post_meta( $order_id, '_woo_pp_txnData', true ) : $order->get_meta( '_woo_pp_txnData', true );

	if ( ! empty( $meta ) ) {
		$txnData = $meta;
	} else {
		$txnData = array( 'refundable_txns' => array() );
	}

	$txn = array(
		'txnID'           => $transaction_response[ $prefix . 'TRANSACTIONID' ],
		'amount'          => $transaction_response[ $prefix . 'AMT' ],
		'refunded_amount' => 0,
	);

	$status = ! empty( $transaction_response[ $prefix . 'PAYMENTSTATUS' ] ) ? $transaction_response[ $prefix . 'PAYMENTSTATUS' ] : '';

	if ( 'Completed' === $status ) {
		$txn['status'] = 'Completed';
	} else {
		$txn['status'] = $status . '_' . $transaction_response[ $prefix . 'REASONCODE' ];
	}
	$txnData['refundable_txns'][] = $txn;

	$paymentAction = $settings->get_paymentaction();

	if ( 'authorization' === $paymentAction ) {
		$txnData['auth_status'] = 'NotCompleted';
	}

	$txnData['txn_type'] = $paymentAction;

	if ( $old_wc ) {
		update_post_meta( $order_id, '_woo_pp_txnData', $txnData );
	} else {
		$order->update_meta_data( '_woo_pp_txnData', $txnData );
		$order->save();
	}
}
