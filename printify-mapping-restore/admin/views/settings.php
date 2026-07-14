<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pmr-wrap">
    <h1><?php esc_html_e( 'Printify Mapping Restore — Settings', 'pmr' ); ?></h1>

    <div class="pmr-section">
        <h2><?php esc_html_e( 'Printify API Credentials', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'Your API key is used to fetch your Printify product list, enabling the most accurate matching strategy. It is also required by the custom shipping method.', 'pmr' ); ?></p>
        <p>
            <a href="https://printify.com/app/account/api" target="_blank">
                <?php esc_html_e( '→ Generate a Printify API Token', 'pmr' ); ?>
            </a> —
            <?php esc_html_e( 'navigate to My Profile → Connections → Generate Token.', 'pmr' ); ?>
        </p>

        <table class="form-table pmr-settings-table">
            <tr>
                <th><label for="pmr-api-key"><?php esc_html_e( 'Printify API Key', 'pmr' ); ?></label></th>
                <td>
                    <input type="password" id="pmr-api-key" class="regular-text"
                           value="<?php echo esc_attr( $api_key ); ?>"
                           autocomplete="off" placeholder="eyJ0eXAiOiJKV1Q…">
                    <p class="description"><?php esc_html_e( 'Stored encrypted in the WordPress options table. Never shared.', 'pmr' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pmr-shop-id"><?php esc_html_e( 'Printify Shop ID', 'pmr' ); ?></label></th>
                <td>
                    <input type="text" id="pmr-shop-id" class="regular-text"
                           value="<?php echo esc_attr( $shop_id ); ?>"
                           placeholder="12345">
                    <p class="description"><?php esc_html_e( 'Found in your Printify URL: printify.com/app/store/XXXXXXXX/products', 'pmr' ); ?></p>
                </td>
            </tr>
        </table>

        <div class="pmr-settings-actions">
            <button class="button button-primary" id="pmr-save-settings">
                <?php esc_html_e( 'Save Settings', 'pmr' ); ?>
            </button>
            <button class="button" id="pmr-test-api" style="margin-left:8px">
                <?php esc_html_e( 'Test API Connection', 'pmr' ); ?>
            </button>
        </div>

        <div id="pmr-settings-result" class="pmr-hidden">
            <div class="pmr-inline-notice" id="pmr-settings-notice"></div>
        </div>
    </div>

    <div class="pmr-section">
        <h2><?php esc_html_e( 'Custom Shipping Method', 'pmr' ); ?></h2>
        <p><?php printf(
            wp_kses( __( 'After restoring metadata, first test whether the <strong>official Printify for WooCommerce plugin</strong> calculates shipping correctly. If it does, you\'re done — no extra config needed.', 'pmr' ), array( 'strong' => array() ) )
        ); ?></p>
        <p><?php esc_html_e( 'If the official plugin still fails, this plugin registers a custom shipping method "Printify Shipping (Mapping Restore)" that queries the Printify Catalog API for live rates using the metadata we restore.', 'pmr' ); ?></p>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" class="button button-secondary">
                <?php esc_html_e( '→ Configure Shipping Zones (WooCommerce)', 'pmr' ); ?>
            </a>
        </p>
        <p class="description"><?php esc_html_e( 'Add "Printify Shipping (Mapping Restore)" to a shipping zone to enable it. Configure markup and fallback rates in the method settings.', 'pmr' ); ?></p>
    </div>

    <div class="pmr-section">
        <h2><?php esc_html_e( 'Meta Keys Written', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'The following WordPress post meta keys are written to your products when you apply approved matches:', 'pmr' ); ?></p>
        <table class="wp-list-table widefat striped pmr-table" style="max-width:700px">
            <thead><tr><th><?php esc_html_e( 'Meta Key', 'pmr' ); ?></th><th><?php esc_html_e( 'Stored On', 'pmr' ); ?></th><th><?php esc_html_e( 'Description', 'pmr' ); ?></th></tr></thead>
            <tbody>
                <tr><td><code>_printify_product_id</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td><td><?php esc_html_e( 'Printify product ID', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_blueprint_id</code></td><td><?php esc_html_e( 'Parent + variation', 'pmr' ); ?></td><td><?php esc_html_e( 'Catalog blueprint ID', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_provider_id</code></td><td><?php esc_html_e( 'Parent + variation', 'pmr' ); ?></td><td><?php esc_html_e( 'Print provider ID', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_provider</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td><td><?php esc_html_e( 'Provider name (human-readable)', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_variant_id</code></td><td><?php esc_html_e( 'Variation / simple', 'pmr' ); ?></td><td><?php esc_html_e( 'Printify variant ID', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_sku</code></td><td><?php esc_html_e( 'Variation / simple', 'pmr' ); ?></td><td><?php esc_html_e( 'Printify\'s SKU', 'pmr' ); ?></td></tr>
                <tr><td><code>_printify_connect</code></td><td><?php esc_html_e( 'Parent product', 'pmr' ); ?></td><td><?php esc_html_e( 'Set to "1" — marks product as Printify-connected', 'pmr' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="pmr-section pmr-section--danger">
        <h2><?php esc_html_e( 'Clear Printify Meta from WooCommerce Products', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'This will remove all Printify metadata from every WooCommerce product and variation, and reset their mapping status back to Pending. Use this if you applied mappings by mistake and want to start fresh.', 'pmr' ); ?></p>
        <p><strong><?php esc_html_e( 'This cannot be undone. All products will lose their Printify connection.', 'pmr' ); ?></strong></p>
        <button class="button pmr-btn-danger" id="pmr-clear-all-meta">
            &#x1F5D1; <?php esc_html_e( 'Clear ALL Printify Meta from All Products', 'pmr' ); ?>
        </button>
        <div id="pmr-clear-all-result" class="pmr-hidden" style="margin-top:10px;">
            <div class="pmr-inline-notice" id="pmr-clear-all-notice"></div>
        </div>
    </div>

    <div class="pmr-section pmr-section--danger">
        <h2><?php esc_html_e( 'Danger Zone', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'Uninstalling this plugin does NOT remove meta written to products. To remove all plugin data (sessions, settings), use the button below. Product meta must be removed manually if needed.', 'pmr' ); ?></p>
        <button class="button pmr-btn-danger" id="pmr-uninstall-data"
                data-confirm="<?php esc_attr_e( 'This will delete all plugin session data and settings. Product meta already written to WooCommerce will remain. Are you sure?', 'pmr' ); ?>">
            <?php esc_html_e( 'Delete All Plugin Data', 'pmr' ); ?>
        </button>
    </div>
</div>
