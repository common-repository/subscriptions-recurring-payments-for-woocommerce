<?php 
/**
 * AWC subscriptions main class
 
 */

class AWC_Subscriptions{
   
   /**
    * Class intance for singleton  class
    *
    * @var    object
    * @access  private
    */
   private static $instance = null;

   public static $name = 'subscription';

   public static $activation_transient = 'woocommerce_subscriptions_activated';

   public static $plugin_file = AWC_FILE;

   public static $plugin_path = AWC_PATH;

   public static $version = '1.0.0';

   public static $wc_minimum_supported_version = '3.0';

   private static $total_subscription_count = null;

   private static $scheduler;

   /** @var AWC_Cache_Manager */
   public static $cache;

   /** @var AWC_Autoloader */
   protected static $autoloader;

   

    /**
    * Constructor function.
    *
    * @access  public
    * @param string $file plugin start file path.
    */
   public function __construct()
   {
       self::$plugin_path = AWC_PATH;
   }
   /**
    * The plugin assets URL.
    *
    * @var     string
    * @access  public
    */
   public static $assets_url;

   public static function init($autoloader = null)
   {
       self::$plugin_path = AWC_PATH;
       self::$assets_url = plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/';
       self::$autoloader = $autoloader ? $autoloader : new AWC_Autoloader( dirname( __FILE__ ) );

       // Register our custom subscription order type after WC_Post_types::register_post_types()
       add_action( 'init', __CLASS__ . '::awc_register_order_types', 6 );

       add_filter( 'woocommerce_data_stores', __CLASS__ . '::add_data_stores', 10, 1 );

       // Register our custom subscription order statuses before WC_Post_types::register_post_status()
       add_action( 'init', __CLASS__ . '::register_post_status', 9 );

       add_action( 'init', __CLASS__ . '::maybe_activate_woocommerce_subscriptions' );

       register_deactivation_hook( __FILE__, __CLASS__ . '::deactivate_woocommerce_subscriptions' );

       // Override the WC default "Add to cart" text to "Sign up now" (in various places/temp)
       add_filter( 'woocommerce_order_button_text', __CLASS__ . '::order_button_text' );
       add_action( 'woocommerce_subscription_add_to_cart', __CLASS__ . '::subscription_add_to_cart', 30 );
       add_action( 'woocommerce_variable-subscription_add_to_cart', __CLASS__ . '::variable_subscription_add_to_cart', 30 );
       add_action( 'wcopc_subscription_add_to_cart', __CLASS__ . '::wcopc_subscription_add_to_cart' ); // One Page Checkout compatibility

       // Enqueue front-end styles, run after Storefront because it sets the styles to be empty
       add_filter( 'woocommerce_enqueue_styles', __CLASS__ . '::enqueue_styles', 100, 1 );

       // Load translation files
       add_action( 'plugins_loaded', __CLASS__ . '::load_plugin_textdomain', 3 );

       // Load Inbuild Stripe payment getway
       if(AWC_Settings::get_option('payment_methods') && is_array(AWC_Settings::get_option('payment_methods')) && in_array('stripe', AWC_Settings::get_option('payment_methods')) && class_exists('AWC_Wc_Stripe_Payment') )
           add_action( 'plugins_loaded', 'AWC_Wc_Stripe_Payment::stripe_init' );


       // Load frontend scripts
       add_action( 'wp_enqueue_scripts', __CLASS__ . '::enqueue_frontend_scripts', 3 );

       // Load dependent files
       add_action( 'plugins_loaded', __CLASS__ . '::load_dependant_classes' );

       // Attach hooks which depend on WooCommerce constants
       add_action( 'plugins_loaded', array( __CLASS__, 'attach_dependant_hooks' ) );

       // Make sure the related order data store instance is loaded and initialised so that cache management will function
       add_action( 'plugins_loaded', 'awc_Related_Order_Store::instance' );

       // Make sure the related order data store instance is loaded and initialised so that cache management will function
       add_action( 'plugins_loaded', 'awc_Customer_Store::instance' );

       // Staging site or site migration notice
       add_action( 'admin_notices', __CLASS__ . '::woocommerce_site_change_notice' );

       add_filter( 'action_scheduler_queue_runner_batch_size', __CLASS__ . '::action_scheduler_multisite_batch_size' );

       // get details of orders of a customer
       add_action( 'wp_ajax_awc_get_customer_orders', __CLASS__ . '::get_customer_orders' );

       self::$cache = AWC_Cache_Manager::get_instance();

       $scheduler_class = apply_filters( 'woocommerce_subscriptions_scheduler', 'awc_Action_Scheduler' );

       self::$scheduler = new $scheduler_class();
   }


