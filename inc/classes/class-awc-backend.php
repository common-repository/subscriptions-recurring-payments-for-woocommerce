<?php

/**
 * Load Backend related actions
 *
 * @class   AWC_Backend
 */


if (!defined('ABSPATH')) {
    exit;
}


class AWC_Backend
{
    /**
     * Class intance for singleton  class
     *
     * @var    object
     * @access  private
     
     */
    private static $instance = null;

    /**
     * @access  public 
     * @var     string
     */
    public static $name = 'subscription';

    /**
     * The version number.
     *
     * @var     string
     * @access  public
     
     */
    public $version;

    /**
	 * Is meta boxes saved once?
	 *
	 * @var boolean
     * @return boolean true/false
	 */
	private static $saved_product_meta = false;

    /**
     * The token.
     *
     * @var     string
     * @access  public
     */
    public $token;

    /**
     * @var     string
     * @access  public
     * 
     */
    public static $wc_minimum_supported_version = '3.0';

    /**
     * The main plugin file.
     *
     * @var     string
     * @access  public
     
     */
    public $file;

    /**
     * The main plugin directory.
     *
     * @var     string
     * @access  public
     
     */
    public $dir;

    /**
     * The plugin assets directory.
     *
     * @var     string
     * @access  public
     
     */
    public $assets_dir;


    /**
     * Suffix for Javascripts.
     *
     * @var     string
     * @access  public
     
     */
    public $script_suffix;

    /**
     * The plugin assets URL.
     *
     * @var     string
     * @access  public
     
     */
    public $assets_url;
    /**
     * The plugin hook suffix.
     *
     * @var     array
     * @access  public
     
     */
    public $hook_suffix = array();

    /**
     * WP DB
     */
    private $wpdb;


