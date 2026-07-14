<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pmr-wrap">
    <h1><?php esc_html_e( 'Review & Apply Printify Mapping', 'pmr' ); ?></h1>

    <?php if ( ! $session_id ): ?>
        <div class="notice notice-warning"><p>
            <?php esc_html_e( 'No session selected. Please ', 'pmr' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-import' ) ); ?>"><?php esc_html_e( 'run a matching session', 'pmr' ); ?></a>
            <?php esc_html_e( ' first.', 'pmr' ); ?>
        </p></div>
        <?php return; ?>
    <?php endif; ?>

    <!-- Stats + Bulk Actions -->
    <div class="pmr-review-header">
        <div class="pmr-stats">
            <span class="pmr-stat"><strong id="stat-total"><?php echo (int) $counts['total']; ?></strong><?php esc_html_e( 'Total', 'pmr' ); ?></span>
            <span class="pmr-stat pmr-stat--pending"><strong id="stat-pending"><?php echo (int) $counts['pending']; ?></strong><?php esc_html_e( 'Pending', 'pmr' ); ?></span>
            <span class="pmr-stat pmr-stat--approved"><strong id="stat-approved"><?php echo (int) $counts['approved']; ?></strong><?php esc_html_e( 'Approved', 'pmr' ); ?></span>
            <span class="pmr-stat pmr-stat--rejected"><strong id="stat-rejected"><?php echo (int) $counts['rejected']; ?></strong><?php esc_html_e( 'Rejected', 'pmr' ); ?></span>
            <span class="pmr-stat pmr-stat--applied"><strong id="stat-applied"><?php echo (int) $counts['applied']; ?></strong><?php esc_html_e( 'Applied', 'pmr' ); ?></span>
        </div>
        <div class="pmr-bulk-actions">
            <button class="button" id="pmr-approve-all-high"><?php esc_html_e( 'Approve All High-Confidence (≥90%)', 'pmr' ); ?></button>
            <button class="button" id="pmr-approve-all-visible"><?php esc_html_e( 'Approve All Visible', 'pmr' ); ?></button>
            <button class="button" id="pmr-reject-unmatched"><?php esc_html_e( 'Reject All Unmatched', 'pmr' ); ?></button>
            <button class="button pmr-btn-danger" id="pmr-delete-rejected"><?php esc_html_e( 'Delete All Rejected', 'pmr' ); ?></button>
            <button class="button pmr-btn-blue" id="pmr-fill-unmatched">&#9998; <?php esc_html_e( 'Fill Unmatched', 'pmr' ); ?></button>
            <button class="button pmr-btn-blue" id="pmr-auto-approve">&#10003;&#10003; <?php esc_html_e( 'Auto-Approve ≥90%', 'pmr' ); ?></button>
            <button class="button pmr-btn-green" id="pmr-export-csv">&#8595; <?php esc_html_e( 'Export CSV', 'pmr' ); ?></button>
            <button class="button button-primary" id="pmr-apply-approved" data-session="<?php echo esc_attr( $session_id ); ?>">
                &#10003; <?php esc_html_e( 'Apply Approved to WooCommerce', 'pmr' ); ?>
            </button>
        </div>
        <div class="pmr-bulk-selected" id="pmr-bulk-selected" style="display:none;">
            <strong><span id="pmr-selected-count">0</span> <?php esc_html_e( 'selected:', 'pmr' ); ?></strong>
            <button class="button button-primary" id="pmr-approve-selected">&#10003; <?php esc_html_e( 'Approve Selected', 'pmr' ); ?></button>
            <button class="button" id="pmr-reject-selected">&#10007; <?php esc_html_e( 'Reject Selected', 'pmr' ); ?></button>
            <button class="button pmr-btn-danger" id="pmr-delete-selected">&#x1F5D1; <?php esc_html_e( 'Delete Selected', 'pmr' ); ?></button>
            <button class="button pmr-btn-danger" id="pmr-clear-meta-selected" style="background:#856404 !important; border-color:#856404 !important;">&#8635; <?php esc_html_e( 'Clear Meta (Undo)', 'pmr' ); ?></button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="pmr-filter-bar">
        <label><?php esc_html_e( 'Filter:', 'pmr' ); ?></label>
        <button class="pmr-filter-btn active" data-filter="all"><?php esc_html_e( 'All', 'pmr' ); ?></button>
        <button class="pmr-filter-btn" data-filter="pending"><?php esc_html_e( 'Pending', 'pmr' ); ?></button>
        <button class="pmr-filter-btn" data-filter="approved"><?php esc_html_e( 'Approved', 'pmr' ); ?></button>
        <button class="pmr-filter-btn" data-filter="rejected"><?php esc_html_e( 'Rejected', 'pmr' ); ?></button>
        <button class="pmr-filter-btn" data-filter="unmatched"><?php esc_html_e( 'Unmatched', 'pmr' ); ?></button>
        <button class="pmr-filter-btn" data-filter="applied"><?php esc_html_e( 'Applied', 'pmr' ); ?></button>
        <input type="text" id="pmr-search" placeholder="<?php esc_attr_e( 'Search by product name…', 'pmr' ); ?>" class="pmr-search-input">
        <select id="pmr-per-page">
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
            <option value="200">200 / page</option>
        </select>
    </div>

    <div id="pmr-apply-result" class="pmr-hidden">
        <div class="notice notice-success pmr-inline-notice"><p id="pmr-apply-msg"></p></div>
    </div>

    <div id="pmr-table-loading" style="display:none;">
        <span class="spinner is-active" style="float:none;"></span>
        <span style="margin-left:6px;"><?php esc_html_e( 'Loading…', 'pmr' ); ?></span>
    </div>

    <!-- Table -->
    <div class="pmr-table-scroll">
        <table class="wp-list-table widefat pmr-review-table" id="pmr-review-table">
            <colgroup>
                <col class="col-check">
                <col class="col-product">
                <col class="col-csv">
                <col class="col-conf">
                <col class="col-ids">
                <col class="col-method">
                <col class="col-status">
                <col class="col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="pmr-check-all"></th>
                    <th class="col-product"><?php esc_html_e( 'WC Product', 'pmr' ); ?></th>
                    <th class="col-csv"><?php esc_html_e( 'CSV Match', 'pmr' ); ?></th>
                    <th class="col-conf"><?php esc_html_e( 'Confidence', 'pmr' ); ?></th>
                    <th class="col-ids"><?php esc_html_e( 'Printify IDs', 'pmr' ); ?></th>
                    <th class="col-method"><?php esc_html_e( 'Method', 'pmr' ); ?></th>
                    <th class="col-status"><?php esc_html_e( 'Status', 'pmr' ); ?></th>
                    <th class="col-actions"><?php esc_html_e( 'Actions', 'pmr' ); ?></th>
                </tr>
            </thead>
            <tbody id="pmr-table-body">
                <tr><td colspan="8" style="text-align:center;padding:20px;">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pmr-pagination" id="pmr-pagination">
        <button class="button" id="pmr-prev-page" disabled>← <?php esc_html_e( 'Prev', 'pmr' ); ?></button>
        <span id="pmr-page-info"></span>
        <button class="button" id="pmr-next-page" disabled><?php esc_html_e( 'Next', 'pmr' ); ?> →</button>
        <span id="pmr-total-info"></span>
        <span class="pmr-page-jump">
            <?php esc_html_e( 'Go to page:', 'pmr' ); ?>
            <input type="number" id="pmr-page-jump-input" min="1" value="1" style="width:55px; padding:2px 5px; font-size:12px; border:1px solid #ddd; border-radius:4px;">
            <button class="button" id="pmr-page-jump-btn"><?php esc_html_e( 'Go', 'pmr' ); ?></button>
        </span>
    </div>

    <div class="pmr-review-footer">
        <button class="button button-primary button-large" id="pmr-apply-approved-bottom" data-session="<?php echo esc_attr( $session_id ); ?>">
            <?php esc_html_e( 'Apply Approved Matches to WooCommerce', 'pmr' ); ?>
        </button>
    </div>
</div>
<script>window.PMR_SESSION = <?php echo json_encode( $session_id ); ?>;</script>

<!-- Unmatched Quick Fill Modal -->
<div id="pmr-unmatched-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:99999; overflow-y:auto;">
    <div style="background:#fff; margin:40px auto; max-width:700px; border-radius:8px; padding:24px; position:relative;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Fill Unmatched Product IDs', 'pmr' ); ?></h2>
        <p style="color:#666; font-size:13px;"><?php esc_html_e( 'Enter the Printify IDs for each unmatched product below. All fields save individually — click Save on each row.', 'pmr' ); ?></p>
        <button id="pmr-modal-close" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:20px; cursor:pointer;">✕</button>

        <div id="pmr-unmatched-list" style="margin-top:16px;">
            <p style="color:#999;"><?php esc_html_e( 'Loading unmatched products…', 'pmr' ); ?></p>
        </div>

        <div style="margin-top:16px; padding-top:12px; border-top:1px solid #eee; display:flex; gap:8px;">
            <button class="button button-primary" id="pmr-modal-close-btn"><?php esc_html_e( 'Close', 'pmr' ); ?></button>
        </div>
    </div>
</div>

<!-- Apply Progress Modal -->
<div id="pmr-progress-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.6); z-index:999999;">
    <div style="background:#fff; margin:80px auto; max-width:520px; border-radius:10px; padding:28px; text-align:center;">
        <h2 style="margin-top:0; color:#1d2327;"><?php esc_html_e( 'Applying to WooCommerce…', 'pmr' ); ?></h2>
        <div style="background:#e9ecef; border-radius:20px; height:12px; margin:16px 0; overflow:hidden;">
            <div id="pmr-progress-bar-fill" style="height:100%; width:0%; background:#7952b3; border-radius:20px; transition:width .3s;"></div>
        </div>
        <p id="pmr-progress-text" style="font-size:14px; color:#555; margin:8px 0;">Starting…</p>
        <p id="pmr-progress-stats" style="font-size:12px; color:#888; margin:4px 0;"></p>
        <div id="pmr-progress-done" style="display:none; margin-top:16px;">
            <p style="color:#1e7e34; font-size:15px; font-weight:600;">&#10003; <?php esc_html_e( 'Complete!', 'pmr' ); ?></p>
            <div id="pmr-progress-summary" style="font-size:13px; color:#555; margin:8px 0;"></div>
            <div id="pmr-progress-errors" style="display:none; margin-top:12px; text-align:left; max-height:200px; overflow-y:auto; background:#fff5f5; border:1px solid #f5c6cb; border-radius:6px; padding:10px;"></div>
            <div id="pmr-progress-dupes" style="display:none; margin-top:8px; text-align:left; max-height:150px; overflow-y:auto; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:10px;"></div>
            <button class="button button-primary" id="pmr-progress-close" style="margin-top:14px;"><?php esc_html_e( 'Close', 'pmr' ); ?></button>
        </div>
    </div>
</div>

<!-- Error Log Modal -->
<div id="pmr-errorlog-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:999999; overflow-y:auto;">
    <div style="background:#fff; margin:50px auto; max-width:650px; border-radius:10px; padding:24px; position:relative;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Error Log', 'pmr' ); ?></h2>
        <button id="pmr-errorlog-close" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:20px; cursor:pointer;">✕</button>
        <div id="pmr-errorlog-content"><p style="color:#999;">Loading…</p></div>
        <button class="button" id="pmr-errorlog-close-btn" style="margin-top:12px;"><?php esc_html_e( 'Close', 'pmr' ); ?></button>
    </div>
</div>
