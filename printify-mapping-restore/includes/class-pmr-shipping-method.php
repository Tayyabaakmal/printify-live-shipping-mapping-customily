<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom WooCommerce Shipping Method: Printify Live Rates
 *
 * This method reads the Printify metadata we restore onto products and
 * queries the Printify Catalog Shipping API for live rates.
 *
 * It serves as a reliable fallback if the official Printify for WooCommerce
 * shipping plugin does not recognise the products after metadata is restored.
 *
 * Method ID:    pmr_printify_shipping
 * Method Title: Printify Shipping (Restored)
 */
class PMR_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'pmr_printify_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Printify Shipping (Mapping Restore)', 'pmr' );
        $this->method_description = __( 'Fetches live shipping rates from Printify API using restored product mapping metadata.', 'pmr' );
        $this->supports           = array( 'shipping-zones', 'instance-settings' );
        $this->title              = $this->get_option( 'title', __( 'Standard Shipping', 'pmr' ) );

        $this->init();
    }

    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable', 'pmr' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title'   => __( 'Method Title', 'pmr' ),
                'type'    => 'text',
                'default' => __( 'Standard Shipping', 'pmr' ),
            ),
            'show_all_rates' => array(
                'title'       => __( 'Show All Rate Types', 'pmr' ),
                'type'        => 'checkbox',
                'label'       => __( 'Show economy, standard, express etc. as separate options', 'pmr' ),
                'default'     => 'yes',
                'description' => __( 'If unchecked, only the cheapest rate is shown.', 'pmr' ),
                'desc_tip'    => true,
            ),
            'markup_percent' => array(
                'title'       => __( 'Markup (%)', 'pmr' ),
                'type'        => 'number',
                'default'     => 0,
                'description' => __( 'Percentage to add on top of Printify rate.', 'pmr' ),
                'desc_tip'    => true,
                'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
            ),
            'fallback_cost' => array(
                'title'       => __( 'Fallback Rate (store currency)', 'pmr' ),
                'type'        => 'price',
                'default'     => '',
                'description' => __( 'If Printify API returns no rates, use this flat rate. Leave blank to hide method.', 'pmr' ),
                'desc_tip'    => true,
            ),
        );
    }

    public function calculate_shipping( $package = array() ): void {
        if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) return;

        $destination = $package['destination'] ?? array();
        $country     = $destination['country'] ?? '';

        if ( ! $country ) return;

        // Group cart items by blueprint+provider pair (one API call per unique pair)
        $groups = $this->group_cart_items( $package['contents'] );

        if ( empty( $groups ) ) return;

        $api          = new PMR_Printify_API();
        $show_all     = 'yes' === $this->get_option( 'show_all_rates', 'yes' );
        $markup       = (float) $this->get_option( 'markup_percent', 0 );
        $fallback     = $this->get_option( 'fallback_cost', '' );

        // Accumulate shipping rates across all product groups.
        // A rate type (e.g. economy) is only shown if ALL groups in the cart support it.
        $accumulated   = array(); // rate_type => total_cost_cents
        $type_count    = array(); // rate_type => how many groups returned it
        $total_groups  = 0;

        foreach ( $groups as $group_key => $group ) {
            $blueprint_id = (int) $group['blueprint_id'];
            $provider_id  = (int) $group['provider_id'];
            $variant_ids  = $group['variant_ids'];
            $quantities   = $group['quantities'];

            $rates = $api->get_shipping_rates( $blueprint_id, $provider_id, $country, $variant_ids );

            if ( empty( $rates ) ) {
                if ( $fallback !== '' ) {
                    $this->add_rate( array(
                        'id'    => $this->id . '_fallback',
                        'label' => $this->title,
                        'cost'  => (float) $fallback,
                    ) );
                }
                // This group returned no rates — still count it so unsupported rate types are filtered out
                $total_groups++;
                continue;
            }

            $total_groups++;

            foreach ( $rates as $rate_type => $rate_data ) {
                $total_qty   = array_sum( $quantities );
                $cost_cents  = $rate_data['first_item'];
                if ( $total_qty > 1 ) {
                    $cost_cents += $rate_data['additional_items'] * ( $total_qty - 1 );
                }

                if ( ! isset( $accumulated[ $rate_type ] ) ) {
                    $accumulated[ $rate_type ] = array(
                        'cost_cents' => 0,
                        'currency'   => $rate_data['currency'],
                    );
                }
                $accumulated[ $rate_type ]['cost_cents'] += $cost_cents;

                // Track how many product groups support this rate type
                $type_count[ $rate_type ] = ( $type_count[ $rate_type ] ?? 0 ) + 1;
            }
        }

        // Add a rate per type — only if ALL groups support that rate type
        foreach ( $accumulated as $rate_type => $data ) {
            // Skip rate types not supported by every product group in the cart
            if ( ( $type_count[ $rate_type ] ?? 0 ) < $total_groups ) {
                continue;
            }
            $cost_usd = $data['cost_cents'] / 100;
            // Apply markup
            if ( $markup > 0 ) {
                $cost_usd = $cost_usd * ( 1 + $markup / 100 );
            }
            // Currency conversion if store is not USD
            $cost = $this->convert_from_usd( $cost_usd, $data['currency'] );

            $label = $this->rate_label( $rate_type );

            // Only show the standard shipping rate.
            if ( $rate_type !== 'standard' ) continue;

            $this->add_rate( array(
                'id'    => $this->id . '_' . sanitize_key( $rate_type ),
                'label' => $label,
                'cost'  => round( $cost, 2 ),
            ) );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function group_cart_items( array $contents ): array {
        $groups = array();

        foreach ( $contents as $item ) {
            $product_id   = (int) ( $item['variation_id'] ?: $item['product_id'] );
            $parent_id    = (int) $item['product_id'];
            $qty          = (int) $item['quantity'];

            $blueprint_id = get_post_meta( $product_id, '_printify_blueprint_id', true )
                         ?: get_post_meta( $parent_id,  '_printify_blueprint_id', true );
            $provider_id  = get_post_meta( $product_id, '_printify_provider_id',  true )
                         ?: get_post_meta( $parent_id,  '_printify_provider_id',  true );
            $variant_id   = get_post_meta( $product_id, '_printify_variant_id',   true );

            if ( ! $blueprint_id || ! $provider_id ) continue;

            $key = "{$blueprint_id}_{$provider_id}";
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'blueprint_id' => $blueprint_id,
                    'provider_id'  => $provider_id,
                    'variant_ids'  => array(),
                    'quantities'   => array(),
                );
            }

            if ( $variant_id ) {
                $groups[ $key ]['variant_ids'][] = (int) $variant_id;
            }
            $groups[ $key ]['quantities'][] = $qty;
        }

        return $groups;
    }

    private function rate_label( string $rate_type ): string {
        $labels = array(
            'standard'  => __( 'Standard Shipping', 'pmr' ),
            'express'   => __( 'Express Shipping', 'pmr' ),
            'economy'   => __( 'Economy Shipping', 'pmr' ),
            'overnight' => __( 'Overnight Shipping', 'pmr' ),
        );

        if ( isset( $labels[ $rate_type ] ) ) {
            return $labels[ $rate_type ];
        }

        // Handle inferred keys like rate_0, rate_1, rate_2
        if ( strpos( $rate_type, 'rate_' ) === 0 ) {
            $index = (int) str_replace( 'rate_', '', $rate_type );
            $fallback_labels = array(
                0 => __( 'Economy Shipping', 'pmr' ),
                1 => __( 'Standard Shipping', 'pmr' ),
                2 => __( 'Express Shipping', 'pmr' ),
            );
            return $fallback_labels[ $index ] ?? __( 'Shipping Option', 'pmr' ) . ' ' . ( $index + 1 );
        }

        return ucfirst( str_replace( '_', ' ', $rate_type ) ) . ' ' . __( 'Shipping', 'pmr' );
    }

    private function convert_from_usd( float $amount_usd, string $source_currency ): float {
        // If WC currency is USD or same as source, no conversion needed
        $store_currency = get_woocommerce_currency();
        if ( $store_currency === 'USD' || $store_currency === $source_currency ) {
            return $amount_usd;
        }
        // Plugins like WooCommerce Currency Switcher may expose a filter
        return apply_filters( 'pmr_convert_shipping_amount', $amount_usd, 'USD', $store_currency );
    }
}