   /**
    * Override the WooCommerce "Place order" text with "Sign up now"
    *
    */
   public static function order_button_text( $button_text ) {
       global $product;

       if ( AWC_Subscription_Cart::cart_contains_subscription() && AWC_Settings::get_option('order_label') ) {
            return apply_filters( 'awc_place_order_text', AWC_Settings::get_option('order_label') );
       }

       return $button_text;
   }

   /**
    * Removes all subscription products from the shopping cart.
    *
    */
   public static function remove_subscriptions_from_cart() {

       AWC_Subscription_Cart::remove_subscriptions_from_cart();
   }


   /**
    * When a subscription is added to the cart, remove other products/subscriptions to
    * work with PayPal Standard, which only accept one subscription per checkout.
    *
    */
   public static function maybe_empty_cart( $valid, $product_id, $quantity, $variation_id = '', $variations = array() ) {

       $is_subscription                 = AWC_Subscription_Products::awc_is_subscription( $product_id );
       $cart_contains_subscription      = AWC_Subscription_Cart::cart_contains_subscription();
       $multiple_subscriptions_possible = AWC_Subscription_Payment_Gateways::one_gateway_supports( 'multiple_subscriptions' );
       $manual_renewals_enabled         = AWC_Settings::get_option('manual_renewal_payment') ? 'yes' : 'no';
       $canonical_product_id            = ! empty( $variation_id ) ? $variation_id : $product_id;

       if ( $is_subscription && !AWC_Settings::get_option('mixed_checkout') ) {

           // Generate a cart item key from variation and cart item data - which may be added by other plugins
           $cart_item_data = (array) apply_filters( 'woocommerce_add_cart_item_data', array(), $product_id, $variation_id, $quantity );
           $cart_item_id   = WC()->cart->generate_cart_id( $product_id, $variation_id, $variations, $cart_item_data );
           $product        = wc_get_product( $product_id );

           // If the product is sold individually or if the cart doesn't already contain this product, empty the cart.
           if ( ( $product && $product->is_sold_individually() ) || ! WC()->cart->find_product_in_cart( $cart_item_id ) ) {
               $coupons = WC()->cart->get_applied_coupons();
               WC()->cart->empty_cart();
               WC()->cart->set_applied_coupons( $coupons );
           }
       } elseif ( $is_subscription && awc_cart_contains_renewal() && ! $multiple_subscriptions_possible && ! $manual_renewals_enabled ) {

           AWC_Subscription_Cart::remove_subscriptions_from_cart();

           wc_add_notice( __( 'A subscription renewal has been removed from your cart. Multiple subscriptions can not be purchased at the same time.', 'subscriptions-recurring-payments-for-woocommerce' ), 'notice' );

       } elseif ( $is_subscription && $cart_contains_subscription && ! $multiple_subscriptions_possible && ! $manual_renewals_enabled && ! AWC_Subscription_Cart::cart_contains_product( $canonical_product_id ) ) {

           AWC_Subscription_Cart::remove_subscriptions_from_cart();

           wc_add_notice( __( 'A subscription has been removed from your cart. Due to payment gateway restrictions, different subscription products can not be purchased at the same time.', 'subscriptions-recurring-payments-for-woocommerce' ), 'notice' );

       } elseif ( $cart_contains_subscription && !AWC_Settings::get_option('mixed_checkout') ) {

           AWC_Subscription_Cart::remove_subscriptions_from_cart();

           wc_add_notice( __( 'A subscription has been removed from your cart. Products and subscriptions can not be purchased at the same time.', 'subscriptions-recurring-payments-for-woocommerce' ), 'notice' );

           // Redirect to cart page to remove subscription & notify shopper
           if ( self::is_woocommerce_pre( '3.0.8' ) ) {
               add_filter( 'add_to_cart_fragments', __CLASS__ . '::redirect_ajax_add_to_cart' );
           } else {
               add_filter( 'woocommerce_add_to_cart_fragments', __CLASS__ . '::redirect_ajax_add_to_cart' );
           }
       }

       return AWC_Subscription_Cart_Validator::maybe_empty_cart( $valid, $product_id, $quantity, $variation_id, $variations );
   }



   /**
    * Output a redirect URL when an item is added to the cart when a subscription was already in the cart.
    *
    */
   public static function redirect_ajax_add_to_cart( $fragments ) {

       $fragments['error'] = true;
       $fragments['product_url'] = wc_get_cart_url();

       # Force error on add_to_cart() to redirect
       add_filter( 'woocommerce_add_to_cart_validation', '__return_false', 10 );
       add_filter( 'woocommerce_cart_redirect_after_error', __CLASS__ . '::redirect_to_cart', 10, 2 );
       do_action( 'wc_ajax_add_to_cart' );

       return $fragments;
   }



