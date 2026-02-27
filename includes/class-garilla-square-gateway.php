<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Garilla_Square_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'garilla_square';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = 'Garilla — Square';
        $this->method_description = 'Accept Card and Express payments via Square Web Payments SDK.';

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title', 'Credit / Debit Card (Square)' );
        $this->description  = $this->get_option( 'description', '' );
        $this->application_id = $this->get_option( 'application_id' );
        $this->access_token = $this->get_option( 'access_token' );
        $this->location_id  = $this->get_option( 'location_id' );
        $this->environment  = $this->get_option( 'environment', 'sandbox' );
        $this->enable_express = 'yes' === $this->get_option( 'enable_express', 'yes' );

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
    }

    /**
     * Output admin options and include a test connection button
     */
    public function admin_options() {
        parent::admin_options();

        // Output a test connection button under the settings form
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">Test Connection</th>
            <td class="forminp forminp-wide">
                <button type="button" id="garilla-square-test-connection" class="button">Test Square Connection</button>
                <span id="garilla-square-test-result" style="margin-left:12px; display:inline-block;"></span>
            </td>
        </tr>
        <?php
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Square payments',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'default' => 'Credit / Debit Card (Square)'
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'default' => 'Pay with Square.'
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'options' => array(
                    'sandbox' => 'Sandbox',
                    'production' => 'Production'
                ),
                'default' => 'sandbox'
            ),
            'application_id' => array(
                'title' => 'Application ID',
                'type' => 'text'
            ),
            'access_token' => array(
                'title' => 'Access Token',
                'type' => 'password',
                'desc' => 'Square access token (use sandbox token for testing). Keep this secret.'
            ),
            'location_id' => array(
                'title' => 'Location ID',
                'type' => 'text'
            ),
            'enable_express' => array(
                'title' => 'Express Payments',
                'type' => 'checkbox',
                'label' => 'Enable Apple Pay / Google Pay via Square Payment Request Button',
                'default' => 'yes'
            )
        );
    }

    /**
     * Output payment fields on checkout
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Enqueue and localize script with settings
        // Ensure the script is registered (registration happens on wp_enqueue_scripts)
        $script_data = array(
            'applicationId' => $this->application_id,
            'locationId'    => $this->location_id,
            'environment'   => $this->environment,
            'enableExpress' => $this->enable_express ? true : false,
            'gatewayId'     => $this->id,
        );
        // Localize gateway-specific settings *before* enqueueing (script must be registered already)
        wp_localize_script( 'garilla-square-payments', 'garillaSquareSettings', $script_data );
        wp_enqueue_script( 'garilla-square-payments' );
        wp_enqueue_style( 'garilla-square-style' );

        // Form HTML
        ?>
        <div id="garilla-square-payment">
            <div id="card-container"></div>
            <div id="payment-request-button"></div>
            <input type="hidden" name="square_payment_nonce" id="square_payment_nonce" />
            <p class="form-row form-row-wide"><small>We securely process payments via Square.</small></p>
        </div>
        <?php
    }

    /**
     * Process payment - called after checkout form submit
     */
    public function process_payment( $order_id ) {
        if ( empty( $_POST['square_payment_nonce'] ) ) {
            wc_add_notice( 'Payment error: payment token missing. Please try again.', 'error' );
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['square_payment_nonce'] ) );

        $order = wc_get_order( $order_id );

        // Charge via Square
        $amount = intval( round( $order->get_total() * 100 ) ); // cents
        $currency = strtoupper( get_woocommerce_currency() );

        $result = $this->charge_square( $nonce, $amount, $currency, $order );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( 'Payment error: ' . $result->get_error_message(), 'error' );
            return array(
                'result' => 'failure'
            );
        }

        // Mark order paid
        $order->payment_complete( $result );
        $order->add_order_note( 'Square payment processed. Transaction ID: ' . $result );

        // Return thank you page
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }

    /**
     * Charge via Square Payments API using cURL
     */
    private function charge_square( $nonce, $amount, $currency, $order ) {
        $access_token = $this->access_token;
        $location_id = $this->location_id;
        $env = $this->environment === 'production' ? 'production' : 'sandbox';

        if ( empty( $access_token ) || empty( $location_id ) ) {
            return new WP_Error( 'missing_config', 'Square credentials not configured.' );
        }

        $endpoint = ($env === 'production') ? 'https://connect.squareup.com/v2/payments' : 'https://connect.squareupsandbox.com/v2/payments';

        $idempotency_key = uniqid( 'garilla_' );

        $body = array(
            'source_id' => $nonce,
            'idempotency_key' => $idempotency_key,
            'amount_money' => array(
                'amount' => $amount,
                'currency' => $currency
            ),
            'location_id' => $location_id,
            'note' => 'Order ' . $order->get_order_number(),
        );

        $args = array(
            'body' => wp_json_encode( $body ),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 60
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && ! empty( $body['payment']['id'] ) ) {
            return $body['payment']['id'];
        }

        // Extract error message
        $message = 'Unknown error from Square';
        if ( ! empty( $body['errors'][0]['detail'] ) ) {
            $message = $body['errors'][0]['detail'];
        } elseif ( ! empty( $body['errors'][0]['message'] ) ) {
            $message = $body['errors'][0]['message'];
        }

        return new WP_Error( 'square_error', $message );
    }

    public function thankyou_page() {
        echo '<p>Thank you for your order. If you have any questions, contact us.</p>';
    }

}

