/* global jQuery, cardanoOnramp */
/**
 * On-ramp admin JS
 *
 * Handles the three AJAX surfaces on the On-ramp tab:
 *   1. Save settings  (form submit)
 *   2. Test connection  (button)
 *   3. Refresh session  (per-row button)
 */
(function ($) {
    'use strict';
    if (typeof cardanoOnramp === 'undefined') return;

    function postJson(action, data) {
        return $.post(cardanoOnramp.ajaxurl, $.extend({
            action: action,
            nonce: cardanoOnramp.nonce
        }, data || {}));
    }

    function setResult(node, ok, text) {
        var $n = $(node);
        $n.removeClass('kg-ok kg-err').empty();
        $n.text(text);
        $n.css({
            color: ok ? '#0a7d22' : '#b91c1c',
            fontWeight: '600'
        });
    }

    $(document).on('submit', '#kg-onramp-settings-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var data = {};
        $form.serializeArray().forEach(function (kv) { data[kv.name] = kv.value; });
        // Include the unchecked-checkbox case: the browser omits unchecked
        // checkboxes from form serialization, so we flip the on/off ourselves.
        data.onramp_enabled = $form.find('input[name=onramp_enabled]').is(':checked') ? '1' : '';

        var $btn = $form.find('button[data-action=onramp-save]');
        $btn.prop('disabled', true).text('Saving…');

        postJson('cardano_onramp_save_settings', data)
            .always(function () { $btn.prop('disabled', false).text('Save settings'); })
            .done(function (resp) {
                if (resp && resp.success) {
                    setResult('#kg-onramp-test-result', true, 'Saved.');
                    // Clear password fields so a re-save doesn't re-encrypt the masked placeholder
                    $form.find('#kg-onramp-api-key, #kg-onramp-secret-key').val('');
                } else {
                    setResult('#kg-onramp-test-result', false, (resp && resp.data && resp.data.message) || 'Save failed');
                }
            })
            .fail(function () { setResult('#kg-onramp-test-result', false, 'Network error'); });
    });

    $(document).on('click', '[data-action=onramp-test-connection]', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        setResult('#kg-onramp-test-result', true, 'Testing…');
        postJson('cardano_onramp_test_connection')
            .always(function () { $btn.prop('disabled', false); })
            .done(function (resp) {
                if (resp && resp.success && resp.data) {
                    setResult('#kg-onramp-test-result', !!resp.data.ok, (resp.data.ok ? '✓ ' : '✗ ') + resp.data.message + ' [' + resp.data.env + ']');
                } else {
                    setResult('#kg-onramp-test-result', false, 'Test failed');
                }
            })
            .fail(function () { setResult('#kg-onramp-test-result', false, 'Network error'); });
    });

    $(document).on('click', '[data-action=onramp-refresh-session]', function () {
        var $btn = $(this);
        var partnerLink = $btn.data('partner-link');
        if (!partnerLink) return;
        $btn.prop('disabled', true).text('…');
        postJson('cardano_onramp_refresh_session', { partner_link_id: partnerLink })
            .always(function () { $btn.prop('disabled', false).text('Refresh'); })
            .done(function (resp) {
                if (resp && resp.success && resp.data) {
                    // Update the row's status pill in place; full reload would
                    // lose any other partial state on the page.
                    var $row = $btn.closest('tr');
                    var $pill = $row.find('.kg-onramp-status');
                    $pill.removeClass(function (_, cls) {
                        return (cls.match(/kg-onramp-status--\S+/g) || []).join(' ');
                    }).addClass('kg-onramp-status--' + resp.data.status).text(resp.data.status);
                    if (resp.data.is_terminal) $btn.remove();
                }
            });
    });
})(jQuery);