   /**
    * Load the subscription add_to_cart template.
    * @access   public
    *
    */
   public static function subscription_add_to_cart() {
       wc_get_template( 'product/cart/subscription.php', array(), '', plugin_dir_path( self::$plugin_file )  . 'temp/' );
   }


   /**
    * Load the variable subscription add_to_cart template
    *
    * Use a very similar cart template as that of a variable product with added functionality.
    */
   public static function variable_subscription_add_to_cart() {
       global $product;

       // Enqueue variation scripts
       wp_enqueue_script( 'wc-add-to-cart-variation' );

       // Get Available variations?
       $get_variations = sizeof( $product->get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );

       // Load the template
       wc_get_template( 'product/cart/asub-frontend-variable-subscription.php', array(
           'available_variations' => $get_variations ? $product->get_available_variations() : false,
           'attributes'           => $product->get_variation_attributes(),
           'selected_attributes'  => $product->get_default_attributes(),
       ), '', plugin_dir_path( self::$plugin_file ) . 'temp/' );
   }


    /**
    * For a smoother sign up process, tell WooCommerce to redirect the shopper immediately to
    * the checkout page after she clicks the "Sign up now" button
    *
    * Only enabled if multiple checkout is not enabled.
    *
    * @param string $url The cart redirect $url WooCommerce determined.
    
    */
   public static function add_to_cart_redirect( $url ) {

       // If product is of the subscription type
       if ( isset( $_REQUEST['add-to-cart'] ) && is_numeric( $_REQUEST['add-to-cart'] ) && AWC_Subscription_Products::awc_is_subscription( (int) $_REQUEST['add-to-cart'] ) ) {

           // Redirect to checkout if mixed checkout is disabled
           if ( !AWC_Settings::get_option('mixed_checkout') ) {

               $quantity   = isset( $_REQUEST['quantity'] ) ? $_REQUEST['quantity'] : 1;
               $product_id = $_REQUEST['add-to-cart'];

               $add_to_cart_notice = wc_add_to_cart_message( array( $product_id => $quantity ), true, true );

               if ( wc_has_notice( $add_to_cart_notice ) ) {
                   $notices                  = wc_get_notices();
                   $add_to_cart_notice_index = array_search( $add_to_cart_notice, $notices['success'] );

                   unset( $notices['success'][ $add_to_cart_notice_index ] );
                   wc_set_notices( $notices );
               }

               $url = wc_get_checkout_url();
           }
       }

       return $url;
   }
   
   /**
    * Called when the plugin is deactivated. Deletes the subscription product type and fires an action.
    *
    */
   public static function deactivate_woocommerce_subscriptions() {

       delete_option( AWC_Subscriptions_Admin::$option_prefix . '_is_active' );

       flush_rewrite_rules();

       do_action( 'woocommerce_subscriptions_deactivated' );
   }

   /**
    * Returns the longest possible time period
    *
    */
   public static function get_longest_period( $current_period, $new_period ) {

       if ( empty( $current_period ) || 'year' == $new_period ) {
           $longest_period = $new_period;
       } elseif ( 'month' === $new_period && in_array( $current_period, array( 'week', 'day' ) ) ) {
           $longest_period = $new_period;
       } elseif ( 'week' === $new_period && 'day' === $current_period ) {
           $longest_period = $new_period;
       } else {
           $longest_period = $current_period;
       }

       return $longest_period;
   }