// Implement AJAX handler to allow alternative server-side flow if needed
function garilla_square_process_payment() {
    // This is an optional endpoint; we accept POST {nonce, order_id}
    check_ajax_referer( 'garilla-square-nonce', 'security' );

    if ( empty( $_POST['nonce'] ) || empty( $_POST['order_id'] ) ) {
        wp_send_json_error( 'Missing parameters' );
    }

    $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
    $order_id = intval( $_POST['order_id'] );
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( 'Invalid order' );
    }

    // Find gateway instance
    $gateways = WC()->payment_gateways()->payment_gateways();
    if ( empty( $gateways['garilla_square'] ) ) {
        wp_send_json_error( 'Gateway not available' );
    }

    $gateway = $gateways['garilla_square'];

    $amount = intval( round( $order->get_total() * 100 ) );
    $currency = strtoupper( get_woocommerce_currency() );

    $result = $gateway->charge_square( $nonce, $amount, $currency, $order );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    // Mark order paid
    $order->payment_complete( $result );
    $order->add_order_note( 'Square payment processed via AJAX. Transaction ID: ' . $result );

    wp_send_json_success( array( 'transaction_id' => $result ) );
}

/**
 * AJAX: Test Square connection using stored gateway settings.
 * Returns JSON with success/failure and message.
 */
function garilla_square_test_connection() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    check_ajax_referer( 'garilla-square-test-nonce', 'security' );

    // Get stored gateway settings
    $opts = get_option( 'woocommerce_garilla_square_settings', array() );
    $access_token = ! empty( $opts['access_token'] ) ? $opts['access_token'] : '';
    $environment = ! empty( $opts['environment'] ) ? $opts['environment'] : 'sandbox';

    if ( empty( $access_token ) ) {
        wp_send_json_error( 'Access token not configured in gateway settings.' );
    }

    $endpoint = ( $environment === 'production' ) ? 'https://connect.squareup.com/v2/locations' : 'https://connect.squareupsandbox.com/v2/locations';

    $response = wp_remote_get( $endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json'
        ),
        'timeout' => 20
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code >= 200 && $code < 300 ) {
        wp_send_json_success( array( 'message' => 'Connected (HTTP ' . $code . ')' ) );
    }

    // Try to extract error message
    $parsed = json_decode( $body, true );
    $msg = 'HTTP ' . $code;
    if ( ! empty( $parsed['errors'][0]['detail'] ) ) {
        $msg .= ': ' . $parsed['errors'][0]['detail'];
    } elseif ( ! empty( $parsed['errors'][0]['message'] ) ) {
        $msg .= ': ' . $parsed['errors'][0]['message'];
    } else {
        $msg .= ' - ' . wp_strip_all_tags( $body );
    }

    wp_send_json_error( $msg );
}
