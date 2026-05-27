/**
 * Asset Upgrade — customer frontend (phase 4).
 *
 * Flow:
 *   1. Detect installed CIP-30 wallets in window.cardano.* (Eternl, Lace,
 *      Vespr, etc.) and render a list. Click → wallet.enable() → api.
 *   2. Pull a reward (stake) address via api.getRewardAddresses(). Send
 *      it to /cardano-mint/v1/upgrade/eligible. Server side normalizes
 *      the hex address via Anvil's parse endpoint before querying
 *      Blockfrost, so we don't have to bech32 it in browser.
 *   3. Render eligible asset cards from the response.
 *   4. Click a card → render the before/after metadata diff in step 4.
 *   5. "Upgrade NFT" button is a placeholder until phase 5 lands the
 *      Anvil multi-mint build + dual-sign flow.
 *
 * Self-contained: no React, no bundler. One IIFE attaches to all
 * .kg-cu-root nodes on the page (supports multiple shortcodes per page).
 */
(function () {
    'use strict';

    var cfg = window.KG_CARDANO_UPGRADE || {};

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    /* ─── modal init ──────────────────────────────────────────────────── */

    function initRoot(root) {
        var modal = root.querySelector('.kg-cu-modal');
        if (!modal) return;
        var policyFilter = (root.getAttribute('data-policy-id') || '').trim();
        var state = { api: null, walletKey: null, eligible: [], selected: null };

        root.querySelectorAll('.kg-cu-trigger').forEach(function (btn) {
            btn.addEventListener('click', function () { openModal(modal, state, policyFilter); });
        });
        modal.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(modal); });
        });
        modal.querySelectorAll('[data-action="back-to-pick"]').forEach(function (el) {
            el.addEventListener('click', function () { showStep(modal, 'pick'); });
        });
        modal.querySelectorAll('[data-action="restart"]').forEach(function (el) {
            el.addEventListener('click', function () { resetState(state); showStep(modal, 'connect'); detectWallets(modal, state, policyFilter); });
        });
        modal.querySelectorAll('[data-action="upgrade"]').forEach(function (el) {
            el.addEventListener('click', function () {
                // Phase 5 lands the actual tx flow. For now, surface a
                // friendly placeholder so the customer knows we got their
                // intent but the feature is still rolling out.
                alert(
                    'Upgrade flow coming in the next release.\n\n' +
                    'Selected asset:\n' +
                    (state.selected && state.selected.asset_name_ascii
                        ? state.selected.asset_name_ascii
                        : (state.selected ? state.selected.asset_name : '?'))
                );
            });
        });
    }

    function openModal(modal, state, policyFilter) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        resetState(state);
        showStep(modal, 'connect');
        detectWallets(modal, state, policyFilter);
    }
    function closeModal(modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
    function showStep(modal, step) {
        modal.querySelectorAll('.kg-cu-step').forEach(function (s) { s.style.display = 'none'; });
        var target = modal.querySelector('.kg-cu-step[data-step="' + step + '"]');
        if (target) target.style.display = 'block';
    }
    function resetState(state) { state.api = null; state.walletKey = null; state.eligible = []; state.selected = null; }
    function showError(modal, msg) {
        var target = modal.querySelector('[data-role="error-msg"]');
        if (target) target.textContent = msg;
        showStep(modal, 'error');
    }

    /* ─── wallet detection + connect ──────────────────────────────────── */

    function detectWallets(modal, state, policyFilter) {
        var list = modal.querySelector('[data-role="wallet-list"]');
        if (!list) return;
        list.innerHTML = '';
        var available = [];
        if (window.cardano) {
            Object.keys(window.cardano).forEach(function (key) {
                var w = window.cardano[key];
                if (w && typeof w.enable === 'function' && w.name) {
                    available.push({ key: key, name: w.name, icon: w.icon || '' });
                }
            });
        }
        if (!available.length) {
            list.innerHTML = '<p class="kg-cu-bad">No CIP-30 wallets detected. Install Eternl, Lace, Vespr, or Typhon and reload.</p>';
            return;
        }
        available.forEach(function (w) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kg-cu-wallet-btn';
            btn.innerHTML = (w.icon ? '<img class="kg-cu-wallet-icon" src="' + escapeHtml(w.icon) + '" alt="">' : '') +
                            '<span>' + escapeHtml(w.name) + '</span>';
            btn.addEventListener('click', function () { connectWallet(modal, state, policyFilter, w.key); });
            list.appendChild(btn);
        });
    }

    function connectWallet(modal, state, policyFilter, walletKey) {
        if (!window.cardano || !window.cardano[walletKey]) {
            showError(modal, 'Wallet ' + walletKey + ' not available. Reload and try again.');
            return;
        }
        showStep(modal, 'loading');
        var statusEl = modal.querySelector('[data-role="loading-status"]');
        statusEl.textContent = 'Asking ' + walletKey + ' for permission…';

        window.cardano[walletKey].enable()
            .then(function (api) {
                state.api = api;
                state.walletKey = walletKey;
                statusEl.textContent = 'Reading your wallet address…';
                return api.getRewardAddresses();
            })
            .then(function (rewardAddresses) {
                if (!rewardAddresses || !rewardAddresses.length) {
                    throw new Error('Wallet returned no reward address. Try a different wallet.');
                }
                var hex = rewardAddresses[0];
                statusEl.textContent = 'Checking eligibility against active upgrades…';
                return fetchEligible(hex);
            })
            .then(function (assets) {
                if (policyFilter) {
                    assets = assets.filter(function (a) { return a.policy_id === policyFilter; });
                }
                state.eligible = assets;
                renderPickStep(modal, state);
                showStep(modal, 'pick');
            })
            .catch(function (err) {
                showError(modal, (err && err.message) ? err.message : 'Connection failed.');
            });
    }

    function fetchEligible(addressHex) {
        return fetch(cfg.rest_url + 'upgrade/eligible', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: JSON.stringify({ address: addressHex }),
            credentials: 'same-origin'
        })
        .then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) throw new Error((body && body.error) ? body.error : ('HTTP ' + r.status));
                return body.assets || [];
            });
        });
    }

    /* ─── pick + diff rendering ───────────────────────────────────────── */

    function renderPickStep(modal, state) {
        var grid  = modal.querySelector('[data-role="asset-grid"]');
        var count = modal.querySelector('[data-role="eligible-count"]');
        var empty = modal.querySelector('[data-role="empty-state"]');
        grid.innerHTML = '';
        if (!state.eligible.length) {
            count.textContent = '';
            empty.style.display = 'block';
            return;
        }
        empty.style.display = 'none';
        count.textContent = state.eligible.length + ' eligible asset' + (state.eligible.length === 1 ? '' : 's') + ' found.';

        state.eligible.forEach(function (a, i) {
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'kg-cu-asset-card';
            card.setAttribute('data-i', String(i));
            var img = (a.current && a.current.image) ? ipfsToHttp(a.current.image) : '';
            var name = (a.asset_name_ascii && a.asset_name_ascii !== '')
                ? a.asset_name_ascii
                : (a.current && a.current.name) ? a.current.name : a.asset_name;
            card.innerHTML =
                (img ? '<img class="kg-cu-asset-thumb" src="' + escapeHtml(img) + '" alt="" loading="lazy">' : '<div class="kg-cu-asset-thumb kg-cu-asset-thumb-empty"></div>') +
                '<div class="kg-cu-asset-name">' + escapeHtml(name) + '</div>' +
                '<div class="kg-cu-asset-tag">' + escapeHtml(a.resolved_from === 'per_asset_full' ? 'custom' : 'patch') + '</div>';
            card.addEventListener('click', function () {
                state.selected = a;
                renderDiffStep(modal, a);
                showStep(modal, 'diff');
            });
            grid.appendChild(card);
        });
    }

    function renderDiffStep(modal, asset) {
        var title    = modal.querySelector('[data-role="diff-title"]');
        var summary  = modal.querySelector('[data-role="diff-summary"]');
        var current  = modal.querySelector('[data-role="diff-current"]');
        var resolved = modal.querySelector('[data-role="diff-resolved"]');

        var displayName = (asset.asset_name_ascii && asset.asset_name_ascii !== '')
            ? asset.asset_name_ascii
            : asset.asset_name;
        title.textContent = 'Review changes — ' + displayName;
        summary.textContent = 'Source: ' + (asset.resolved_from === 'per_asset_full'
            ? 'per-asset override (custom metadata for this NFT)'
            : 'policy-wide patch (same change applied across the collection)');

        current.textContent  = JSON.stringify(asset.current  || {}, null, 2);
        resolved.textContent = JSON.stringify(asset.resolved || {}, null, 2);
    }

    function ipfsToHttp(uri) {
        if (typeof uri !== 'string' || !uri) return '';
        if (uri.indexOf('ipfs://') === 0) return 'https://ipfs.io/ipfs/' + uri.slice(7);
        return uri;
    }

    /* ─── bootstrap ───────────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        $all('.kg-cu-root').forEach(initRoot);
    });
})();
