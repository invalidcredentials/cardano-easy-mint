(function ($) {
    'use strict';

    // Admin-side glue for the Payment Wallets page.
    // All actions go through admin-ajax with the cardanocheckoutnonce nonce
    // already localized in the cardanoAltPay global.

    const cfg = window.cardanoAltPay || {};

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
            navigator.clipboard.writeText(mnemonic).then(function () {
                $b.text('Copied. Paste into your password manager now.');
            }, function () {
                window.alert('Clipboard copy failed. Select the words above and copy manually.');
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
