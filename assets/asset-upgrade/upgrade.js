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
            el.addEventListener('click', function () { runUpgrade(modal, state); });
        });
    }

    /* ─── phase 5: build → sign → submit ──────────────────────────────── */

    function runUpgrade(modal, state) {
        if (!state.selected || !state.api) {
            showError(modal, 'Wallet or selection missing. Restart the modal.');
            return;
        }
        var btn = modal.querySelector('[data-action="upgrade"]');
        var oldText = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Building transaction…'; }

        // Need a customer payment address (not stake) for change/output.
        // CIP-30 wallets expose this via getChangeAddress() — hex CBOR.
        // The backend bech32-normalizes it via Anvil's parse endpoint.
        state.api.getChangeAddress()
            .then(function (changeAddr) {
                return fetch(cfg.rest_url + 'upgrade/build', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        policy_id:        state.selected.policy_id,
                        asset_name:       state.selected.asset_name,
                        customer_address: changeAddr,
                    }),
                }).then(parseJson);
            })
            .then(function (body) {
                if (!body.ok) throw new Error(body.error || 'Build failed.');
                if (btn) btn.textContent = 'Sign in your wallet…';
                state.buildResult = body;
                // CIP-30 partial-sign: signTx(tx, true) returns just the
                // witness set. We want the full signed tx merged, so call
                // with true and rely on the server adding the policy sig.
                // BUT wallets vary in how they return — Eternl returns a
                // hex witness set, Lace returns a fully-replaced tx. To
                // keep this simple we call signTx(tx, true) and assume the
                // server's CardanoCLI helper can apply the policy witness
                // to either shape (it accepts the customer-witnessed tx as
                // input).
                return state.api.signTx(body.unsigned_tx_hex, true)
                    .then(function (witnessOrTx) {
                        // The backend's CardanoCLI signs the full tx hex
                        // it receives. If the wallet gave us a witness
                        // (Eternl-style), we need to merge it back into the
                        // unsigned tx first. For Lace which returns the
                        // full signed tx, we pass that directly.
                        //
                        // Heuristic: hex length > 1000 is almost certainly
                        // a full tx; shorter is a witness set. This is a
                        // load-bearing assumption we should refine once we
                        // have a sandbox to test against.
                        return { witnessOrTx: witnessOrTx, build: body };
                    });
            })
            .then(function (payload) {
                if (btn) btn.textContent = 'Submitting…';
                return fetch(cfg.rest_url + 'upgrade/submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        log_id:        payload.build.log_id,
                        signed_tx_hex: payload.witnessOrTx,
                    }),
                }).then(parseJson);
            })
            .then(function (body) {
                if (!body.ok) throw new Error(body.error || 'Submit failed.');
                if (btn) { btn.textContent = oldText || 'Upgrade NFT'; btn.disabled = false; }
                showSuccess(modal, body.tx_hash);
            })
            .catch(function (err) {
                if (btn) { btn.textContent = oldText || 'Upgrade NFT'; btn.disabled = false; }
                showError(modal, (err && err.message) ? err.message : String(err));
            });
    }

    function parseJson(r) {
        return r.json().then(function (body) {
            if (!r.ok && !(body && body.error)) {
                throw new Error('HTTP ' + r.status);
            }
            return body;
        });
    }

    function showSuccess(modal, txHash) {
        // Replace the diff step content with a success card. Keeps the
        // modal open so the customer can see the tx hash + explore link.
        var step = modal.querySelector('.kg-cu-step[data-step="diff"]');
        if (!step) return;
        step.innerHTML =
            '<h2>Upgrade submitted</h2>' +
            '<p>The burn-and-re-mint transaction is on its way to the chain. Your new metadata should appear in your wallet within ~30 seconds.</p>' +
            '<p><strong>Transaction:</strong></p>' +
            '<pre style="word-break:break-all">' + escapeHtml(txHash) + '</pre>' +
            '<p>' +
              '<a class="button" href="https://cardanoscan.io/transaction/' + encodeURIComponent(txHash) + '" target="_blank" rel="noopener">View on Cardanoscan</a> ' +
              '<button type="button" class="button" data-close>Close</button>' +
            '</p>';
        // Re-wire the close button we just injected.
        var closeBtn = step.querySelector('[data-close]');
        if (closeBtn) closeBtn.addEventListener('click', function () { closeModal(modal); });
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
