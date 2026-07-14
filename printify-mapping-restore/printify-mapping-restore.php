<?php
/**
 * Plugin Name: Printify Live Shipping & Mapping for Customily
 * Plugin URI:  https://github.com/TayyabaAkmal/printify-live-shipping-mapping-customily
 * Description: A full-featured WooCommerce plugin that maps Printify product metadata to products created via Customily, and calculates live shipping rates directly from the Printify Catalog API at checkout. Includes a bulk review UI, confidence-scored fuzzy matching, CSV import/export, meta management, and multi-product cart shipping logic.
 * Version:     1.1.0
 * Author:      Tayyaba Akmal
 * Author URI:  https://thegrowth360.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pmr
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PMR_VERSION', '1.1.0' );
define( 'PMR_PLUGIN_FILE', __FILE__ );
define( 'PMR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Printify WooCommerce plugin uses these meta keys on WC products/variations:
 *
 *  On the parent product (post):
 *    _printify_product_id   — Printify product ID (e.g. "5f3b1a2c3d4e5f6a7b8c9d0e")
 *    _printify_shop_id      — Printify shop ID
 *    _printify_blueprint_id — Blueprint ID from Printify catalog
 *    _printify_provider_id  — Print provider ID
 *    _printify_provider     — Print provider name (human-readable)
 *
 *  On each WC variation (post):
 *    _printify_variant_id   — Printify variant ID (integer or string)
 *    _printify_sku          — SKU as stored in Printify
 *
 * The official Printify Shipping plugin queries the cart for these keys
 * and sends them to the Printify Catalog Shipping endpoint:
 *   GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{print_provider_id}/shipping.json
 *
 * Our custom shipping method uses the same endpoint as a fallback.
 */

// ─── Autoload includes (WC-independent classes only) ─────────────────────────
require_once PMR_PLUGIN_DIR . 'includes/class-pmr-db.php';
require_once PMR_PLUGIN_DIR . 'includes/class-pmr-csv-parser.php';
require_once PMR_PLUGIN_DIR . 'includes/class-pmr-matcher.php';
require_once PMR_PLUGIN_DIR . 'includes/class-pmr-meta-writer.php';
require_once PMR_PLUGIN_DIR . 'includes/class-pmr-printify-api.php';
require_once PMR_PLUGIN_DIR . 'admin/class-pmr-admin.php';
// NOTE: class-pmr-shipping-method.php extends WC_Shipping_Method and must NOT
// be required here — WooCommerce is not yet loaded at this point (plugins load
// alphabetically, so 'printify-*' loads before 'woocommerce').
// It is loaded inside the plugins_loaded hook below.

// ─── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'PMR_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'PMR_DB', 'deactivate' ) );

// ─── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Printify Mapping Restore</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }
    // WooCommerce is confirmed loaded — safe to load our WC_Shipping_Method subclass.
    require_once PMR_PLUGIN_DIR . 'includes/class-pmr-shipping-method.php';
    PMR_Admin::init();
} );

// Register custom shipping method
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['pmr_printify_shipping'] = 'PMR_Shipping_Method';
    return $methods;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// SHIPPING METHOD → PRINTIFY ORDER SYNC
// Matches WooCommerce orders to Printify orders via the Orders API,
// then updates the shipping method on the Printify side.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Step 1: Save the selected shipping rate type when an order is placed.
 */
add_action( 'woocommerce_checkout_order_created', 'pmr_save_selected_rate_type', 10, 1 );
function pmr_save_selected_rate_type( $order ) {
    foreach ( $order->get_shipping_methods() as $shipping_method ) {
        // Use get_method_id() + get_instance_id() to build rate ID
        $method_id   = $shipping_method->get_method_id();   // pmr_printify_shipping
        $instance_id = $shipping_method->get_instance_id(); // numeric instance
        $rate_id     = $method_id . ':' . $instance_id;    // pmr_printify_shipping:1

        // Also check meta for rate_id stored by WC
        $meta_rate_id = $shipping_method->get_meta( 'rate_id' ) ?: '';

        // Match our plugin method
        if ( strpos( $method_id, 'pmr_printify_shipping' ) !== false
            || strpos( $meta_rate_id, 'pmr_printify_shipping' ) !== false ) {

            // Detect rate type from label
            $label     = strtolower( $shipping_method->get_name() );
            $rate_type = 'standard';
            if ( strpos( $label, 'economy' ) !== false )   $rate_type = 'economy';
            elseif ( strpos( $label, 'express' ) !== false )   $rate_type = 'express';
            elseif ( strpos( $label, 'overnight' ) !== false )  $rate_type = 'overnight';

            $order->update_meta_data( '_pmr_selected_rate_type', $rate_type );
            $order->save();
            break;
        }
    }
}

