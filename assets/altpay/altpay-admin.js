(function ($) {
    'use strict';

    // Admin-side glue for the Payment Wallets page.
    // All actions go through admin-ajax with the cardanocheckoutnonce nonce
    // already localized in the cardanoAltPay global.

    const cfg = window.cardanoAltPay || {};

    // navigator.clipboard.writeText only works in a secure context
    // (https or localhost). On http://*.local hosts it silently rejects,
    // so fall back to a hidden textarea + execCommand('copy').
    function copyText(text) {
        const tryNative = (window.isSecureContext || window.location.protocol === 'https:')
            && navigator.clipboard
            && navigator.clipboard.writeText;
        if (tryNative) {
            return navigator.clipboard.writeText(text).catch(legacyCopy.bind(null, text));
        }
        return legacyCopy(text);
    }

    function legacyCopy(text) {
        return new Promise(function (resolve, reject) {
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                ta.setSelectionRange(0, text.length);
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                if (ok) resolve(); else reject(new Error('execCommand copy returned false'));
            } catch (e) {
                reject(e);
            }
        });
    }

    function ajax(action, payload) {
        const data = Object.assign({ action: action, nonce: cfg.nonce }, payload || {});
        return $.post(cfg.ajaxurl, data).then(function (resp) {
            if (!resp || !resp.success) {
                const msg = (resp && resp.data && resp.data.message) || 'request failed';
                throw new Error(msg);
            }
            return resp.data;
        });
    }

    function toggleForm(formName, chain, show) {
        $(`[data-form="${formName}"][data-chain="${chain}"]`).toggle(show);
    }

    $(document).on('click', '[data-action="altpay-generate"]', function () {
        toggleForm('altpay-generate', $(this).data('chain'), true);
        toggleForm('altpay-import', $(this).data('chain'), false);
    });

    $(document).on('click', '[data-action="altpay-import"]', function () {
        toggleForm('altpay-import', $(this).data('chain'), true);
        toggleForm('altpay-generate', $(this).data('chain'), false);
    });

    $(document).on('click', '[data-action="altpay-generate-cancel"], [data-action="altpay-import-cancel"]', function () {
        $('[data-form]').hide();
    });

    $(document).on('click', '[data-action="altpay-generate-confirm"]', function () {
        const $btn = $(this);
        const chain = $btn.data('chain');
        const $form = $(`[data-form="altpay-generate"][data-chain="${chain}"]`);
        const name = $form.find('[data-field="name"]').val();
        const network = $form.find('[data-field="network"]').val();
        $btn.prop('disabled', true).text('Generating…');
        ajax('cardano_altpay_generate_wallet', { chain: chain, name: name, network: network })
            .then(function (data) {
                showMnemonicReveal(chain, data);
            })
            .catch(function (e) {
                window.alert('Generate failed: ' + e.message);
                $btn.prop('disabled', false).text('Generate');
            });
    });

    function showMnemonicReveal(chain, data) {
        const mnemonic = data.mnemonic || '';
        const words = mnemonic ? mnemonic.split(/\s+/).filter(Boolean) : [];
        const rows = [];
        for (let i = 0; i < words.length; i += 6) {
            rows.push(words.slice(i, i + 6).map(function (w, j) {
                const idx = (i + j + 1).toString().padStart(2, ' ');
                return `<span class="mnemonic-word" style="display:inline-block; min-width:130px;"><span style="color:#888;">${idx}.</span> ${w}</span>`;
            }).join(''));
        }

        // Backdrop + dialog injected inline so no CSS file change required.
        const html = `
        <div class="kg-altpay-reveal-backdrop" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:100000; display:flex; align-items:center; justify-content:center;">
            <div class="kg-altpay-reveal-dialog" style="background:#fff; max-width:760px; width:92%; padding:26px 28px; border-radius:8px; box-shadow:0 10px 40px rgba(0,0,0,0.35);">
                <h2 style="color:#dc3545; margin-top:0;">CRITICAL: SAVE YOUR RECOVERY PHRASE NOW</h2>
                <p style="font-size:14px; line-height:1.5;">
                    <strong>This will only be shown ONCE.</strong> Write these 24 words down in order and store them somewhere safe.
                    Anyone with this phrase can drain the ${chain.toUpperCase()} wallet.
                </p>
                <p style="font-size:13px; margin:0 0 6px 0;">
                    <strong>Wallet:</strong> #${data.id} &nbsp;
                    <strong>Receive index 0:</strong>
                    <code style="word-break:break-all;">${data.address0 || '(derive on demand)'}</code>
                </p>
                <div class="kg-altpay-mnemonic-box" style="background:#000; color:#0f0; padding:18px 20px; border-radius:6px; font-family:'Courier New', ui-monospace, monospace; font-size:15px; line-height:1.85; margin:14px 0 16px 0; user-select:all;">
                    ${rows.map(function (r) { return r; }).join('<br>')}
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="button button-primary button-large" data-action="altpay-reveal-copy">Copy to Clipboard</button>
                    <button type="button" class="button button-large" data-action="altpay-reveal-dismiss">I have saved it, dismiss</button>
                </div>
                <p style="font-size:12px; color:#666; margin-top:14px;">After you dismiss this, the page will reload and the phrase will be gone forever.</p>
            </div>
        </div>`;

        const $overlay = $(html);
        $('body').append($overlay);

        $overlay.on('click', '[data-action="altpay-reveal-copy"]', function () {
            const $b = $(this);
            copyText(mnemonic).then(function () {
                $b.text('Copied. Paste into your password manager now.');
            }).catch(function () {
                window.alert('Clipboard copy failed. The words are selectable in the box above — triple-click and Ctrl+C.');
            });
        });
        $overlay.on('click', '[data-action="altpay-reveal-dismiss"]', function () {
            if (!window.confirm('Dismissing reloads the page and the mnemonic is gone forever. Continue?')) return;
            $overlay.remove();
            window.location.reload();
        });
    }

    $(document).on('click', '[data-action="altpay-import-confirm"]', function () {
        const $btn = $(this);
        const chain = $btn.data('chain');
        const $form = $(`[data-form="altpay-import"][data-chain="${chain}"]`);
        const name = $form.find('[data-field="name"]').val();
        const network = $form.find('[data-field="network"]').val();
        const secret = $form.find('[data-field="secret"]').val().trim();
        if (!secret) { window.alert('Mnemonic required'); return; }
        $btn.prop('disabled', true).text('Importing…');
        ajax('cardano_altpay_import_wallet', { chain: chain, name: name, network: network, secret: secret })
            .then(function (data) {
                window.alert(`Imported ${chain.toUpperCase()} wallet #${data.id}\nReceive index 0: ${data.address0 || '(see derivation)'}`);
                window.location.reload();
            })
            .catch(function (e) {
                window.alert('Import failed: ' + e.message);
                $btn.prop('disabled', false).text('Import');
            });
    });

    $(document).on('change', '[data-action="altpay-set-network"]', function () {
        const $sel = $(this);
        const id = $sel.data('wallet-id');
        const newVal = $sel.val();
        const oldVal = $sel.data('current');
        if (newVal === oldVal) return;
        // BTC mainnet uses coin_type 0 while testnet/signet share coin_type 1.
        // Flipping BETWEEN mainnet and testnet/signet changes derived addresses.
        // Flipping testnet <-> signet (or eth mainnet <-> sepolia) is safe.
        const isBtcCoinTypeShift = (oldVal === 'mainnet' && (newVal === 'testnet' || newVal === 'signet'))
                                || ((oldVal === 'testnet' || oldVal === 'signet') && newVal === 'mainnet');
        const isEthCoinTypeShift = (oldVal === 'mainnet' && newVal === 'sepolia')
                                || (oldVal === 'sepolia' && newVal === 'mainnet');
        const warn = isBtcCoinTypeShift
            ? 'Switching BTC mainnet <-> testnet/signet changes the derivation coin type, which means the previously-issued child addresses NO LONGER match what this wallet derives. Continue?'
            : (isEthCoinTypeShift ? 'Switching ETH networks is safe (same keys/addresses) but live invoices may need a Rescan. Continue?' : null);
        if (warn && !window.confirm(warn)) { $sel.val(oldVal); return; }
        ajax('cardano_altpay_set_wallet_network', { wallet_id: id, network: newVal })
            .then(function () {
                $sel.data('current', newVal);
                window.alert('Network set to ' + newVal + '. Existing pending invoices may need a Rescan from the Invoices tab.');
            })
            .catch(function (e) {
                $sel.val(oldVal);
                window.alert('Could not update network: ' + e.message);
            });
    });

    $(document).on('click', '[data-action="altpay-archive"]', function () {
        const id = $(this).data('wallet-id');
        if (!window.confirm('Archive this wallet? It will not appear in the dropdown for new invoices.')) return;
        ajax('cardano_altpay_archive_wallet', { wallet_id: id, archived: 1 })
            .then(function () { window.location.reload(); })
            .catch(function (e) { window.alert('Archive failed: ' + e.message); });
    });

    $(document).on('click', '[data-action="altpay-unarchive"]', function () {
        const id = $(this).data('wallet-id');
        ajax('cardano_altpay_archive_wallet', { wallet_id: id, archived: 0 })
            .then(function () { window.location.reload(); })
            .catch(function (e) { window.alert('Unarchive failed: ' + e.message); });
    });

    $(document).on('click', '[data-action="altpay-rescan"]', function () {
        const $btn = $(this);
        const id = $btn.data('invoice-id');
        $btn.prop('disabled', true).text('Rescanning…');
        ajax('cardano_altpay_rescan_invoice', { invoice_id: id })
            .then(function (data) {
                const inv = data.invoice || {};
                $btn.prop('disabled', false).text('Rescan');
                window.alert(`Invoice #${id} status: ${inv.status}\nObserved: ${inv.observed_amount_minor || '—'}`);
                window.location.reload();
            })
            .catch(function (e) {
                $btn.prop('disabled', false).text('Rescan');
                window.alert('Rescan failed: ' + e.message);
            });
    });

    // Async balance fill on the Dashboard cards. One AJAX per wallet.
    function fillDashboardBalances() {
        const slots = document.querySelectorAll('.kg-altpay-balance[data-dash-balance]');
        slots.forEach(function (el) {
            const id = el.getAttribute('data-dash-balance');
            const card = el.closest('[data-dash-chain]');
            const chain = card ? card.getAttribute('data-dash-chain') : '';
            ajax('cardano_altpay_wallet_balances', { wallet_id: id })
                .then(function (data) {
                    const major = formatMajor(chain, data.total_minor || '0');
                    el.innerHTML = '<strong>' + major + '</strong> ' + chain.toUpperCase()
                        + ' <span style="color:#888; font-size:11px;">(' + Number(data.total_minor || 0).toLocaleString('en-US') + ' ' + chainMinorLabel(chain) + ')</span>';
                })
                .catch(function () { el.innerHTML = '<span style="color:#a00;">RPC failed</span>'; });
        });
    }
    if (document.querySelector('.kg-altpay-dashboard')) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fillDashboardBalances);
        } else {
            fillDashboardBalances();
        }
    }

    function chainMinorLabel(c) {
        return c === 'btc' ? 'sats' : c === 'eth' ? 'wei' : 'lamports';
    }

    function formatMajor(chain, minorStr) {
        const decimals = { btc: 8, eth: 18, sol: 9 }[chain] || 0;
        const display  = { btc: 8, eth: 6, sol: 4 }[chain] != null ? { btc: 8, eth: 6, sol: 4 }[chain] : decimals;
        if (!minorStr || minorStr === '0') return '0';
        try {
            const big = BigInt(minorStr);
            const base = BigInt(10) ** BigInt(decimals);
            const whole = (big / base).toString();
            const frac = (big % base).toString().padStart(decimals, '0').slice(0, display).replace(/0+$/, '');
            return frac ? whole + '.' + frac : whole;
        } catch (e) {
            return (Number(minorStr) / Math.pow(10, decimals)).toFixed(display);
        }
    }

    $(document).on('click', '[data-action="altpay-send-from-wallet"]', function () {
        const $btn = $(this);
        const walletId = $btn.data('wallet-id');
        const chain    = String($btn.data('chain') || '').toLowerCase();
        const minorLabel = chainMinorLabel(chain);
        const sweepDefault = (cfg.sweepTargets && cfg.sweepTargets[chain]) || '';

        $btn.prop('disabled', true).text('Checking balances…');
        ajax('cardano_altpay_wallet_balances', { wallet_id: walletId })
            .then(function (data) {
                $btn.prop('disabled', false).text('Send funds');
                const total = data.total_minor || '0';
                if (total === '0') {
                    window.alert('No funds available across the child addresses on this wallet yet.');
                    return;
                }
                const totalMajor = formatMajor(chain, total);
                const lines = ['Total balance across child addresses: ' + totalMajor + ' ' + chain.toUpperCase() + ' (' + Number(total).toLocaleString('en-US') + ' ' + minorLabel + ')\n'];
                lines.push('Per-child:');
                data.children.forEach(function (c) {
                    if (c.balance_minor === '0') return;
                    lines.push('  index ' + c.index + ': ' + formatMajor(chain, c.balance_minor) + ' ' + chain.toUpperCase() + '  (' + c.address.substring(0, 10) + '…' + c.address.substring(c.address.length - 6) + ')');
                });
                lines.push('\nThis sends from the most-funded single child address. Repeat to drain more children.');
                window.alert(lines.join('\n'));

                const dest = window.prompt('Send to (your external ' + chain.toUpperCase() + ' wallet address):', sweepDefault);
                if (!dest) return;

                // Cap the default amount at (top-child balance - network fee buffer)
                // so sending 'max' doesn't overrun the fee. Customer can still type
                // any amount manually; this is just the prefill.
                //   BTC: ~10,000 sat conservative buffer (covers ~5 sat/vbyte * 200 vbytes)
                //   ETH: ~210,000 gwei (210,000,000,000 wei) conservative buffer
                //   SOL: 5,000 lamports (Solana base fee per signature)
                const FEE_BUFFER = chain === 'btc' ? 10000n
                                : chain === 'eth' ? 210000000000n
                                : chain === 'sol' ? 5000n
                                : 0n;
                let topBalance = data.children[0] && data.children[0].balance_minor !== '0' ? data.children[0].balance_minor : '';
                let suggested = topBalance;
                try {
                    if (topBalance) {
                        const balBig = BigInt(topBalance);
                        suggested = balBig > FEE_BUFFER ? (balBig - FEE_BUFFER).toString() : '0';
                    }
                } catch (e) { /* fall through with raw topBalance */ }

                const amt = window.prompt(
                    'Amount in ' + minorLabel + ' (smallest unit).\n\n' +
                    'Top child holds ' + (topBalance || '0') + ' ' + minorLabel + '.\n' +
                    'Suggested max (after ~' + FEE_BUFFER.toString() + ' ' + minorLabel + ' fee buffer): ' + suggested,
                    suggested
                );
                if (!amt) return;
                const cleanAmt = String(amt).replace(/[^0-9]/g, '');
                if (!cleanAmt || cleanAmt === '0') { window.alert('Amount must be a positive integer in ' + minorLabel + '.'); return; }

                if (!window.confirm('Confirm withdrawal:\n\n  wallet: #' + walletId + ' (' + chain.toUpperCase() + ')\n  amount: ' + cleanAmt + ' ' + minorLabel + ' (' + formatMajor(chain, cleanAmt) + ' ' + chain.toUpperCase() + ')\n  to: ' + dest + '\n\nThis broadcasts a real transaction. Continue?')) return;

                $btn.prop('disabled', true).text('Sending…');
                ajax('cardano_altpay_send_from_wallet', {
                    wallet_id: walletId,
                    to_address: dest.trim(),
                    amount_minor: cleanAmt,
                }).then(function (r) {
                    window.alert('Withdrawal broadcast.\n\nTx: ' + (r.tx_hash || '(no hash)') + '\nFrom child index: ' + r.source_index);
                    window.location.reload();
                }).catch(function (e) {
                    window.alert('Withdrawal failed: ' + e.message);
                    $btn.prop('disabled', false).text('Send funds');
                });
            })
            .catch(function (e) {
                $btn.prop('disabled', false).text('Send funds');
                window.alert('Could not load balances: ' + e.message);
            });
    });

    $(document).on('click', '[data-action="altpay-refund"]', function () {
        const $btn = $(this);
        const id    = $btn.data('invoice-id');
        const chain = String($btn.data('chain') || '').toLowerCase();
        const defaultAmt = String($btn.data('default-amount') || '');

        const minorLabel = chain === 'btc' ? 'sats' : chain === 'eth' ? 'wei' : 'lamports';
        const placeholderAddr = chain === 'btc' ? 'bc1q… or tb1q…'
            : chain === 'eth' ? '0x…'
            : 'Base58…';
        const toAddr = window.prompt('Refund destination (' + chain.toUpperCase() + ' address, ' + placeholderAddr + ')', '');
        if (!toAddr) return;
        const amount = window.prompt('Amount in ' + minorLabel + ' (smallest unit). Funded amount was ' + defaultAmt + '.', defaultAmt);
        if (!amount) return;
        const cleanAmount = String(amount).replace(/[^0-9]/g, '');
        if (!cleanAmount || cleanAmount === '0') { window.alert('Amount must be a positive integer in ' + minorLabel + '.'); return; }

        if (!window.confirm('Confirm refund:\n\n  invoice #' + id + '\n  chain: ' + chain.toUpperCase() + '\n  amount: ' + cleanAmount + ' ' + minorLabel + '\n  to: ' + toAddr + '\n\nThis broadcasts a real transaction. Continue?')) return;

        $btn.prop('disabled', true).text('Refunding…');
        ajax('cardano_altpay_refund_invoice', { invoice_id: id, to_address: toAddr.trim(), amount_minor: cleanAmount })
            .then(function (data) {
                window.alert('Refund broadcast.\n\nTx: ' + (data.tx_hash || '(no hash)'));
                window.location.reload();
            })
            .catch(function (e) {
                window.alert('Refund failed: ' + e.message);
                $btn.prop('disabled', false).text('Refund');
            });
    });

    $(document).on('click', '[data-action="altpay-save-settings"]', function () {
        const $btn = $(this);
        const $form = $('#kg-altpay-settings-form');
        const payload = {};
        $form.find('input, select').each(function () {
            const name = this.name;
            if (!name) return;
            if (this.type === 'checkbox') {
                if (this.checked) payload[name] = '1';
            } else {
                payload[name] = $(this).val();
            }
        });
        $btn.prop('disabled', true);
        $('.kg-altpay-save-status').text('Saving…').css('color', '#555');
        ajax('cardano_altpay_save_settings', payload)
            .then(function () {
                $('.kg-altpay-save-status').text('Saved.').css('color', '#0a7d22');
                $btn.prop('disabled', false);
            })
            .catch(function (e) {
                $('.kg-altpay-save-status').text('Failed: ' + e.message).css('color', '#a00');
                $btn.prop('disabled', false);
            });
    });

})(jQuery);
