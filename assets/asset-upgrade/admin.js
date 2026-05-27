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
