<?php
/**
 * Plugin Name: WooCommerce Lightning Gateway (Fedi/LNURL)
 * Plugin URI: https://github.com/dianamoltbot-dev/woo-lightning-fedi
 * Description: Accept Bitcoin Lightning payments via LNURL-pay or Lightning Address. Built for Fedi federations.
 * Version: 1.0.0
 * Author: Diana × Spark101 Tech
 * Author URI: https://www.spark101.tech
 * License: MIT
 * Requires PHP: 7.4
 * Requires at least: 5.0
 * WC requires at least: 5.0
 */

defined('ABSPATH') || exit;

define('WLF_VERSION', '1.0.0');
define('WLF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WLF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check WooCommerce is active
add_action('plugins_loaded', 'wlf_init_gateway');

function wlf_init_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>WooCommerce Lightning Gateway</strong> requiere WooCommerce activo.</p></div>';
        });
        return;
    }

    require_once WLF_PLUGIN_DIR . 'includes/class-wlf-gateway.php';
    require_once WLF_PLUGIN_DIR . 'includes/class-wlf-lnurl.php';
    require_once WLF_PLUGIN_DIR . 'includes/class-wlf-exchange.php';

    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'WLF_Gateway';
        return $gateways;
    });
}

// Register REST API endpoints for payment verification
add_action('rest_api_init', function() {
    register_rest_route('wlf/v1', '/check-payment/(?P<order_id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'wlf_check_payment_rest',
        'permission_callback' => '__return_true',
    ]);
});

function wlf_check_payment_rest($request) {
    $order_id = $request->get_param('order_id');
    $order = wc_get_order($order_id);

    if (!$order) {
        return new WP_REST_Response(['paid' => false, 'error' => 'Order not found'], 404);
    }

    $paid = $order->get_status() === 'processing' || $order->get_status() === 'completed';
    return new WP_REST_Response(['paid' => $paid, 'status' => $order->get_status()]);
}

// Enqueue frontend assets
add_action('wp_enqueue_scripts', function() {
    if (is_checkout() || is_wc_endpoint_url('order-pay')) {
        wp_enqueue_style('wlf-checkout', WLF_PLUGIN_URL . 'assets/css/checkout.css', [], WLF_VERSION);
        wp_enqueue_script('wlf-checkout', WLF_PLUGIN_URL . 'assets/js/checkout.js', ['jquery'], WLF_VERSION, true);
        wp_localize_script('wlf-checkout', 'wlfData', [
            'restUrl'       => rest_url('wlf/v1/'),
            'nonce'         => wp_create_nonce('wp_rest'),
            'checkInterval' => (int) get_option('wlf_check_interval', 3) * 1000,
        ]);
    }
});
