<?php
defined( 'ABSPATH' ) || exit;

/**
 * Parses a Printify-exported CSV.
 *
 * Printify's CSV columns vary slightly by export type, so we try
 * multiple candidate column names and normalise everything into a
 * common array shape.
 */
class PMR_CSV_Parser {

    /**
     * Canonical column-name aliases:
     *   internal_key => [ possible CSV header names... ]
     */
    private const COL_MAP = array(
        'printify_product_id'   => [ 'Product ID', 'product_id', 'Printify Product ID' ],
        'printify_product_title'=> [ 'Product Title', 'product_title', 'Title', 'title', 'Product Name', 'Blueprint Title', 'blueprint_title', 'Blueprint Name', 'blueprint_name' ],
        'printify_variant_id'   => [ 'Variant ID', 'variant_id', 'Printify Variant ID' ],
        'printify_variant_title'=> [ 'Variant Title', 'variant_title', 'Variant', 'variant' ],
        'printify_blueprint_id' => [ 'Blueprint ID', 'blueprint_id', 'Blueprint Id' ],
        'printify_blueprint'    => [ 'Blueprint Name', 'blueprint_name', 'Blueprint', 'blueprint' ],
        'printify_provider_id'  => [ 'Print Provider ID', 'print_provider_id', 'Provider ID', 'provider_id', 'Print Provider Id' ],
        'printify_provider'     => [ 'Print Provider Name', 'print_provider_name', 'Provider Name', 'provider_name', 'Print Provider' ],
        'printify_sku'          => [ 'SKU', 'sku', 'Variant SKU', 'variant_sku' ],
    );

    /**
     * Parse an uploaded CSV file.
     *
     * @param string $file_path Absolute path to the temporary uploaded file.
     * @return array{rows: array, headers: array, warnings: array}
     */
    public static function parse( string $file_path ): array {
        $result = array(
            'rows'     => array(),
            'headers'  => array(),
            'warnings' => array(),
        );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            $result['warnings'][] = 'File not found or not readable.';
            return $result;
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $result['warnings'][] = 'Could not open file.';
            return $result;
        }

        // Auto-detect delimiter
        $first_line = fgets( $handle );
        rewind( $handle );
        $delimiter = self::detect_delimiter( $first_line );

        // Read headers
        $raw_headers = fgetcsv( $handle, 0, $delimiter );
        if ( ! $raw_headers ) {
            $result['warnings'][] = 'CSV appears to be empty.';
            fclose( $handle );
            return $result;
        }

        // Trim BOM + whitespace from headers
        $raw_headers = array_map( function( $h ) {
            return trim( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) );
        }, $raw_headers );

        $result['headers'] = $raw_headers;

        // Build column-index map
        $col_index = self::build_col_index( $raw_headers );

        // Warn about missing important columns
        $required = [ 'printify_variant_id', 'printify_provider_id', 'printify_blueprint_id' ];
        foreach ( $required as $key ) {
            if ( ! isset( $col_index[ $key ] ) ) {
                $result['warnings'][] = "Column '{$key}' not found in CSV. Tried: " . implode( ', ', self::COL_MAP[ $key ] );
            }
        }

        // Parse rows
        $line = 1;
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $line++;
            if ( array_filter( $row ) === array() ) continue; // skip blank rows

            $parsed = array( '_csv_line' => $line );
            foreach ( self::COL_MAP as $key => $_ ) {
                $idx = $col_index[ $key ] ?? null;
                $parsed[ $key ] = ( $idx !== null && isset( $row[ $idx ] ) ) ? trim( $row[ $idx ] ) : '';
            }

            // Skip rows without a variant_id — they're unusable for mapping
            if ( empty( $parsed['printify_variant_id'] ) ) {
                continue;
            }

            $result['rows'][] = $parsed;
        }

        fclose( $handle );

        if ( empty( $result['rows'] ) ) {
            $result['warnings'][] = 'No usable rows found (all rows missing Variant ID).';
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function detect_delimiter( string $line ): string {
        $counts = array(
            ','  => substr_count( $line, ',' ),
            ';'  => substr_count( $line, ';' ),
            "\t" => substr_count( $line, "\t" ),
        );
        arsort( $counts );
        return key( $counts );
    }

    private static function build_col_index( array $headers ): array {
        $index = array();
        foreach ( self::COL_MAP as $key => $aliases ) {
            foreach ( $aliases as $alias ) {
                $found = array_search( $alias, $headers );
                if ( $found !== false ) {
                    $index[ $key ] = $found;
                    break;
                }
            }
            // Case-insensitive fallback
            if ( ! isset( $index[ $key ] ) ) {
                foreach ( $aliases as $alias ) {
                    foreach ( $headers as $i => $h ) {
                        if ( strtolower( $h ) === strtolower( $alias ) ) {
                            $index[ $key ] = $i;
                            break 2;
                        }
                    }
                }
            }
        }
        return $index;
    }
}
