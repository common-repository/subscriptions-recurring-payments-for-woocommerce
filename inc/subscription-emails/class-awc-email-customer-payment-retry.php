<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Retry
 *
 * Email sent to the customer when an attempt to automatically process a subscription renewal payment has failed
 * and a retry rule has been applied to retry the payment in the future.
 *
 */
class AWC_Email_Customer_Payment_Retry extends AWC_Email_Customer_Renewal_Invoice {

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'customer_payment_retry';
		$this->title          = __( 'Customer Payment Retry', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->description    = __( 'Sent to a customer when an attempt to automatically process a subscription renewal payment has failed and a retry rule has been applied to retry the payment in the future. The email contains the renewal order information, date of the scheduled retry and payment links to allow the customer to pay for the renewal order manually instead of waiting for the automatic retry.', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->customer_email = true;

		$this->template_html  = 'emails/customer-payment-retry.php';
		$this->template_plain = 'emails/text/customer-payment-retry.php';
		$this->template_base  = plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'templates/';

		$this->subject        = __( 'Automatic payment failed for {order_number}, we will retry {retry_time}', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->heading        = __( 'Automatic payment failed for order {order_number}', 'subscriptions-recurring-payments-for-woocommerce' );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 
	 * @return string
	 */
	public function get_default_subject( $paid = false ) {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return $this->heading;
	}

	/**
	 * trigger function.
	 *
	 * We can use most of AWC_Email_Customer_Renewal_Invoice's trigger method but we need to set up the
	 * retry data ourselves before calling it as AWC_Email_Customer_Renewal_Invoice has no retry
	 * associated with it.
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id, $order = null ) {

		$this->retry = AWC_Payment_Retry_Manager::store()->get_last_retry_for_order( $order_id );

		$retry_time_index = array_search( '{retry_time}', $this->find );
		if ( false === $retry_time_index ) {
			$this->find['retry_time']    = '{retry_time}';
			$this->replace['retry_time'] = awc_get_human_time_diff( $this->retry->get_time() );
		} else {
			$this->replace[ $retry_time_index ] = awc_get_human_time_diff( $this->retry->get_time() );
		}

		parent::trigger( $order_id, $order );
	}

	/**
	 * get_subject function.
	 *
	 * @access public
	 * @return string
	 */
	function get_subject() {
		return apply_filters( 'woocommerce_subscriptions_email_subject_customer_retry', parent::get_subject(), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @access public
	 * @return string
	 */
	function get_heading() {
		return apply_filters( 'woocommerce_email_heading_customer_retry', parent::get_heading(), $this->object );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
