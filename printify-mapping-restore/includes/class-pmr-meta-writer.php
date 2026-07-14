<?php
defined( 'ABSPATH' ) || exit;

/**
 * Writes approved Printify metadata to existing WooCommerce products/variations.
 *
 * Meta keys written (matching what the official Printify WooCommerce plugin sets):
 *
 *   On parent product:
 *     _printify_product_id
 *     _printify_blueprint_id
 *     _printify_provider_id
 *     _printify_provider          (human-readable name)
 *
 *   On variation (or simple product):
 *     _printify_variant_id
 *     _printify_sku               (Printify's own SKU)
 *     _printify_blueprint_id      (duplicated for easy lookup)
 *     _printify_provider_id       (duplicated for easy lookup)
 */
class PMR_Meta_Writer {

    /**
     * Apply a single approved proposal row to WooCommerce.
     *
     * @param array $proposal Row from pmr_mapping_proposals.
     * @return bool True on success.
     */
    public static function apply( array $proposal ): bool {
        $wc_product_id   = (int) $proposal['wc_product_id'];
        $wc_variation_id = (int) $proposal['wc_variation_id'];

        if ( ! $wc_product_id ) return false;

        // ── Parent product meta ───────────────────────────────────────────────
        $parent = wc_get_product( $wc_product_id );
        if ( ! $parent ) return false;

        if ( ! empty( $proposal['printify_product_id'] ) ) {
            update_post_meta( $wc_product_id, '_printify_product_id',   $proposal['printify_product_id'] );
        }
        if ( ! empty( $proposal['printify_blueprint_id'] ) ) {
            update_post_meta( $wc_product_id, '_printify_blueprint_id', $proposal['printify_blueprint_id'] );
        }
        if ( ! empty( $proposal['printify_provider_id'] ) ) {
            update_post_meta( $wc_product_id, '_printify_provider_id',  $proposal['printify_provider_id'] );
        }
        if ( ! empty( $proposal['printify_provider'] ) ) {
            update_post_meta( $wc_product_id, '_printify_provider',     $proposal['printify_provider'] );
        }

        // ── Variation / simple product meta ───────────────────────────────────
        $target_id = $wc_variation_id > 0 ? $wc_variation_id : $wc_product_id;

        if ( ! empty( $proposal['printify_variant_id'] ) ) {
            update_post_meta( $target_id, '_printify_variant_id',   $proposal['printify_variant_id'] );
        }
        if ( ! empty( $proposal['printify_sku'] ) ) {
            update_post_meta( $target_id, '_printify_sku',          $proposal['printify_sku'] );
        }
        // Duplicate these on variation for easy retrieval during shipping
        if ( ! empty( $proposal['printify_blueprint_id'] ) ) {
            update_post_meta( $target_id, '_printify_blueprint_id', $proposal['printify_blueprint_id'] );
        }
        if ( ! empty( $proposal['printify_provider_id'] ) ) {
            update_post_meta( $target_id, '_printify_provider_id',  $proposal['printify_provider_id'] );
        }

        // Mark the product as being connected to Printify (used by official shipping plugin)
        update_post_meta( $wc_product_id, '_printify_connect', '1' );

        // Clear WC caches
        wc_delete_product_transients( $wc_product_id );

        return true;
    }

    /**
     * Apply multiple approved proposals from a session.
     *
     * @param string $session_id
     * @return array{applied: int, failed: int}
     */
    public static function apply_session( string $session_id, int $offset = 0, int $batch = 50 ): array {
        global $wpdb;
        $table = PMR_DB::table_name();

        // Total approved count
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND status = 'approved'",
            $session_id
        ) );

        // Get batch
        $proposals = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s AND status = 'approved' LIMIT %d OFFSET %d",
            $session_id, $batch, $offset
        ), ARRAY_A );

        $applied  = 0;
        $failed   = 0;
        $errors   = array();
        $dupes    = array();

        foreach ( $proposals as $p ) {
            $wc_id  = (int) $p['wc_product_id'];
            $var_id = (int) $p['wc_variation_id'];

            // Duplicate detection — check if another session already applied meta
            $existing_blueprint = get_post_meta(
                $var_id > 0 ? $var_id : $wc_id,
                '_printify_blueprint_id', true
            );
            if ( $existing_blueprint && $existing_blueprint !== $p['printify_blueprint_id'] ) {
                $dupes[] = array(
                    'product_id'    => $wc_id,
                    'product_title' => $p['wc_title'] ?? '',
                    'existing'      => $existing_blueprint,
                    'new'           => $p['printify_blueprint_id'],
                );
            }

            if ( self::apply( $p ) ) {
                PMR_DB::update_status( (int) $p['id'], 'applied' );
                $applied++;
            } else {
                $failed++;
                $errors[] = array(
                    'product_id'    => $wc_id,
                    'product_title' => $p['wc_title'] ?? '',
                    'reason'        => 'WC product not found or meta write failed',
                );
            }
        }

        $done = ( $offset + $batch ) >= $total;

        return array(
            'applied'    => $applied,
            'failed'     => $failed,
            'errors'     => $errors,
            'duplicates' => $dupes,
            'total'      => $total,
            'offset'     => $offset,
            'batch'      => $batch,
            'done'       => $done,
            'progress'   => $total > 0 ? min( 100, (int) round( ( $offset + $applied + $failed ) / $total * 100 ) ) : 100,
        );
    }

    /**
     * Read the Printify meta currently stored on a product/variation.
     * Useful for the review UI to show "already has meta" warnings.
     */
    public static function read_current_meta( int $product_id, int $variation_id = 0 ): array {
        $pid = $product_id;
        $vid = $variation_id > 0 ? $variation_id : $product_id;

        return array(
            'printify_product_id'   => get_post_meta( $pid, '_printify_product_id',   true ),
            'printify_blueprint_id' => get_post_meta( $pid, '_printify_blueprint_id', true ),
            'printify_provider_id'  => get_post_meta( $pid, '_printify_provider_id',  true ),
            'printify_provider'     => get_post_meta( $pid, '_printify_provider',     true ),
            'printify_variant_id'   => get_post_meta( $vid, '_printify_variant_id',   true ),
            'printify_sku'          => get_post_meta( $vid, '_printify_sku',          true ),
        );
    }

    /**
     * Remove all Printify metadata from a WooCommerce product or variation.
     *
     * @param int $product_id   The parent WooCommerce product ID.
     * @param int $variation_id The variation ID (0 for simple products).
     * @return bool True on success.
     */
    public static function clear_meta( int $product_id, int $variation_id = 0 ): bool {
        if ( ! $product_id ) return false;

        $parent_keys = array(
            '_printify_product_id',
            '_printify_blueprint_id',
            '_printify_provider_id',
            '_printify_provider',
            '_printify_connect',
        );
        foreach ( $parent_keys as $key ) {
            delete_post_meta( $product_id, $key );
        }

        $target_id   = $variation_id > 0 ? $variation_id : $product_id;
        $target_keys = array(
            '_printify_variant_id',
            '_printify_sku',
            '_printify_blueprint_id',
            '_printify_provider_id',
        );
        foreach ( $target_keys as $key ) {
            delete_post_meta( $target_id, $key );
        }

        wc_delete_product_transients( $product_id );
        return true;
    }
}
