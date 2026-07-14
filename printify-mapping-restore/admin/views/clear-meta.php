<?php defined( 'ABSPATH' ) || exit;

global $wpdb;
$table         = PMR_DB::table_name();
$total_applied = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'applied'" );
$total_all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
?>
<div class="wrap pmr-wrap">
    <h1><?php esc_html_e( 'Clear Printify Meta', 'pmr' ); ?></h1>
    <p class="pmr-tagline"><?php esc_html_e( 'Remove Printify metadata from WooCommerce products and reset their mapping status.', 'pmr' ); ?></p>

    <!-- Stats -->
    <div class="pmr-stats" style="margin-bottom:20px;">
        <span class="pmr-stat pmr-stat--applied">
            <strong><?php echo $total_applied; ?></strong>
            <?php esc_html_e( 'Applied (meta written)', 'pmr' ); ?>
        </span>
        <span class="pmr-stat">
            <strong><?php echo $total_all; ?></strong>
            <?php esc_html_e( 'Total proposals', 'pmr' ); ?>
        </span>
    </div>

    <!-- Clear All Applied -->
    <div class="pmr-section">
        <h2><?php esc_html_e( 'Clear All Applied Meta', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'Removes Printify metadata from all WooCommerce products that have been applied, and resets their status back to Pending.', 'pmr' ); ?></p>
        <ul style="list-style:disc; margin-left:20px; color:#555; font-size:13px; margin-bottom:16px;">
            <li><?php esc_html_e( 'Deletes: _printify_product_id, _printify_blueprint_id, _printify_provider_id, _printify_provider, _printify_variant_id, _printify_sku, _printify_connect', 'pmr' ); ?></li>
            <li><?php esc_html_e( 'Resets status: applied → pending', 'pmr' ); ?></li>
            <li style="color:#c82333;"><strong><?php esc_html_e( 'This cannot be undone.', 'pmr' ); ?></strong></li>
        </ul>
        <button class="button pmr-btn-danger" id="pmr-clear-all-meta" <?php echo $total_applied === 0 ? 'disabled' : ''; ?>>
            &#x1F5D1; <?php printf( esc_html__( 'Clear Meta from All %d Applied Products', 'pmr' ), $total_applied ); ?>
        </button>
        <div id="pmr-clear-all-result" class="pmr-hidden" style="margin-top:12px;">
            <div class="notice pmr-inline-notice" id="pmr-clear-all-notice"></div>
        </div>
    </div>

    <!-- Clear by Session -->
    <div class="pmr-section">
        <h2><?php esc_html_e( 'Clear Meta by Session', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'Select a specific session to clear only that session\'s applied products.', 'pmr' ); ?></p>
        <?php
        $sessions = $wpdb->get_results(
            "SELECT DISTINCT session_id, COUNT(*) as cnt
             FROM {$table}
             WHERE status = 'applied'
             GROUP BY session_id",
            ARRAY_A
        );
        if ( empty( $sessions ) ):
        ?>
            <p class="pmr-muted"><?php esc_html_e( 'No sessions with applied products found.', 'pmr' ); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed" style="max-width:600px; margin-bottom:12px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Session ID', 'pmr' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Applied', 'pmr' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Action', 'pmr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sessions as $s ): ?>
                    <tr>
                        <td><code style="font-size:11px;"><?php echo esc_html( $s['session_id'] ); ?></code></td>
                        <td><?php echo (int) $s['cnt']; ?></td>
                        <td>
                            <button class="button button-small pmr-btn-danger pmr-clear-session-meta"
                                    data-session="<?php echo esc_attr( $s['session_id'] ); ?>">
                                &#x1F5D1; <?php esc_html_e( 'Clear', 'pmr' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="pmr-session-clear-result" class="pmr-hidden">
                <div class="notice pmr-inline-notice" id="pmr-session-clear-notice"></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info -->
    <div class="pmr-section">
        <h2><?php esc_html_e( 'What Gets Deleted', 'pmr' ); ?></h2>
        <table class="wp-list-table widefat striped" style="max-width:650px;">
            <thead><tr>
                <th><?php esc_html_e( 'Meta Key', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Stored On', 'pmr' ); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><code>_printify_product_id</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_blueprint_id</code></td><td><?php esc_html_e( 'Parent + variation', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_provider_id</code></td><td><?php esc_html_e( 'Parent + variation', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_provider</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_variant_id</code></td><td><?php esc_html_e( 'Variation / simple', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_sku</code></td><td><?php esc_html_e( 'Variation / simple', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_connect</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
