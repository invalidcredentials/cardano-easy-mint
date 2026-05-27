(function ($) {
    'use strict';

    var cfg = window.KG_ASSET_UPGRADE || {};

    function post(action, data) {
        return $.post(cfg.ajax_url, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
    }

    function escapeHtml(s) {
        return $('<div>').text(String(s == null ? '' : s)).html();
    }

    function shortPolicy(id) {
        if (!id) return '';
        return id.substring(0, 10) + '…' + id.substring(id.length - 6);
    }

    function lockBadge(lock) {
        if (!lock) return '<span>—</span>';
        var cls = ({
            'no_lock':                'kg-au-lock-ok',
            'unlocked_with_deadline': 'kg-au-lock-warn',
            'unknown_lock':           'kg-au-lock-warn',
            'locked':                 'kg-au-lock-bad'
        })[lock.flag] || '';
        return '<span class="' + cls + '">' + escapeHtml(lock.label) + '</span>';
    }

    function renderPolicies(policies) {
        var $body = $('#kg-au-policies-body').empty();
        if (!policies.length) {
            $body.append('<tr class="kg-au-policies-empty"><td colspan="7"><em>No policies registered yet. Use the form above to add one.</em></td></tr>');
            return;
        }
        policies.forEach(function (p) {
            var $tr = $('<tr>');
            $tr.append('<td>' + escapeHtml(p.upgrade_label) + '</td>');
            $tr.append('<td><code title="' + escapeHtml(p.policy_id) + '">' + escapeHtml(shortPolicy(p.policy_id)) + '</code></td>');
            $tr.append('<td>' + escapeHtml(p.network) + '</td>');
            $tr.append('<td>' + escapeHtml(p.asset_count == null ? '?' : p.asset_count) + '</td>');
            $tr.append('<td>' + lockBadge(p.lock_state) + '</td>');
            $tr.append('<td><code>' + escapeHtml(p.status) + '</code></td>');
            var $actions = $('<td>');
            $actions.append($('<a class="button button-small button-primary kg-au-edit">Edit spec</a>')
                .attr('href', cfg.page_url + '&action=edit&policy_id=' + encodeURIComponent(p.policy_id)));
            $actions.append(' ');
            $actions.append($('<button class="button button-small kg-au-view">View assets</button>').data('policy', p.policy_id));
            $actions.append(' ');
            $actions.append($('<button class="button button-small button-link-delete kg-au-remove">Remove</button>').data('policy', p.policy_id));
            $tr.append($actions);
            $body.append($tr);
        });
    }

    function loadPolicies() {
        return post('cardano_upgrade_list_policies')
            .done(function (res) {
                if (res && res.success) renderPolicies(res.data.policies || []);
                else {
                    $('#kg-au-policies-body').html('<tr><td colspan="7"><span class="kg-au-bad">Failed to load policies: ' + escapeHtml((res && res.data && res.data.message) || 'unknown') + '</span></td></tr>');
                }
            })
            .fail(function (xhr) {
                $('#kg-au-policies-body').html('<tr><td colspan="7"><span class="kg-au-bad">Network error: ' + xhr.status + '</span></td></tr>');
            });
    }

    function setMsg($el, type, text) {
        $el.removeClass('kg-au-ok kg-au-bad kg-au-info').addClass('kg-au-' + type).text(text);
    }

    $(function () {
        // Dispatch by action — the same page slug serves the main list
        // and the per-policy edit view; the controller picks the template
        // and we mirror the routing here.
        if (cfg.action === 'edit' && cfg.policy_id) {
            initEditor(cfg.policy_id);
            return;
        }
        loadPolicies();

        $('#kg-au-register').on('click', function () {
            var $btn = $(this);
            var $msg = $('#kg-au-register-msg');
            var $sp  = $('.kg-au-spinner').addClass('is-active');
            var policy_id = ($('#kg-au-policy-id').val() || '').trim().toLowerCase();
            var network   = $('#kg-au-network').val();
            var label     = ($('#kg-au-label').val() || '').trim();

            if (!/^[a-f0-9]{56}$/.test(policy_id)) {
                setMsg($msg, 'bad', 'Policy ID must be exactly 56 hex characters.');
                $sp.removeClass('is-active');
                return;
            }

            setMsg($msg, 'info', 'Walking Blockfrost… this can take a few seconds for large collections.');
            $btn.prop('disabled', true);

            post('cardano_upgrade_register_policy', { policy_id: policy_id, network: network, label: label })
                .always(function () {
                    $btn.prop('disabled', false);
                    $sp.removeClass('is-active');
                })
                .done(function (res) {
                    if (res && res.success) {
                        setMsg($msg, 'ok',
                            'Registered ' + res.data.asset_count + ' asset(s). ' +
                            (res.data.lock_state && res.data.lock_state.label ? '(' + res.data.lock_state.label + ')' : '')
                        );
                        $('#kg-au-policy-id').val('');
                        $('#kg-au-label').val('');
                        loadPolicies();
                    } else {
                        setMsg($msg, 'bad', (res && res.data && res.data.message) || 'Registration failed.');
                    }
                })
                .fail(function (xhr) {
                    setMsg($msg, 'bad', 'Request failed: ' + xhr.status + ' ' + xhr.statusText);
                });
        });

        $(document).on('click', '.kg-au-remove', function () {
            var policy = $(this).data('policy');
            if (!policy) return;
            if (!confirm('Remove all upgrade specs for policy ' + policy.substring(0, 10) + '…?\nThe audit log of past upgrades is kept.')) return;
            post('cardano_upgrade_remove_policy', { policy_id: policy })
                .done(loadPolicies)
                .fail(function (xhr) { alert('Remove failed: ' + xhr.status); });
        });

        $(document).on('click', '.kg-au-view', function () {
            var policy = $(this).data('policy');
            if (!policy) return;
            openAssetsPane(policy, false);
        });

        $('#kg-au-pane-close').on('click', function () {
            $('#kg-au-assets-pane').hide();
        });

        $('#kg-au-pane-refresh').on('click', function () {
            var policy = $('#kg-au-pane-policy').text();
            if (policy) openAssetsPane(policy, true);
        });

        function openAssetsPane(policy, refresh) {
            $('#kg-au-pane-policy').text(policy);
            $('#kg-au-pane-meta').text(refresh ? 'Refreshing from chain…' : 'Loading…');
            $('#kg-au-pane-content').html('<em>fetching…</em>');
            $('#kg-au-assets-pane').show();

            post('cardano_upgrade_list_assets', { policy_id: policy, refresh: refresh ? '1' : '0' })
                .done(function (res) {
                    if (res && res.success) {
                        var assets = res.data.assets || [];
                        $('#kg-au-pane-meta').text(
                            assets.length + ' asset(s)' +
                            (res.data.cached ? ' · from cache (click Refresh for fresh)' : ' · just fetched from chain')
                        );
                        if (!assets.length) {
                            $('#kg-au-pane-content').html('<em>No assets returned.</em>');
                            return;
                        }
                        var html = '<table class="widefat striped"><thead><tr><th>Asset name (hex)</th><th>Asset name (ASCII)</th><th>Quantity</th></tr></thead><tbody>';
                        assets.forEach(function (a) {
                            var ascii = '';
                            try { ascii = decodeHexAscii(a.asset_name); } catch (_) { ascii = ''; }
                            html += '<tr><td><code>' + escapeHtml(a.asset_name) + '</code></td>';
                            html += '<td>' + escapeHtml(ascii) + '</td>';
                            html += '<td>' + escapeHtml(a.quantity) + '</td></tr>';
                        });
                        html += '</tbody></table>';
                        $('#kg-au-pane-content').html(html);
                    } else {
                        var msg = (res && res.data && res.data.message) || 'Failed.';
                        $('#kg-au-pane-meta').text('').end();
                        $('#kg-au-pane-content').html('<span class="kg-au-bad">' + escapeHtml(msg) + '</span>');
                    }
                })
                .fail(function (xhr) {
                    $('#kg-au-pane-content').html('<span class="kg-au-bad">Request failed: ' + xhr.status + '</span>');
                });
        }

        /* ─── Edit view (phase 3 spec editor) ───────────────────────── */

        function initEditor(policy_id) {
            renderSummary(null);
            post('cardano_upgrade_get_policy_edit', { policy_id: policy_id })
                .done(function (res) {
                    if (!res || !res.success) {
                        $('#kg-au-summary').html('<span class="kg-au-bad">' + escapeHtml((res && res.data && res.data.message) || 'Failed to load.') + '</span>');
                        return;
                    }
                    renderEditor(res.data);
                })
                .fail(function (xhr) {
                    $('#kg-au-summary').html('<span class="kg-au-bad">Request failed: ' + xhr.status + '</span>');
                });

            wireEditorButtons(policy_id);
        }

        function renderSummary(policy) {
            if (!policy) {
                $('#kg-au-summary').html('<em>Loading policy…</em>');
                return;
            }
            var lock = policy.policy_locks_at_slot
                ? '<span class="kg-au-lock-warn">locks at slot ' + escapeHtml(policy.policy_locks_at_slot) + '</span>'
                : '<span class="kg-au-lock-ok">no time-lock</span>';
            $('#kg-au-summary').html(
                '<table class="widefat"><tbody>' +
                '<tr><th>Label</th><td>' + escapeHtml(policy.upgrade_label) + '</td></tr>' +
                '<tr><th>Policy ID</th><td><code>' + escapeHtml(policy.policy_id) + '</code></td></tr>' +
                '<tr><th>Network</th><td>' + escapeHtml(policy.network) + '</td></tr>' +
                '<tr><th>Assets</th><td>' + escapeHtml(policy.asset_count == null ? '?' : policy.asset_count) + '</td></tr>' +
                '<tr><th>Time-lock</th><td>' + lock + '</td></tr>' +
                '<tr><th>Patch status</th><td><code>' + escapeHtml(policy.status) + '</code></td></tr>' +
                '</tbody></table>'
            );
        }

        function renderEditor(data) {
            var policy = data.policy;
            renderSummary(policy);

            // Pretty-print the existing patch into the textarea.
            var existing = (policy.new_metadata_parsed && typeof policy.new_metadata_parsed === 'object')
                ? policy.new_metadata_parsed : {};
            $('#kg-au-patch').val(Object.keys(existing).length ? JSON.stringify(existing, null, 2) : '');
            $('#kg-au-patch-status').val(policy.status || 'draft');

            renderPerAssetRows(data.per_asset || []);
        }

        function renderPerAssetRows(rows) {
            var $body = $('#kg-au-per-asset-body').empty();
            if (!rows.length) {
                $body.append('<tr><td colspan="6"><em>No per-asset overrides yet. Paste a CIP-25 bundle above to import.</em></td></tr>');
                return;
            }
            rows.forEach(function (r) {
                var $tr = $('<tr>');
                $tr.append('<td><code>' + escapeHtml(r.asset_name) + '</code></td>');
                $tr.append('<td>' + escapeHtml(r.asset_name_ascii || '') + '</td>');
                $tr.append('<td><code>' + escapeHtml(r.mode) + '</code></td>');
                var $statusCell = $('<td>');
                var $statusSel  = $('<select class="kg-au-row-status">')
                    .append('<option value="draft">draft</option>')
                    .append('<option value="active">active</option>')
                    .append('<option value="paused">paused</option>')
                    .append('<option value="completed">completed</option>')
                    .val(r.status)
                    .data('id', r.id);
                $statusCell.append($statusSel);
                $tr.append($statusCell);
                $tr.append('<td>' + escapeHtml(r.updated_at) + '</td>');
                var $actions = $('<td>');
                $actions.append($('<button class="button button-small kg-au-preview-row">Preview</button>').data('asset', r.asset_name));
                $actions.append(' ');
                $actions.append($('<button class="button button-small button-link-delete kg-au-delete-row">Delete</button>').data('id', r.id));
                $tr.append($actions);
                $body.append($tr);
            });
        }

        function wireEditorButtons(policy_id) {
            $('#kg-au-save-patch').on('click', function () {
                var $btn = $(this);
                var $msg = $('#kg-au-patch-msg');
                var $sp  = $btn.next('.kg-au-spinner').addClass('is-active');
                var patch_json = $('#kg-au-patch').val();
                var status     = $('#kg-au-patch-status').val();
                $btn.prop('disabled', true);
                setMsg($msg, 'info', 'Saving…');
                post('cardano_upgrade_save_patch', { policy_id: policy_id, patch_json: patch_json, status: status })
                    .always(function () { $btn.prop('disabled', false); $sp.removeClass('is-active'); })
                    .done(function (res) {
                        if (res && res.success) setMsg($msg, 'ok', 'Saved. Status: ' + res.data.status);
                        else setMsg($msg, 'bad', (res && res.data && res.data.message) || 'Save failed.');
                    })
                    .fail(function (xhr) { setMsg($msg, 'bad', 'Request failed: ' + xhr.status); });
            });

            $('#kg-au-save-bundle').on('click', function () {
                var $btn = $(this);
                var $msg = $('#kg-au-bundle-msg');
                $btn.prop('disabled', true);
                setMsg($msg, 'info', 'Parsing + saving…');
                post('cardano_upgrade_save_per_asset_bundle', {
                    policy_id: policy_id,
                    bundle_json: $('#kg-au-bundle').val(),
                    status: $('#kg-au-bundle-status').val()
                })
                    .always(function () { $btn.prop('disabled', false); })
                    .done(function (res) {
                        if (res && res.success) {
                            setMsg($msg, 'ok',
                                'Imported: ' + res.data.inserted + ' inserted, ' + res.data.updated + ' updated.');
                            initEditor(policy_id);
                        } else {
                            setMsg($msg, 'bad', (res && res.data && res.data.message) || 'Import failed.');
                        }
                    })
                    .fail(function (xhr) { setMsg($msg, 'bad', 'Request failed: ' + xhr.status); });
            });

            $('#kg-au-preview-go').on('click', function () {
                runPreview(policy_id, ($('#kg-au-preview-asset').val() || '').trim().toLowerCase());
            });

            $(document).on('change', '.kg-au-row-status', function () {
                var $sel = $(this);
                var id = $sel.data('id');
                var status = $sel.val();
                post('cardano_upgrade_set_status', { id: id, status: status })
                    .done(function (res) {
                        if (!res || !res.success) {
                            alert('Status change failed: ' + ((res && res.data && res.data.message) || 'unknown'));
                            // Reload to get back to a consistent state.
                            initEditor(policy_id);
                        }
                    })
                    .fail(function () { alert('Status change request failed.'); });
            });

            $(document).on('click', '.kg-au-delete-row', function () {
                var id = $(this).data('id');
                if (!confirm('Delete this per-asset override?')) return;
                post('cardano_upgrade_delete_per_asset', { id: id })
                    .done(function () { initEditor(policy_id); })
                    .fail(function () { alert('Delete request failed.'); });
            });

            $(document).on('click', '.kg-au-preview-row', function () {
                var asset = $(this).data('asset');
                $('#kg-au-preview-asset').val(asset);
                runPreview(policy_id, asset);
            });
        }

        function runPreview(policy_id, asset_name) {
            var $msg = $('#kg-au-preview-msg');
            if (!/^[a-f0-9]+$/.test(asset_name) || asset_name.length > 128) {
                setMsg($msg, 'bad', 'Asset name must be hex, max 128 chars.');
                return;
            }
            setMsg($msg, 'info', 'Fetching on-chain metadata…');
            post('cardano_upgrade_preview_diff', { policy_id: policy_id, asset_name: asset_name })
                .done(function (res) {
                    if (!res || !res.success) {
                        setMsg($msg, 'bad', (res && res.data && res.data.message) || 'Preview failed.');
                        $('#kg-au-diff').hide();
                        return;
                    }
                    setMsg($msg, 'ok', 'Loaded. Resolved from: ' + res.data.resolved_from);
                    $('#kg-au-diff-current').text(JSON.stringify(res.data.current, null, 2));
                    $('#kg-au-diff-resolved').text(JSON.stringify(res.data.resolved, null, 2));
                    $('#kg-au-diff-source').text('asset (ascii): ' + (res.data.asset_name_ascii || '—'));
                    $('#kg-au-diff').show();
                })
                .fail(function (xhr) {
                    setMsg($msg, 'bad', 'Request failed: ' + xhr.status);
                });
        }

        function decodeHexAscii(hex) {
            if (!hex || hex.length % 2 !== 0) return '';
            var out = '';
            for (var i = 0; i < hex.length; i += 2) {
                var c = parseInt(hex.substring(i, i + 2), 16);
                if (isNaN(c)) return '';
                // Only render printable ASCII; anything else means the asset name
                // isn't an ASCII string and we shouldn't try to decode it.
                if (c < 0x20 || c > 0x7e) return '';
                out += String.fromCharCode(c);
            }
            return out;
        }
    });
})(jQuery);
