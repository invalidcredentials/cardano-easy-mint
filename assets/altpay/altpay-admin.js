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
                const mn = data.mnemonic ? `\n\nMNEMONIC (copy NOW — shown once):\n${data.mnemonic}` : '';
                window.alert(`Generated ${chain.toUpperCase()} wallet #${data.id}\nReceive index 0: ${data.address0 || '(see derivation)'}${mn}`);
                window.location.reload();
            })
            .catch(function (e) {
                window.alert('Generate failed: ' + e.message);
                $btn.prop('disabled', false).text('Generate');
            });
    });

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
