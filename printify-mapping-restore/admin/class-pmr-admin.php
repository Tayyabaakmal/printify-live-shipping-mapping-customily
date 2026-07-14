<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin controller: registers the menu, enqueues assets, handles AJAX.
 */
class PMR_Admin {

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_pmr_upload_csv',     array( __CLASS__, 'ajax_upload_csv' ) );
        add_action( 'wp_ajax_pmr_run_match',      array( __CLASS__, 'ajax_run_match' ) );
        add_action( 'wp_ajax_pmr_update_status',  array( __CLASS__, 'ajax_update_status' ) );
        add_action( 'wp_ajax_pmr_apply_approved', array( __CLASS__, 'ajax_apply_approved' ) );
        add_action( 'wp_ajax_pmr_save_settings',  array( __CLASS__, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_pmr_test_api',       array( __CLASS__, 'ajax_test_api' ) );
        add_action( 'wp_ajax_pmr_delete_session', array( __CLASS__, 'ajax_delete_session' ) );
        add_action( 'wp_ajax_pmr_update_proposal',array( __CLASS__, 'ajax_update_proposal' ) );
        add_action( 'wp_ajax_pmr_get_proposals',  array( __CLASS__, 'ajax_get_proposals' ) );
        add_action( 'wp_ajax_pmr_get_counts',     array( __CLASS__, 'ajax_get_counts' ) );
        add_action( 'wp_ajax_pmr_clear_meta',     array( __CLASS__, 'ajax_clear_meta' ) );
        add_action( 'wp_ajax_pmr_clear_all_meta',     array( __CLASS__, 'ajax_clear_all_meta' ) );
        add_action( 'wp_ajax_pmr_clear_session_meta', array( __CLASS__, 'ajax_clear_session_meta' ) );
        add_action( 'wp_ajax_pmr_delete_rejected',    array( __CLASS__, 'ajax_delete_rejected' ) );
        add_action( 'wp_ajax_pmr_apply_batch',        array( __CLASS__, 'ajax_apply_batch' ) );
        add_action( 'wp_ajax_pmr_get_error_log',      array( __CLASS__, 'ajax_get_error_log' ) );
        add_action( 'wp_ajax_pmr_export_csv',         array( __CLASS__, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_pmr_auto_approve',       array( __CLASS__, 'ajax_auto_approve' ) );
        add_action( 'wp_ajax_pmr_delete_selected',    array( __CLASS__, 'ajax_delete_selected' ) );
        add_action( 'wp_ajax_pmr_delete_selected',    array( __CLASS__, 'ajax_delete_selected' ) );
        add_action( 'wp_ajax_pmr_delete_selected',    array( __CLASS__, 'ajax_delete_selected' ) );
    }

    public static function register_menu(): void {
        add_menu_page(
            __( 'Printify Mapping Restore', 'pmr' ),
            __( 'Printify Restore', 'pmr' ),
            'manage_woocommerce',
            'pmr-dashboard',
            array( __CLASS__, 'render_dashboard' ),
            'dashicons-update',
            56
        );
        add_submenu_page( 'pmr-dashboard', __( 'Dashboard', 'pmr' ), __( 'Dashboard', 'pmr' ), 'manage_woocommerce', 'pmr-dashboard', array( __CLASS__, 'render_dashboard' ) );
        add_submenu_page( 'pmr-dashboard', __( 'Import & Match', 'pmr' ), __( 'Import & Match', 'pmr' ), 'manage_woocommerce', 'pmr-import', array( __CLASS__, 'render_import' ) );
        add_submenu_page( 'pmr-dashboard', __( 'Review & Apply', 'pmr' ), __( 'Review & Apply', 'pmr' ), 'manage_woocommerce', 'pmr-review', array( __CLASS__, 'render_review' ) );
        add_submenu_page( 'pmr-dashboard', __( 'Settings', 'pmr' ), __( 'Settings', 'pmr' ), 'manage_woocommerce', 'pmr-settings', array( __CLASS__, 'render_settings' ) );
        add_submenu_page( 'pmr-dashboard', __( 'Clear Meta', 'pmr' ), __( 'Clear Meta', 'pmr' ), 'manage_woocommerce', 'pmr-clear-meta', array( __CLASS__, 'render_clear_meta' ) );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'pmr-' ) === false ) return;

        wp_enqueue_style(
            'pmr-admin',
            PMR_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PMR_VERSION
        );
        wp_enqueue_script(
            'pmr-admin',
            PMR_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            PMR_VERSION,
            true
        );
        // Pass session_id to JS if we are on the review page
        $current_session = sanitize_text_field( $_GET['session'] ?? '' );

        wp_localize_script( 'pmr-admin', 'PMR', array(
            'nonce'      => wp_create_nonce( 'pmr_nonce' ),
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'session_id' => $current_session,
            'strings'    => array(
                'confirm_apply'  => __( 'Apply all approved matches to WooCommerce products? This will write Printify metadata to the database.', 'pmr' ),
                'confirm_delete' => __( 'Delete this session and all its proposals? This cannot be undone.', 'pmr' ),
                'running'        => __( 'Running…', 'pmr' ),
                'done'           => __( 'Done', 'pmr' ),
            ),
        ) );
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        $sessions  = PMR_DB::get_sessions();
        $api_key   = get_option( 'pmr_printify_api_key', '' );
        $shop_id   = get_option( 'pmr_printify_shop_id', '' );
        include PMR_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public static function render_import(): void {
        include PMR_PLUGIN_DIR . 'admin/views/import.php';
    }

    public static function render_review(): void {
        $session_id = sanitize_text_field( $_GET['session'] ?? '' );
        $counts     = $session_id ? PMR_DB::get_status_counts( $session_id ) : array( 'total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'applied'=>0,'unmatched'=>0 );
        include PMR_PLUGIN_DIR . 'admin/views/review.php';
    }

    // ── AJAX: paginated proposals ──────────────────────────────────────────────
    public static function ajax_get_proposals(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $status     = sanitize_text_field( $_POST['status']     ?? '' );
        $search     = sanitize_text_field( $_POST['search']     ?? '' );
        $per_page   = max( 1, min( 200, (int) ( $_POST['per_page'] ?? 50 ) ) );
        $page       = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        $result = PMR_DB::get_proposals_paged( $session_id, $status, $search, $per_page, $page );

        // Enrich with existing meta flag
        foreach ( $result['rows'] as &$p ) {
            $existing    = PMR_Meta_Writer::read_current_meta( (int) $p['wc_product_id'], (int) $p['wc_variation_id'] );
            $p['has_existing'] = ! empty( array_filter( $existing ) );
            $p['edit_url']     = get_edit_post_link( $p['wc_product_id'], 'raw' );
        }
        unset( $p );

        wp_send_json_success( array(
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages'=> (int) ceil( $result['total'] / $per_page ),
        ) );
    }

    // ── AJAX: clear meta from selected proposals ─────────────────────────────
    public static function ajax_clear_meta(): void {
        self::verify_nonce();
        $ids = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No IDs provided.' ) );
        }

        global $wpdb;
        $table   = PMR_DB::table_name();
        $cleared = 0;
        $failed  = 0;

        foreach ( $ids as $id ) {
            $proposal = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
                ARRAY_A
            );
            if ( ! $proposal ) { $failed++; continue; }

            $ok = PMR_Meta_Writer::clear_meta(
                (int) $proposal['wc_product_id'],
                (int) $proposal['wc_variation_id']
            );

            if ( $ok ) {
                // Reset status back to pending
                PMR_DB::update_status( $id, 'pending' );
                $cleared++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success( array( 'cleared' => $cleared, 'failed' => $failed ) );
    }

    // ── AJAX: clear meta by session ──────────────────────────────────────────
    public static function ajax_clear_session_meta(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'No session ID.' ) );
        }

        global $wpdb;
        $table = PMR_DB::table_name();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wc_product_id, wc_variation_id FROM {$table} WHERE session_id = %s AND status = 'applied'",
                $session_id
            ),
            ARRAY_A
        );

        $cleared = 0;
        foreach ( $rows as $row ) {
            if ( PMR_Meta_Writer::clear_meta( (int) $row['wc_product_id'], (int) $row['wc_variation_id'] ) ) {
                $cleared++;
            }
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'pending' WHERE session_id = %s AND status = 'applied'",
            $session_id
        ) );

        wp_send_json_success( array( 'cleared' => $cleared ) );
    }

    // ── AJAX: delete selected proposals by ID ────────────────────────────────
    public static function ajax_delete_selected(): void {
        self::verify_nonce();
        $ids = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No IDs provided.' ) );
        }

        global $wpdb;
        $table        = PMR_DB::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $deleted      = $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids )
        );

        wp_send_json_success( array( 'deleted' => (int) $deleted ) );
    }

    // ── AJAX: delete rejected proposals from DB ──────────────────────────────
    public static function ajax_delete_rejected(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

        global $wpdb;
        $table = PMR_DB::table_name();

        if ( $session_id ) {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE session_id = %s AND status = 'rejected'",
                $session_id
            ) );
        } else {
            $deleted = $wpdb->query( "DELETE FROM {$table} WHERE status = 'rejected'" );
        }

        wp_send_json_success( array( 'deleted' => (int) $deleted ) );
    }

    // ── Render Clear Meta page ───────────────────────────────────────────────
    public static function render_clear_meta(): void {
        include PMR_PLUGIN_DIR . 'admin/views/clear-meta.php';
    }

    // ── AJAX: clear ALL meta from all products in all sessions ───────────────
    public static function ajax_clear_all_meta(): void {
        self::verify_nonce();

        global $wpdb;
        $table = PMR_DB::table_name();

        // Get all unique product/variation pairs that are applied
        $rows = $wpdb->get_results(
            "SELECT wc_product_id, wc_variation_id FROM {$table} WHERE status = 'applied'",
            ARRAY_A
        );

        $cleared = 0;
        $failed  = 0;

        foreach ( $rows as $row ) {
            $ok = PMR_Meta_Writer::clear_meta(
                (int) $row['wc_product_id'],
                (int) $row['wc_variation_id']
            );
            if ( $ok ) { $cleared++; } else { $failed++; }
        }

        // Reset ALL applied statuses back to pending
        $wpdb->query( "UPDATE {$table} SET status = 'pending' WHERE status = 'applied'" );

        wp_send_json_success( array( 'cleared' => $cleared, 'failed' => $failed ) );
    }

    // ── AJAX: status counts ────────────────────────────────────────────────────
    public static function ajax_get_counts(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        wp_send_json_success( PMR_DB::get_status_counts( $session_id ) );
    }

    public static function render_settings(): void {
        $api_key = get_option( 'pmr_printify_api_key', '' );
        $shop_id = get_option( 'pmr_printify_shop_id', '' );
        include PMR_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    private static function verify_nonce(): void {
        if ( ! check_ajax_referer( 'pmr_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
    }

    /** Step 1: Upload CSV and parse it, return preview. */
    public static function ajax_upload_csv(): void {
        self::verify_nonce();

        if ( empty( $_FILES['csv_file'] ) ) {
            wp_send_json_error( array( 'message' => 'No file uploaded.' ) );
        }

        $file = $_FILES['csv_file'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'Upload error: ' . $file['error'] ) );
        }

        $parsed = PMR_CSV_Parser::parse( $file['tmp_name'] );

        // Store parsed rows in a transient so step 2 can use them
        $token = wp_generate_uuid4();
        set_transient( 'pmr_csv_' . $token, $parsed['rows'], HOUR_IN_SECONDS * 2 );

        wp_send_json_success( array(
            'token'    => $token,
            'count'    => count( $parsed['rows'] ),
            'warnings' => $parsed['warnings'],
            'preview'  => array_slice( $parsed['rows'], 0, 5 ),
            'headers'  => $parsed['headers'],
        ) );
    }

    /** Step 2: Run matching engine. */
    public static function ajax_run_match(): void {
        self::verify_nonce();

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Missing CSV token.' ) );
        }

        $csv_rows = get_transient( 'pmr_csv_' . $token );
        if ( ! $csv_rows ) {
            wp_send_json_error( array( 'message' => 'CSV data expired. Please re-upload.' ) );
        }

        $use_api = ! empty( $_POST['use_api'] ) && $_POST['use_api'] === '1';
        $api     = $use_api ? new PMR_Printify_API() : null;

        if ( $use_api && $api && ! $api->has_credentials() ) {
            wp_send_json_error( array( 'message' => 'Printify API key / Shop ID not configured. Go to Settings first.' ) );
        }

        $matcher    = new PMR_Matcher( $csv_rows, $api );
        $proposals  = $matcher->run();
        $session_id = $matcher->get_session_id();

        delete_transient( 'pmr_csv_' . $token );

        $counts = array(
            'total'     => count( $proposals ),
            'matched'   => count( array_filter( $proposals, fn($p) => $p['match_score'] >= 0.65 ) ),
            'unmatched' => count( array_filter( $proposals, fn($p) => $p['match_score'] < 0.65 ) ),
        );

        wp_send_json_success( array(
            'session_id' => $session_id,
            'counts'     => $counts,
            'review_url' => admin_url( 'admin.php?page=pmr-review&session=' . $session_id ),
        ) );
    }

    /** Update status (approve/reject) for one or many proposals. */
    public static function ajax_update_status(): void {
        self::verify_nonce();

        $ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
        $status = sanitize_text_field( $_POST['status'] ?? '' );

        if ( empty( $ids ) || ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid input.' ) );
        }

        PMR_DB::bulk_update_status( $ids, $status );
        wp_send_json_success( array( 'updated' => count( $ids ) ) );
    }

    /** Update a single proposal's Printify fields (manual correction). */
    public static function ajax_update_proposal(): void {
        self::verify_nonce();

        global $wpdb;
        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( array( 'message' => 'Missing ID.' ) );

        $allowed = array(
            'printify_product_id', 'printify_variant_id',
            'printify_blueprint_id', 'printify_provider_id',
            'printify_provider', 'printify_sku',
        );

        $data = array();
        foreach ( $allowed as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $data[ $field ] = sanitize_text_field( $_POST[ $field ] );
            }
        }

        if ( ! empty( $data ) ) {
            $wpdb->update( PMR_DB::table_name(), $data, array( 'id' => $id ) );
        }

        wp_send_json_success( array( 'updated' => $id ) );
    }

    /** Apply all approved proposals from a session to WooCommerce. */
    public static function ajax_apply_approved(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'Missing session ID.' ) );
        }
        // Legacy single-call — redirect to batch with offset 0
        $result = PMR_Meta_Writer::apply_session( $session_id, 0, 9999 );
        wp_send_json_success( $result );
    }

    // ── AJAX: apply in batches with progress ─────────────────────────────────
    public static function ajax_apply_batch(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $batch      = max( 1, min( 100, (int) ( $_POST['batch'] ?? 50 ) ) );

        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'Missing session ID.' ) );
        }

        // Save errors to option for log page
        $result = PMR_Meta_Writer::apply_session( $session_id, $offset, $batch );

        // Append to error log
        if ( ! empty( $result['errors'] ) ) {
            $log = get_option( 'pmr_error_log_' . $session_id, array() );
            $log = array_merge( $log, $result['errors'] );
            update_option( 'pmr_error_log_' . $session_id, $log );
        }

        wp_send_json_success( $result );
    }

    // ── AJAX: get error log ───────────────────────────────────────────────────
    public static function ajax_get_error_log(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $log        = get_option( 'pmr_error_log_' . $session_id, array() );
        wp_send_json_success( array( 'errors' => $log, 'count' => count( $log ) ) );
    }

    // ── AJAX: export CSV ──────────────────────────────────────────────────────
    public static function ajax_export_csv(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $status     = sanitize_text_field( $_POST['status']     ?? 'applied' );

        $proposals = PMR_DB::get_proposals( $session_id, $status );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="pmr-export-' . date('Y-m-d') . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'WC Product ID', 'WC Variation ID', 'WC Title', 'WC SKU', 'Status', 'Confidence', 'Blueprint ID', 'Provider ID', 'Provider', 'Variant ID', 'Printify SKU' ) );

        foreach ( $proposals as $p ) {
            fputcsv( $out, array(
                $p['wc_product_id'],
                $p['wc_variation_id'],
                $p['wc_title'],
                $p['wc_sku'],
                $p['status'],
                round( $p['match_score'] * 100 ) . '%',
                $p['printify_blueprint_id'],
                $p['printify_provider_id'],
                $p['printify_provider'],
                $p['printify_variant_id'],
                $p['printify_sku'],
            ) );
        }
        fclose( $out );
        exit;
    }

    // ── AJAX: auto-approve high confidence ───────────────────────────────────
    public static function ajax_auto_approve(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $threshold  = max( 0.5, min( 1.0, (float) ( $_POST['threshold'] ?? 0.90 ) ) );

        global $wpdb;
        $table   = PMR_DB::table_name();
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'approved' WHERE session_id = %s AND status = 'pending' AND match_score >= %f",
            $session_id, $threshold
        ) );

        wp_send_json_success( array( 'approved' => (int) $updated ) );
    }

    /** Save API settings. */
    public static function ajax_save_settings(): void {
        self::verify_nonce();

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
        $shop_id = sanitize_text_field( $_POST['shop_id'] ?? '' );

        update_option( 'pmr_printify_api_key', $api_key );
        update_option( 'pmr_printify_shop_id', $shop_id );

        wp_send_json_success( array( 'message' => 'Settings saved.' ) );
    }

    /** Test API credentials. */
    public static function ajax_test_api(): void {
        self::verify_nonce();

        $api   = new PMR_Printify_API();
        $shops = $api->get_shops();

        if ( is_wp_error( $shops ) ) {
            wp_send_json_error( array( 'message' => $shops->get_error_message() ) );
        }

        wp_send_json_success( array(
            'shops'   => $shops,
            'message' => sprintf( __( 'Connected! Found %d shop(s).', 'pmr' ), count( $shops ) ),
        ) );
    }

    /** Delete a session and its proposals. */
    public static function ajax_delete_session(): void {
        self::verify_nonce();

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'Missing session ID.' ) );
        }

        PMR_DB::delete_session( $session_id );
        wp_send_json_success( array( 'deleted' => true ) );
    }
}
