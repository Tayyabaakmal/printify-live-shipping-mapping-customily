/* Printify Smart Mapping & Shipping — Admin JS */
(function ($) {
    'use strict';

    var PMRData = window.PMR || {};

    // ── Utility ────────────────────────────────────────────────────────────────
    function ajax(action, data, successFn, errorFn) {
        data.action = action;
        data.nonce  = PMRData.nonce;
        $.post(PMRData.ajaxurl, data, function (res) {
            if (res.success) {
                successFn && successFn(res.data);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : 'Unknown error.';
                errorFn ? errorFn(msg) : alert('Error: ' + msg);
            }
        }, 'json').fail(function (xhr) {
            var msg = 'Request failed (HTTP ' + xhr.status + ')';
            errorFn ? errorFn(msg) : alert(msg);
        });
    }

    function notice(el, msg, type) {
        el.removeClass('notice-success notice-error notice-warning')
          .addClass('notice notice-' + (type || 'success'))
          .html('<p>' + msg + '</p>')
          .removeClass('pmr-hidden');
    }

    // ── Import Page ───────────────────────────────────────────────────────────
    if ($('#pmr-upload-area').length) {

        var $area   = $('#pmr-upload-area');
        var $input  = $('#pmr-csv-file');
        var $result = $('#pmr-upload-result');
        var $match  = $('#pmr-match-section');
        var $mres   = $('#pmr-match-result');

        $('#pmr-choose-file').on('click', function (e) {
            e.preventDefault();
            $input.trigger('click');
        });

        $area.on('click', function () { $input.trigger('click'); });
        $area.on('dragover dragleave', function (e) {
            e.preventDefault();
            $(this).toggleClass('dragging', e.type === 'dragover');
        });
        $area.on('drop', function (e) {
            e.preventDefault();
            $(this).removeClass('dragging');
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) uploadFile(files[0]);
        });
        $input.on('change', function () {
            if (this.files.length) uploadFile(this.files[0]);
        });

        function uploadFile(file) {
            var fd = new FormData();
            fd.append('action', 'pmr_upload_csv');
            fd.append('nonce',  PMRData.nonce);
            fd.append('csv_file', file);
            $area.find('.pmr-upload-area__inner p').text('Uploading…');
            $.ajax({
                url: PMRData.ajaxurl, type: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json'
            }).done(function (res) {
                if (!res.success) { alert('Upload error: ' + (res.data && res.data.message)); return; }
                var d = res.data;
                $('#pmr-csv-token').val(d.token);
                $('#pmr-upload-summary').text('✓ Uploaded: ' + d.count + ' rows found in CSV.');
                if (d.warnings && d.warnings.length) {
                    var $wl = $('#pmr-warnings-list').empty();
                    d.warnings.forEach(function (w) { $wl.append('<li>' + w + '</li>'); });
                    $('#pmr-warnings-box').removeClass('pmr-hidden');
                } else {
                    $('#pmr-warnings-box').addClass('pmr-hidden');
                }
                if (d.preview && d.preview.length) {
                    var keys  = Object.keys(d.preview[0]).filter(function (k) { return k !== '_csv_line'; });
                    var thead = '<thead><tr>' + keys.map(function (k) { return '<th>' + k + '</th>'; }).join('') + '</tr></thead>';
                    var rows  = d.preview.map(function (row) {
                        return '<tr>' + keys.map(function (k) { return '<td>' + (row[k] || '—') + '</td>'; }).join('') + '</tr>';
                    }).join('');
                    $('#pmr-preview-table').html(thead + '<tbody>' + rows + '</tbody>');
                }
                $result.removeClass('pmr-hidden');
                $match.show();
                $area.find('.pmr-upload-area__inner p').text('File uploaded: ' + file.name);
            }).fail(function () { alert('Upload failed.'); });
        }

        $('#pmr-run-match').on('click', function () {
            var token = $('#pmr-csv-token').val();
            if (!token) { alert('Please upload a CSV first.'); return; }
            var $btn = $(this);
            $btn.prop('disabled', true).text(PMRData.strings.running);
            $('#pmr-match-progress').removeClass('pmr-hidden');
            $mres.addClass('pmr-hidden');
            ajax('pmr_run_match', {
                token:   token,
                use_api: $('#pmr-use-api').is(':checked') ? '1' : '0'
            }, function (data) {
                $btn.prop('disabled', false).text(PMRData.strings.done);
                $('#pmr-match-progress').addClass('pmr-hidden');
                var c = data.counts;
                $('#pmr-match-summary').text('Matching complete! ' + c.total + ' proposals created. ' + c.matched + ' matched, ' + c.unmatched + ' unmatched.');
                $('#pmr-goto-review').attr('href', data.review_url);
                $mres.removeClass('pmr-hidden');
            }, function (msg) {
                $btn.prop('disabled', false).text('Run Matching');
                $('#pmr-match-progress').addClass('pmr-hidden');
                alert('Matching error: ' + msg);
            });
        });
    }

    // ── Review Page ───────────────────────────────────────────────────────────
    if ($('#pmr-review-table').length) {

        var sessionId   = PMRData.session_id || window.PMR_SESSION || '';
        var currentPage = 1;
        var totalPages  = 1;
        var totalRows   = 0;
        var currentFilter = 'all';
        var currentSearch = '';
        var perPage     = 50;
        var searchTimer = null;
        var loading     = false;

        // ── Load rows via AJAX ─────────────────────────────────────────────
        function loadPage(page) {
            if (loading) return;
            loading = true;
            currentPage = page;

            $('#pmr-table-loading').show();
            $('#pmr-review-table').css('opacity', 0.4);
            $('#pmr-prev-page, #pmr-next-page').prop('disabled', true);

            ajax('pmr_get_proposals', {
                session_id : sessionId,
                status     : currentFilter,
                search     : currentSearch,
                per_page   : perPage,
                page       : page
            }, function (data) {
                totalPages = data.total_pages;
                totalRows  = data.total;
                renderRows(data.rows);
                updatePagination();
                $('#pmr-table-loading').hide();
                $('#pmr-review-table').css('opacity', 1);
                loading = false;
            }, function (msg) {
                $('#pmr-table-loading').hide();
                $('#pmr-review-table').css('opacity', 1);
                loading = false;
                alert('Load error: ' + msg);
            });
        }

        // ── Render rows ────────────────────────────────────────────────────
        function renderRows(rows) {
            var $tbody = $('#pmr-table-body');
            if (!rows || !rows.length) {
                $tbody.html('<tr><td colspan="9" style="text-align:center;padding:20px;">No results found.</td></tr>');
                return;
            }

            var html = '';
            rows.forEach(function (p) {
                var score   = parseFloat(p.match_score);
                var pct     = Math.round(score * 100);
                var rowCls  = '';
                if (p.status === 'applied')       rowCls = 'pmr-row--applied';
                else if (p.status === 'approved') rowCls = 'pmr-row--approved';
                else if (p.status === 'rejected') rowCls = 'pmr-row--rejected';
                else if (pct < 65)                rowCls = 'pmr-row--unmatched';

                var confCls = pct >= 90 ? 'pmr-conf--high' : (pct >= 65 ? 'pmr-conf--med' : 'pmr-conf--low');

                var idFields = [
                    ['printify_product_id',   'Product ID'],
                    ['printify_variant_id',   'Variant ID'],
                    ['printify_blueprint_id', 'Blueprint ID'],
                    ['printify_provider_id',  'Provider ID'],
                    ['printify_provider',     'Provider'],
                    ['printify_sku',          'Printify SKU']
                ];

                // Summary line shown by default
                var variantVal   = p['printify_variant_id']   || '—';
                var blueprintVal = p['printify_blueprint_id'] || '—';
                var providerVal  = p['printify_provider']     || '—';
                var summaryText  = 'V:' + variantVal + ' / B:' + blueprintVal + ' / ' + providerVal;

                var idHtml = idFields.map(function (f) {
                    var val = p[f[0]] || '';
                    return '<div class="pmr-id-row">' +
                        '<span class="pmr-id-label">' + f[1] + ':</span>' +
                        '<input type="text" class="pmr-id-input" data-field="' + f[0] + '" value="' + escAttr(val) + '" placeholder="—">' +
                        '</div>';
                }).join('');

                html += '<tr class="pmr-proposal-row ' + rowCls + '" ' +
                    'data-id="' + parseInt(p.id) + '" ' +
                    'data-status="' + escAttr(p.status) + '" ' +
                    'data-score="' + score + '" ' +
                    'data-title="' + escAttr((p.wc_title || '').toLowerCase()) + '">' +

                    '<td class="col-check"><input type="checkbox" class="pmr-row-check" value="' + parseInt(p.id) + '"></td>' +

                    '<td class="col-product">' +
                    '<a href="' + escAttr(p.edit_url || '#') + '" target="_blank">' + escHtml(p.wc_title) + '</a>' +
                    '<small class="pmr-muted">ID: ' + parseInt(p.wc_product_id) +
                    (p.wc_variation_id ? ' / Var: ' + parseInt(p.wc_variation_id) : '') + '</small>' +
                    (p.has_existing ? '<span class="pmr-badge pmr-badge--blue">Has existing meta</span>' : '') +
                    '</td>' +

                    '<td class="col-csv">' + (p.csv_row_title ? escHtml(p.csv_row_title) : '<em class="pmr-muted">No match</em>') + '</td>' +

                    '<td class="col-conf"><div class="pmr-confidence">' +
                    '<div class="pmr-confidence__bar" style="--pct:' + pct + '%"></div>' +
                    '<span class="pmr-confidence__label ' + confCls + '">' + pct + '%</span>' +
                    '</div></td>' +

                    '<td class="col-ids">' +
                    '<span class="pmr-ids-summary">' + escHtml(summaryText) + '</span>' +
                    '<button class="button button-small pmr-ids-toggle">Edit</button>' +
                    '<div class="pmr-id-fields pmr-hidden" data-id="' + parseInt(p.id) + '">' +
                    idHtml +
                    '<button class="button button-small pmr-save-ids">Save</button>' +
                    '</div>' +
                    '</td>' +

                    '<td class="col-method"><span class="pmr-method-badge">' + escHtml(p.match_method || '') + '</span></td>' +

                    '<td class="col-status"><span class="pmr-status-badge pmr-status--' + escAttr(p.status) + '">' +
                    ucfirst(p.status) + '</span></td>' +

                    '<td class="col-actions">' +
                    '<div class="pmr-actions-cell">' +
                    '<button class="button button-small button-primary pmr-action-btn" data-action="approved" data-id="' + parseInt(p.id) + '" title="Approve">&#10003;</button>' +
                    '<button class="button button-small pmr-action-btn" data-action="rejected" data-id="' + parseInt(p.id) + '" title="Reject">&#10007;</button>' +
                    '<button class="button button-small pmr-action-btn" data-action="pending" data-id="' + parseInt(p.id) + '" title="Pending">&#63;</button>' +
                    '</div>' +
                    '</td>' +
                    '</tr>';
            });

            $tbody.html(html);
            updateSelectionCount();
        }

        // ── Pagination controls ────────────────────────────────────────────
        function updatePagination() {
            $('#pmr-page-info').text('Page ' + currentPage + ' of ' + totalPages);
            $('#pmr-total-info').text('(' + totalRows + ' total)');
            $('#pmr-prev-page').prop('disabled', currentPage <= 1);
            $('#pmr-next-page').prop('disabled', currentPage >= totalPages);
            $('#pmr-page-jump-input').attr('max', totalPages).val(currentPage);
        }

        $('#pmr-prev-page').on('click', function () { if (currentPage > 1) loadPage(currentPage - 1); });
        $('#pmr-next-page').on('click', function () { if (currentPage < totalPages) loadPage(currentPage + 1); });

        // Page jump
        $('#pmr-page-jump-btn').on('click', function () {
            var p = parseInt($('#pmr-page-jump-input').val());
            if (p >= 1 && p <= totalPages) loadPage(p);
        });
        $('#pmr-page-jump-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                var p = parseInt($(this).val());
                if (p >= 1 && p <= totalPages) loadPage(p);
            }
        });

        // ── Filter buttons ─────────────────────────────────────────────────
        $('.pmr-filter-btn').on('click', function () {
            $('.pmr-filter-btn').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('filter');
            currentPage   = 1;
            loadPage(1);
        });

        // ── Search ─────────────────────────────────────────────────────────
        $('#pmr-search').on('input', function () {
            clearTimeout(searchTimer);
            var q = $(this).val();
            searchTimer = setTimeout(function () {
                currentSearch = q;
                currentPage   = 1;
                loadPage(1);
            }, 400);
        });

        // ── Per page ───────────────────────────────────────────────────────
        $('#pmr-per-page').on('change', function () {
            perPage     = parseInt($(this).val());
            currentPage = 1;
            loadPage(1);
        });

        // ── Single row approve/reject ──────────────────────────────────────
        $(document).on('click', '.pmr-action-btn', function () {
            var $btn   = $(this);
            var id     = parseInt($btn.data('id'));
            var action = $btn.data('action');
            var $row   = $btn.closest('tr');

            // Immediate UI feedback — don't wait for AJAX
            $row.attr('data-status', action)
                .removeClass('pmr-row--approved pmr-row--rejected pmr-row--applied pmr-row--unmatched')
                .addClass('pmr-row--' + action);
            $row.find('.pmr-status-badge')
                .attr('class', 'pmr-status-badge pmr-status--' + action)
                .text(ucfirst(action));

            ajax('pmr_update_status', { ids: [id], status: action }, function () {
                fetchAndUpdateCounts();
            });
        });

        // ── Checkbox selection ─────────────────────────────────────────────
        $('#pmr-check-all').on('change', function () {
            var checked = $(this).is(':checked');
            $('.pmr-row-check').prop('checked', checked);
            updateSelectionCount();
        });

        $(document).on('change', '.pmr-row-check', function () {
            updateSelectionCount();
        });

        function updateSelectionCount() {
            var count = $('.pmr-row-check:checked').length;
            $('#pmr-selected-count').text(count);
            $('#pmr-bulk-selected').toggle(count > 0);
        }

        // ── Delete Selected ───────────────────────────────────────────────────────
        $('#pmr-delete-selected').on('click', function () {
            var ids = getSelectedIds(true);
            if (!ids.length) { alert('No rows selected.'); return; }
            if (!confirm('Permanently DELETE ' + ids.length + ' selected proposal(s)?\n\nThis cannot be undone.')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Deleting…');
            ajax('pmr_delete_selected', { ids: ids }, function (data) {
                $btn.prop('disabled', false).html('&#x1F5D1; Delete Selected');
                ids.forEach(function (id) {
                    $('[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
                });
                $('.pmr-row-check').prop('checked', false);
                $('#pmr-check-all').prop('checked', false);
                updateSelectionCount();
                fetchAndUpdateCounts();
            }, function (msg) {
                $btn.prop('disabled', false).html('&#x1F5D1; Delete Selected');
                alert('Error: ' + msg);
            });
        });

        // ── Clear Meta (Undo) Selected ────────────────────────────────────────
        $('#pmr-clear-meta-selected').on('click', function () {
            var ids = getSelectedIds(true);
            if (!ids.length) { alert('No rows selected.'); return; }
            if (!confirm('This will DELETE all Printify meta from ' + ids.length + ' WooCommerce product(s) and reset status to Pending.\n\nAre you sure?')) return;

            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing…');

            ajax('pmr_clear_meta', { ids: ids }, function (data) {
                $btn.prop('disabled', false).html('&#x1F5D1; Clear Meta (Undo)');
                alert('Cleared: ' + data.cleared + ' product(s).' + (data.failed ? ' Failed: ' + data.failed : ''));

                ids.forEach(function (id) {
                    var $row = $('[data-id="' + id + '"]');
                    $row.attr('data-status', 'pending')
                        .removeClass('pmr-row--approved pmr-row--rejected pmr-row--applied pmr-row--unmatched');
                    $row.find('.pmr-status-badge')
                        .attr('class', 'pmr-status-badge pmr-status--pending')
                        .text('Pending');
                    $row.find('.pmr-badge--blue').remove();
                });

                $('.pmr-row-check').prop('checked', false);
                $('#pmr-check-all').prop('checked', false);
                updateSelectionCount();
                fetchAndUpdateCounts();
            }, function (msg) {
                $btn.prop('disabled', false).html('&#x1F5D1; Clear Meta (Undo)');
                alert('Error: ' + msg);
            });
        });

        // ── Approve / Reject Selected ──────────────────────────────────────
        $('#pmr-approve-selected').on('click', function () {
            var ids = getSelectedIds();
            if (!ids.length) { alert('No rows selected.'); return; }
            bulkUpdateStatus(ids, 'approved');
        });

        $('#pmr-reject-selected').on('click', function () {
            var ids = getSelectedIds();
            if (!ids.length) { alert('No rows selected.'); return; }
            if (!confirm('Reject ' + ids.length + ' selected items?')) return;
            bulkUpdateStatus(ids, 'rejected');
        });

        function getSelectedIds(includeApplied) {
            var ids = [];
            $('.pmr-row-check:checked').each(function () {
                var $row = $(this).closest('.pmr-proposal-row');
                if (includeApplied || $row.data('status') !== 'applied') {
                    ids.push(parseInt($row.data('id')));
                }
            });
            return ids;
        }

        // ── Delete Selected ────────────────────────────────────────────────────
        $('#pmr-delete-selected').on('click', function () {
            var ids = getSelectedIds(true);
            if (!ids.length) { alert('No rows selected.'); return; }
            if (!confirm('Permanently DELETE ' + ids.length + ' selected proposal(s) from the database?\n\nThis cannot be undone.')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Deleting…');
            ajax('pmr_delete_selected', { ids: ids }, function (data) {
                $btn.prop('disabled', false).html('&#x274C; Delete Selected');
                // Remove rows from table
                ids.forEach(function (id) {
                    $('[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
                });
                $('.pmr-row-check').prop('checked', false);
                $('#pmr-check-all').prop('checked', false);
                updateSelectionCount();
                fetchAndUpdateCounts();
            }, function (msg) {
                $btn.prop('disabled', false).html('&#x274C; Delete Selected');
                alert('Error: ' + msg);
            });
        });

        // ── Delete All Rejected ────────────────────────────────────────────────
        $('#pmr-delete-rejected').on('click', function () {
            if (!confirm('Permanently DELETE all Rejected proposals from the database?\n\nThis cannot be undone.')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Deleting…');
            ajax('pmr_delete_rejected', { session_id: sessionId }, function (data) {
                $btn.prop('disabled', false).text('Delete All Rejected');
                alert('✓ Deleted ' + data.deleted + ' rejected proposal(s).');
                loadPage(1);
                fetchAndUpdateCounts();
            }, function (msg) {
                $btn.prop('disabled', false).text('Delete All Rejected');
                alert('Error: ' + msg);
            });
        });

        // ── Approve All High-Confidence ────────────────────────────────────
        $('#pmr-approve-all-high').on('click', function () {
            var ids = [];
            $('.pmr-proposal-row').each(function () {
                if (parseFloat($(this).data('score')) >= 0.9 && $(this).data('status') === 'pending') {
                    ids.push(parseInt($(this).data('id')));
                }
            });
            if (!ids.length) { alert('No pending high-confidence rows on this page. Try loading all pages first.'); return; }
            bulkUpdateStatus(ids, 'approved');
        });

        // ── Approve All Visible ────────────────────────────────────────────
        $('#pmr-approve-all-visible').on('click', function () {
            var ids = [];
            $('.pmr-proposal-row').each(function () {
                if ($(this).data('status') !== 'applied') ids.push(parseInt($(this).data('id')));
            });
            if (!ids.length) { alert('No visible rows.'); return; }
            bulkUpdateStatus(ids, 'approved');
        });

        // ── Reject All Unmatched ───────────────────────────────────────────
        $('#pmr-reject-unmatched').on('click', function () {
            var ids = [];
            $('.pmr-proposal-row').each(function () {
                if (parseFloat($(this).data('score')) === 0) ids.push(parseInt($(this).data('id')));
            });
            if (!ids.length) { alert('No unmatched rows on this page.'); return; }
            bulkUpdateStatus(ids, 'rejected');
        });

        // ── Bulk update ────────────────────────────────────────────────────
        function bulkUpdateStatus(ids, status) {
            // Immediate UI
            ids.forEach(function (id) {
                var $row = $('[data-id="' + id + '"]');
                $row.attr('data-status', status)
                    .removeClass('pmr-row--approved pmr-row--rejected pmr-row--applied pmr-row--unmatched')
                    .addClass('pmr-row--' + status);
                $row.find('.pmr-status-badge')
                    .attr('class', 'pmr-status-badge pmr-status--' + status)
                    .text(ucfirst(status));
            });
            $('.pmr-row-check').prop('checked', false);
            $('#pmr-check-all').prop('checked', false);
            updateSelectionCount();

            ajax('pmr_update_status', { ids: ids, status: status }, function () {
                fetchAndUpdateCounts();
            });
        }

        // ── Fetch fresh counts from server ─────────────────────────────────
        function fetchAndUpdateCounts() {
            ajax('pmr_get_counts', { session_id: sessionId }, function (counts) {
                $('#stat-total').text(counts.total);
                $('#stat-pending').text(counts.pending);
                $('#stat-approved').text(counts.approved);
                $('#stat-rejected').text(counts.rejected);
                $('#stat-applied').text(counts.applied);
            });
        }

        // ── Toggle IDs expand ─────────────────────────────────────────────────
        $(document).on('click', '.pmr-ids-toggle', function () {
            var $fields = $(this).siblings('.pmr-id-fields');
            $fields.toggleClass('pmr-hidden');
            $(this).text($fields.hasClass('pmr-hidden') ? 'Edit' : 'Close');
        });

        // ── Save manual ID edits ───────────────────────────────────────────
        $(document).on('click', '.pmr-save-ids', function () {
            var $container = $(this).closest('.pmr-id-fields');
            var id = $container.data('id');
            var data = { id: id };
            $container.find('.pmr-id-input').each(function () {
                data[$(this).data('field')] = $(this).val();
            });
            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving…');
            ajax('pmr_update_proposal', data, function () {
                $btn.prop('disabled', false).text('Saved ✓');
                setTimeout(function () { $btn.text('Save'); }, 1500);
            }, function () {
                $btn.prop('disabled', false).text('Save');
            });
        });

        // ── Apply approved ─────────────────────────────────────────────────
        function applyApproved(sid) {
            if (!confirm(PMRData.strings.confirm_apply)) return;
            ajax('pmr_apply_approved', { session_id: sid }, function (data) {
                $('#pmr-apply-msg').text('✓ Applied ' + data.applied + ' matches. Failed: ' + data.failed + '.');
                $('#pmr-apply-result').removeClass('pmr-hidden');
                fetchAndUpdateCounts();
                loadPage(currentPage);
            });
        }

        $('#pmr-apply-approved, #pmr-apply-approved-bottom').on('click', function () {
            applyApproved($(this).data('session'));
        });

        // ── Fill Unmatched Modal ───────────────────────────────────────────────
        $('#pmr-fill-unmatched').on('click', function () {
            $('#pmr-unmatched-modal').show();
            loadUnmatchedList();
        });

        $('#pmr-modal-close, #pmr-modal-close-btn').on('click', function () {
            $('#pmr-unmatched-modal').hide();
        });

        $('#pmr-unmatched-modal').on('click', function (e) {
            if ($(e.target).is('#pmr-unmatched-modal')) $(this).hide();
        });

        function loadUnmatchedList() {
            $('#pmr-unmatched-list').html('<p style="color:#999;">Loading…</p>');
            ajax('pmr_get_proposals', {
                session_id : sessionId,
                status     : 'unmatched',
                search     : '',
                per_page   : 200,
                page       : 1
            }, function (data) {
                if (!data.rows || !data.rows.length) {
                    $('#pmr-unmatched-list').html('<p style="color:#1e7e34; font-weight:600;">✓ No unmatched products found!</p>');
                    return;
                }
                var html = '<p style="font-size:12px; color:#666; margin-bottom:10px;">Showing ' + Math.min(data.total, 200) + ' of ' + data.total + ' unmatched. Fill IDs and click Save on each row.</p>';
                html += '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; font-size:12px;">';
                html += '<thead><tr style="background:#f8f9fa;">';
                html += '<th style="padding:6px 8px; text-align:left; border-bottom:2px solid #dee2e6; min-width:150px;">Product</th>';
                html += '<th style="padding:6px 8px; width:85px; border-bottom:2px solid #dee2e6;">Variant ID</th>';
                html += '<th style="padding:6px 8px; width:85px; border-bottom:2px solid #dee2e6;">Blueprint ID</th>';
                html += '<th style="padding:6px 8px; width:75px; border-bottom:2px solid #dee2e6;">Provider ID</th>';
                html += '<th style="padding:6px 8px; width:110px; border-bottom:2px solid #dee2e6;">Provider Name</th>';
                html += '<th style="padding:6px 8px; width:55px; border-bottom:2px solid #dee2e6;"></th>';
                html += '</tr></thead><tbody>';

                data.rows.forEach(function (p) {
                    html += '<tr data-id="' + parseInt(p.id) + '" style="border-bottom:1px solid #f0f0f0;">';
                    html += '<td style="padding:6px 8px;">';
                    html += '<strong style="font-size:11px; display:block;">' + escHtml(p.wc_title) + '</strong>';
                    html += '<span style="color:#999; font-size:10px;">ID: ' + parseInt(p.wc_product_id) + (p.wc_variation_id ? ' / Var: ' + parseInt(p.wc_variation_id) : '') + '</span>';
                    html += '</td>';
                    html += '<td style="padding:4px 6px;"><input type="text" class="pmr-um-variant" value="' + escAttr(p.printify_variant_id || '') + '" placeholder="12345" style="width:100%; font-size:11px; padding:2px 4px; border:1px solid #ddd; border-radius:3px;"></td>';
                    html += '<td style="padding:4px 6px;"><input type="text" class="pmr-um-blueprint" value="' + escAttr(p.printify_blueprint_id || '') + '" placeholder="75" style="width:100%; font-size:11px; padding:2px 4px; border:1px solid #ddd; border-radius:3px;"></td>';
                    html += '<td style="padding:4px 6px;"><input type="text" class="pmr-um-provider-id" value="' + escAttr(p.printify_provider_id || '') + '" placeholder="1" style="width:100%; font-size:11px; padding:2px 4px; border:1px solid #ddd; border-radius:3px;"></td>';
                    html += '<td style="padding:4px 6px;"><input type="text" class="pmr-um-provider" value="' + escAttr(p.printify_provider || '') + '" placeholder="SPOKE" style="width:100%; font-size:11px; padding:2px 4px; border:1px solid #ddd; border-radius:3px;"></td>';
                    html += '<td style="padding:4px 6px;"><button class="button button-small button-primary pmr-um-save" style="font-size:11px; height:24px; line-height:22px; padding:0 6px;">Save</button></td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                if (data.total > 200) {
                    html += '<p style="color:#d57a00; font-size:12px; margin-top:8px;">⚠ Save these and reopen to load next batch.</p>';
                }
                $('#pmr-unmatched-list').html(html);
            });
        }

        $(document).on('click', '.pmr-um-save', function () {
            var $row = $(this).closest('tr');
            var $btn = $(this);
            var data = {
                id                    : parseInt($row.data('id')),
                printify_variant_id   : $row.find('.pmr-um-variant').val(),
                printify_blueprint_id : $row.find('.pmr-um-blueprint').val(),
                printify_provider_id  : $row.find('.pmr-um-provider-id').val(),
                printify_provider     : $row.find('.pmr-um-provider').val()
            };
            $btn.prop('disabled', true).text('…');
            ajax('pmr_update_proposal', data, function () {
                $btn.prop('disabled', false).text('✓').addClass('pmr-saved-btn');
                setTimeout(function () { $btn.text('Save').removeClass('pmr-saved-btn'); }, 2000);
            }, function () {
                $btn.prop('disabled', false).text('Err');
            });
        });

        // ── Helpers ────────────────────────────────────────────────────────
        function escHtml(str) {
            return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function escAttr(str) {
            return String(str || '').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
        function ucfirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }

        // ── Initial load ───────────────────────────────────────────────────
        loadPage(1);
    }

    // ── Clear Meta Page ───────────────────────────────────────────────────────
    if ($('#pmr-clear-all-meta').length || $('.pmr-clear-session-meta').length) {

        // Clear ALL meta
        $('#pmr-clear-all-meta').on('click', function () {
            if (!confirm('This will remove ALL Printify meta from every WooCommerce product and reset all Applied statuses to Pending.\n\nThis CANNOT be undone. Are you sure?')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing…');
            ajax('pmr_clear_all_meta', {}, function (data) {
                $btn.prop('disabled', false).html('&#x1F5D1; Clear ALL Printify Meta from All Products');
                var msg = '✓ Done! Cleared meta from ' + data.cleared + ' product(s).';
                if (data.failed) msg += ' Failed: ' + data.failed + '.';
                notice($('#pmr-clear-all-notice'), msg, 'success');
                $('#pmr-clear-all-result').removeClass('pmr-hidden');
            }, function (msg) {
                $btn.prop('disabled', false).html('&#x1F5D1; Clear ALL Printify Meta from All Products');
                notice($('#pmr-clear-all-notice'), '✗ Error: ' + msg, 'error');
                $('#pmr-clear-all-result').removeClass('pmr-hidden');
            });
        });

        // Clear session meta
        $(document).on('click', '.pmr-clear-session-meta', function () {
            var sid  = $(this).data('session');
            var $btn = $(this);
            if (!confirm('Clear all applied meta for session:\n' + sid + '\n\nThis cannot be undone.')) return;
            $btn.prop('disabled', true).text('Clearing…');
            ajax('pmr_clear_session_meta', { session_id: sid }, function (data) {
                $btn.closest('tr').fadeOut();
                notice($('#pmr-session-clear-notice'), '✓ Cleared ' + data.cleared + ' product(s).', 'success');
                $('#pmr-session-clear-result').removeClass('pmr-hidden');
            }, function (msg) {
                $btn.prop('disabled', false).html('&#x1F5D1; Clear');
                notice($('#pmr-session-clear-notice'), '✗ ' + msg, 'error');
                $('#pmr-session-clear-result').removeClass('pmr-hidden');
            });
        });
    }

    // ── Settings Page ─────────────────────────────────────────────────────────
    if ($('#pmr-save-settings').length) {

        $('#pmr-save-settings').on('click', function () {
            ajax('pmr_save_settings', {
                api_key: $('#pmr-api-key').val(),
                shop_id: $('#pmr-shop-id').val()
            }, function (data) {
                notice($('#pmr-settings-notice'), data.message || 'Saved.', 'success');
                $('#pmr-settings-result').removeClass('pmr-hidden');
            }, function (msg) {
                notice($('#pmr-settings-notice'), msg, 'error');
                $('#pmr-settings-result').removeClass('pmr-hidden');
            });
        });

        $('#pmr-test-api').on('click', function () {
            ajax('pmr_test_api', {}, function (data) {
                notice($('#pmr-settings-notice'), '✓ ' + data.message, 'success');
                $('#pmr-settings-result').removeClass('pmr-hidden');
            }, function (msg) {
                notice($('#pmr-settings-notice'), '✗ ' + msg, 'error');
                $('#pmr-settings-result').removeClass('pmr-hidden');
            });
        });

    }

    // ── Dashboard – delete session ────────────────────────────────────────────
    $(document).on('click', '.pmr-delete-session', function () {
        var session = $(this).data('session');
        if (!confirm(PMRData.strings.confirm_delete)) return;
        var $row = $(this).closest('tr');
        ajax('pmr_delete_session', { session_id: session }, function () {
            $row.remove();
        });
    });

}(jQuery));
