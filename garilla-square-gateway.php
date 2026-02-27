<?php
/**
 * Plugin Name: Garilla Square Gateway
 * Description: Square Web Payments SDK gateway for WooCommerce — supports Card and Express (Google/Apple Pay).
 * Version: 0.1.0
 * Author: The Fuel Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Only load when WooCommerce is active
add_action( 'plugins_loaded', 'garilla_square_gateway_init', 11 );
function garilla_square_gateway_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    include_once __DIR__ . '/includes/class-garilla-square-gateway.php';

    // Register the gateway
    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'Garilla_Square_Gateway';
        return $methods;
    } );

    // AJAX endpoint for processing nonce -> charge
    add_action( 'wp_ajax_garilla_square_process_payment', 'garilla_square_process_payment' );
    add_action( 'wp_ajax_nopriv_garilla_square_process_payment', 'garilla_square_process_payment' );
    add_action( 'wp_ajax_garilla_square_test_connection', 'garilla_square_test_connection' );
}

// Enqueue assets
add_action( 'wp_enqueue_scripts', 'garilla_square_enqueue_scripts' );
function garilla_square_enqueue_scripts() {
    // Register plugin assets so they can be enqueued from the payment_fields() even when checkout
    // is rendered via shortcode. We don't force enqueue here to avoid loading on every page.
    wp_register_script( 'garilla-square-payments', plugins_url( 'assets/js/garilla-square-payments.js', __FILE__ ), array( 'jquery' ), '0.1.0', true );
    wp_register_style( 'garilla-square-style', plugins_url( 'assets/css/garilla-square-style.css', __FILE__ ) );

    // Localize general AJAX helpers (specific gateway settings are localized from payment_fields())
    wp_localize_script( 'garilla-square-payments', 'garillaSquare', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'garilla-square-nonce' ),
    ) );

    // Also expose non-sensitive gateway settings to the front-end so the script can
    // initialize the Square SDK even when payment_fields() hasn't run yet (shortcode,
    // caching, or themes that inject checkout via JS).
    $opts = get_option( 'woocommerce_garilla_square_settings', array() );
    $settings = array(
        'applicationId' => ! empty( $opts['application_id'] ) ? $opts['application_id'] : '',
        'locationId'    => ! empty( $opts['location_id'] ) ? $opts['location_id'] : '',
        'environment'   => ! empty( $opts['environment'] ) ? $opts['environment'] : 'sandbox',
        'enableExpress' => ( ! empty( $opts['enable_express'] ) && $opts['enable_express'] === 'yes' ) ? true : false,
        'gatewayId'     => 'garilla_square',
    );
    wp_localize_script( 'garilla-square-payments', 'garillaSquareSettings', $settings );

    /**
     * If the checkout is rendered via the [woocommerce_checkout] shortcode the is_checkout()
     * conditional may not be true during enqueue. Detect the shortcode in the current post
     * and enqueue the registered assets so the Square UI loads on those pages.
     */
    add_action( 'wp', 'garilla_square_maybe_enqueue_for_shortcode' );
    function garilla_square_maybe_enqueue_for_shortcode() {
        if ( is_admin() ) {
            return;
        }

        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( empty( $post ) || empty( $post->post_content ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'woocommerce_checkout' ) ) {
            // Enqueue the registered script/style so the frontend initializes
            wp_enqueue_script( 'garilla-square-payments' );
            wp_enqueue_style( 'garilla-square-style' );
        }
    }

/**
 * Enqueue admin script on the gateway settings page so the Test Connection button works.
 */
add_action( 'admin_enqueue_scripts', 'garilla_square_admin_scripts' );
function garilla_square_admin_scripts( $hook ) {
    // Only load on WooCommerce settings page for this gateway
    if ( empty( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] ) {
        return;
    }

    if ( empty( $_GET['section'] ) || 'garilla_square' !== $_GET['section'] ) {
        return;
    }

    wp_enqueue_script( 'garilla-square-admin', plugins_url( 'assets/js/admin-garilla-square.js', __FILE__ ), array( 'jquery' ), '0.1.0', true );
    wp_localize_script( 'garilla-square-admin', 'garillaSquareAdmin', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'garilla-square-test-nonce' ),
    ) );
}
}

// AJAX handler implementation is in includes file (declared there)