    /**
     * Constructor function.
     *
     * @access  public
     * @param string $file plugin start file path.
     
     */
    public function __construct($file = '')
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->version = AWC_VERSION;
        $this->token = AWC_TOKEN;
        $this->file = $file;
        $this->dir = dirname($this->file);
        $this->assets_dir = trailingslashit($this->dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));
        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        $plugin = plugin_basename($this->file);
        
        
        // Add woocommerce settings page to $hook_suffix 
        array_push($this->hook_suffix, 'woocommerce_page_wc-settings');

        // add action links to link to link list display on the plugins page.
        add_filter("plugin_action_links_$plugin", array($this, 'pluginActionLinks'));

        // reg activation hook.
        register_activation_hook($this->file, array($this, 'install'));
        
        // reg deactivation hook.
        register_deactivation_hook($this->file, array($this, 'deactivation'));

        // enqueue scripts & styles.
        add_action('admin_enqueue_scripts', array($this, 'adminEnqueueScripts'), 10, 1);
        add_action('admin_enqueue_scripts', array($this, 'adminEnqueueStyles'), 10, 1);


        // Admin Menu 
        add_action( 'admin_menu', array($this, 'awc_admin_menu_page_hook') );

        // Admin notices 
        add_action( 'admin_notices', array($this, 'awc_display_admin_notices') );
        
    }


    /**
     * Store a message to display using @see awc_display_admin_notices().
     *
     * @param string The message to display
     */
    public static function awc_add_admin_notice( $message, $notice_type = 'success' ) {

        $notices = get_transient( '_awc_admin_notices' );

        if ( false === $notices ) {
            $notices = array();
        }

        $notices[ $notice_type ][] = $message;

        set_transient( '_awc_admin_notices', $notices, 60 * 60 );
    }



    /**
     * Display any notices added with @see awc_add_admin_notice()
     *
     * This method is also hooked to 'admin_notices' to display notices there.
     *
     */
    function awc_display_admin_notices( $clear = true ) {

        $notices = get_transient( '_awc_admin_notices' );

        if ( false !== $notices && ! empty( $notices ) ) {

            if ( ! empty( $notices['success'] ) ) {
                array_walk( $notices['success'], 'esc_html' );
                echo '<div id="moderated" class="updated"><p>' . wp_kses_post( implode( "</p>\n<p>", $notices['success'] ) ) . '</p></div>';
            }

            if ( ! empty( $notices['error'] ) ) {
                array_walk( $notices['error'], 'esc_html' );
                echo '<div id="moderated" class="error"><p>' . wp_kses_post( implode( "</p>\n<p>", $notices['error'] ) ) . '</p></div>';
            }
        }

        if ( false !== $clear ) {
            $this->awc_clear_admin_notices();
        }
    }



    /**
     * Delete any admin notices we stored for display later.
     *
     */
    public function awc_clear_admin_notices() {
        delete_transient( '_awc_admin_notices' );
    }


    /**
	 * Save meta info for subscription variations
	 *
	 * @param int $variation_id
	 * @param int $i
	 * return void
	 
	 */
	public static function awc_save_product_variation( $variation_id, $index ) {
		if ( ! AWC_Subscription_Products::awc_is_subscription( $variation_id ) || empty( $_POST['_asubnonce_save_variations'] ) || ! wp_verify_nonce( $_POST['_asubnonce_save_variations'], 'awc_subscription_variations' ) ) {
			return;
		}
        
        

		if ( isset( $_POST['variable_subscription_sign_up_fee'][ $index ] ) ) {
			$subscription_sign_up_fee = wc_format_decimal( $_POST['variable_subscription_sign_up_fee'][ $index ] );
			update_post_meta( $variation_id, '_subscription_sign_up_fee', $subscription_sign_up_fee );
		}

		if ( isset( $_POST['variable_subscription_price'][ $index ] ) ) {
			$subscription_price = wc_format_decimal( $_POST['variable_subscription_price'][ $index ] );
			update_post_meta( $variation_id, '_subscription_price', $subscription_price );
			update_post_meta( $variation_id, '_regular_price', $subscription_price );
		}

		// Make sure trial period is within allowable range
		$subscription_ranges = awc_get_subscription_ranges();
		$max_trial_length    = count( $subscription_ranges[ $_POST['variable_subscription_trial_period'][ $index ] ] ) - 1;

		$_POST['variable_subscription_trial_length'][ $index ] = absint( $_POST['variable_subscription_trial_length'][ $index ] );

		if ( $_POST['variable_subscription_trial_length'][ $index ] > $max_trial_length ) {
			$_POST['variable_subscription_trial_length'][ $index ] = $max_trial_length;
		}

		// Work around a WPML bug which means 'variable_subscription_trial_period' is not set when using "Edit Product" as the product translation interface
		if ( $_POST['variable_subscription_trial_length'][ $index ] < 0 ) {
			$_POST['variable_subscription_trial_length'][ $index ] = 0;
		}

		$subscription_fields = array(
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_trial_period',
			'_subscription_trial_length',
		);

		foreach ( $subscription_fields as $field_name ) {
			if ( isset( $_POST[ 'variable' . $field_name ][ $index ] ) ) {
				update_post_meta( $variation_id, $field_name, wc_clean( $_POST[ 'variable' . $field_name ][ $index ] ) );
			}
		}
	}




    /**
	 * Check if subscription product meta data should be saved for the current request.
	 *
     * @param $post_id = post id
	 * @param $product_types Array of product types.
     * @return bullian true/false
	 */
	private static function awc_is_subscription_product_request( $post_id, $product_types ) {

		if ( self::$saved_product_meta ) {
			$is_subscription_product_save_request = false;
		} elseif ( empty( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_subscription_meta' ) ) {
			$is_subscription_product_save_request = false;
		} elseif ( ! isset( $_POST['product-type'] ) || ! in_array( $_POST['product-type'], $product_types ) ) {
			$is_subscription_product_save_request = false;
		} elseif ( empty( $_POST['post_ID'] ) || $_POST['post_ID'] != $post_id ) {
			$is_subscription_product_save_request = false;
		} else {
			$is_subscription_product_save_request = true;
		}



		return apply_filters( 'awc_is_subscription_product_save_request', $is_subscription_product_save_request, $post_id, $product_types );
	}



    /**
	 * Returns either a string or array of strings describing the allowable trial period range  for a subscription.
	 * @access  public static  
	 * @param $form string
	 */
	public static function awc_get_trial_period_validation_message( $form = 'combined' ) {

		$subscription_ranges = awc_get_subscription_ranges();

		if ( 'combined' == $form ) {
			// translators: number of 1$: days, 2$: weeks, 3$: months, 4$: years
			$error_message = sprintf( __( 'The trial period can not exceed: %1$s, %2$s, %3$s or %4$s.', 'subscriptions-recurring-payments-for-woocommerce' ), array_pop( $subscription_ranges['day'] ), array_pop( $subscription_ranges['week'] ), array_pop( $subscription_ranges['month'] ), array_pop( $subscription_ranges['year'] ) );
		} else {
			$error_message = array();
			foreach ( awc_get_available_time_periods() as $period => $string ) {
				// translators: placeholder is a time period (e.g. "4 weeks")
				$error_message[ $period ] = sprintf( __( 'The trial period can not exceed %s.', 'subscriptions-recurring-payments-for-woocommerce' ), array_pop( $subscription_ranges[ $period ] ) );
			}
		}

		return apply_filters( 'woocommerce_subscriptions_trial_period_validation_message', $error_message );
	}

    




   

    
    /**
     * @access  public
     * @desc    Admin notice if woocommerce aren't installed
     */
    public function awc_notice_need_woocommerce(){
        $error = sprintf(
            esc_html__(
                '%s requires %sWooCommerce%s to be installed & activated!',
                'subscriptions-recurring-payments-for-woocommerce'
            ),
            AWC_PLUGIN_NAME, '<a href="https://wordpress.org/plugins/woocommerce/">', '</a>'
        );

        echo ('<div class="error"><p>' . $error . '</p></div>');
    }
    
    /**
     * @access  public
     * @desc    Add saperate admin menu for aco subscription settings
     * 
    */
    public function awc_admin_menu_page_hook(){
        $capabilities = 'no' != AWC_Settings::get_option('manager_can_manage') ? 'manage_woocommerce' : 'manage_options';
        $this->hook_suffix[] = add_menu_page( 
            __('Woo Subscriptions', 'subscriptions-recurring-payments-for-woocommerce'), 
            __('Woo Subscriptions', 'subscriptions-recurring-payments-for-woocommerce'), 
            $capabilities,
            $this->token . '-subscriptions', 
            array($this, 'awc_admin_page_callback'), 
            'dashicons-image-rotate', 
            56
        );
    }


    /**
     * @access  public
     * @return  page content
     * 
     */
    public function awc_admin_page_callback(){
        echo (
            '<div id="' . $this->token . '_ui_root" class="bg-white border-round-5 pb-5">
                <div class="' . $this->token . '_loader"><p>' . __('Loading User Interface...', 'advanced-table-rate-shipping-for-woocommerce') . '</p></div>
            </div>'
        );

        // wp_localize_script(
        //     $this->token . '-backend',
        //     $this->token . 'shipping_settings',
        //     array()
        // );
    }


    public function acotrs_customize_shipping_zone_shipping_methods($methods, $raw_methods, $allowed_classes, $instance){
        // $methods_array = array();
        
        foreach($methods as $k => $m){
            if($m->id === 'acotrs_shipping' ){
                // unset($methods[$k]);
                // if(count($methods_array) > 0)
                //     continue;
                // array_push($methods_array, $m);
            }    
        }
        
        // if(count($methods_array) > 0) 
        //     $methods = array_merge($methods, $methods_array);

        return $methods; 
    }


 

    /**
     * Ensures only one instance of Class is loaded or can be loaded.
     *
     * @param string $file plugin start file path.
     * @return Main Class instance
     
     * @static
     */
    public static function instance($file = '')
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($file);
        }
        return self::$instance;
    }


    /**
     * Show action links on the plugin screen.
     *
     * @param mixed $links Plugin Action links.
     *
     * @return array
     */
    public function pluginActionLinks($links)
    {
        $action_links = array(
            'settings' => '<a href="' . admin_url('admin.php?page=' . $this->token . '-subscriptions#/settings') . '">'
                . __('Configure', 'advanced-table-rate-shipping-for-woocommerce') . '</a>'
        );

        return array_merge($action_links, $links);
    }

    /**
     * Check if woocommerce is activated
     *
     * @access  public
     * @return  boolean woocommerce install status
     */
    public static function isWoocommerceActivated()
    {
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        if (is_multisite()) {
            $plugins = get_site_option('active_sitewide_plugins');
            if (isset($plugins['woocommerce/woocommerce.php'])) {
                return true;
            }
        }
        return false;
    }



    /**
     * Installation. Runs on activation.
     *
     * @access  public
     * @return  void
     
     */
    public function install()
    {
        global $wpdb;
        include_once ABSPATH . '/wp-admin/includes/upgrade.php';
        $table_charset = '';
        $prefix = $wpdb->prefix;
        $users_table = $prefix . 'um_vip_users';
        if ($wpdb->has_cap('collation')) {
            if (!empty($wpdb->charset)) {
                $table_charset = "DEFAULT CHARACTER SET {$wpdb->charset}";
            }
            if (!empty($wpdb->collate)) {
                $table_charset .= " COLLATE {$wpdb->collate}";
            }
        }
        $create_vip_users_sql = "CREATE TABLE {$users_table} (id int(11) NOT NULL auto_increment,user_id int(11) NOT NULL,user_type tinyint(4) NOT NULL default 0,startTime datetime NOT NULL default '0000-00-00 00:00:00',endTime datetime NOT NULL default '0000-00-00 00:00:00',PRIMARY KEY (id),INDEX uid_index(user_id),INDEX utype_index(user_type)) ENGINE = MyISAM {$table_charset};";
        maybe_create_table($users_table, $create_vip_users_sql);
    }


    /**
     * Load admin CSS.
     *
     * @access  public
     * @return  void
     
     */
    public function adminEnqueueStyles($screen)
    {
        
        if (!isset($this->hook_suffix) || empty($this->hook_suffix)) {    
            return;
        }
        if (in_array($screen, $this->hook_suffix, true)) {
            wp_register_style($this->token . '-admin', esc_url($this->assets_url) . 'css/backend.css', array(), $this->version);
            wp_enqueue_style($this->token . '-admin');
            wp_enqueue_style($this->token . '-admin-wrapper');
        }
    }

    /**
     * Load admin Javascript.
     *
     * @access  public
     * @return  void
     
     */
    public function adminEnqueueScripts()
    {
        if (!isset($this->hook_suffix) || empty($this->hook_suffix)) {   
            return;
        }

        $screen = get_current_screen();

        if (in_array($screen->id, $this->hook_suffix, true)) {
            // Enqueue WordPress media scripts.
            if (!did_action('wp_enqueue_media')) {
                wp_enqueue_media();
            }

            if (!wp_script_is('wp-i18n', 'registered')) {
                wp_register_script('wp-i18n', esc_url($this->assets_url) . 'js/i18n.min.js', array(), $this->version, true);
            }
            // Enqueue custom backend script.
            wp_enqueue_script($this->token . '-backend', esc_url($this->assets_url) . 'js/backend.js', array('wp-i18n'), $this->version, true);
            // Localize a script.
            wp_localize_script(
                $this->token . '-backend',
                $this->token . '_object',
                array(
                    'api_nonce' => wp_create_nonce('wp_rest'),
                    'root' => rest_url($this->token . '/v1/'),
                    'assets_url' => $this->assets_url,
                    'base_url' => get_admin_url( '/' )
                )
            );
        }
        // elseif($screen->id == 'product'){
        //     wp_register_script( $this->token . '-productmeta', esc_url($this->assets_url) . 'js/frontend/awc_product_meta.js', array(), time(), true );
            
        // }
    }


    
    /**
     * Deactivation hook
     */
    public function deactivation()
    {
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