   /**
    * Returns the shortest possible time period
    *
    */
   public static function get_shortest_period( $current_period, $new_period ) {

       if ( empty( $current_period ) || 'day' == $new_period ) {
           $shortest_period = $new_period;
       } elseif ( 'week' === $new_period && in_array( $current_period, array( 'month', 'year' ) ) ) {
           $shortest_period = $new_period;
       } elseif ( 'month' === $new_period && 'year' === $current_period ) {
           $shortest_period = $new_period;
       } else {
           $shortest_period = $current_period;
       }

       return $shortest_period;
   }


   
   /**
    * Takes a number and returns the number with its relevant suffix appended, eg. for 2, the function returns 2nd
    *
    
    */
   public static function append_numeral_suffix( $number ) {

       // Handle teens: if the tens digit of a number is 1, then write "th" after the number. For example: 11th, 13th, 19th, 112th, 9311th. http://en.wikipedia.org/wiki/English_numerals
       if ( strlen( $number ) > 1 && 1 == substr( $number, -2, 1 ) ) {
           // translators: placeholder is a number, this is for the teens
           $number_string = sprintf( __( '%sth', 'subscriptions-recurring-payments-for-woocommerce' ), $number );
       } else { // Append relevant suffix
           switch ( substr( $number, -1 ) ) {
               case 1:
                   // translators: placeholder is a number, numbers ending in 1
                   $number_string = sprintf( __( '%sst', 'subscriptions-recurring-payments-for-woocommerce' ), $number );
                   break;
               case 2:
                   // translators: placeholder is a number, numbers ending in 2
                   $number_string = sprintf( __( '%snd', 'subscriptions-recurring-payments-for-woocommerce' ), $number );
                   break;
               case 3:
                   // translators: placeholder is a number, numbers ending in 3
                   $number_string = sprintf( __( '%srd', 'subscriptions-recurring-payments-for-woocommerce' ), $number );
                   break;
               default:
                   // translators: placeholder is a number, numbers ending in 4-9, 0
                   $number_string = sprintf( __( '%sth', 'subscriptions-recurring-payments-for-woocommerce' ), $number );
                   break;
           }
       }

       return apply_filters( 'woocommerce_numeral_suffix', $number_string, $number );
   }
   

   /**
    * Get customer's order details via ajax.
   */
   public static function get_customer_orders() {
       check_ajax_referer( 'get-customer-orders', 'security' );

       if ( ! current_user_can( 'edit_shop_orders' ) ) {
           wp_die( -1 );
       }

       $user_id = absint( $_POST['user_id'] );

       $orders = wc_get_orders(
           array(
               'customer'       => $user_id,
               'post_type'      => 'shop_order',
               'posts_per_page' => '-1',
           )
       );

       $customer_orders = array();
       foreach ( $orders as $order ) {
           $customer_orders[ awc_get_objects_property( $order, 'id' ) ] = $order->get_order_number();
       }

       wp_send_json( $customer_orders );
   }


   /**
    * Renewals use a lot more memory on WordPress multisite (10-15mb instead of 0.1-1mb) so
    * we need to reduce the number of renewals run in each request.
    *
    */
   public static function action_scheduler_multisite_batch_size( $batch_size ) {

       if ( is_multisite() ) {
           $batch_size = 10;
       }

       return $batch_size;
   }
 
   /**
    * Checks if the WordPress site URL is the same as the URL for the site subscriptions normally
    * runs on. Useful for checking if automatic payments should be processed.
    *
    
    */
   public static function is_duplicate_site() {

       $wp_site_url_parts  = wp_parse_url( self::get_site_url_from_source( 'current_wp_site' ) );
       $awc_site_url_parts = wp_parse_url( self::get_site_url_from_source( 'subscriptions_install' ) );

       if ( ! isset( $wp_site_url_parts['path'] ) && ! isset( $awc_site_url_parts['path'] ) ) {
           $paths_match = true;
       } elseif ( isset( $wp_site_url_parts['path'] ) && isset( $awc_site_url_parts['path'] ) && $wp_site_url_parts['path'] == $awc_site_url_parts['path'] ) {
           $paths_match = true;
       } else {
           $paths_match = false;
       }

       if ( isset( $wp_site_url_parts['host'] ) && isset( $awc_site_url_parts['host'] ) && $wp_site_url_parts['host'] == $awc_site_url_parts['host'] ) {
           $hosts_match = true;
       } else {
           $hosts_match = false;
       }

       // Check the host and path, do not check the protocol/scheme to avoid issues with WP Engine and other occasions where the WP_SITEURL constant may be set, but being overridden (e.g. by FORCE_SSL_ADMIN)
       if ( $paths_match && $hosts_match ) {
           $is_duplicate = false;
       } else {
           $is_duplicate = true;
       }

       return apply_filters( 'woocommerce_subscriptions_is_duplicate_site', $is_duplicate );
   }



   /**
    * Returns WordPress/Subscriptions record of the site URL for this site
    *
    * @param string $source Takes values 'current_wp_site' or 'subscriptions_install'
    
    */
   public static function get_site_url_from_source( $source = 'current_wp_site' ) {
       // Let the default source be WP
       if ( 'subscriptions_install' === $source ) {
           $site_url = self::get_site_url();
       } elseif ( ! is_multisite() && defined( 'WP_SITEURL' ) ) {
           $site_url = WP_SITEURL;
       } else {
           $site_url = get_site_url();
       }

       return $site_url;
   }


