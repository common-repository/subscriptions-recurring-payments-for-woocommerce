<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


abstract class AWC_SV_API_Base {

	protected $request_method = 'POST';
	protected $request_uri;
	protected $request_headers = array();
	protected $request_user_agent;
	protected $request_http_version = '1.0';
	protected $request_duration;
	protected $request;
	protected $response_code;
	protected $response_message;
	protected $response_headers;
	protected $raw_response_body;
	protected $response_handler;
	protected $response;


	/**
	 * Perform the request and return the parsed response
	 * ‍@access	protected
	 */
	protected function perform_request( $request ) {

		// ensure API is in its default state
		$this->reset_response();

		// save the request object
		$this->request = $request;

		$start_time = microtime( true );

		// perform the request
		$response = $this->do_remote_request( $this->get_request_uri(), $this->get_request_args() );

		// calculate request duration
		$this->request_duration = round( microtime( true ) - $start_time, 5 );

		try {

			// parse & validate response
			$response = $this->handle_response( $response );

		} catch ( Exception $e ) {

			// alert other actors that a request has been made
			$this->broadcast_request();

			throw $e;
		}

		return $response;
	}


	/**
	 * Simple wrapper for wp_remote_request() so child classes can override this and provide their own transport mechanism if needed, 
	 * e.g. a custom
	 * cURL implementation
	 *
	 * @param string $request_uri
	 * @param string $request_args
	 * @return array|WP_Error
	 */
	protected function do_remote_request( $request_uri, $request_args ) {
		return wp_safe_remote_request( $request_uri, $request_args );
	}


