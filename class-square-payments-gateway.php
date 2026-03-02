<?php

class Square_Payments_Gateway extends WC_Payment_Gateway {

    public function __construct() {

        $this->id = 'square_web_payments';
        $this->method_title = 'Square Web Payments';
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled        = $this->get_option('enabled');
        $this->sandbox        = $this->get_option('sandbox') === 'yes';
        $this->app_id         = $this->get_option('application_id');
        $this->location_id    = $this->get_option('location_id');
        $this->access_token   = $this->get_option('access_token');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_review_order_before_payment', [$this, 'render_express_container']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('wp_ajax_square_process_payment', [$this, 'handle_payment']);
        add_action('wp_ajax_nopriv_square_process_payment', [$this, 'handle_payment']);
    }

    public function init_form_fields() {

        $this->form_fields = [

            'enabled' => [
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'default' => 'yes'
            ],

            'sandbox' => [
                'title'   => 'Sandbox Mode',
                'label'   => 'Enable Sandbox',
                'type'    => 'checkbox',
                'default' => 'yes',
                'description' => 'Use Square sandbox environment'
            ],

            'application_id' => [
                'title' => 'Application ID',
                'type'  => 'text'
            ],

            'location_id' => [
                'title' => 'Location ID',
                'type'  => 'text'
            ],

            'access_token' => [
                'title' => 'Access Token',
                'type'  => 'password'
            ],
        ];
    }

    public function render_express_container() {
        if (!$this->is_available()) return;
        echo '<div id="square-express"></div>';
    }

    public function enqueue_scripts() {

        if (!is_checkout() || $this->enabled !== 'yes') return;

        $sdk_url = $this->sandbox
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';

        wp_enqueue_script('square-sdk', $sdk_url, [], null, true);

        wp_enqueue_script(
            'square-checkout',
            plugin_dir_url(__FILE__) . 'assets/js/checkout.js',
            ['square-sdk', 'jquery'],
            null,
            true
        );

        wp_localize_script('square-checkout', 'SquareParams', [
            'appId'      => $this->app_id,
            'locationId' => $this->location_id,
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('square_payment')
        ]);
    }

    public function handle_payment() {

        check_ajax_referer('square_payment', 'nonce');

        $payment_token = sanitize_text_field($_POST['token']);
        $order_id      = intval($_POST['order_id']);

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Invalid order');
        }

        $endpoint = $this->sandbox
            ? 'https://connect.squareupsandbox.com/v2/payments'
            : 'https://connect.squareup.com/v2/payments';

        $body = [
            "idempotency_key" => uniqid(),
            "source_id"       => $payment_token,
            "amount_money"    => [
                "amount"   => intval($order->get_total() * 100),
                "currency" => get_woocommerce_currency()
            ],
            "autocomplete" => true
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ],
            'body' => wp_json_encode($body)
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['payment']['status']) && $data['payment']['status'] === 'COMPLETED') {
            $order->payment_complete($data['payment']['id']);
            wp_send_json_success();
        }

        wp_send_json_error($data);
    }

    public function payment_fields() {
        echo '<div id="square-card-container"></div>';
        echo '<div id="square-express"></div>';
        echo '<input type="hidden" id="square-token" name="square_token" />';
    }
}