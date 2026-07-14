<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Printify REST API v1.
 *
 * Used for:
 *  1. Enriching matches by looking up the merchant's Printify products
 *     and finding WC ↔ Printify links via the external_id field.
 *  2. Fetching live shipping rates for the custom shipping method.
 */
class PMR_Printify_API {

    const BASE_URL = 'https://api.printify.com/v1';

    private string $api_key  = '';
    private string $shop_id  = '';

    public function __construct( string $api_key = '', string $shop_id = '' ) {
        $this->api_key = $api_key ?: (string) get_option( 'pmr_printify_api_key', '' );
        $this->shop_id = $shop_id ?: (string) get_option( 'pmr_printify_shop_id', '' );
    }

    // ── Products ──────────────────────────────────────────────────────────────

    /**
     * Fetch all products for the connected shop (handles pagination).
     */
    public function get_all_products(): array {
        if ( ! $this->api_key || ! $this->shop_id ) return array();

        $products = array();
        $page     = 1;

        do {
            $response = $this->get( "/shops/{$this->shop_id}/products.json", array(
                'page'  => $page,
                'limit' => 100,
            ) );

            if ( is_wp_error( $response ) || empty( $response['data'] ) ) break;

            $products = array_merge( $products, $response['data'] );
            $last     = $response['last_page'] ?? 1;
            $page++;
        } while ( $page <= $last );

        return $products;
    }

    /**
     * Fetch a single Printify product.
     */
    public function get_product( string $printify_product_id ): ?array {
        $r = $this->get( "/shops/{$this->shop_id}/products/{$printify_product_id}.json" );
        return is_array( $r ) ? $r : null;
    }

    // ── Catalog / Shipping ────────────────────────────────────────────────────

    /**
     * Fetch shipping rates for a blueprint/provider combination.
     *
     * Endpoint:
     *   GET /v1/catalog/blueprints/{blueprint_id}/print_providers/{provider_id}/shipping.json
     *
     * Returns an array of shipping profiles, each with:
     *   handling_time, profiles[]: { variant_ids[], first_item, additional_items, countries[] }
     *
     * @param int    $blueprint_id
     * @param int    $provider_id
     * @param string $country_code  ISO 2-letter
     * @param array  $variant_ids   Printify variant IDs in the cart for this product
     * @return array  Rates in cents: { standard: int, express: int|null, ... }
     */
    public function get_shipping_rates(
        int $blueprint_id,
        int $provider_id,
        string $country_code,
        array $variant_ids = array()
    ): array {
        $r = $this->get(
            "/catalog/blueprints/{$blueprint_id}/print_providers/{$provider_id}/shipping.json"
        );

        if ( is_wp_error( $r ) || ! isset( $r['profiles'] ) ) {
            return array();
        }

        // Two-pass approach:
        // Pass 1 — separate profiles into: country-specific (US only) vs REST_OF_THE_WORLD.
        //           A profile that has REST_OF_THE_WORLD is ALWAYS treated as fallback,
        //           even if the country code also appears in its countries list.
        // Pass 2 — use country-specific profiles if any exist; otherwise use REST_OF_THE_WORLD.

        $exact_matches    = array(); // profiles where country is listed WITHOUT REST_OF_THE_WORLD
        $fallback_matches = array(); // profiles that contain REST_OF_THE_WORLD

        foreach ( $r['profiles'] as $profile ) {

            // ── Country check ──────────────────────────────────────────────
            $countries     = $profile['countries'] ?? array();
            $rest_of_world = in_array( 'REST_OF_THE_WORLD', $countries, true );
            $country_match = in_array( $country_code, $countries, true );

            // Skip profiles that don't apply to this country at all
            if ( ! $rest_of_world && ! $country_match ) {
                continue;
            }

            // ── Variant check ──────────────────────────────────────────────
            $profile_variants = $profile['variant_ids'] ?? array();
            $overlap          = array_intersect( $variant_ids, $profile_variants );
            if ( ! empty( $variant_ids ) && empty( $overlap ) ) {
                continue;
            }

            $first = (int) ( $profile['first_item']['cost'] ?? 0 );
            $add   = (int) ( $profile['additional_items']['cost'] ?? 0 );

            $rate_data = array(
                'first_item'       => $first,
                'additional_items' => $add,
                'currency'         => $profile['first_item']['currency'] ?? 'USD',
            );

            // REST_OF_THE_WORLD profiles always go to fallback — never to exact matches.
            // This prevents the "Rest of World" rate sneaking in when a US-specific rate exists.
            if ( $rest_of_world ) {
                $fallback_matches[] = $rate_data;
            } else {
                $exact_matches[] = $rate_data;
            }
        }

        // Use country-specific profiles if ANY exist; only fall back to REST_OF_THE_WORLD if none found
        $matched_profiles = ! empty( $exact_matches ) ? $exact_matches : $fallback_matches;

        if ( empty( $matched_profiles ) ) {
            return array();
        }

        // Deduplicate profiles with identical costs (same price = same rate type)
        $seen_costs      = array();
        $unique_profiles = array();
        foreach ( $matched_profiles as $profile ) {
            $cost_key = $profile['first_item'] . '_' . $profile['additional_items'];
            if ( ! isset( $seen_costs[ $cost_key ] ) ) {
                $seen_costs[ $cost_key ] = true;
                $unique_profiles[]       = $profile;
            }
        }

        // Sort profiles by cost ascending: cheapest = economy, middle = standard, most expensive = express
        usort( $unique_profiles, function( $a, $b ) {
            return $a['first_item'] <=> $b['first_item'];
        } );

        // Assign rate type labels based on the number of available rates
        $count         = count( $unique_profiles );
        $label_maps    = array(
            1 => array( 'standard' ),
            2 => array( 'economy', 'standard' ),
            3 => array( 'economy', 'standard', 'express' ),
        );
        $type_sequence = $label_maps[ min( $count, 3 ) ] ?? array( 'economy', 'standard', 'express' );

        $rates = array();
        foreach ( $unique_profiles as $i => $profile ) {
            $type           = $type_sequence[ $i ] ?? ( 'rate_' . $i );
            $rates[ $type ] = $profile;
        }

        return $rates;
    }

    // ── Shops ─────────────────────────────────────────────────────────────────

    public function get_shops(): array {
        $r = $this->get( '/shops.json' );
        return is_array( $r ) ? $r : array();
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get( string $path, array $query = array() ) {
        if ( ! $this->api_key ) return new WP_Error( 'no_api_key', 'Printify API key not set.' );

        $url = self::BASE_URL . $path;
        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        $response = wp_remote_get( $url, array(
            'headers' => $this->headers(),
            'timeout' => 20,
        ) );

        return $this->parse_response( $response );
    }

    private function headers(): array {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'PrintifyMappingRestore/' . PMR_VERSION . ' (WordPress)',
        );
    }

    private function parse_response( $response ) {
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 400 ) {
            $msg = $data['message'] ?? "HTTP {$code}";
            return new WP_Error( 'printify_api_error', $msg, array( 'status' => $code ) );
        }

        return $data;
    }

    public function has_credentials(): bool {
        return ! empty( $this->api_key ) && ! empty( $this->shop_id );
    }
}
