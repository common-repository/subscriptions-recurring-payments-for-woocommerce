<?php

if (!defined('ABSPATH')) {
    exit;
}

class AWC_Restapi
{


    /**
     * @var    object
     * @access  private
     
     */
    private static $instance = null;

    /**
     * The version number.
     *
     * @var     string
     * @access  public
     
     */
    public $version;
    /**
     * The token.
     *
     * @var     string
     * @access  public
     
     */
    public $token;

    /**
     * Wp dB 
     * @var     string
     * @access  private
     * 
     */
    private $wpdb;

    /**
     * Item ID for remote api request to wppath server for API Key
     */
    public $item_id;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->token = AWC_TOKEN;
        $this->item_id = '';

        add_action( 'init', array($this, 'awc_api_initial_callback') );

        add_action(
            'rest_api_init',
            function () {

                //Delete shipping option from custom page
                register_rest_route(
                    $this->token . '/v1',
                    '/delete_shipping_option/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'acotrs_delete_shipping_callback'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );



                //Delete shipping multiple option
                register_rest_route(
                    $this->token . '/v1',
                    '/delete_methods/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'acotrs_delete_multiple_shipping_callback'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );

                
                // Licenced Info
                register_rest_route(
                    $this->token . '/v1',
                    '/initial_config/',
                    array(
                        'methods' => 'GET',
                        'callback' => array($this, 'acotrs_get_initial_config'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );


                register_rest_route(
                    $this->token . '/v1',
                    '/config/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'awc_getconfig'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );


                // Update subscript settings to option
                register_rest_route(
                    $this->token . '/v1',
                    '/updatedata/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'awc_update_config'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );

                register_rest_route(
                    $this->token . '/v1',
                    '/subscription-status-action/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'awc_subscription_status_action_callback'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );

                register_rest_route(
                    $this->token . '/v1',
                    '/search-subscriptions/',
                    array(
                        'methods' => 'POST',
                        'callback' => array($this, 'awc_subscription_search'),
                        'permission_callback' => array($this, 'getPermission'),
                    )
                );
            }
        );
    }




    /**
     * @param $data post array
     * @access  public
     * @return  subscription after search
     */
    public function awc_subscription_search($data){
      
        $return_array = array('s' => $data['s']);
        $subscription_query = array(
            'subscriptions_per_page' => AWC_Settings::get_option('list_page_amount') ? AWC_Settings::get_option('list_page_amount') : 10,
            'paged'           => isset($data['page']) ? $data['page'] : 1,
            'subscription_status' => isset($data['item_type']) && $data['item_type'] != 'all' ? array($data['item_type']) : array('active', 'cancelled', 'on-hold'), 
            's' => $data['s']
        );
    
        

        $subscriptions = AWC_Admin_Post_Types::awc_get_subscriptions($subscription_query);
        $return_array['subscriptions'] = $subscriptions;



    return new WP_REST_Response($return_array, 200);


    } 


    /**
     * @access  public
     * Change subscription status by click action button from backend list
     
     */
    public function awc_subscription_status_action_callback($data){
        $statuses = awc_get_subscription_statuses();
        $ids = $data['id'];
        
        foreach($ids as $id){
            $subscription = awc_get_subscription( $id );
            update_option( 'tests', array('action' => $subscription->can_be_updated_to($data['action']), 'subs' => $subscription ) );
            if($subscription->can_be_updated_to($data['action'])){
                $subscription->update_status( $data['action'] );
            }
        }

        $items = $data['items'];

        if( count(array_intersect( array('all', 'subscriptions'), $items)) > 0 ){
            $subscription_query = array(
                'subscriptions_per_page' => AWC_Settings::get_option('list_page_amount') ? AWC_Settings::get_option('list_page_amount') : 10,
                'paged'           => -1,
                'subscription_status' => isset($data['item_type']) && $data['item_type'] != 'all' ? array($data['item_type']) : array('active', 'cancelled', 'on-hold'), 
            );

            $return_array = array();
            $subscriptions = AWC_Admin_Post_Types::awc_get_subscriptions($subscription_query);

            $return_array['subscriptions'] = $subscriptions;
            $return_array['total_page'] = count($subscriptions) / 2;
        }

        if( count(array_intersect( array('all', 'status_count'), $items)) > 0 ){
            $status_counts = AWC_Admin_Post_Types::awc_get_subscription_status_count();
            $return_array['status_count'] = $status_counts;
        }

        return new WP_REST_Response($return_array, 200);
    }

    /**
     * @access  public
     * @return  set all initial method
    */
    public function awc_api_initial_callback(){
        $months = wp_cache_get( 'awc_subscribes_filter_months' );
        if($months === false){
            $query = $this->wpdb->prepare("SELECT YEAR(meta_value) AS `year`, MONTH(meta_value) AS `month`, count(post_id) as posts 
                FROM ".$this->wpdb->postmeta." WHERE meta_key =%s           
                GROUP BY YEAR(meta_value), MONTH(meta_value) ORDER BY meta_value DESC", '_schedule_start');
            $Quary = $this->wpdb->get_results($query);
            $months = new stdClass();
            $empty = 0;
            $months->$empty = __('All dates', 'subscriptions-recurring-payments-for-woocommerce');

            foreach($Quary as $sm){
                $value = esc_attr($sm->year . str_pad($sm->month, 2, '0', STR_PAD_LEFT));
                $month_dt = new DateTime($sm->year.'-'.$sm->month.'-01');
                $months->$value = $month_dt->format('F Y');
            }
            wp_cache_set( 'awc_subscribes_filter_months',  apply_filters( 'awc_subscribes_filter_months', $months ));
        }
    }


    /**
     * @param   post/data 
     * @return  success message
     */
    public function acotrs_delete_multiple_shipping_callback($data){
        $instance_ids = $data['instance_id'];
        $msg = 'error';
        foreach($instance_ids as $sid){
            $shipping_method = WC_Shipping_Zones::get_shipping_method( $sid );
            $option_key      = $shipping_method->get_instance_option_key();
            $option_key      = str_replace('woocommerce_', '', $option_key);
            if( $this->wpdb->delete( "{$this->wpdb->prefix}woocommerce_shipping_zone_methods", array( 'instance_id' => $sid ) ) ) {
                delete_option( $option_key );
                $msg = 'success';
            }
        }

        $return = array(
            'msg' => $msg
        );

        return new WP_REST_Response($return, 200);
    }






    /**
     * @access  public
     * @return  message
     * @param   array
     */
    public function acotrs_change_shipping_status_callback($data){
        
        $is_enabled = absint( 'yes' === $data['enabled'] );
        $instance_id = $data['instance_id'];

        $msg = 'error';
		if ( $this->wpdb->update( "{$this->wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $is_enabled ), array( 'instance_id' => absint( $instance_id ) ) ) ) {
            $msg = 'success';
		}
        
        return new WP_REST_Response($msg, 200);
    }

  

    /**
     * @access private 
     * @return zone methods by zone id
     */
    private function acotrs_zone_methods($method_id){
        $zone_ids = $this->wpdb->prepare( 'SELECT `zone_id` FROM '.$this->wpdb->prefix.'woocommerce_shipping_zone_methods WHERE `method_id`=%s GROUP BY `zone_id`', $method_id);
        $zone_ids = $this->wpdb->get_results($zone_ids, OBJECT);
        
        

        $return_methods = array();
        foreach($zone_ids as $k => $s):
            $zone    = new WC_Shipping_Zone( $s->zone_id );
            $methods = $zone->get_shipping_methods( false, 'json' );

            $methods = array_map(function($v) use($zone){
                if($v->id == 'acotrs_shipping'){
                    return array(
                        'method_title' => $v->method_title, 
                        'enabled' => $v->enabled,
                        'zone_id' => $v->zone_id,
                        'zone_name' => $zone->get_zone_name(),
                        'title' => $v->title, 
                        'instance_id' => $v->instance_id, 
                        'no_of_options' => isset($v->config) && isset($v->config['table_of_rates']) ? count($v->config['table_of_rates']) : 0
                    );
                }else{
                    return false;
                }
            }, $methods);
            $methods = array_values(array_filter($methods));
            $return_methods = array_merge($return_methods, $methods);
        endforeach;


        return $return_methods;
    }



    /**
     * @access  public
     * @param   NULL
     * @return  array
    */
    public function acotrs_zone_lists(){
        $zones = WC_Shipping_Zones::get_zones();
        $zone_array = array(
            0 => __('Locations not covered by your other zones', 'advanced-table-rate-shipping-for-woocommerce')
        );
        foreach($zones as $szone){
            $zone_array[$szone['id']] = $szone['zone_name'];
        }
        return $zone_array;
    }



   /**
     * @access  public
     * @param   post_array
     * @return  list of zones
     */
    public function acotrs_list_of_zones(){

        $zones   = $this->acotrs_zone_lists();
        
        $returnArray = array(
            'zones' => $zones
        );
        return new WP_REST_Response($returnArray, 200);
    }



    /**
     * @access  public
     * @param   post_array
     * @return  list of zone methods by zone id
     */
    public function acotrs_list_of_zone_methods($data){

        $methods = $this->acotrs_zone_methods($data['method_id']);
        $zones   = $this->acotrs_zone_lists();
        
        $returnArray = array(
            'lists' => $methods, 
            'zones' => $zones
        );
        return new WP_REST_Response($returnArray, 200);
    }


    /**
     * @param $_POST Data
     * Save licence key to DB
     */
    public function acotrs_UpdateLicenceKey($data){
        
        $licence_key = trim(sanitize_text_field($data['licence_key']));
        update_option('acotrs_activation_license_key', $licence_key);

         // data to send in our API request
         $api_params = array(
            'edd_action' => 'activate_license',
            'license' => $licence_key,
            'item_id' => $this->item_id, // The ID of the item in EDD
            'url' => home_url()
        );
        // Call the custom API.
        $response = wp_remote_post(ACOTRS_STORE_URL, array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));


        // make sure the response came back okay
        $message = '';
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            if (is_wp_error($response)) {
                $temp = $response->get_error_message();
                if(!empty($temp)) {
                    $message = $response->get_error_message();
                } else {
                    $message = __('An error occurred, please try again.', 'advanced-table-rate-shipping-for-woocommerce');
                }
            }
        } else {
            $license_data = json_decode(wp_remote_retrieve_body($response));

            if (false === $license_data->success) {
                switch ($license_data->error) {
                    case 'expired' :
                        $message = sprintf(
                            __('Your license key expired on %s.', 'advanced-table-rate-shipping-for-woocommerce'), date_i18n(get_option('date_format'), strtotime($license_data->expires, current_time('timestamp')))
                        );
                        break;
                    case 'revoked' :
                        $message = __('Your license key has been disabled.', 'advanced-table-rate-shipping-for-woocommerce');
                        break;
                    case 'missing' :
                        $message = __('Invalid license.', 'advanced-table-rate-shipping-for-woocommerce');
                        break;
                    case 'invalid' :
                    case 'site_inactive' :
                        $message = __('Your license is not active for this URL.', 'advanced-table-rate-shipping-for-woocommerce');
                        break;
                    case 'item_name_mismatch' :
                        $message = sprintf(__('This appears to be an invalid license key for %s.', 'advanced-table-rate-shipping-for-woocommerce'), ACOTRS_PLUGIN_NAME);
                        break;
                    case 'no_activations_left':
                        $message = __('Your license key has reached its activation limit.', 'advanced-table-rate-shipping-for-woocommerce');
                        break;
                    default :
                        $message = __('An error occurred, please try again.', 'advanced-table-rate-shipping-for-woocommerce');
                        break;
                }
            }

            if(empty($message)){
                update_option('acotrs_activation_license_status', $license_data->license);
            }
        }

        

        $data = array(
            'licenced' => get_option( 'acotrs_activation_license_status', false) == 'valid' ? true : false, 
            'msg' => $message, 
            'response' => $response
        );


        return new WP_REST_Response($data, 200);
    }


    /**
     * @access  private
     */
    private function awc_get_option_name(){
        $option_name    = 'awc_settings';
        return apply_filters( 'awc_option_name', $option_name );
    }


    public function awc_update_config($data){
        // Get all data from backend.js
        
        $option_name    = $this->awc_get_option_name();
        $config         = $data['config'];
        
        $update = update_option( $option_name, $config, true );


        $msg = 'true';
        if(!$update) $msg = 'error';
        $data = array(
            'config' => $config
        );
        return new WP_REST_Response($data, 200);
    }

    public function awc_get_user_roles($formated = false){
        global $wp_roles;
        $roles = $wp_roles->get_names();

        if($formated){
            $newRoles = array();
            foreach($roles as $k => $s){
                $newItem = new stdClass();
                $newItem->name = $k;
                $newItem->label = $s;
                
                array_push($newRoles, $newItem);
            }

            $roles = $newRoles;
        }
        return $roles;
    }


    /**
     * Return Licence true/false
     */
    public function acotrs_getLicenced(){
        $license_status = get_option('acotrs_activation_license_status');
        
        if ($license_status == 'valid')
            return true;

        return FALSE;
        
    }


    /**
     * @access  public 
     * @return list of shipping method for shipping zone
     */
    public function acotrs_get_initial_config(){
        return new WP_REST_Response(array(
            'licenced' => $this->acotrs_getLicenced()
        ), 200);
    }


    /**
     * @access  public 
     * @return Single Shipping mehod configration as json
     */
    public function awc_getconfig($data)
    {

        $return_array = array();
        $items = $data['items'];

        if( count(array_intersect( array('all', 'config'), $items)) > 0 ){
            $option_name    = $this->awc_get_option_name();
            $config = get_option( $option_name, array('error' => 'error'));
            $return_array['config'] = $config;
        }

        //User Role
        if( count(array_intersect( array('all', 'roles'), $items)) > 0 ){
            $roles = $this->awc_get_user_roles();
            $roleLists = $this->awc_get_user_roles(true);

            $return_array['roles'] = $roles;
            $return_array['role_lists'] = $roleLists;
        }
        

        //All Users 
        if( count(array_intersect( array('all', 'users'), $items)) > 0 ){
            $users = get_users(  );
            $users = array_map(function($v){
                return array(
                    'id' => $v->ID, 
                    'name' => $v->data->display_name
                );
            }, $users);
            $return_array['users'] = $users;
        }

        if( count(array_intersect( array('all', 'months'), $items)) > 0 ){
            $months = wp_cache_get( 'awc_subscribes_filter_months' );
            $return_array['months'] = $months;
        }
        
        if( count(array_intersect( array('all', 'subscriptions'), $items)) > 0 ){
            

            $subscription_query = array(
				'subscriptions_per_page' => AWC_Settings::get_option('list_page_amount') ? AWC_Settings::get_option('list_page_amount') : 10,
				'paged' => isset($data['page']) ? (int)$data['page'] : 1,
				'subscription_status' => isset($data['item_type']) && $data['item_type'] != 'all' ? array($data['item_type']) : array('active', 'cancelled', 'on-hold'), 
            );

            if(isset($data['date_filter']) && $data['date_filter'] != 0){
                $time=strtotime($data['date_filter'].'01');
                $month=date("m",$time);
                $year=date("Y",$time);

                $subscription_query['date_query'] = array(
                    array(
                        'column' => 'post_date_gmt',
                        'month' => $month,
                        'year' => $year
                    )
                );

            }
             

                
            

            $subscriptions = AWC_Admin_Post_Types::awc_get_subscriptions($subscription_query);
            $return_array['subscriptions'] = $subscriptions;
            $return_array['total_page'] = count($subscriptions) / 2;
        

        }

        if( count(array_intersect( array('all', 'status_count'), $items)) > 0 ){
            $status_counts = AWC_Admin_Post_Types::awc_get_subscription_status_count();
            $return_array['status_count'] = $status_counts;
        }

        return new WP_REST_Response($return_array, 200);
    }

    /**
     *
     * Ensures only one instance of APIFW is loaded or can be loaded.
     *
     * @param string $file Plugin root path.
     * @return Main APIFW instance
     * @see WordPress_Plugin_Template()
     
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Permission Callback
     **/
    public function getPermission()
    {
        if (current_user_can('administrator') || current_user_can('manage_woocommerce')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Cloning is forbidden.
     *
     
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }
}
