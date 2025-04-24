<?php

/*
Plugin Name: WooCommerce Payment Gateway - OrdinalsBot
Plugin URI: https://ordinalsbot.com
Description: Accept Bitcoin Instantly via OrdinalsBot
Version: 0.0.4
Author: OrdinalsBot
Author URI: https://ordinalsbot.com
*/

add_action('plugins_loaded', 'ordinalsbot_init');

define('ORDINALSBOT_WOOCOMMERCE_VERSION', '0.0.4');
define('ORDINALSBOT_CHECKOUT_PATH', 'https://ordinalsbot.com/tokenpay/checkout/');

function ordinalsbot_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    require_once(__DIR__ . '/lib/ordinalsbot/init.php');

    class WC_Gateway_OrdinalsBot extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'ordinalsbot';
            $this->has_fields = false;
            $this->method_title = 'OrdinalsBot';
            $this->icon = PLUGIN_DIR . 'assets/tokenpay.svg';

            $this->init_form_fields();
            $this->init_settings();

            // Use uploaded icon if set, else fall back to default
            $uploaded = $this->get_option( 'icon_upload' );
            if ( ! empty( $uploaded ) ) {
                $this->icon = esc_url( $uploaded );
            } else {
                $this->icon = PLUGIN_DIR . 'assets/tokenpay.svg';
            }
            
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_secret = $this->get_option('api_secret');
            $this->api_auth_token = (empty($this->get_option('api_auth_token')) ? $this->get_option('api_secret') : $this->get_option('api_auth_token'));
            $this->checkout_url = $this->get_option('checkout_url');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_ordinalsbot', array($this, 'thankyou'));
            add_action('woocommerce_api_wc_gateway_ordinalsbot', array($this, 'payment_callback'));
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('OrdinalsBot', 'woothemes'); ?></h3>
            <p><?php _e('Accept Bitcoin instantly through OrdinalsBot.com.', 'woothemes'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable OrdinalsBot', 'woocommerce'),
                    'label' => __('Enable Bitcoin payments via OrdinalsBot', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('The payment method title which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Tokenpay: on-chain bitcoin and runes', 'woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('The payment method description which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Powered by OrdinalsBot'),
                ),
                'icon_upload' => array(
                    'title'       => __( 'Icon Upload', 'woocommerce' ),
                    'type'        => 'file',
                    'description' => __( 'Upload a custom icon (SVG/PNG). Leave blank to use the default.', 'woocommerce' ),
                    'default'     => '',
                ),
                'api_auth_token' => array(
                    'title' => __('API Auth Token', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your personal API Key. Generate one <a href="https://docs.ordinalsbot.com" target="_blank">here</a>.  ', 'woocommerce'),
                    'default' => (empty($this->get_option('api_secret')) ? '' : $this->get_option('api_secret')),
                ),
                'checkout_url' => array(
                  'title' => __('Checkout URL', 'woocommerce'),
                  'description' => __('URL for the checkout', 'woocommerce'),
                  'type' => 'text',
                  'default' => ORDINALSBOT_CHECKOUT_PATH,
              ),
            );
        }

        public function process_admin_options() {
            // Save the other settings first
            parent::process_admin_options();
    
            // Handle our file upload field
            if ( isset( $_FILES['woocommerce_ordinalsbot_icon_upload'] ) && ! empty( $_FILES['woocommerce_ordinalsbot_icon_upload']['name'] ) ) {
                // Allow SVG, PNG, JPG
                $overrides = array( 'test_form' => false );
                $upload    = wp_handle_upload( $_FILES['woocommerce_ordinalsbot_icon_upload'], $overrides );
    
                if ( empty( $upload['error'] ) && ! empty( $upload['url'] ) ) {
                    // Store the URL back into our settings
                    $this->update_option( 'icon_upload', esc_url_raw( $upload['url'] ) );
                }
            }
        }

        public function thankyou()
        {
            if ($description = $this->get_description()) {
                echo wpautop(wptexturize($description));
            }
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $this->init_ordinalsbot();

            $ordinalsbot_order_id = get_post_meta($order->get_id(), 'ordinalsbot_order_id', true);

            if (empty($ordinalsbot_order_id)) {               
                // send a request to https://api.ordinalsbot.com/fxrate to get dogusd fx rate=
                $fxrate_response = wp_remote_get('https://api.ordinalsbot.com/fxrate');
                if (is_wp_error($fxrate_response)) {
                    error_log('Failed to get fx rate: ' . $fxrate_response->get_error_message());
                    return;
                }
            
                $fxrate_body = wp_remote_retrieve_body($fxrate_response);
                $fxrate_data = json_decode($fxrate_body, true);
            
                if (!isset($fxrate_data['dog']['usd']) || empty($fxrate_data['dog']['usd'])) {
                    error_log('Invalid fx rate response: missing DOG to USD rate');
                    return;
                }
            
                // Calculate price in DOG using the retrieved fx rate
                $dogusd_rate = $fxrate_data['dog']['usd'];
                $usd_price = $order->get_total();
                $dog_price = $usd_price / $dogusd_rate;
                $dog_price = ceil($dog_price * 1.1);
                error_log('total price in $DOG ' . $dog_price);               
            
                // Prepare tokenpayParams
                $tokenpayParams = array(
                    'amount'          => $dog_price,
                    'token'           => 'DOG•GO•TO•THE•MOON',
                    // 'additionalFee'   => 100, // this is optional and can be added later
                    'description'     => 'WooCommerce - #' . $order->get_id(),
                    'order_id'        => $order->get_id(),
                    'name'            => $order->get_formatted_billing_full_name(),
                    'email'           => $order->get_billing_email(),
                    'webhookUrl'    => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_ordinalsbot&order_id=' . $order->get_id(),
                    'successUrl'     => $order->get_checkout_order_received_url(),
                );
                $ordinalsbot_order = \OrdinalsBot\Merchant\Order::create($tokenpayParams);
                $ordinalsbot_order_id = $ordinalsbot_order->id;
                error_log('OrdinalsBot Order ID: ' . $ordinalsbot_order_id);
                error_log('local order ID: ' . $order->get_id());
                update_post_meta($order_id, 'ordinalsbot_order_id', $ordinalsbot_order_id);

                return array(
                    'result' => 'success',
                    'redirect' => $this->checkout_url . $ordinalsbot_order_id,
                );
            }
            else {
                return array(
                    'result' => 'success',
                    'redirect' => $this->checkout_url . $ordinalsbot_order_id,
                );
            }
        }

        public function payment_callback()
        {
            $request = $_REQUEST;
            $order = wc_get_order($request['order_id']);
            // error_log('Order: ' . print_r($order, true));

            try {
                if (!$order || !$order->get_id()) {
                    throw new Exception('Order #' . $request['order_id'] . ' does not exists');
                }

                $token = get_post_meta($order->get_id(), 'ordinalsbot_order_id', true);
                error_log('OrdinalsBot ID: ' . $token);

                if (empty($token) ) {
                    throw new Exception('Order has no OrdinalsBot ID associated');
                }

                $request2 = json_decode(file_get_contents('php://input'), true);
                error_log('OrdinalsBot Callback request2: ' . print_r($request2, true));
                $webhookSecretToken = wc_get_order($request2['webhookSecretToken']);
                error_log('webhookSecretToken: ' . $webhookSecretToken);

                $this->init_ordinalsbot();
                $cgOrder = \OrdinalsBot\Merchant\Order::find($token);

                if (!$cgOrder) {
                    throw new Exception('OrdinalsBot Order #' . $order->get_id() . ' does not exists');
                }
                error_log('OrdinalsBot Order ->state: ' . print_r($cgOrder->state, true));
                switch ($cgOrder->state) {
                    case 'completed':
                        error_log('Payment completed');
                        $statusWas = "wc-" . $order->get_status();
                        $order->add_order_note(__('Payment is settled and has been credited to your OrdinalsBot account. Purchased goods/services can be securely delivered to the customer.', 'ordinalsbot'));
                        $order->payment_complete();

                        // error_log('Order $statusWas: ' . $statusWas);
                        error_log('Order status: ' . $order->get_status());
                        if ($order->get_status() === 'processing' && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                        }
                        if (($order->get_status() === 'processing' || $order->get_status() == 'completed') && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                        }
                        break;
                    case 'error':
                        error_log('Payment failed');
                        $order->add_order_note(__('Payment failed', 'ordinalsbot'));
                        $order->update_status('cancelled');
                        break;
                }
            } catch (Exception $e) {
                die(get_class($e) . ': ' . $e->getMessage());
            }
        }

        private function init_ordinalsbot()
        {
            \OrdinalsBot\OrdinalsBot::config(
                array(
                    'auth_token'    => (empty($this->api_auth_token) ? $this->api_secret : $this->api_auth_token),
                    'environment'   => 'live',
                    'user_agent'    => ('OrdinalsBot - WooCommerce v' . WOOCOMMERCE_VERSION . ' Plugin v' . ORDINALSBOT_WOOCOMMERCE_VERSION)
                )
            );
        }
    }

    function add_ordinalsbot_gateway($methods)
    {
        $methods[] = 'WC_Gateway_OrdinalsBot';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_ordinalsbot_gateway');
}
