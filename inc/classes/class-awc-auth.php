<?php
/**
 * WooCommerce Auth
 *
 * Handles wc-auth endpoint requests
 *
 * 
 * @category API
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWC_Auth {

	/**
	 * Setup class
	 *
	 */
	public function __construct() {
		add_filter( 'woocommerce_api_permissions_in_scope', array( $this, 'get_permissions_in_scope' ), 10, 2 );
	}

	/**
	 * Return a list of permissions a scope allows
	 *
	 * @param array $permissions
	 * @param string $scope	 
	 * @return array
	 */
	public function get_permissions_in_scope( $permissions, $scope ) {

		switch ( $scope ) {
			case 'read':
				$permissions[] = __( 'View subscriptions', 'subscriptions-recurring-payments-for-woocommerce' );
			break;
			case 'write':
				$permissions[] = __( 'Create subscriptions', 'subscriptions-recurring-payments-for-woocommerce' );
			break;
			case 'read_write':
				$permissions[] = __( 'View and manage subscriptions', 'subscriptions-recurring-payments-for-woocommerce' );
			break;
		}

		return $permissions;
	}
}
