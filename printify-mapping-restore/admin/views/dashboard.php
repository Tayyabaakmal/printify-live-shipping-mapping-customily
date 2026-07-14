<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pmr-wrap">
    <h1><?php esc_html_e( 'Printify Mapping Restore', 'pmr' ); ?></h1>
    <p class="pmr-tagline"><?php esc_html_e( 'Restore missing Printify metadata to your existing WooCommerce products — without reimporting or recreating anything.', 'pmr' ); ?></p>

    <div class="pmr-status-bar">
        <?php
        $api_key = get_option( 'pmr_printify_api_key', '' );
        $shop_id = get_option( 'pmr_printify_shop_id', '' );
        $configured = $api_key && $shop_id;
        ?>
        <span class="pmr-badge <?php echo $configured ? 'pmr-badge--green' : 'pmr-badge--orange'; ?>">
            <?php echo $configured
                ? esc_html__( '✓ Printify API Connected', 'pmr' )
                : esc_html__( '⚠ API Not Configured', 'pmr' ); ?>
        </span>
        <?php if ( ! $configured ): ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-settings' ) ); ?>" class="button button-secondary pmr-btn-small">
                <?php esc_html_e( 'Configure API', 'pmr' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="pmr-cards">
        <div class="pmr-card">
            <div class="pmr-card__icon dashicons dashicons-upload"></div>
            <h3><?php esc_html_e( 'Step 1: Import CSV', 'pmr' ); ?></h3>
            <p><?php esc_html_e( 'Upload your Printify product export CSV containing Blueprint IDs, Provider IDs, Variant IDs and SKUs.', 'pmr' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-import' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Import & Match', 'pmr' ); ?>
            </a>
        </div>

        <div class="pmr-card">
            <div class="pmr-card__icon dashicons dashicons-visibility"></div>
            <h3><?php esc_html_e( 'Step 2: Review Matches', 'pmr' ); ?></h3>
            <p><?php esc_html_e( 'Inspect proposed matches, approve or reject each one, and manually correct any mismatches.', 'pmr' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-review' ) ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Review Matches', 'pmr' ); ?>
            </a>
        </div>

        <div class="pmr-card">
            <div class="pmr-card__icon dashicons dashicons-yes-alt"></div>
            <h3><?php esc_html_e( 'Step 3: Apply Metadata', 'pmr' ); ?></h3>
            <p><?php esc_html_e( 'Write approved Printify metadata to your existing WooCommerce products — no products created.', 'pmr' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-review' ) ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Apply Approved', 'pmr' ); ?>
            </a>
        </div>
    </div>

    <?php if ( ! empty( $sessions ) ): ?>
    <h2><?php esc_html_e( 'Previous Sessions', 'pmr' ); ?></h2>
    <table class="wp-list-table widefat fixed striped pmr-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Session ID', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Created', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Total', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Approved', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Applied', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Rejected', 'pmr' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'pmr' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $sessions as $s ): ?>
            <tr>
                <td><code><?php echo esc_html( substr( $s['session_id'], 0, 12 ) . '…' ); ?></code></td>
                <td><?php echo esc_html( wp_date( get_option('date_format') . ' ' . get_option('time_format'), strtotime( $s['created_at'] ) ) ); ?></td>
                <td><?php echo intval( $s['total'] ); ?></td>
                <td><?php echo intval( $s['approved'] ); ?></td>
                <td><?php echo intval( $s['applied'] ); ?></td>
                <td><?php echo intval( $s['rejected'] ); ?></td>
                <td>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pmr-review&session=' . urlencode( $s['session_id'] ) ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Review', 'pmr' ); ?>
                    </a>
                    <button class="button button-small pmr-delete-session"
                            data-session="<?php echo esc_attr( $s['session_id'] ); ?>">
                        <?php esc_html_e( 'Delete', 'pmr' ); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
