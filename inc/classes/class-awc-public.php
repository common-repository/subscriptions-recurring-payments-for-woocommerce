<?php

if (!defined('ABSPATH')) {
    exit;
}


if(!class_exists('AWC_Public')){
class AWC_Public
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
         * The main plugin file.
         *
         * @var     string
         * @access  public
         
         */
        public $file;

        /**
         * The token.
         *
         * @var     string
         * @access  public
         
         */
        public $token;


        /**
         * Constructor function.
         *
         * @access  public
         * @param string $file Plugin root file path.
         
         */
        public function __construct($file = '')
        {
            $this->version = AWC_VERSION;
            $this->token = AWC_TOKEN;
            $this->file = $file;

            // Load dependent files
            add_action( 'plugins_loaded', __CLASS__ . '::awc_load_dependant_classes' );
        }



        /**
         * @access  public
         * load depended classes for frontend
         */
        public static function awc_load_dependant_classes(){
            AWC_Subscription_Cart::init();
            AWC_Subscription_Cart_Validator::init();
            new AWC_Cart_Renewal();
            new AWC_Cart_Resubscribe();
            new AWC_Cart_Initial_Payment();
            new AWC_Cart_Switch();
            AWC_Subscriptions_Checkout::init();
            AWC_Subscription_Switchers::init();
            AWC_Download_Handler::init();
        }

        public function acotrs_woocommerce_after_shipping_rate($method, $index){
            $meta = $method->get_meta_data();
            if(isset($meta['description'])){
                echo sprintf('<small style="display:block;" class="acotrs-description">%s</small>', $meta['description']->scalar);
            }
        }

        /**
         * Add Handling Fee on Shipping
         * @param NULL
         */
        public function acotrs_add_shipping_handlingfee(){
            global $woocommerce;
            $chosen_shippings = WC()->session->get( 'chosen_shipping_methods' )[0]; // The chosen shipping methods
            $shipping_methods = WC()->session->get('shipping_for_package_0')['rates'];

            $handlingfee = '';
                if(is_array($shipping_methods) && count($shipping_methods) > 0){
                    foreach ( $shipping_methods as $method_id => $shipping_rate ){
                        // Get the meta data in an unprotected array
                        $meta_data = $shipping_rate->get_meta_data();
                        if(isset($meta_data['handling_fee']) && $method_id == $chosen_shippings && !empty($meta_data['handling_fee'])){
                            $handlingfee = $meta_data['handling_fee'];
                        }
                    }
                }

                if(!empty($handlingfee)) 
                    $woocommerce->cart->add_fee( __('Handling Fee', 'advanced-table-rate-shipping-for-woocommerce'), (int)$handlingfee, true, '' );
        }


        /**
         * Apply Delivery Date on Cart page
         * @param NULL
         */
        public function acotrs_shipping_delivery_date_display(){
            $chosen_shippings = WC()->session->get( 'chosen_shipping_methods' )[0]; // The chosen shipping methods
            $shipping_methods = WC()->session->get('shipping_for_package_0')['rates'];
        
                // Loop through the array
                $delivery_date = '';
                if(is_array($shipping_methods) && count($shipping_methods) > 0){
                    foreach ( $shipping_methods as $method_id => $shipping_rate ){
                        // Get the meta data in an unprotected array
                        $meta_data = $shipping_rate->get_meta_data();
                        if(isset($meta_data['delivery_day']) && $method_id == $chosen_shippings && !empty($meta_data['delivery_day']) ){   
                                $delivery_date  = $meta_data['delivery_day'];
                        }
                    }
                }

                
                if(!empty($delivery_date)):
                ob_start();
                    ?>
                    <tr class="shipping">
                            <th><?php esc_html_e( 'Delivery on', 'advanced-table-rate-shipping-for-woocommerce' ); ?></th>
                            <td data-title="<?php esc_attr_e( 'Delivery Date', 'advanced-table-rate-shipping-for-woocommerce' ); ?>"><?php echo $delivery_date; ?></td>
                    </tr>
                    <?php 
                $output = ob_get_clean();
                echo $output;
                endif;
        }
        

        /**
         * Ensures only one instance of APIFW_Front_End is loaded or can be loaded.
         *
         * @param string $file Plugin root file path.
         * @return Main APIFW_Front_End instance
         
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
}