/**
 * Step 2: When the order moves to processing, schedule a job to find the
 * corresponding Printify order and update its shipping method.
 * Customily submits orders to Printify asynchronously (typically 20-40 min delay),
 * so we schedule multiple retry attempts to catch the order once it appears.
 */
add_action( 'woocommerce_order_status_processing', 'pmr_match_and_update_printify_shipping', 20, 1 );
add_action( 'woocommerce_order_status_on-hold',    'pmr_match_and_update_printify_shipping', 20, 1 );

function pmr_match_and_update_printify_shipping( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Only run when our plugin's shipping method was used
    $rate_type = $order->get_meta( '_pmr_selected_rate_type' );
    if ( ! $rate_type ) return;

    // Standard is Printify's default — no update needed
    if ( $rate_type === 'standard' ) {
        $order->add_order_note( 'ℹ️ PMR: Standard shipping selected — no Printify update needed.' );
        return;
    }

    // Already updated — skip all further retries
    if ( $order->get_meta( '_pmr_printify_shipping_updated' ) ) {
        return;
    }

    // Schedule retries at 2, 5, 30, 40, and 50 minutes to account for Printify's processing delay.
    $pmr_retry_times = array( 2, 5, 30, 40, 50 );
    foreach ( $pmr_retry_times as $mins ) {
        wp_schedule_single_event(
            time() + ( $mins * MINUTE_IN_SECONDS ),
            'pmr_find_and_update_printify_order',
            array( $order_id )
        );
    }
    $order->add_order_note( 'ℹ️ PMR: Printify shipping sync scheduled. Retries at: 2, 5, 30, 40, and 50 minutes after order placement.' );
    $order->save();
}

/**
 * Step 3: Fetch recent Printify orders and match against the WooCommerce order
 * using customer name, email, and order timestamp.
 */
add_action( 'pmr_find_and_update_printify_order', 'pmr_find_and_update_handler', 10, 1 );
function pmr_find_and_update_handler( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $rate_type = $order->get_meta( '_pmr_selected_rate_type' );
    if ( ! $rate_type || $rate_type === 'standard' ) return;

    $api_key = get_option( 'pmr_printify_api_key', '' );
    $shop_id = get_option( 'pmr_printify_shop_id', '' );

    if ( ! $api_key || ! $shop_id ) {
        $order->add_order_note( '❌ PMR: Printify API key or Shop ID not configured in plugin settings.' );
        return;
    }

    // Extract WooCommerce order details for matching against Printify
    $wc_email     = $order->get_billing_email();
    $wc_firstname = strtolower( trim( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) );
    $wc_lastname  = strtolower( trim( $order->get_shipping_last_name()  ?: $order->get_billing_last_name() ) );
    $wc_date      = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

    // Fetch the most recent 10 Printify orders to find a match
    $response = wp_remote_get(
        "https://api.printify.com/v1/shops/{$shop_id}/orders.json?limit=10&page=1",
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( '❌ PMR: Printify API error: ' . $response->get_error_message() . ' — Retrying in 10 minutes.' );
        wp_schedule_single_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'pmr_find_and_update_printify_order', array( $order_id ) );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['data'] ) ) {
        $order->add_order_note( '❌ PMR: Printify orders fetch failed (HTTP ' . $code . ').' );
        return;
    }

    // Attempt to match Printify order to the WooCommerce order
    $matched_printify_id = null;

    foreach ( $body['data'] as $p_order ) {
        $p_address   = $p_order['address_to'] ?? array();
        $p_firstname = strtolower( trim( $p_address['first_name'] ?? '' ) );
        $p_lastname  = strtolower( trim( $p_address['last_name']  ?? '' ) );
        $p_email     = strtolower( trim( $p_address['email']      ?? '' ) );
        $p_created   = strtotime( $p_order['created_at'] ?? '' );

        // Match criteria: same name + email, OR same name + order within 10 minutes
        $name_match  = ( $p_firstname === $wc_firstname && $p_lastname === $wc_lastname );
        $email_match = ( ! empty( $p_email ) && strtolower( $wc_email ) === $p_email );
        $time_match  = ( abs( $p_created - $wc_date ) < 600 ); // within 10 minutes

        if ( $name_match && ( $email_match || $time_match ) ) {
            $matched_printify_id = $p_order['id'];
            break;
        }
    }

    if ( ! $matched_printify_id ) {
        $order->add_order_note( '⚠️ PMR: Could not match Printify order. Will retry in 5 minutes.' );
        $order->save();
        // Retry API errors after 10 minutes
        $retry_count = (int) $order->get_meta( '_pmr_retry_count' );
        $order->add_order_note( '⚠️ PMR: Printify order not found yet — will retry on next scheduled run.' );
        $order->save();
        return;
    }

    // Store the matched Printify order ID on the WooCommerce order
    $order->update_meta_data( '_printify_order_id', $matched_printify_id );
    $order->save();

    // Proceed to update the shipping method on Printify
    pmr_update_printify_shipping( $matched_printify_id, $rate_type, $order, $api_key, $shop_id );
}