   /**
    * Returns Subscriptions record of the site URL for this site
    *
    
    */
   public static function get_site_url( $blog_id = null, $path = '', $scheme = null ) {
       if ( empty( $blog_id ) || ! is_multisite() ) {
           $url = get_option( 'wc_subscriptions_siteurl' );
       } else {
           switch_to_blog( $blog_id );
           $url = get_option( 'wc_subscriptions_siteurl' );
           restore_current_blog();
       }

       // Remove the prefix used to prevent the site URL being updated on WP Engine
       $url = str_replace( '_[wc_subscriptions_siteurl]_', '', $url );

       $url = set_url_scheme( $url, $scheme );

       if ( ! empty( $path ) && is_string( $path ) && strpos( $path, '..' ) === false ) {
           $url .= '/' . ltrim( $path, '/' );
       }

       return apply_filters( 'wc_subscriptions_site_url', $url, $path, $scheme, $blog_id );
   }


   /**
    * Creates a URL to prevent duplicate payments from staging sites.
    *
    * The URL can not simply be the site URL, e.g. http://example.com, because WP Engine replaces all
    * instances of the site URL in the database when creating a staging site. As a result, we obfuscate
    * the URL by inserting '_[wc_subscriptions_siteurl]_' into the middle of it.
    *
    * We don't use a hash because keeping the URL in the value allows for viewing and editing the URL
    * directly in the database.
    *
    * @return string The duplicate lock URL.
    */
   public static function get_current_sites_duplicate_lock() {
       $site_url = self::get_site_url_from_source( 'current_wp_site' );
       $scheme   = parse_url( $site_url, PHP_URL_SCHEME ) . '://';
       $site_url = str_replace( $scheme, '', $site_url );

       return $scheme . substr_replace( $site_url, '_[wc_subscriptions_siteurl]_', strlen( $site_url ) / 2, 0 );
   }

   


