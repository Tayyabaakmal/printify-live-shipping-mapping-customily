<?php
defined( 'ABSPATH' ) || exit;

/**
 * Matching engine.
 *
 * Tries multiple strategies (in descending confidence order) to pair
 * each WooCommerce product / variation with a CSV row from Printify.
 *
 * Strategies (applied in order; first confident match wins per variant):
 *  1. Exact SKU match            — WC variation SKU === Printify SKU
 *  2. Exact SKU match (product)  — WC simple product SKU === Printify SKU
 *  3. Printify API lookup        — fetch products from Printify and match external ID
 *  4. Fuzzy title + variant      — normalised levenshtein similarity
 */
class PMR_Matcher {

    private array $csv_rows    = array();
    private array $wc_products = array();
    private array $proposals   = array();
    private string $session_id = '';
    private ?PMR_Printify_API $api = null;

    public function __construct( array $csv_rows, ?PMR_Printify_API $api = null ) {
        $this->csv_rows   = $csv_rows;
        $this->api        = $api;
        $this->session_id = wp_generate_uuid4();
    }

    public function get_session_id(): string {
        return $this->session_id;
    }

    /**
     * Run all matching strategies and persist proposals.
     *
     * @return array List of proposal arrays (not yet saved).
     */
    public function run(): array {
        $this->load_wc_products();

        // Build a SKU index from CSV rows for fast lookup
        $csv_by_sku    = $this->index_csv_by( 'printify_sku' );
        $csv_by_variant = $this->index_csv_by( 'printify_variant_id' );

        // Attempt Printify API enrichment (maps external WC product IDs → Printify product IDs)
        $api_map = $this->build_api_map();

        foreach ( $this->wc_products as $product ) {
            $wc_id    = (int) $product->get_id();
            $wc_type  = $product->get_type();
            $wc_title = $product->get_name();

            if ( $wc_type === 'variable' ) {
                foreach ( $product->get_children() as $var_id ) {
                    $variation = wc_get_product( $var_id );
                    if ( ! $variation ) continue;

                    $wc_sku   = $variation->get_sku();
                    $var_title = $this->variation_label( $variation );

                    $match = $this->match_variation(
                        $wc_id, $var_id, $wc_title, $wc_sku, $var_title,
                        $csv_by_sku, $api_map
                    );
                    if ( $match ) {
                        $this->proposals[] = $match;
                    }
                }
            } else {
                // Simple / other product types
                $wc_sku = $product->get_sku();
                $match  = $this->match_simple(
                    $wc_id, $wc_title, $wc_sku,
                    $csv_by_sku, $api_map
                );
                if ( $match ) {
                    $this->proposals[] = $match;
                }
            }
        }

        // Persist to DB
        foreach ( $this->proposals as $p ) {
            PMR_DB::insert_proposal( $p );
        }

        return $this->proposals;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function load_wc_products(): void {
        $args = array(
            'status'         => array( 'publish', 'draft', 'private' ),
            'limit'          => -1,
            'return'         => 'objects',
            'type'           => array( 'simple', 'variable' ),
        );
        $this->wc_products = wc_get_products( $args );
    }

    /**
     * Build index of CSV rows keyed by a field value.
     */
    private function index_csv_by( string $field ): array {
        $index = array();
        foreach ( $this->csv_rows as $row ) {
            $val = strtolower( trim( $row[ $field ] ?? '' ) );
            if ( $val !== '' ) {
                $index[ $val ][] = $row;
            }
        }
        return $index;
    }

    /**
     * Optionally fetch the merchant's Printify products via API and build
     * a map: wc_external_id → printify_product_data.
     * The external ID is set by Printify on publish (it's the WC post ID).
     */
    private function build_api_map(): array {
        if ( ! $this->api ) return array();

        $printify_products = $this->api->get_all_products();
        $map = array();

        foreach ( $printify_products as $pp ) {
            $external_id = $pp['external']['id'] ?? null;
            if ( $external_id ) {
                $map[ (int) $external_id ] = $pp;
            }
        }
        return $map;
    }

    /**
     * Match a WC variation to a CSV row.
     */
    private function match_variation(
        int $wc_product_id, int $var_id, string $wc_title,
        string $wc_sku, string $var_title,
        array $csv_by_sku, array $api_map
    ): ?array {

        $base = array(
            'session_id'      => $this->session_id,
            'wc_product_id'   => $wc_product_id,
            'wc_variation_id' => $var_id,
            'wc_title'        => $wc_title . ' — ' . $var_title,
            'wc_sku'          => $wc_sku,
            'status'          => 'pending',
        );

        // Strategy 1: Exact SKU match on variation SKU
        if ( $wc_sku ) {
            $key = strtolower( $wc_sku );
            if ( isset( $csv_by_sku[ $key ] ) ) {
                $row = $csv_by_sku[ $key ][0];
                return array_merge( $base, $this->row_to_fields( $row ), array(
                    'match_method' => 'exact_sku',
                    'match_score'  => 1.0,
                ) );
            }
        }

        // Strategy 2: API map — product matched via external ID, variant via SKU or variant_id
        if ( isset( $api_map[ $wc_product_id ] ) ) {
            $pp = $api_map[ $wc_product_id ];
            foreach ( ( $pp['variants'] ?? array() ) as $pv ) {
                if ( ! empty( $pv['sku'] ) && strtolower( $pv['sku'] ) === strtolower( $wc_sku ) ) {
                    $csv_row = $this->find_csv_row_by_variant_id( (string) $pv['id'] );
                    if ( $csv_row ) {
                        return array_merge( $base, $this->row_to_fields( $csv_row ), array(
                            'printify_product_id' => $pp['id'],
                            'match_method'        => 'api_external_id+sku',
                            'match_score'         => 0.97,
                        ) );
                    }
                    // Build from API directly
                    return array_merge( $base, array(
                        'printify_product_id'   => $pp['id'],
                        'printify_variant_id'   => (string) $pv['id'],
                        'printify_blueprint_id' => (string) ( $pp['blueprint_id'] ?? '' ),
                        'printify_provider_id'  => (string) ( $pp['print_provider_id'] ?? '' ),
                        'printify_provider'     => '',
                        'printify_sku'          => $pv['sku'] ?? '',
                        'csv_row_title'         => '[From Printify API]',
                        'match_method'          => 'api_external_id+sku',
                        'match_score'           => 0.95,
                    ) );
                }
            }
        }

        // Strategy 3: Fuzzy title match
        return $this->fuzzy_match( $base, $wc_title, $var_title );
    }

    /**
     * Match a simple WC product to a CSV row.
     */
    private function match_simple(
        int $wc_product_id, string $wc_title, string $wc_sku,
        array $csv_by_sku, array $api_map
    ): ?array {

        $base = array(
            'session_id'      => $this->session_id,
            'wc_product_id'   => $wc_product_id,
            'wc_variation_id' => 0,
            'wc_title'        => $wc_title,
            'wc_sku'          => $wc_sku,
            'status'          => 'pending',
        );

        // Strategy 1: Exact SKU
        if ( $wc_sku ) {
            $key = strtolower( $wc_sku );
            if ( isset( $csv_by_sku[ $key ] ) ) {
                $row = $csv_by_sku[ $key ][0];
                return array_merge( $base, $this->row_to_fields( $row ), array(
                    'match_method' => 'exact_sku',
                    'match_score'  => 1.0,
                ) );
            }
        }

        // Strategy 2: API external ID
        if ( isset( $api_map[ $wc_product_id ] ) ) {
            $pp = $api_map[ $wc_product_id ];
            // For simple products take first variant
            $pv      = $pp['variants'][0] ?? null;
            $csv_row = $pv ? $this->find_csv_row_by_variant_id( (string) $pv['id'] ) : null;
            if ( $csv_row ) {
                return array_merge( $base, $this->row_to_fields( $csv_row ), array(
                    'printify_product_id' => $pp['id'],
                    'match_method'        => 'api_external_id',
                    'match_score'         => 0.95,
                ) );
            }
        }

        // Strategy 3: Fuzzy
        return $this->fuzzy_match( $base, $wc_title, '' );
    }

    /**
     * Fuzzy title matching against CSV product titles + variant titles.
     */
    private function fuzzy_match( array $base, string $product_title, string $variant_title ): ?array {
        $best_score = 0.0;
        $best_row   = null;

        $search_str = strtolower( trim( $product_title . ' ' . $variant_title ) );

        foreach ( $this->csv_rows as $row ) {
            $candidate = strtolower( trim(
                ( $row['printify_product_title'] ?? '' ) . ' ' .
                ( $row['printify_variant_title'] ?? '' )
            ) );

            similar_text( $search_str, $candidate, $pct );
            $score = $pct / 100.0;

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_row   = $row;
            }
        }

        // Only propose if above threshold (65%)
        if ( $best_row && $best_score >= 0.65 ) {
            return array_merge( $base, $this->row_to_fields( $best_row ), array(
                'match_method' => 'fuzzy_title',
                'match_score'  => round( $best_score, 4 ),
            ) );
        }

        // No match — still create an unmatched proposal so admin can manually assign
        return array_merge( $base, array(
            'printify_product_id'   => '',
            'printify_variant_id'   => '',
            'printify_blueprint_id' => '',
            'printify_provider_id'  => '',
            'printify_provider'     => '',
            'printify_sku'          => '',
            'csv_row_title'         => '',
            'match_method'          => 'unmatched',
            'match_score'           => 0.0,
        ) );
    }

    private function find_csv_row_by_variant_id( string $variant_id ): ?array {
        foreach ( $this->csv_rows as $row ) {
            if ( (string) ( $row['printify_variant_id'] ?? '' ) === $variant_id ) {
                return $row;
            }
        }
        return null;
    }

    private function row_to_fields( array $row ): array {
        return array(
            'printify_product_id'   => $row['printify_product_id']   ?? '',
            'printify_variant_id'   => $row['printify_variant_id']   ?? '',
            'printify_blueprint_id' => $row['printify_blueprint_id'] ?? '',
            'printify_provider_id'  => $row['printify_provider_id']  ?? '',
            'printify_provider'     => $row['printify_provider']     ?? '',
            'printify_sku'          => $row['printify_sku']          ?? '',
            'csv_row_title'         => trim(
                ( $row['printify_product_title'] ?? '' ) . ' — ' .
                ( $row['printify_variant_title'] ?? '' )
            ),
        );
    }

    private function variation_label( WC_Product_Variation $v ): string {
        $attrs = $v->get_variation_attributes();
        return implode( ' / ', array_values( array_filter( $attrs ) ) );
    }
}