/**
 * Step 4: Update the Printify order's shipping method via the Printify API.
 */
function pmr_update_printify_shipping( $printify_order_id, $rate_type, $order, $api_key, $shop_id ) {

    // Printify shipping method numeric IDs:
    // 1 = Standard, 2 = Economy, 3 = Express, 4 = Overnight
    $shipping_id_map = array(
        'economy'   => 2,
        'standard'  => 1,
        'express'   => 3,
        'overnight' => 4,
    );

    $shipping_id = $shipping_id_map[ $rate_type ] ?? 1;

    // Attempt multiple API endpoints since Printify's API varies by account type.
    $endpoints = array(
        // Option 1: PATCH on order
        array(
            'url'    => "https://api.printify.com/v1/shops/{$shop_id}/orders/{$printify_order_id}.json",
            'method' => 'PATCH',
            'body'   => wp_json_encode( array( 'shipping_method' => $shipping_id ) ),
        ),
        // Option 2: POST to shipping sub-resource
        array(
            'url'    => "https://api.printify.com/v1/shops/{$shop_id}/orders/{$printify_order_id}/shipping.json",
            'method' => 'POST',
            'body'   => wp_json_encode( array( 'shipping_method' => $shipping_id ) ),
        ),
        // Option 3: POST to set_shipping
        array(
            'url'    => "https://api.printify.com/v1/shops/{$shop_id}/orders/{$printify_order_id}/set_shipping.json",
            'method' => 'POST',
            'body'   => wp_json_encode( array( 'shipping_method' => $shipping_id ) ),
        ),
    );

    $response = null;
    $used_url  = '';
    foreach ( $endpoints as $ep ) {
        $resp = wp_remote_request( $ep['url'], array(
            'method'  => $ep['method'],
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $ep['body'],
            'timeout' => 30,
        ) );
        $resp_code = wp_remote_retrieve_response_code( $resp );
        // 405 = wrong method/endpoint, try next
        if ( ! is_wp_error( $resp ) && $resp_code !== 405 && $resp_code !== 404 ) {
            $response = $resp;
            $used_url = $ep['method'] . ' ' . $ep['url'];
            break;
        }
        $order->add_order_note( 'ℹ️ PMR: Tried ' . $ep['method'] . ' ' . $ep['url'] . ' → HTTP ' . $resp_code . '. Trying next…' );
    }

    if ( ! $response ) {
        $order->add_order_note( '❌ PMR: All API endpoints failed. Printify may not support shipping update via API for this account.' );
        $order->save();
        return;
    }

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( '❌ PMR: Shipping update failed: ' . $response->get_error_message() );
        $order->save();
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 200 && $code < 300 ) {
        $order->update_meta_data( '_pmr_printify_shipping_updated', $rate_type );
        $order->update_meta_data( '_printify_order_id', $printify_order_id );
        $order->update_meta_data( '_pmr_retry_count', 99 ); // stop further retries
        $order->add_order_note(
            '✅ PMR: Printify order #' . $printify_order_id .
            ' shipping updated to "' . strtoupper( $rate_type ) . '" (method ID: ' . $shipping_id . ').'
        );
        $order->save();
    } else {
        $msg = $body['message'] ?? ( $body['error'] ?? 'Unknown error' );
        $order->add_order_note(
            '❌ PMR: Printify shipping update failed for order #' . $printify_order_id .
            ' (HTTP ' . $code . '): ' . $msg
        );
        $order->save();
        error_log( 'PMR Printify shipping update failed: ' . print_r( $body, true ) );
    }
}