   /**
    * Displays a notice when Subscriptions is being run on a different site, like a staging or testing site.
    *
    
    */
   public static function woocommerce_site_change_notice() {

       if ( self::is_duplicate_site() && current_user_can( 'manage_options' ) ) {

           if ( ! empty( $_REQUEST['_asubnonce'] ) && wp_verify_nonce( $_REQUEST['_asubnonce'], 'awc_duplicate_site' ) && isset( $_GET['awc_subscription_duplicate_site'] ) ) {

               if ( 'update' === $_GET['awc_subscription_duplicate_site'] ) {

                   AWC_Subscriptions::set_duplicate_site_url_lock();

               } elseif ( 'ignore' === $_GET['awc_subscription_duplicate_site'] ) {

                   update_option( 'awc_ignore_duplicate_siteurl_notice', self::get_current_sites_duplicate_lock() );

               }

               wp_safe_redirect( remove_query_arg( array( 'awc_subscription_duplicate_site', '_asubnonce' ) ) );

           } elseif ( self::get_current_sites_duplicate_lock() !== get_option( 'awc_ignore_duplicate_siteurl_notice' ) ) {
               $notice = new AWC_Admin_Notice( 'error' );
               $notice->set_simple_content(
                   sprintf(
                       // translators: 1$-2$: opening and closing <strong> tags. 3$-4$: opening and closing link tags for learn more. Leads to duplicate site article on docs. 5$-6$: Opening and closing link to production URL. 7$: Production URL .
                       esc_html__( 'It looks like this site has moved or is a duplicate site. %1$sAco Woo Subscriptions%2$s has disabled automatic payments and subscription related emails on this site to prevent duplicate payments from a staging or test environment. %1$sAco Woo Subscriptions%2$s considers %5$s%7$s%6$s to be the site\'s URL. %3$sLearn more &raquo;%4$s.', 'subscriptions-recurring-payments-for-woocommerce' ),
                       '<strong>', '</strong>',
                       '<strong>', '</strong>',
                       '<a href="' . esc_url( self::get_site_url_from_source( 'subscriptions_install' ) ) . '" target="_blank">', '</a>',
                       esc_url( self::get_site_url_from_source( 'subscriptions_install' ) )
                   )
               );
               $notice->set_actions( array(
                   array(
                       'name'  => __( 'Quit nagging me and don\'t enable automatic payments', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'url'   => wp_nonce_url( add_query_arg( 'awc_subscription_duplicate_site', 'ignore' ), 'awc_duplicate_site', '_asubnonce' ),
                       'class' => 'button button-primary',
                   ),
                   array(
                       'name'  => __( 'Enable automatic payments', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'url'   => wp_nonce_url( add_query_arg( 'awc_subscription_duplicate_site', 'update' ), 'awc_duplicate_site', '_asubnonce' ),
                       'class' => 'button',
                   ),
               ) );

               $notice->display();
           }
       }
   }


   /**
    * Checks on each admin page load if Subscriptions plugin is activated.
    *
    * Apparently the official WP API is "lame" and it's far better to use an upgrade routine fired on admin_init: http://core.trac.wordpress.org/ticket/14170
    *
    
    */
   public static function maybe_activate_woocommerce_subscriptions() {
       $is_active = get_option( AWC_Subscriptions_Admin::$option_prefix . '_is_active', false );

       if ( false == $is_active ) {

           // Add the "Subscriptions" product type
           if ( ! get_term_by( 'slug', self::$name, 'product_type' ) ) {
               wp_insert_term( self::$name, 'product_type' );
           }

           // Maybe add the "Variable Subscriptions" product type
           if ( ! get_term_by( 'slug', 'variable-subscription', 'product_type' ) ) {
               wp_insert_term( __( 'Variable Subscription', 'subscriptions-recurring-payments-for-woocommerce' ), 'product_type' );
           }



           // if this is the first time activating WooCommerce Subscription we want to enable PayPal debugging by default.
           if ( '0' == get_option( AWC_Subscriptions_Admin::$option_prefix . '_previous_version', '0' ) && false == get_option( AWC_Subscriptions_Admin::$option_prefix . '_paypal_debugging_default_set', false ) ) {
               $paypal_settings          = get_option( 'woocommerce_paypal_settings' );
               $paypal_settings['debug'] = 'yes';
               update_option( 'woocommerce_paypal_settings', $paypal_settings );
               update_option( AWC_Subscriptions_Admin::$option_prefix . '_paypal_debugging_default_set', 'true' );
           }

           update_option( AWC_Subscriptions_Admin::$option_prefix . '_is_active', true );

           set_transient( self::$activation_transient, true, 60 * 60 );

           flush_rewrite_rules();

           do_action( 'woocommerce_subscriptions_activated' );
       }

   }



   /**
    * Enqueues scripts for frontend
    *
    
    */
   public static function enqueue_frontend_scripts() {
       $dependencies = array( 'jquery' );

       if ( is_cart() || is_checkout() ) {
           wp_enqueue_script( 'awc-cart', self::$assets_url . 'js/frontend/awc-cart.js', $dependencies, AWC_Subscriptions::$version, true );
       } elseif ( is_product() ) {
           wp_enqueue_script( 'awc-single-product', self::$assets_url . 'js/frontend/single-product.js', $dependencies, AWC_Subscriptions::$version, true );
       } elseif ( awc_is_view_subscription_page() ) {
           global $wp;
           $subscription   = awc_get_subscription( $wp->query_vars['view-subscription'] );

           if ( $subscription && current_user_can( 'view_order', $subscription->get_id() ) ) {
               $dependencies[] = 'jquery-blockui';
               $script_params  = array(
                   'ajax_url'               => esc_url( WC()->ajax_url() ),
                   'subscription_id'        => $subscription->get_id(),
                   'add_payment_method_msg' => __( 'To enable automatic renewals for this subscription, you will first need to add a payment method.', 'subscriptions-recurring-payments-for-woocommerce' ) . "\n\n" . __( 'Would you like to add a payment method now?', 'subscriptions-recurring-payments-for-woocommerce' ),
                   'auto_renew_nonce'       => AWC_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) ? wp_create_nonce( "toggle-auto-renew-{$subscription->get_id()}" ) : false,
                   'add_payment_method_url' => esc_url( $subscription->get_change_payment_method_url() ),
                   'has_payment_gateway'    => $subscription->has_payment_gateway() && wc_get_payment_gateway_by_order( $subscription )->supports( 'subscriptions' ),
               );
               wp_enqueue_script( 'awc-view-subscription', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/frontend/view-subscription.js', $dependencies, AWC_Subscriptions::$version, true );
               wp_localize_script( 'awc-view-subscription', 'awcViewSubscription', apply_filters( 'woocommerce_subscriptions_frontend_view_subscription_script_parameters', $script_params ) );
           }
       }
   }

   /**
    * Enqueues stylesheet for the My Subscriptions table on the My Account page.
    *
    */
   public static function enqueue_styles( $styles ) {

       if ( is_checkout() || is_cart() ) {
           $styles['asub-checkout'] = array(
               'src'     => str_replace( array( 'http:', 'https:' ), '', esc_url( self::$assets_url . 'css/checkout.css' )),
               'deps'    => 'wc-checkout',
               'version' => WC_VERSION,
               'media'   => 'all',
           );
       } elseif ( is_account_page() ) {
           $styles['asub-view-subscription'] = array(
               'src'     => str_replace( array( 'http:', 'https:' ), '', esc_url( self::$assets_url . 'css/view-subscription.css' )),
               'deps'    => 'woocommerce-smallscreen',
               'version' => self::$version,
               'media'   => 'all',
           );
       }

       return $styles;
   }


   /**
    * Register our custom post statuses, used for order/subscription status
    */
   public static function register_post_status() {

       $subscription_statuses = awc_get_subscription_statuses();

       $registered_statuses = apply_filters( 'woocommerce_subscriptions_registered_statuses', array(
           // translators: placeholder is a post count.
           'wc-active'         => _nx_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'post status label including post count', 'subscriptions-recurring-payments-for-woocommerce' ),
           // translators: placeholder is a post count.
           'wc-switched'       => _nx_noop( 'Switched <span class="count">(%s)</span>', 'Switched <span class="count">(%s)</span>', 'post status label including post count', 'subscriptions-recurring-payments-for-woocommerce' ),
           // translators: placeholder is a post count.
           'wc-expired'        => _nx_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'post status label including post count', 'subscriptions-recurring-payments-for-woocommerce' ),
           // translators: placeholder is a post count.
           'wc-pending-cancel' => _nx_noop( 'Pending Cancellation <span class="count">(%s)</span>', 'Pending Cancellation <span class="count">(%s)</span>', 'post status label including post count', 'subscriptions-recurring-payments-for-woocommerce' ),
       ) );

       if ( is_array( $subscription_statuses ) && is_array( $registered_statuses ) ) {

           foreach ( $registered_statuses as $status => $label_count ) {

               register_post_status( $status, array(
                   'label'                     => $subscription_statuses[ $status ], // use same label/translations as awc_get_subscription_statuses()
                   'public'                    => false,
                   'exclude_from_search'       => false,
                   'show_in_admin_all_list'    => true,
                   'show_in_admin_status_list' => true,
                   'label_count'               => $label_count,
               ) );
           }
       }
   }

