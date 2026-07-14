<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pmr-wrap">
    <h1><?php esc_html_e( 'Import Printify CSV & Run Matching', 'pmr' ); ?></h1>

    <div class="pmr-section">
        <h2><?php esc_html_e( 'Step 1: Upload Your Printify CSV Export', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'Export your products from Printify (Products → Export) and upload the CSV here. Required columns: Variant ID, Blueprint ID, Print Provider ID. Optional but helpful: SKU, Product Title, Variant Title.', 'pmr' ); ?></p>

        <div class="pmr-upload-area" id="pmr-upload-area">
            <div class="pmr-upload-area__inner">
                <span class="dashicons dashicons-upload pmr-upload-icon"></span>
                <p><?php esc_html_e( 'Drag & drop your CSV, or click to browse', 'pmr' ); ?></p>
                <input type="file" id="pmr-csv-file" accept=".csv,.txt" class="pmr-file-input">
                <button class="button button-primary" id="pmr-choose-file"><?php esc_html_e( 'Choose File', 'pmr' ); ?></button>
            </div>
        </div>

        <div id="pmr-upload-result" class="pmr-hidden">
            <div class="notice notice-success pmr-inline-notice">
                <p id="pmr-upload-summary"></p>
            </div>
            <div id="pmr-warnings-box" class="pmr-hidden">
                <div class="notice notice-warning pmr-inline-notice">
                    <ul id="pmr-warnings-list"></ul>
                </div>
            </div>
            <h3><?php esc_html_e( 'CSV Preview (first 5 rows)', 'pmr' ); ?></h3>
            <div class="pmr-table-scroll">
                <table class="wp-list-table widefat fixed striped" id="pmr-preview-table"></table>
            </div>
        </div>
    </div>

    <div class="pmr-section" id="pmr-match-section" style="display:none">
        <h2><?php esc_html_e( 'Step 2: Run Matching Engine', 'pmr' ); ?></h2>
        <p><?php esc_html_e( 'The matching engine will compare your WooCommerce products to the CSV using multiple strategies: exact SKU match, Printify API lookup, and fuzzy title matching.', 'pmr' ); ?></p>

        <div class="pmr-options">
            <label class="pmr-checkbox-label">
                <input type="checkbox" id="pmr-use-api" value="1"
                    <?php checked( get_option( 'pmr_printify_api_key' ) && get_option( 'pmr_printify_shop_id' ) ); ?>>
                <?php esc_html_e( 'Use Printify API to improve matches (requires API key in Settings)', 'pmr' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'The API lookup matches products by external ID — the most reliable method. Recommended.', 'pmr' ); ?></p>
        </div>

        <button class="button button-primary button-large" id="pmr-run-match">
            <span class="dashicons dashicons-controls-play"></span>
            <?php esc_html_e( 'Run Matching', 'pmr' ); ?>
        </button>

        <div id="pmr-match-progress" class="pmr-hidden">
            <div class="pmr-progress-bar"><div class="pmr-progress-bar__fill" id="pmr-progress-fill"></div></div>
            <p id="pmr-progress-label"><?php esc_html_e( 'Matching in progress…', 'pmr' ); ?></p>
        </div>
    </div>

    <div class="pmr-section pmr-hidden" id="pmr-match-result">
        <div class="notice notice-success pmr-inline-notice">
            <p id="pmr-match-summary"></p>
        </div>
        <a href="#" id="pmr-goto-review" class="button button-primary button-large">
            <?php esc_html_e( 'Review Matches →', 'pmr' ); ?>
        </a>
    </div>

    <input type="hidden" id="pmr-csv-token" value="">
</div>
