<?php
defined( 'ABSPATH' ) || exit;

/**
 * Creates and manages the plugin's custom DB table that stores
 * proposed matches before the admin approves them.
 */
class PMR_DB {

    const TABLE_SUFFIX = 'pmr_mapping_proposals';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install(): void {
        global $wpdb;
        $table      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      VARCHAR(64)     NOT NULL,
            wc_product_id   BIGINT UNSIGNED NOT NULL,
            wc_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            wc_title        TEXT            NOT NULL,
            wc_sku          VARCHAR(255)    NOT NULL DEFAULT '',
            printify_product_id   VARCHAR(64)  NOT NULL DEFAULT '',
            printify_variant_id   VARCHAR(64)  NOT NULL DEFAULT '',
            printify_blueprint_id VARCHAR(64)  NOT NULL DEFAULT '',
            printify_provider_id  VARCHAR(64)  NOT NULL DEFAULT '',
            printify_provider     VARCHAR(255) NOT NULL DEFAULT '',
            printify_sku          VARCHAR(255) NOT NULL DEFAULT '',
            csv_row_title         TEXT         NOT NULL,
            match_method          VARCHAR(64)  NOT NULL DEFAULT '',
            match_score           FLOAT        NOT NULL DEFAULT 0,
            status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session  (session_id),
            KEY idx_product  (wc_product_id),
            KEY idx_status   (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'pmr_db_version', PMR_VERSION );
    }

    public static function deactivate(): void {
        // We intentionally do NOT drop the table on deactivate —
        // admins may want to re-activate later with data intact.
    }

    public static function uninstall(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_name() );
        delete_option( 'pmr_db_version' );
        delete_option( 'pmr_printify_api_key' );
        delete_option( 'pmr_printify_shop_id' );
    }

    // ── CRUD helpers ──────────────────────────────────────────────────────────

    public static function insert_proposal( array $data ): int {
        global $wpdb;
        $wpdb->insert( self::table_name(), $data );
        return (int) $wpdb->insert_id;
    }

    public static function get_proposals( string $session_id, string $status = '' ): array {
        global $wpdb;
        $table = self::table_name();
        $where = $wpdb->prepare( "WHERE session_id = %s", $session_id );
        if ( $status ) {
            $where .= $wpdb->prepare( " AND status = %s", $status );
        }
        return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY match_score DESC", ARRAY_A );
    }

    public static function get_proposals_paged(
        string $session_id,
        string $status   = '',
        string $search   = '',
        int    $per_page = 50,
        int    $page     = 1
    ): array {
        global $wpdb;
        $table  = self::table_name();
        $wheres = array( $wpdb->prepare( "session_id = %s", $session_id ) );

        if ( $status === 'unmatched' ) {
            $wheres[] = "match_score = 0";
        } elseif ( $status === 'applied' ) {
            $wheres[] = $wpdb->prepare( "status = %s", 'applied' );
        } elseif ( $status && $status !== 'all' ) {
            $wheres[] = $wpdb->prepare( "status = %s", $status );
        }
        if ( $search ) {
            $wheres[] = $wpdb->prepare( "wc_title LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $wheres );
        $offset    = ( $page - 1 ) * $per_page;

        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} {$where_sql} ORDER BY match_score DESC LIMIT {$per_page} OFFSET {$offset}",
            ARRAY_A
        );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );

        return array( 'rows' => $rows ?: array(), 'total' => $total );
    }

    public static function get_status_counts( string $session_id ): array {
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT status, COUNT(*) as cnt FROM {$table} WHERE session_id = %s GROUP BY status", $session_id ),
            ARRAY_A
        );
        $counts = array( 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'applied' => 0, 'unmatched' => 0 );
        foreach ( $rows as $r ) {
            $counts[ $r['status'] ] = (int) $r['cnt'];
            $counts['total']       += (int) $r['cnt'];
        }
        // unmatched = score 0
        $counts['unmatched'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND match_score = 0", $session_id )
        );
        return $counts;
    }

    public static function update_status( int $id, string $status ): void {
        global $wpdb;
        $wpdb->update( self::table_name(), array( 'status' => $status ), array( 'id' => $id ) );
    }

    public static function bulk_update_status( array $ids, string $status ): void {
        global $wpdb;
        if ( empty( $ids ) ) return;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table_name() . " SET status = %s WHERE id IN ({$placeholders})",
            array_merge( array( $status ), $ids )
        ) );
    }

    public static function delete_session( string $session_id ): void {
        global $wpdb;
        $wpdb->delete( self::table_name(), array( 'session_id' => $session_id ) );
    }

    public static function get_sessions(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT session_id, COUNT(*) as total,
                SUM(status='approved') as approved,
                SUM(status='rejected') as rejected,
                SUM(status='applied') as applied,
                MIN(created_at) as created_at
             FROM " . self::table_name() . "
             GROUP BY session_id ORDER BY created_at DESC",
            ARRAY_A
        );
    }
}
