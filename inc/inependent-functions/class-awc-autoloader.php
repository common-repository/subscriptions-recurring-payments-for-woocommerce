<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 *
 * @class AWC_Autoloader
 */
class AWC_Autoloader {

	/**
	 * The base path for autoloading.
	 *
	 * @var string
	 */
	protected $base_path = '';

	/**
	 * Whether to use the legacy API classes.
	 *
	 * @var bool
	 */
	protected $legacy_api = false;

	/**
	 * AWC_Autoloader constructor.
	 *
	 * @param string $base_path
	 */
	public function __construct( $base_path ) {
		$this->base_path = untrailingslashit( $base_path );
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->unregister();
	}

	/**
	 * Register the autoloader.
	 *
	 * @author Jeremy Pry
	 */
	public function register() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Unregister the autoloader.
	 */
	public function unregister() {
		spl_autoload_unregister( array( $this, 'autoload' ) );
	}


	/**
	 * Autoload a class.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $class The class name to autoload.
	 */
	public function autoload( $class ) {
		$class = strtolower( $class );

		if ( ! $this->should_autoload( $class ) ) {
			return;
		}

		$full_path = $this->base_path . $this->get_relative_class_path( $class ) . $this->get_file_name( $class );
		if ( is_readable( $full_path ) ) {
			require_once( $full_path );
		}
	}


	/**
	 * Determine whether we should autoload a given class.
	 *
	 * @param string $class The class name.
	 *
	 * @return bool
	 */
	protected function should_autoload( $class ) {
		// We're not using namespaces, so if the class has namespace separators, skip.
		if ( false !== strpos( $class, '\\' ) ) {
			return false;
		}

		// There are some legacy classes without awc or Subscription in the name.
		static $legacy = array(
			'wc_order_item_pending_switch'         => 1,
			'wc_report_retention_rate'             => 1,
			'wc_report_upcoming_recurring_revenue' => 1,
		);
		if ( isset( $legacy[ $class ] ) ) {
			return true;
		}

		return  false !== strpos( $class, 'awc_' ) || 0 === strpos( $class, 'awc_subscription' ) || 0 === strpos( $class, 'shop_subscription' ) || ( false !== strpos( $class, 'wc_' ) && false !== strpos( $class, 'subscription' ) );
	}

	/**
	 * Convert the class name into an appropriate file name.
	 *
	 * @param string $class The class name.
	 *
	 * @return string The file name.
	 */
	protected function get_file_name( $class ) {
		$file_prefix = 'class-';
		if ( $this->is_class_abstract( $class ) ) {
			$file_prefix = 'abstract-';
		}
		return $file_prefix . str_replace( '_', '-', $class ) . '.php';
	}

	/**
	 * Determine if the class is one of our abstract classes.
	 *
	 * @param string $class The class name.
	 *
	 * @return bool
	 */
	protected function is_class_abstract( $class ) {
		static $abstracts = array(
			'awc_background_repairer'      => true,
			'awc_background_updater'       => true,
			'awc_background_upgrader'      => true,
			'awc_cache_manager'            => true,
			'awc_debug_tool'               => true,
			'awc_debug_tool_cache_updater' => true,
			'awc_dynamic_hook_deprecator'  => true,
			'awc_hook_deprecator'          => true,
			'awc_retry_store'              => true,
			'awc_scheduler'                => true,
			'awc_sv_api_base'              => true,
			'awc_customer_store'           => true,
			'awc_related_order_store'      => true,
			'awc_migrator'                 => true,
			'awc_table_maker'              => true,
		);

		return isset( $abstracts[ $class ] );
	}



	/**
	 * Determine if the class is one of our data stores.
	 *
	 * @param string $class The class name.

	 * @return bool
	 */
	protected function is_class_data_store( $class ) {
		$return = false;
		static $data_stores = array(
			'awc_related_order_store_cached_cpt'  => true,
			'awc_related_order_store_cpt'         => true,
			'awc_customer_store_cached_cpt'       => true,
			'awc_customer_store_cpt'              => true,
			'awc_product_variable_data_store_cpt' => true,
			'awc_subscription_data_store_cpt'     => true,
		);

		$return = isset( $data_stores[ $class ] );

		return $return;
	}