   /**
    * Called on plugins_loaded to load any translation files.
    *
    
    */
   public static function load_plugin_textdomain() {
       $plugin_rel_path = basename(dirname(__FILE__)) . '/languages'; /* Relative to WP_PLUGIN_DIR */
       load_plugin_textdomain('subscriptions-recurring-payments-for-woocommerce', false, $plugin_rel_path);
   }
   
   /**
    * Some hooks need to check for the version of WooCommerce, which we can only do after WooCommerce is loaded.
    *
    
    */
   public static function attach_dependant_hooks() {

       // Redirect the user immediately to the checkout page after clicking "Sign Up Now" buttons to encourage immediate checkout
       add_filter( 'woocommerce_add_to_cart_redirect', __CLASS__ . '::add_to_cart_redirect' );

       if ( self::is_woocommerce_pre( '2.6' ) ) {
           // Display Subscriptions on a User's account page
           add_action( 'woocommerce_before_my_account', __CLASS__ . '::get_my_subscriptions_template' );
       }

       // Ensure the autoloader knows which API to use.
       self::$autoloader->use_legacy_api( self::is_woocommerce_pre( '3.0' ) );
   }

   /**
    * Register data stores for WooCommerce 3.0+
    *
    
    */
   public static function add_data_stores( $data_stores ) {
       // Our custom data stores.
       $data_stores['subscription']                   = 'awc_Subscription_Data_Store_CPT';
       $data_stores['product-variable-subscription']  = 'awc_Product_Variable_Data_Store_CPT';

       // Use WC core data stores for our products.
       $data_stores['product-subscription_variation']      = 'WC_Product_Variation_Data_Store_CPT';
       $data_stores['order-item-line_item_pending_switch'] = 'WC_Order_Item_Product_Data_Store';

       return $data_stores;
   }



       /**
    * Loads the my-subscriptions.php template on the My Account page.
    *
    
    * @param int $current_page
    */
   public static function get_my_subscriptions_template( $current_page = 1 ) {

       $all_subscriptions  = awc_get_users_subscriptions();

       $current_page    = empty( $current_page ) ? 1 : absint( $current_page );
       $posts_per_page = get_option( 'posts_per_page' );

       $max_num_pages = ceil( count( $all_subscriptions ) / $posts_per_page );

       $subscriptions = array_slice( $all_subscriptions, ( $current_page - 1 ) * $posts_per_page, $posts_per_page );

       wc_get_template(
           'my-account/my-subscriptions.php',
           array(
               'subscriptions' => $subscriptions,
               'current_page'  => $current_page,
               'max_num_pages' => $max_num_pages,
               'paginate'      => true,
           ),
           '',
           plugin_dir_path( self::$plugin_file ) . 'temp/'
       );
   }

