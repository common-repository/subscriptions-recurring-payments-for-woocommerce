<?php
/**
 * Subscription Product Variation Class
 *
 * The subscription product variation class extends the WC_Product_Variation product class
 * to create subscription product variations.
 *
 * @class    WC_Product_Subscription
 * @package  WooCommerce Subscriptions
 * @category Class
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class WC_Product_Subscription_Variation extends WC_Product_Variation {

	/**
	 * A way to access the old array property.
	 */
	protected $subscription_variation_level_meta_data;


	public static function test(){
		// update_option( 'tests', 'inside test function' );
	}
	/**
	 * Create a simple subscription product object.
	 *
	 * @access public
	 * @param mixed $product
	 */
	public function __construct( $product = 0 ) {

		parent::__construct( $product );

		$this->subscription_variation_level_meta_data = new AWC_ArrayPropertyPostMetaBlackMagic( $this->get_id() );

		
	}

	/**
	 * Magic __get method for backwards compatibility. Map legacy vars to AWC_Subscription_Products getters.
	 *
	 * @param  string $key Key name.
	 * @return mixed
	 */
	public function __get( $key ) {

		
		if ( 'subscription_variation_level_meta_data' === $key ) {
			$value = $this->subscription_variation_level_meta_data; // Behold, the horror that is the magic of awc_Array_Property_Post_Meta_Black_Magic
		} else {

			$value = awc_product_deprecated_property_handler( $key, $this );
			
			// No matching property found in awc_product_deprecated_property_handler()
			if ( is_null( $value ) ) {
				$value = parent::__get( $key );
			}
		}

		return $value;
	}

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription_variation';
	}

	/**
	 * Get variation price HTML. Prices are not inherited from parents.
	 *
	 * @return string containing the formatted price
	 */
	public function get_price_html( $price = '' ) {

		$price = parent::get_price_html( $price );

		if ( ! empty( $price ) ) {
			$price = AWC_Subscription_Products::awc_get_price_string( $this, array( 'price' => $price ) );
		}

		return $price;
	}

	/**
	 * Get the add to cart button text
	 *
	 * @access public
	 * @return string
	 */
	public function add_to_cart_text() {

		if ( $this->is_purchasable() && $this->is_in_stock() ) {
			$text = get_option( AWC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign up now', 'subscriptions-recurring-payments-for-woocommerce' ) );
		} else {
			$text = parent::add_to_cart_text(); // translated "Read More"
		}

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Get the add to cart button text for the single page
	 *
	 * @access public
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', self::add_to_cart_text(), $this );
	}

	/**
	 * Checks if the variable product this variation belongs to is purchasable.
	 *
	 * @access public
	 * @return bool
	 */
	public function is_purchasable() {
		$purchasable = awc_Limiter::is_purchasable( wc_get_product( $this->get_parent_id() )->is_purchasable(), $this );
		return apply_filters( 'woocommerce_subscription_variation_is_purchasable', $purchasable, $this );
	}

	/**
	 * Checks the product type to see if it is either this product's type or the parent's
	 * product type.
	 *
	 * @access public
	 * @param mixed $type Array or string of types
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( 'variation' == $type || ( is_array( $type ) && in_array( 'variation', $type ) ) ) {
			return true;
		} else {
			return parent::is_type( $type );
		}
	}


	/**
	 * Return the sign-up fee for this product
	 *
	 * @return string
	 */
	public function get_sign_up_fee() {
		return AWC_Subscription_Products::awc_get_sign_up_fee( $this );
	}

	/**
	 * Returns the sign up fee (including tax) by filtering the products price used in
	 * @see WC_Product::get_price_including_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_including_tax( $qty = 1, $price = '' ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_including_tax = parent::get_price_including_tax( $qty );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100 );

		return $sign_up_fee_including_tax;
	}

	/**
	 * Returns the sign up fee (excluding tax) by filtering the products price used in
	 * @see WC_Product::get_price_excluding_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_excluding_tax( $qty = 1, $price = '' ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_excluding_tax = parent::get_price_excluding_tax( $qty );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100 );

		return $sign_up_fee_excluding_tax;
	}
}