	/**
	 * Get the relative path for the class location.
	 *
	 * This handles all of the special class locations and exceptions.
	 *
	 * @param string $class The class name.
	 *
	 * @return string The relative path (from the plugin root) to the class file.
	 */
	protected function get_relative_class_path( $class ) {
		

		$path     = '/inc';
		$is_admin = ( false !== strpos( $class, 'admin' ) );

		if ( $this->is_class_abstract( $class ) ) {	
			$path .= '/abstract_class';
		} elseif ( false !== strpos( $class, 'paypal' ) && $class != 'awc_woocommerce_gateway_paypal_express_checkout' ) {
			$path .= '/aco-gateway/paypal';
			if ( 'awc_paypal' === $class ) {
				$path .= '';
			} elseif ( 'awc_repair_suspended_paypal_subscriptions' === $class ) {
				// Deliberately avoid concatenation for this class, using the base path.
				$path = '/includes/upgrades';
			} elseif ( $is_admin ) {
				$path .= '/includes/admin';
			} else {
				$path .= '/includes';
			}
		} elseif ( 0 === strpos( $class, 'awc_retry' ) && 'awc_payment_retry_manager' !== $class ) {
			$path .= '/payment-retry';
		} elseif ( $is_admin && 'awc_change_payment_method_admin' !== $class ) {
			$path .= '/admin';
		} elseif ( false !== strpos( $class, 'meta_box' ) ) {
			$path .= '/admin/meta-boxes';
		} elseif ( false !== strpos( $class, 'report' ) ) {
			$path .= '/admin/reports';
		} elseif ( false !== strpos( $class, 'debug_tool' ) ) {
			$path .= '/admin/debugs-tool';
		} elseif ( false !== strpos( $class, 'rest' ) ) {
			$path .= $this->legacy_api ? '/api/legacy' : '/api';
		} elseif ( false !== strpos( $class, 'api' ) && !in_array($class, array('awc_api')) ) {
			$path .= '/api/legacy';
		} elseif (false !== strpos($class, 'api') && in_array($class, array('awc_api'))){
			$path .= '/api';
		} elseif ( $this->is_class_data_store( $class ) ) {
			$path .= '/data-stores';
		} elseif ( false !== strpos( $class, 'email' ) && 'awc_subscriptions_email' !== $class ) {
			$path .= '/subscription-emails';
		} elseif ( false !== strpos( $class, 'gateway' ) && !in_array($class, array('awc_subscription_change_payment_gateway', 'awc_woocommerce_gateway_paypal_express_checkout')) ) {
			$path .= '/aco-gateway';
		}elseif(false !== strpos( $class, 'stripe' )){
			$path .= '/payment-methods/stripe';
		}elseif(false != strpos($class, 'gateway_paypal_express')){
			$path .= '/payment-methods/awc-paypal';
		}elseif ( false !== strpos( $class, 'datetime' ) ) {
			$path .= '/libraries';
		} elseif ( false !== strpos( $class, 'privacy' ) ) {
			$path .= '/aco-priv';
		} elseif ( false !== strpos( $class, 'early' ) ) {
			$path .= '/renewal';
		} elseif( false !== strpos( $class, 'qty' ) ){
			$path .= '/qty';
		}else{
			$path .= '/classes';
		}
        
		return trailingslashit( $path );
	}



	/**
	 * Set whether the legacy API should be used.
	 *
	 * @param bool $use_legacy_api Whether to use the legacy API classes.
	 *
	 * @return $this
	 */
	public function use_legacy_api( $use_legacy_api ) {
		$this->legacy_api = (bool) $use_legacy_api;

		return $this;
	}
}