/**
 * Display a PMR shipping sync status box on the WooCommerce admin order page.
 */
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'pmr_admin_shipping_status_box', 10, 1 );
function pmr_admin_shipping_status_box( $order ) {
    $selected   = $order->get_meta( '_pmr_selected_rate_type' );
    $updated    = $order->get_meta( '_pmr_printify_shipping_updated' );
    $p_order_id = $order->get_meta( '_printify_order_id' );

    if ( ! $selected ) return;

    $color  = $updated ? '#1e7e34' : '#d57a00';
    $status = $updated
        ? '✅ Updated to: <strong>' . strtoupper( $updated ) . '</strong>'
        : '⏳ Pending Printify update';

    echo '<div style="margin-top:12px; padding:10px 14px; background:#f8f9fa; border-left:3px solid #7952b3; border-radius:4px; font-size:12px; line-height:1.8;">';
    echo '<strong style="color:#7952b3;">PMR Shipping Sync</strong><br>';
    echo 'Customer selected: <strong>' . esc_html( strtoupper( $selected ) ) . '</strong><br>';
    echo '<span style="color:' . $color . ';">' . $status . '</span>';
    if ( $p_order_id ) {
        echo '<br>Printify Order: <code>' . esc_html( $p_order_id ) . '</code>';
    }
    echo '</div>';
}

/**
 * Register a manual retry action on the WooCommerce admin order page.
 * Use this if the automatic sync failed to match the Printify order.
 */
add_action( 'woocommerce_order_actions', 'pmr_add_retry_action' );
function pmr_add_retry_action( $actions ) {
    $actions['pmr_retry_shipping_sync'] = 'PMR: Retry Printify Shipping Sync';
    return $actions;
}

add_action( 'woocommerce_order_action_pmr_retry_shipping_sync', 'pmr_manual_retry_shipping_sync' );
function pmr_manual_retry_shipping_sync( $order ) {
    // Reset retry state so the handler can run again
    $order->delete_meta_data( '_pmr_printify_shipping_updated' );
    $order->delete_meta_data( '_pmr_retry_count' );

    // If no rate type was saved at checkout, detect it from the shipping method label
    $rate_type = $order->get_meta( '_pmr_selected_rate_type' );
    if ( ! $rate_type ) {
        // Detect rate type from the shipping method label name
        foreach ( $order->get_shipping_methods() as $method ) {
            $label = strtolower( $method->get_name() );
            if ( strpos( $label, 'economy' ) !== false ) {
                $rate_type = 'economy';
            } elseif ( strpos( $label, 'express' ) !== false ) {
                $rate_type = 'express';
            } elseif ( strpos( $label, 'overnight' ) !== false ) {
                $rate_type = 'overnight';
            } else {
                $rate_type = 'standard';
            }
        }
        $order->update_meta_data( '_pmr_selected_rate_type', $rate_type ?: 'economy' );
    }

    $order->add_order_note( '🔄 PMR: Manual retry triggered. Rate type: ' . strtoupper( $rate_type ?: 'economy' ) );
    $order->save();
    pmr_find_and_update_handler( $order->get_id() );
}