   /**
    * @access  private
    * @param   NULL
    * @return  post type
    */
   public static function awc_register_order_types(){
       wc_register_order_type(
           'shop_subscription',
           apply_filters( 'awc_woocommerce_register_post_type_subscription',
               array(
                   // register_post_type() params
                   'labels'                           => array(
                       'name'               => __( 'Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'singular_name'      => __( 'Subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'add_new'            => _x( 'Add Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'add_new_item'       => _x( 'Add New Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'edit'               => _x( 'Edit', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'edit_item'          => _x( 'Edit Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'new_item'           => _x( 'New Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'view'               => _x( 'View Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'view_item'          => _x( 'View Subscription', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'search_items'       => __( 'Search Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'not_found'          => __('Not Found', 'subscriptions-recurring-payments-for-woocommerce'),
                       'not_found_in_trash' => _x( 'No Subscriptions found in trash', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'parent'             => _x( 'Parent Subscriptions', 'custom post type setting', 'subscriptions-recurring-payments-for-woocommerce' ),
                       'menu_name'          => __( 'Aco Subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ),
                   ),
                   'description'                      => __( 'This is where subscriptions are stored.', 'subscriptions-recurring-payments-for-woocommerce' ),
                   'public'                           => false,
                   'show_ui'                          => true,
                   'capability_type'                  => 'shop_order',
                   'map_meta_cap'                     => true,
                   'publicly_queryable'               => false,
                   'exclude_from_search'              => true,
                   'show_in_menu'                     => current_user_can( 'manage_woocommerce' ) ? false : false,
                   'hierarchical'                     => false,
                   'show_in_nav_menus'                => false,
                   'rewrite'                          => false,
                   'query_var'                        => false,
                   'supports'                         => array( 'title', 'comments', 'custom-fields' ),
                   'has_archive'                      => false,

                   // wc_register_order_type() params
                   'exclude_from_orders_screen'       => true,
                   'add_order_meta_boxes'             => true,
                   'exclude_from_order_count'         => true,
                   'exclude_from_order_views'         => true,
                   'exclude_from_order_webhooks'      => true,
                   'exclude_from_order_reports'       => true,
                   'exclude_from_order_sales_reports' => true,
                   'class_name'                       => AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ? 'AWC_Subscription_Legacy' : 'AWC_Subscription',
                   'capabilities' => array(
                       'create_posts' => 'do_not_allow'
                   )
               )
           )
       );
   }





   /**
    * Loads classes that depend on WooCommerce base classes.
    *
    */
   public static function load_dependant_classes() {
       new AWC_Admin_Post_Types();
       new AWC_Admin_Meta_Boxes();
       AWC_Webhooks::init();
       new AWC_Auth();
       AWC_API::init();
       AWC_Template_Loader::init();
       new AWC_Query();
       AWC_Remove_Item::init();
       AWC_User_Change_Status_Handler::init();
       AWC_My_Account_Payment_Methods::init();
       AWC_My_Account_Auto_Renew_Toggle::init();
       

       // Early Renewal
       if ( AWC_Early_Renewal_Manager::is_early_renewal_enabled() )
           new AWC_Cart_Early_Renewal();


        if ( class_exists( 'WC_Abstract_Privacy' ) ) {
			new AWC_Privacy();
		}
   }


   /**
    * Returns the sign-up fee for a subscription, if it is a subscription.
    *
    * @param mixed $product A WC_Product object or product ID
    * @return int|string The value of the sign-up fee, or 0 if the product is not a subscription or the subscription has no sign-up fee
    
    */
   public static function get_sign_up_fee( $product ) {
       return apply_filters( 'woocommerce_subscriptions_product_sign_up_fee', AWC_Subscription_Products::awc_get_meta_data( $product, 'subscription_sign_up_fee', 0, 'use_default_value' ), AWC_Subscription_Products::awc_maybe_get_product_instance( $product ) );
   }

   


   
 
   /**
    * Check if the installed version of WooCommerce is older than a specified version.
    *
    
    */
   public static function is_woocommerce_pre( $version ) {

       if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, $version, '<' ) ) {
           $woocommerce_is_pre_version = true;
       } else {
           $woocommerce_is_pre_version = false;
       }

       return $woocommerce_is_pre_version;
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
   public static function instance($file = '')
   {
       if (is_null(self::$instance)) {
           self::$instance = new self($file);
       }
       return self::$instance;
   } 
}