	/**
	 * Handle and parse the response
	 *
	 * @param array|WP_Error $response response data
	 * @throws Exception network issues, timeouts, API errors, etc
	 * @return object request class instance that implements SV_WC_API_Request
	 */
	protected function handle_response( $response ) {

		// check for WP HTTP API specific errors (network timeout, etc)
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message(), (int) $response->get_error_code() );
		}

		// set response data
		$this->response_code     = wp_remote_retrieve_response_code( $response );
		$this->response_message  = wp_remote_retrieve_response_message( $response );
		$this->response_headers  = wp_remote_retrieve_headers( $response );
		$this->raw_response_body = wp_remote_retrieve_body( $response );

		// allow child classes to validate response prior to parsing 
		$this->awc_do_pre_parse_response_validation();

		// parse the response body and tie it to the request
		$this->response = $this->get_parsed_response( $this->raw_response_body );

		// allow child classes to validate response after parsing 
		$this->awc_do_post_parse_response_validation();

		// fire do_action() so other actors can act on request/response data, primarily used for logging
		$this->broadcast_request();

		return $this->response;
	}


	/**
	 * Allow child classes to validate a response prior to instantiating the
	 * response object. Useful for checking response codes or messages, e.g.
	 * throw an exception if the response code is not 200.
	 *
	 * A child class implementing this method should simply return true if the response
	 * processing should continue, or throw a \SV_WC_API_Exception with a
	 * relevant error message & code to stop processing.
	 *
	 *
	 */
	protected function awc_do_pre_parse_response_validation() {
		// stub method
	}


	/**
	 * Allow child classes to validate a response after it has been parsed and instantiated. This is useful for check error codes or messages that exist in the parsed response.
	 *
	 * Note: Response body sanitization is handled automatically
	 *
	 */
	protected function awc_do_post_parse_response_validation() {
		// stub method
	}


	/**
	 * Return the parsed response object for the request
	 *
	 * @param string 
	 *
	 * @return object response class instance which implements SV_WC_API_Request
	 */
	protected function get_parsed_response( $raw_response_body ) {

		$handler_class = $this->get_response_handler();

		return new $handler_class( $raw_response_body );
	}


	/**
	 * Alert other actors that a request has been performed. This is primarily used
	 * for request logging.
	 *
	 */
	protected function broadcast_request() {

		$request_data = array(
			'method'     => $this->get_request_method(),
			'uri'        => $this->get_request_uri(),
			'user-agent' => $this->get_request_user_agent(),
			'headers'    => $this->get_sanitized_request_headers(),
			'body'       => $this->request->to_string_safe(),
			'duration'   => $this->get_request_duration() . 's', // seconds
		);

		$response_data = array(
			'code'    => $this->get_response_code(),
			'message' => $this->get_response_message(),
			'headers' => $this->get_response_headers(),
			'body'    => $this->get_sanitized_response_body() ? $this->get_sanitized_response_body() : $this->get_raw_response_body(),
		);

		do_action( 'wc_' . $this->get_api_id() . '_api_request_performed', $request_data, $response_data, $this );
	}


	/**
	 * Reset the API response members to their
	 *
	 */
	protected function reset_response() {

		$this->response_code     = null;
		$this->response_message  = null;
		$this->response_headers  = null;
		$this->raw_response_body = null;
		$this->response          = null;
		$this->request_duration  = null;
	}


	/** Request Getters *******************************************************/


	/**
	 * Get the request URI
	 *
	 * @return string
	 */
	protected function get_request_uri() {

		$uri = $this->request_uri . ( $this->get_request() ? $this->get_request()->get_path() : '' );

		/**
		 * Request URI Filter.
		 *
		 * Allow actors to filter the request URI. Note that child classes can override
		 * this method, which means this filter may be invoked prior to the overridden
		 * method.
		 *
		 * @param string $uri current request URI
		 * @param \AWC_SV_API_Base class instance
		 */
		return apply_filters( 'wc_' . $this->get_api_id() . '_api_request_uri', $uri, $this );
	}


	/**
	 * Get the request arguments in the format required by wp_remote_request()
	 *
	 * @return mixed|void
	 */
	protected function get_request_args() {

		$args = array(
			'method'      => $this->get_request_method(),
			'timeout'     => MINUTE_IN_SECONDS,
			'redirection' => 0,
			'httpversion' => $this->get_request_http_version(),
			'sslverify'   => true,
			'blocking'    => true,
			'user-agent'  => $this->get_request_user_agent(),
			'headers'     => $this->get_request_headers(),
			'body'        => $this->get_request()->to_string(),
			'cookies'     => array(),
		);

		/**
		 * Request arguments.
		 *
		 * Allow other actors to filter the request arguments. Note that
		 * child classes can override this method, which means this filter.
		 *
		 * @param array $args request arguments
		 * @param \AWC_SV_API_Base class instance
		 */
		return apply_filters( 'wc_' . $this->get_api_id() . '_http_request_args', $args, $this );
	}


	/**
	 * Get the request method, POST by default
	 *
	 * @return string
	 */
	protected function get_request_method() {
		// if the request object specifies the method to use, use that, otherwise use the API default
		return $this->get_request() && $this->get_request()->get_method() ? $this->get_request()->get_method() : $this->request_method;
	}


	/**
	 *
	 * @return string
	 */
	protected function get_request_http_version() {

		return $this->request_http_version;
	}


	/**
	 * Get the request headers
	 *
	 * @return array
	 */
	protected function get_request_headers() {
		return $this->request_headers;
	}


	/**
	 * Get sanitized request headers suitable for logging, stripped of any
	 * confidential information
	 *
	 * The `Authorization` header is sanitized automatically.
	 *
	 * Child classes that implement any custom authorization headers should
	 * override this method to perform sanitization.
	 *
	 * @return array
	 */
	protected function get_sanitized_request_headers() {

		$headers = $this->get_request_headers();

		if ( ! empty( $headers['Authorization'] ) ) {
			$headers['Authorization'] = str_repeat( '*', strlen( $headers['Authorization'] ) );
		}

		return $headers;
	}


	/**
	 * Get the request user agent, defaults to:
	 *
	 * Dasherized-Plugin-Name/Plugin-Version (WooCommerce/WC-Version; WordPress/WP-Version)
	 *
	 * @return string
	 */
	protected function get_request_user_agent() {

		return sprintf( '%s/%s (WooCommerce/%s; WordPress/%s)', str_replace( ' ', '-', $this->get_plugin()->get_plugin_name() ), $this->get_plugin()->get_version(), WC_VERSION, $GLOBALS['wp_version'] );
	}


	/**
	 * Get the request duration in seconds, rounded to the 5th decimal place
	 *
	 * @return string
	 */
	protected function get_request_duration() {
		return $this->request_duration;
	}


	/** Response Getters ******************************************************/


	/**
	 * Get the response handler class name
	 *
	 * @return string
	 */
	protected function get_response_handler() {
		return $this->response_handler;
	}


	/**
	 * Get the response code
	 *
	 * @return string
	 */
	protected function get_response_code() {
		return $this->response_code;
	}


	/**
	 * Get the response message
	 *
	 * @return string
	 */
	protected function get_response_message() {
		return $this->response_message;
	}


	/**
	 * Get the response headers
	 *
	 * @return array
	 */
	protected function get_response_headers() {
		return $this->response_headers;
	}


	/**
	 * Get the raw response body, prior to any parsing or sanitization
	 *
	 * @return string
	 */
	protected function get_raw_response_body() {
		return $this->raw_response_body;
	}


	/**
	 * Get the sanitized response body, provided by the response class
	 *
	 * @return string|null
	 */
	protected function get_sanitized_response_body() {
		return is_callable( array( $this->get_response(), 'to_string_safe' ) ) ? $this->get_response()->to_string_safe() : null;
	}



	/**
	 * Returns the most recent request object
	 *
	 * @see \SV_WC_API_Request
	 * @return object the most recent request object
	 */
	public function get_request() {
		return $this->request;
	}


	/**
	 * Returns the most recent response object
	 *
	 * @see \SV_WC_API_Response
	 * @return object the most recent response object
	 */
	public function get_response() {
		return $this->response;
	}


	/**
	 * Get the ID for the API, used primarily to namespace the action name
	 * for broadcasting requests
	 *
	 * @return string
	 */
	protected function get_api_id() {

		return $this->get_plugin()->get_id();
	}


	/**
	 * Return a new request object
	 *
	 * Child classes must implement this to return an object that implements
	 * \SV_WC_API_Request which should be used in the child class API methods
	 * to build the request. The returned SV_WC_API_Request should be passed
	 * to self::perform_request() by your concrete API methods
	 *
	 * @param array $args optional request arguments
	 * @return SV_WC_API_Request
	 */
	abstract protected function get_new_request( $args = array() );


	/**
	 * Return the plugin class instance associated with this API
	 *
	 * Child classes must implement this to return their plugin class instance
	 *
	 * This is used for defining the plugin ID used in filter names, as well
	 * as the plugin name used for the default user agent.
	 *
	 * @return SV_WC_Plugin
	 */
	abstract protected function get_plugin();


	/** Setters ***************************************************************/


	/**
	 * Set a header request
	 *
	 * @param string $name & $value	 
	 * @return string
	 */
	protected function set_request_header( $name, $value ) {
		$this->request_headers[ $name ] = $value;
	}


	/**
	 * Set HTTP basic auth for the request
	 *
	 * @param string $username
	 * @param string $password
	 */
	protected function set_http_basic_auth( $username, $password ) {

		$this->request_headers['Authorization'] = sprintf( 'Basic %s', base64_encode( "{$username}:{$password}" ) );
	}


	/**
	 * Set the Content-Type request header
	 *
	 * @param string $content_type
	 */
	protected function set_request_content_type_header( $content_type ) {
		$this->request_headers['content-type'] = $content_type;
	}


	/**
	 * Set the Accept request header
	 *
	 * @param string $type the request accept type
	 */
	protected function set_request_accept_header( $type ) {
		$this->request_headers['accept'] = $type;
	}


	/**
	 * Set the response handler class name. This class will be instantiated
	 * to parse the response for the request.
	 *
	 * Note the class should implement SV_WC_API
	 *
	 * @param string $handler handle class name
	 * @return array
	 */
	protected function set_response_handler( $handler ) {
		$this->response_handler = $handler;
	}


}
