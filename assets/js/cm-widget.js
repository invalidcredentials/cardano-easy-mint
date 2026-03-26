/**
 * Cardano Minting Widget — Embeddable, self-contained, zero dependencies.
 *
 * Usage:
 *   <div id="cardano-mint-widget"></div>
 *   <script
 *     src="https://yoursite.com/wp-content/plugins/cardano-minting/assets/js/cm-widget.js"
 *     data-api="https://yoursite.com/wp-json/cardano-mint/v1"
 *     data-key="cmk_..."
 *     data-collection="42"
 *     data-container="cardano-mint-widget"
 *   ></script>
 */
(function () {
    'use strict';

    /* ── Config from script tag ──────────────────────────────────────── */

    var scriptTag = document.currentScript || (function () {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var CFG = {
        api:        scriptTag.getAttribute('data-api') || '',
        key:        scriptTag.getAttribute('data-key') || '',
        collection: scriptTag.getAttribute('data-collection') || '',
        theme:      scriptTag.getAttribute('data-theme') || 'game-dark',
        container:  scriptTag.getAttribute('data-container') || 'cardano-mint-widget',
    };

    if (!CFG.api || !CFG.collection) {
        console.error('[CMW] data-api and data-collection attributes are required.');
        return;
    }

    /* ── Bech32 Encoding (from CIP-30 hex addresses) ─────────────────── */

    function hexToBytes(hex) {
        var bytes = [];
        for (var i = 0; i < hex.length; i += 2) bytes.push(parseInt(hex.substr(i, 2), 16));
        return bytes;
    }

    function to5Bits(bytes) {
        var bits = 0, value = 0, out = [];
        for (var i = 0; i < bytes.length; i++) {
            value = (value << 8) | bytes[i];
            bits += 8;
            while (bits >= 5) { out.push((value >> (bits - 5)) & 31); bits -= 5; }
        }
        if (bits > 0) out.push((value << (5 - bits)) & 31);
        return out;
    }

    function bech32Encode(hrp, data5) {
        var CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        var GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        function polymod(values) {
            var chk = 1;
            for (var j = 0; j < values.length; j++) {
                var v = values[j], b = chk >> 25;
                chk = ((chk & 0x1ffffff) << 5) ^ v;
                for (var i = 0; i < 5; i++) if ((b >> i) & 1) chk ^= GEN[i];
            }
            return chk;
        }
        function hrpExpand(h) {
            var out = [];
            for (var i = 0; i < h.length; i++) out.push(h.charCodeAt(i) >> 5);
            out.push(0);
            for (var i = 0; i < h.length; i++) out.push(h.charCodeAt(i) & 31);
            return out;
        }
        var all = hrpExpand(hrp).concat(data5).concat([0, 0, 0, 0, 0, 0]);
        var chk = polymod(all) ^ 1;
        var checksum = [];
        for (var p = 0; p < 6; p++) checksum.push((chk >> (5 * (5 - p))) & 31);
        var encoded = hrp + '1';
        var full = data5.concat(checksum);
        for (var i = 0; i < full.length; i++) encoded += CHARSET[full[i]];
        return encoded;
    }

    function hexToBech32(hex) {
        var bytes = hexToBytes(hex);
        if (!bytes.length) return hex;
        var networkId = bytes[0] & 0x0F;
        var hrp = networkId === 1 ? 'addr' : 'addr_test';
        return bech32Encode(hrp, to5Bits(bytes));
    }

    /* ── REST Client ─────────────────────────────────────────────────── */

    function apiGet(endpoint) {
        return fetch(CFG.api + endpoint, {
            headers: { 'X-CM-Api-Key': CFG.key },
        }).then(function (r) { return r.json(); });
    }

    function apiPost(endpoint, data) {
        return fetch(CFG.api + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CM-Api-Key': CFG.key,
            },
            body: JSON.stringify(data),
        }).then(function (r) { return r.json(); });
    }

    /* ── Known Wallets ───────────────────────────────────────────────── */

    var KNOWN_WALLETS = ['nami', 'eternl', 'lace', 'flint', 'typhoncip30', 'gerowallet', 'nufi', 'begin', 'vespr', 'yoroi'];

    function getInstalledWallets() {
        if (!window.cardano) return [];
        return KNOWN_WALLETS.filter(function (k) { return window.cardano[k]; });
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function resolveIpfs(url) {
        if (!url) return '';
        if (url.indexOf('ipfs://') === 0) return 'https://ipfs.io/ipfs/' + url.substring(7);
        return url;
    }

    /* ── CSS ──────────────────────────────────────────────────────────── */

    var WIDGET_CSS = (function () { return [
        ':host { display: block; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }',
        '.cmw-root { background: var(--cmw-bg, #0a0e1a); color: var(--cmw-text, #e6edf3); border-radius: 12px; padding: 24px; max-width: 480px; margin: 0 auto; }',

        /* Image */
        '.cmw-image { width: 100%; border-radius: 10px; object-fit: cover; max-height: 400px; margin-bottom: 16px; }',

        /* Header */
        '.cmw-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }',
        '.cmw-title { font-size: 20px; font-weight: 700; flex: 1; }',
        '.cmw-badge { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px; }',
        '.cmw-badge--live { background: #00c853; color: #000; }',
        '.cmw-badge--ended { background: #ff3860; color: #fff; }',

        /* Info cards */
        '.cmw-info { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }',
        '.cmw-info-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 12px; }',
        '.cmw-info-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: rgba(255,255,255,0.4); margin-bottom: 4px; }',
        '.cmw-info-value { font-size: 18px; font-weight: 700; }',
        '.cmw-info-sub { font-size: 11px; color: rgba(255,255,255,0.35); margin-top: 2px; }',

        /* Supply bar */
        '.cmw-supply-bar { height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; margin-top: 8px; }',
        '.cmw-supply-fill { height: 100%; background: var(--cmw-accent, #00e5ff); border-radius: 3px; transition: width 0.3s; }',

        /* Variants */
        '.cmw-variants { margin: 16px 0; }',
        '.cmw-variants-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: rgba(255,255,255,0.4); margin-bottom: 8px; }',
        '.cmw-variant-list { display: flex; gap: 8px; flex-wrap: wrap; }',
        '.cmw-variant-btn { background: rgba(255,255,255,0.06); border: 2px solid rgba(255,255,255,0.1); color: var(--cmw-text, #e6edf3); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: all 0.2s; }',
        '.cmw-variant-btn:hover { border-color: var(--cmw-accent, #00e5ff); }',
        '.cmw-variant-btn.active { border-color: var(--cmw-accent, #00e5ff); background: rgba(0,229,255,0.1); }',

        /* Wallet */
        '.cmw-wallet-section { margin-top: 16px; }',
        '.cmw-wallet-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }',
        '.cmw-wallet-btn { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: var(--cmw-text); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: all 0.2s; }',
        '.cmw-wallet-btn:hover { border-color: var(--cmw-accent, #00e5ff); background: rgba(0,229,255,0.05); }',
        '.cmw-wallet-connected { font-size: 12px; color: rgba(255,255,255,0.5); margin-bottom: 8px; }',
        '.cmw-wallet-connected span { color: #00c853; }',

        /* Mint button */
        '.cmw-btn-mint { width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border: none; border-radius: 8px; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; transition: all 0.2s; }',
        '.cmw-btn-mint--ready { background: var(--cmw-confirm, #f0c040); color: #000; }',
        '.cmw-btn-mint--ready:hover { filter: brightness(1.1); transform: translateY(-1px); }',
        '.cmw-btn-mint--disabled { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.3); cursor: not-allowed; }',
        '.cmw-btn-mint--minting { background: var(--cmw-accent, #00e5ff); color: #000; cursor: wait; }',

        /* Status */
        '.cmw-status { padding: 12px; border-radius: 6px; margin: 12px 0; font-size: 13px; }',
        '.cmw-status--info { background: rgba(0,229,255,0.1); border: 1px solid rgba(0,229,255,0.2); }',
        '.cmw-status--success { background: rgba(0,200,83,0.1); border: 1px solid rgba(0,200,83,0.2); color: #00c853; }',
        '.cmw-status--error { background: rgba(255,56,96,0.1); border: 1px solid rgba(255,56,96,0.2); color: #ff3860; }',

        /* Expiry */
        '.cmw-expiry { font-size: 11px; color: rgba(255,255,255,0.35); margin-top: 4px; }',
        '.cmw-expiry--expired { color: #ff3860; }',

        /* Loading */
        '.cmw-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 0; color: rgba(255,255,255,0.4); gap: 12px; }',
        '.cmw-spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.08); border-top-color: var(--cmw-accent); border-radius: 50%; animation: cmw-spin 0.8s linear infinite; }',
        '@keyframes cmw-spin { to { transform: rotate(360deg); } }',

        /* Footer */
        '.cmw-footer { text-align: center; margin-top: 20px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); font-size: 10px; color: rgba(255,255,255,0.18); }',
        '.cmw-footer a { color: rgba(255,255,255,0.25); text-decoration: none; }',
        '.cmw-footer a:hover { color: rgba(255,255,255,0.45); }',
    ].join('\n'); })();

    /* ── Widget Class ────────────────────────────────────────────────── */

    function CardanoMintWidget(containerEl) {
        this.container = containerEl;
        this.shadow = containerEl.attachShadow({ mode: 'open' });
        this.root = null;
        this.collection = null;
        this.selectedVariant = null;
        this.wallet = { connected: false, address: '', addressHex: '', handler: null, key: '', name: '' };
        this.minting = false;
        this.destroyed = false;
    }

    /* ── Events ──────────────────────────────────────────────────── */

    CardanoMintWidget._listeners = {};

    CardanoMintWidget.on = function (event, callback) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(callback);
    };

    CardanoMintWidget._emit = function (event, data) {
        var fns = this._listeners[event] || [];
        for (var i = 0; i < fns.length; i++) {
            try { fns[i](data); } catch (e) { console.error('[CMW] Event handler error:', e); }
        }
    };

    /* ── Init ────────────────────────────────────────────────────── */

    CardanoMintWidget.prototype.init = function () {
        var self = this;

        var style = document.createElement('style');
        style.textContent = WIDGET_CSS;
        this.shadow.appendChild(style);

        this.root = document.createElement('div');
        this.root.className = 'cmw-root';
        this.shadow.appendChild(this.root);

        this.root.innerHTML = '<div class="cmw-loading"><div class="cmw-spinner"></div><div>Loading collection...</div></div>';

        apiGet('/collections/' + CFG.collection).then(function (data) {
            if (self.destroyed) return;
            if (data.error) {
                self.root.innerHTML = '<div class="cmw-status cmw-status--error">' + escHtml(data.error) + '</div>';
                return;
            }
            self.collection = data;
            if (data.variants && data.variants.length > 0) {
                self.selectedVariant = data.variants[0].variant;
            }
            self.render();
        }).catch(function (err) {
            console.error('[CMW] Failed to load collection:', err);
            if (!self.destroyed) {
                self.root.innerHTML = '<div class="cmw-status cmw-status--error">Failed to load collection data.</div>';
            }
        });
    };

    /* ── Render ──────────────────────────────────────────────────── */

    CardanoMintWidget.prototype.render = function () {
        var c = this.collection;
        if (!c) return;

        var remaining = c.quantity_remaining || 0;
        var total = c.quantity_total || 0;
        var soldOut = remaining <= 0;
        var supplyPct = total > 0 ? Math.round(((total - remaining) / total) * 100) : 0;

        // Check expiration.
        var expired = false;
        var expiryText = '';
        if (c.expiration_date) {
            var expDate = new Date(c.expiration_date);
            if (expDate < new Date()) {
                expired = true;
                expiryText = 'Minting ended ' + expDate.toLocaleDateString();
            } else {
                expiryText = 'Minting ends ' + expDate.toLocaleDateString();
            }
        }

        var canMint = !soldOut && !expired;

        // Get current variant data for price display.
        var displayPrice = c.price_usd || 0;
        var displayPriceAda = c.price_ada || 0;
        if (this.selectedVariant && c.variants) {
            for (var vi = 0; vi < c.variants.length; vi++) {
                if (c.variants[vi].variant === this.selectedVariant) {
                    displayPrice = c.variants[vi].price_usd || displayPrice;
                    displayPriceAda = c.variants[vi].price_ada || displayPriceAda;
                    break;
                }
            }
        }

        // Image.
        var imageUrl = resolveIpfs(c.collection_image || c.image_url || '');

        var html = '';

        // Hero image.
        if (imageUrl) {
            html += '<img class="cmw-image" src="' + escAttr(imageUrl) + '" alt="' + escAttr(c.title || '') + '">';
        }

        // Header.
        html += '<div class="cmw-header">';
        html += '<span class="cmw-title">' + escHtml(c.title || 'NFT Collection') + '</span>';
        if (canMint) {
            html += '<span class="cmw-badge cmw-badge--live">LIVE</span>';
        } else if (soldOut) {
            html += '<span class="cmw-badge cmw-badge--ended">SOLD OUT</span>';
        } else {
            html += '<span class="cmw-badge cmw-badge--ended">ENDED</span>';
        }
        html += '</div>';

        // Expiry line.
        if (expiryText) {
            html += '<div class="cmw-expiry' + (expired ? ' cmw-expiry--expired' : '') + '">' + escHtml(expiryText) + '</div>';
        }

        // Price + Supply info cards.
        html += '<div class="cmw-info">';
        html += '<div class="cmw-info-card">';
        html += '<div class="cmw-info-label">Price</div>';
        html += '<div class="cmw-info-value">$' + displayPrice.toFixed(2) + '</div>';
        html += '<div class="cmw-info-sub">' + displayPriceAda.toFixed(2) + ' ADA</div>';
        html += '</div>';
        html += '<div class="cmw-info-card">';
        html += '<div class="cmw-info-label">Supply</div>';
        html += '<div class="cmw-info-value">' + remaining + ' / ' + total + '</div>';
        html += '<div class="cmw-supply-bar"><div class="cmw-supply-fill" style="width:' + supplyPct + '%"></div></div>';
        html += '</div>';
        html += '</div>';

        // Variants (if more than 1).
        if (c.variants && c.variants.length > 1) {
            html += '<div class="cmw-variants">';
            html += '<div class="cmw-variants-label">Variants</div>';
            html += '<div class="cmw-variant-list">';
            for (var i = 0; i < c.variants.length; i++) {
                var v = c.variants[i];
                var isActive = v.variant === this.selectedVariant;
                var vRemaining = v.quantity_remaining || 0;
                html += '<button class="cmw-variant-btn' + (isActive ? ' active' : '') + '" data-variant="' + escAttr(v.variant) + '">';
                html += escHtml(v.title || v.variant);
                if (vRemaining <= 0) html += ' (Sold Out)';
                html += '</button>';
            }
            html += '</div>';
            html += '</div>';
        }

        // Status area.
        html += '<div class="cmw-status cmw-status--info" id="cmw-status" style="display:none"></div>';

        // Wallet + Mint section.
        html += '<div class="cmw-wallet-section">';

        if (this.wallet.connected) {
            html += '<div class="cmw-wallet-connected"><span>&#9679;</span> ' + escHtml(this.wallet.name) + ': ' + this.wallet.address.substring(0, 14) + '...' + this.wallet.address.slice(-6) + '</div>';

            if (canMint && !this.minting) {
                html += '<button class="cmw-btn-mint cmw-btn-mint--ready" id="cmw-mint-btn">MINT NOW</button>';
            } else if (this.minting) {
                html += '<button class="cmw-btn-mint cmw-btn-mint--minting" disabled>MINTING...</button>';
            } else {
                html += '<button class="cmw-btn-mint cmw-btn-mint--disabled" disabled>' + (soldOut ? 'SOLD OUT' : 'MINTING ENDED') + '</button>';
            }
        } else {
            var wallets = getInstalledWallets();
            if (wallets.length > 0) {
                html += '<div class="cmw-wallet-list">';
                for (var w = 0; w < wallets.length; w++) {
                    var wName = window.cardano[wallets[w]].name || wallets[w];
                    html += '<button class="cmw-wallet-btn" data-wallet="' + escAttr(wallets[w]) + '">' + escHtml(wName) + '</button>';
                }
                html += '</div>';
            } else {
                html += '<div class="cmw-status cmw-status--info">No Cardano wallet detected. Install Eternl, Lace, or another CIP-30 wallet.</div>';
            }
            html += '<button class="cmw-btn-mint cmw-btn-mint--disabled" disabled>CONNECT WALLET TO MINT</button>';
        }

        html += '</div>';

        // Footer.
        html += '<div class="cmw-footer">Powered by <a href="https://cardanowordpressplugins.com" target="_blank" rel="noopener">Cardano Minting</a></div>';

        this.root.innerHTML = html;
        this.bindEvents();
    };

    /* ── Event Binding ────────────────────────────────────────────── */

    CardanoMintWidget.prototype.bindEvents = function () {
        var self = this;

        // Wallet connect buttons.
        var walletBtns = this.root.querySelectorAll('.cmw-wallet-btn');
        for (var i = 0; i < walletBtns.length; i++) {
            walletBtns[i].addEventListener('click', function () {
                self.connectWallet(this.getAttribute('data-wallet'));
            });
        }

        // Variant buttons.
        var variantBtns = this.root.querySelectorAll('.cmw-variant-btn');
        for (var j = 0; j < variantBtns.length; j++) {
            variantBtns[j].addEventListener('click', function () {
                self.selectedVariant = this.getAttribute('data-variant');
                self.render();
            });
        }

        // Mint button.
        var mintBtn = this.root.getElementById('cmw-mint-btn');
        if (mintBtn) {
            mintBtn.addEventListener('click', function () {
                self.doMint();
            });
        }
    };

    /* ── Wallet Connect ──────────────────────────────────────────── */

    CardanoMintWidget.prototype.connectWallet = function (key) {
        var self = this;
        this.showStatus('Connecting wallet...', 'info');

        window.cardano[key].enable().then(function (api) {
            self.wallet.handler = api;
            self.wallet.key = key;
            self.wallet.name = window.cardano[key].name || key;

            return api.getUsedAddresses().then(function (addresses) {
                if (addresses && addresses.length > 0) return addresses[0];
                return api.getUnusedAddresses().then(function (unused) {
                    return (unused && unused.length > 0) ? unused[0] : null;
                });
            });
        }).then(function (addrHex) {
            if (!addrHex) throw new Error('No address found in wallet.');
            self.wallet.addressHex = addrHex;
            self.wallet.address = hexToBech32(addrHex);
            self.wallet.connected = true;
            self.hideStatus();
            self.render();
            CardanoMintWidget._emit('walletConnected', { address: self.wallet.address, wallet: self.wallet.name });
        }).catch(function (err) {
            console.error('[CMW] Wallet connect error:', err);
            self.showStatus('Failed to connect wallet: ' + (err.message || err), 'error');
        });
    };

    /* ── Mint Flow ───────────────────────────────────────────────── */

    CardanoMintWidget.prototype.doMint = function () {
        var self = this;
        if (this.minting) return;
        this.minting = true;
        this.render();

        this.showStatus('Building transaction...', 'info');

        // Step 1: Build mint transaction via server.
        apiPost('/mint/build', {
            collection_id: this.collection.collection_id,
            variant: this.selectedVariant || '',
            customer_address: this.wallet.address,
        }).then(function (buildResult) {
            if (buildResult.error) throw new Error(buildResult.error);

            var txCbor = buildResult.transaction || buildResult.tx || '';
            if (!txCbor) throw new Error('No transaction returned from server.');

            self.showStatus('Please sign the transaction in your wallet...', 'info');

            // Step 2: Sign with user's wallet.
            return self.wallet.handler.signTx(txCbor, true).then(function (witnessSet) {
                self.showStatus('Submitting transaction...', 'info');

                // Step 3: Submit via server (adds policy wallet signature).
                return apiPost('/mint/submit', {
                    transaction: txCbor,
                    witnesses: [witnessSet],
                    policy_id: self.collection.policy_id || '',
                });
            });
        }).then(function (submitResult) {
            if (submitResult.error) throw new Error(submitResult.error);

            var txHash = submitResult.txHash || submitResult.tx_hash || submitResult.hash || '';
            self.minting = false;
            self.render();
            self.showStatus('Mint successful! TX: ' + (txHash ? txHash.substring(0, 20) + '...' : 'Submitted'), 'success');
            CardanoMintWidget._emit('mintSuccess', { txHash: txHash, collection: self.collection.collection_id });

            // Refresh collection data after successful mint.
            setTimeout(function () {
                apiGet('/collections/' + CFG.collection).then(function (data) {
                    if (!data.error) {
                        self.collection = data;
                        self.render();
                    }
                });
            }, 3000);

        }).catch(function (err) {
            console.error('[CMW] Mint error:', err);
            self.minting = false;
            self.render();
            self.showStatus('Mint failed: ' + (err.message || err), 'error');
            CardanoMintWidget._emit('mintError', { error: err.message || err });
        });
    };

    /* ── Status Helpers ──────────────────────────────────────────── */

    CardanoMintWidget.prototype.showStatus = function (msg, type) {
        var el = this.root.getElementById('cmw-status');
        if (!el) return;
        el.className = 'cmw-status cmw-status--' + (type || 'info');
        el.textContent = msg;
        el.style.display = 'block';
    };

    CardanoMintWidget.prototype.hideStatus = function () {
        var el = this.root.getElementById('cmw-status');
        if (el) el.style.display = 'none';
    };

    /* ── Boot ─────────────────────────────────────────────────────── */

    function boot() {
        var container = document.getElementById(CFG.container);
        if (!container) {
            console.error('[CMW] Container element #' + CFG.container + ' not found.');
            return;
        }
        var widget = new CardanoMintWidget(container);
        widget.init();
        window.CardanoMintWidget = CardanoMintWidget;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
