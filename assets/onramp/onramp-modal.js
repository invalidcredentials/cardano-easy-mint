/* global cardanoOnrampPublic */
/**
 * KG-themed Guardarian on-ramp modal.
 *
 * Public API:
 *   window.KGOnramp.open({
 *     amountUsd:                100,                   // pre-fills amount input
 *     customerCardanoAddress:   'addr1...',
 *     mintId:                   123,                   // optional, links session to a mint
 *     network:                  'mainnet',             // for Blockfrost polling
 *     expectedAdaArrival:       '90',                  // minimum lovelace bump to consider "arrived"
 *     onComplete:               function(state) { … }, // fired when ADA detected in wallet
 *     onClose:                  function() { … },      // user dismissed without success
 *   })
 *
 * State machine:
 *   quote   pre-flight: amount picker + indicative ADA, customer confirms
 *   iframe  Guardarian hosted checkout embedded; status polling every 5s
 *   waiting Guardarian reported deposit captured / sending / finished;
 *           polling Blockfrost for actual wallet-balance bump
 *   success ADA confirmed in customer wallet, "Mint now" CTA shown
 *   error   unrecoverable; close button + retry
 *
 * Configuration is read from window.cardanoOnrampPublic (localized by
 * the plugin enqueuer): { restRoot, nonce, defaultNetwork, feeBufferAda }.
 */
(function () {
    'use strict';

    var ROOT = (typeof cardanoOnrampPublic === 'object' && cardanoOnrampPublic) ? cardanoOnrampPublic : {};
    var REST = (ROOT.restRoot || '/wp-json/cardano-mint/v1/').replace(/\/+$/, '/');
    var NONCE = ROOT.nonce || '';
    var DEFAULT_NETWORK = ROOT.defaultNetwork || 'mainnet';
    var FEE_BUFFER_ADA = parseInt(ROOT.feeBufferAda, 10) || 7;
    // When false (default), Guardarian's iframe asks the customer for their
    // wallet address — we surface a copy button on the pre-flight UI and
    // pre-load the clipboard so paste is one-tap.
    var PRESET_PAYOUT = !!ROOT.presetPayout;
    var PARTNER_API_KEY = ROOT.partnerApiKey || '';
    var WIDGET_BASE_URL = ROOT.widgetBaseUrl || 'https://guardarian.com/calculator/v1';

    var state = null; // active session state, set on open()

    // ─── Public API ──────────────────────────────────────────────────

    var KGOnramp = {
        open: function (opts) { open(opts || {}); },
        close: function () { closeModal(); }
    };
    window.KGOnramp = KGOnramp;

    // Auto-resume after Guardarian's success redirect. Guardarian navigates
    // the top frame back to redirects.successful (set to <mint-url>?kg-onramp-return=
    // <partner_link_id> below); on landing, we look up the session, scrub
    // the query param so refreshes don't re-fire, and open the modal
    // straight into the "waiting for ADA" view with Blockfrost polling.
    if (typeof window !== 'undefined' && window.location) {
        var qp = new URLSearchParams(window.location.search);
        var pendingLink = qp.get('kg-onramp-return');
        if (pendingLink) {
            qp.delete('kg-onramp-return');
            var newSearch = qp.toString();
            var cleanUrl = window.location.pathname + (newSearch ? '?' + newSearch : '') + window.location.hash;
            try { window.history.replaceState({}, '', cleanUrl); } catch (_) {}
            // Defer until DOM is ready so we can mount the overlay.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { resumeFromRedirect(pendingLink); });
            } else {
                resumeFromRedirect(pendingLink);
            }
        }
    }

    function resumeFromRedirect(partnerLinkId) {
        // Fetch the session to recover the payout address; we need it for
        // Blockfrost polling and don't want to require a wallet reconnect
        // just to surface the waiting view.
        api('onramp/sessions/' + encodeURIComponent(partnerLinkId))
            .then(function (s) {
                var address = s.payout_address;
                if (!address) return;
                // Mount a synthetic state matching what createSession would
                // have produced, then jump straight to renderWaiting.
                if (state) closeModal();
                state = {
                    opts: { customerCardanoAddress: address, network: DEFAULT_NETWORK },
                    phase: null,
                    shell: null,
                    session: {
                        partner_link_id:    partnerLinkId,
                        provider_tx_id:     null,
                        to_amount_estimated: s.to_amount_estimated,
                        from_amount:        s.from_amount,
                        from_currency:      s.from_currency,
                    },
                    initialLovelace: 0,
                    statusPoll: null,
                    balancePoll: null,
                    balanceDeadline: null,
                    iframeAbandonOk: true,
                };
                state.shell = buildShell();
                renderWaiting(s);
                // Capture current balance as baseline so the +ADA delta
                // we display is the funds that arrived from this purchase.
                api('onramp/wallet-balance?address=' + encodeURIComponent(address) + '&network=' + encodeURIComponent(DEFAULT_NETWORK))
                    .then(function (bal) { state.initialLovelace = parseInt(bal.lovelace, 10) || 0; })
                    .catch(function () {});
                startBalancePolling();
            })
            .catch(function (e) {
                console.warn('[KGOnramp] resume failed:', e && e.message);
            });
    }

    // ─── DOM helpers ─────────────────────────────────────────────────

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'class') n.className = attrs[k];
            else if (k === 'style' && typeof attrs[k] === 'object') Object.assign(n.style, attrs[k]);
            else if (k === 'dataset') Object.assign(n.dataset, attrs[k]);
            else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') n.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
            else if (k === 'html') n.innerHTML = attrs[k];
            else n.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) {
            if (c == null) return;
            if (typeof c === 'string') n.appendChild(document.createTextNode(c));
            else n.appendChild(c);
        });
        return n;
    }

    function api(path, opts) {
        opts = opts || {};
        var headers = { 'X-WP-Nonce': NONCE };
        if (opts.body && typeof opts.body !== 'string') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        return fetch(REST + path.replace(/^\/+/, ''), {
            method: opts.method || 'GET',
            headers: headers,
            body: opts.body || undefined,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    // Log full payload so the dev console always has the
                    // raw response — error message text alone often hides
                    // the field-level validation detail.
                    try { console.warn('[KGOnramp] error response:', r.status, data); } catch (e) {}
                    // WP REST shape on error: { code, message, data: {status, body, ...} }
                    var msg = '';
                    if (data) {
                        if (typeof data.message === 'string' && data.message) msg = data.message;
                        else if (data.data && typeof data.data.body === 'string') msg = data.data.body;
                        else if (typeof data.code === 'string') msg = data.code;
                    }
                    if (!msg) msg = 'http ' + r.status;
                    var err = new Error(msg);
                    err.payload = data;
                    err.status = r.status;
                    throw err;
                }
                return data;
            });
        });
    }

    function fmtUsd(v) {
        var n = parseFloat(v);
        if (!isFinite(n)) return String(v);
        return '$' + n.toFixed(2);
    }
    function fmtAda(v) {
        if (v == null) return '—';
        var n = parseFloat(v);
        if (!isFinite(n)) return String(v);
        return n.toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ₳';
    }
    function lovelaceToAda(lovelace) {
        var n = parseInt(lovelace, 10);
        if (!isFinite(n)) return 0;
        return n / 1000000;
    }
    function shortenAddress(addr) {
        if (!addr || addr.length <= 22) return addr || '';
        return addr.slice(0, 12) + '…' + addr.slice(-6);
    }
    function copyToClipboard(text) {
        // Modern API first; falls back to a hidden textarea + execCommand for
        // older Safari and HTTP-only sites where the Clipboard API is gated.
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }
    function fallbackCopy(text) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) { return false; }
    }

    // ─── Modal scaffold ──────────────────────────────────────────────

    function buildShell() {
        var overlay = el('div', { class: 'kg-onramp-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'kg-onramp-title' });
        var modal = el('div', { class: 'kg-onramp-modal' });

        var titleNode = el('h2', { class: 'kg-onramp-title', id: 'kg-onramp-title' }, ['Buy ADA with card']);
        var subtitleNode = el('p', { class: 'kg-onramp-subtitle' }, ['Funds land directly in your connected wallet.']);
        var headerLeft = el('div', null, [titleNode, subtitleNode]);
        var closeBtn = el('button', {
            class: 'kg-onramp-close',
            type: 'button',
            'aria-label': 'Close',
            onclick: function () { confirmClose(); }
        }, ['×']);
        var header = el('div', { class: 'kg-onramp-header' }, [headerLeft, closeBtn]);

        var body = el('div', { class: 'kg-onramp-body' });
        var footer = el('div', { class: 'kg-onramp-footer' }, [
            'Card processing by ',
            el('a', { href: 'https://guardarian.com', target: '_blank', rel: 'noopener' }, ['Guardarian']),
            '. Your funds go straight to your own wallet — we never hold them.'
        ]);

        modal.appendChild(header);
        modal.appendChild(body);
        modal.appendChild(footer);
        overlay.appendChild(modal);

        // Backdrop click closes (with confirmation in iframe phase)
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) confirmClose();
        });
        // ESC closes
        document.addEventListener('keydown', escHandler);

        document.body.appendChild(overlay);
        // Lock body scroll while open
        document.body.style.overflow = 'hidden';

        // Animate in next frame so the transition fires
        requestAnimationFrame(function () { overlay.classList.add('is-open'); });

        return { overlay: overlay, modal: modal, header: header, body: body, footer: footer, titleNode: titleNode, subtitleNode: subtitleNode };
    }

    function escHandler(e) { if (e.key === 'Escape') confirmClose(); }

    function confirmClose() {
        if (state && state.phase === 'iframe' && !state.iframeAbandonOk) {
            if (!window.confirm('Close this window? Your card payment is in progress and may be lost.')) return;
        }
        if (state && state.phase === 'waiting') {
            // ADA is on its way — closing is fine but warn so the user isn't surprised
            if (!window.confirm('Your ADA is on the way. You can close this and check your wallet later — the payment will still arrive.')) return;
        }
        if (state && typeof state.opts.onClose === 'function') {
            try { state.opts.onClose(state); } catch (e) {}
        }
        closeModal();
    }

    function closeModal() {
        if (!state) return;
        clearTimers();
        document.removeEventListener('keydown', escHandler);
        document.body.style.overflow = '';
        if (state.shell && state.shell.overlay && state.shell.overlay.parentNode) {
            state.shell.overlay.classList.remove('is-open');
            var ov = state.shell.overlay;
            setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 220);
        }
        state = null;
    }

    function clearTimers() {
        if (!state) return;
        if (state.statusPoll) { clearInterval(state.statusPoll); state.statusPoll = null; }
        if (state.balancePoll) { clearInterval(state.balancePoll); state.balancePoll = null; }
        if (state.balanceDeadline) { clearTimeout(state.balanceDeadline); state.balanceDeadline = null; }
        if (state.messageListener) { window.removeEventListener('message', state.messageListener); state.messageListener = null; }
    }

    // ─── State / phase transitions ───────────────────────────────────

    function open(opts) {
        if (state) closeModal();
        state = {
            opts: opts,
            phase: null,
            shell: null,
            session: null,
            initialLovelace: null,
            statusPoll: null,
            balancePoll: null,
            balanceDeadline: null,
            iframeAbandonOk: false
        };
        state.shell = buildShell();
        renderQuote();
    }

    function setPhase(p) {
        if (!state) return;
        state.phase = p;
        // Body padding switches when iframe is full-bleed
        if (p === 'iframe') state.shell.body.classList.add('kg-onramp-body--iframe');
        else state.shell.body.classList.remove('kg-onramp-body--iframe');
        if (p === 'iframe') state.shell.modal.classList.add('kg-onramp-modal--iframe');
        else state.shell.modal.classList.remove('kg-onramp-modal--iframe');
    }

    // ─── Phase: quote ────────────────────────────────────────────────

    function renderQuote() {
        setPhase('quote');
        state.shell.titleNode.textContent = 'Buy ADA with card';
        state.shell.subtitleNode.textContent = 'Funds land directly in your connected wallet.';

        var amount = parseFloat(state.opts.amountUsd) || 100;

        var amountInput = el('input', {
            type: 'text',
            inputmode: 'decimal',
            value: String(amount),
            'aria-label': 'Amount in USD'
        });
        var quoteValueNode = el('div', { class: 'kg-onramp-quote__value kg-onramp-quote__value--accent' }, ['—']);
        var minMaxNode = el('p', { class: 'kg-onramp-help' }, ['Fetching limits…']);
        var errorNode = el('p', { class: 'kg-onramp-error', style: { display: 'none' } }, ['']);
        var continueBtn = el('button', { class: 'kg-onramp-btn kg-onramp-btn--primary', disabled: 'disabled' }, ['Continue to checkout']);
        var cancelBtn = el('button', { class: 'kg-onramp-btn kg-onramp-btn--ghost' }, ['Cancel']);

        // Connected wallet address row — shown in both modes so the customer
        // sees where their funds will land. With preset off (the common case)
        // the Copy button is the keystone of the UX since they'll paste it
        // into Guardarian's address field next.
        var address = state.opts.customerCardanoAddress || '';
        var copyBtn = el('button', {
            type: 'button',
            class: 'kg-onramp-btn kg-onramp-btn--ghost',
            style: { flex: '0 0 auto', padding: '8px 12px', fontSize: '12px' }
        }, ['Copy']);
        copyBtn.addEventListener('click', function () {
            copyToClipboard(address).then(function (ok) {
                copyBtn.textContent = ok ? 'Copied ✓' : 'Press ⌘C';
                setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1600);
            });
        });
        var addressRow = el('div', {
            class: 'kg-onramp-quote__row',
            style: { gap: '10px' }
        }, [
            el('div', { style: { display: 'flex', flexDirection: 'column', minWidth: '0', flex: '1 1 auto' } }, [
                el('span', { class: 'kg-onramp-quote__label' }, ['Funds go to your wallet']),
                el('span', {
                    title: address,
                    style: {
                        fontFamily: 'ui-monospace, SFMono-Regular, "Cascadia Mono", Menlo, monospace',
                        fontSize: '13px',
                        color: 'var(--kg-text-bright, #e0e6ed)',
                        marginTop: '4px',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap'
                    }
                }, [shortenAddress(address)])
            ]),
            copyBtn
        ]);

        var headsUpText = PRESET_PAYOUT
            ? (' you\'ll need ~' + FEE_BUFFER_ADA + ' ADA in your wallet to mint (network fee + min UTxO). The amount above already covers that for a typical $' + Math.round(amount) + ' mint.')
            : (' Guardarian will ask for your wallet address in a moment — we\'ve got it ready to paste. You\'ll also need ~' + FEE_BUFFER_ADA + ' ADA on top to cover the mint network fee, which the amount above already covers.');

        var quoteBlock = el('div', { class: 'kg-onramp-quote' }, [
            el('div', null, [
                el('div', { class: 'kg-onramp-quote__label', style: { marginBottom: '8px' } }, ['You pay']),
                el('div', { class: 'kg-onramp-amount-input' }, [
                    el('span', { class: 'kg-onramp-amount-input__currency' }, ['$']),
                    amountInput,
                    el('span', { class: 'kg-onramp-amount-input__suffix' }, ['USD'])
                ])
            ]),
            el('div', { class: 'kg-onramp-quote__row' }, [
                el('span', { class: 'kg-onramp-quote__label' }, ['You receive (est.)']),
                quoteValueNode
            ]),
            addressRow,
            minMaxNode,
            el('p', { class: 'kg-onramp-help' }, [
                el('strong', null, ['Heads up:']),
                headsUpText
            ]),
            errorNode,
            el('div', { class: 'kg-onramp-actions' }, [cancelBtn, continueBtn])
        ]);

        // Replace body
        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(quoteBlock);

        // Quote refresh debounced as user types
        var quoteTimer = null;
        var seq = 0;
        function refreshQuote() {
            var current = ++seq;
            var amt = parseFloat(amountInput.value) || 0;
            if (amt <= 0) {
                quoteValueNode.textContent = '—';
                continueBtn.disabled = true;
                return;
            }
            api('onramp/quote', { method: 'POST', body: { from_currency: 'USD', from_amount: amt } })
                .then(function (q) {
                    if (current !== seq) return; // stale response
                    quoteValueNode.textContent = q.to_amount_estimated ? fmtAda(q.to_amount_estimated) : '—';
                    if (q.min || q.max) {
                        minMaxNode.textContent = 'Limits: ' + fmtUsd(q.min || 0) + ' – ' + fmtUsd(q.max || 0) + ' per transaction.';
                    } else {
                        minMaxNode.textContent = '';
                    }
                    errorNode.style.display = 'none';
                    continueBtn.disabled = false;
                    state.lastQuote = q;
                })
                .catch(function (e) {
                    if (current !== seq) return;
                    quoteValueNode.textContent = '—';
                    errorNode.textContent = e.message || 'Could not fetch a quote. Try again in a moment.';
                    errorNode.style.display = 'block';
                    continueBtn.disabled = true;
                });
        }
        amountInput.addEventListener('input', function () {
            if (quoteTimer) clearTimeout(quoteTimer);
            quoteTimer = setTimeout(refreshQuote, 350);
        });
        refreshQuote();

        cancelBtn.addEventListener('click', function () { confirmClose(); });
        continueBtn.addEventListener('click', function () {
            var amt = parseFloat(amountInput.value) || 0;
            if (amt <= 0) return;
            createSession(amt);
        });
    }

    // ─── Phase: create + iframe ──────────────────────────────────────

    function createSession(amountUsd) {
        // Show loader while we POST and wait for the redirect_url
        var loaderBlock = el('div', { class: 'kg-onramp-waiting' }, [
            el('div', { class: 'kg-onramp-waiting__spinner' }),
            el('p', { class: 'kg-onramp-waiting__title' }, ['Setting up secure checkout…']),
            el('p', { class: 'kg-onramp-waiting__sub' }, ['One moment.'])
        ]);
        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(loaderBlock);

        // Capture pre-purchase wallet balance as the baseline for arrival detection.
        // We poll Blockfrost for the customer's address; when balance bumps by at
        // least the expected amount, we consider the on-ramp successful.
        var address = state.opts.customerCardanoAddress;
        var network = state.opts.network || DEFAULT_NETWORK;

        api('onramp/wallet-balance?address=' + encodeURIComponent(address) + '&network=' + encodeURIComponent(network))
            .then(function (bal) {
                state.initialLovelace = parseInt(bal.lovelace, 10) || 0;
            })
            .catch(function () {
                // Soft-fail: arrival detection will still work using the absolute
                // balance check in pollWalletBalance(); we just won't have the
                // pre-purchase delta.
                state.initialLovelace = 0;
            });

        var body = {
            from_currency: 'USD',
            from_amount: amountUsd,
            customer_cardano_address: address,
            mint_id: state.opts.mintId || null,
            customer_email: state.opts.customerEmail || '',
            customer_locale: navigator.language ? navigator.language.split('-')[0] : 'en',
            return_url: window.location.href.split('#')[0],
            cancel_url: window.location.href.split('#')[0],
            fail_url:   window.location.href.split('#')[0]
        };

        api('onramp/sessions', { method: 'POST', body: body })
            .then(function (resp) {
                state.session = resp;
                if (!resp.redirect_url) throw new Error('Guardarian did not return a checkout URL');
                renderIframe();
                startStatusPolling();
            })
            .catch(function (e) {
                renderError(e.message || 'Could not create checkout session');
            });
    }

    function renderIframe() {
        setPhase('iframe');
        state.shell.titleNode.textContent = 'Complete your card payment';
        state.shell.subtitleNode.textContent = 'Secured by Guardarian.';

        if (!PRESET_PAYOUT && state.opts.customerCardanoAddress) {
            copyToClipboard(state.opts.customerCardanoAddress);
        }

        var bannerText = PRESET_PAYOUT
            ? 'Tracking your payment in real time…'
            : 'Your wallet address is on your clipboard — paste it when Guardarian asks.';

        // Build Guardarian's calculator widget URL. The widget is the
        // documented embed surface: with is_iframe_checkout=true it opens
        // the post-submit hosted checkout in the SAME iframe via
        // window.open(_, '_self'), which is what makes 3DS / address paste
        // / success redirect not blow up the parent page.
        //
        // We pre-pass external_partner_link_id so the widget reuses our
        // backend-created transaction (idempotency on Guardarian's side)
        // rather than creating a duplicate.
        var widgetUrl = buildWidgetUrl();

        var openTabLink = el('a', {
            href: widgetUrl,
            target: '_blank',
            rel: 'noopener noreferrer',
            style: { marginLeft: 'auto', color: 'var(--kg-cyan, #00e5cc)', textDecoration: 'underline', fontSize: '12px', flexShrink: '0' }
        }, ['Open in new tab ↗']);

        var banner = el('div', { class: 'kg-onramp-iframe-banner' }, [
            el('span', { class: 'kg-onramp-iframe-banner__dot' }),
            el('span', { style: { flex: '1 1 auto', minWidth: '0' } }, [bannerText]),
            openTabLink
        ]);
        var loader = el('div', { class: 'kg-onramp-iframe-loader' }, ['Loading secure checkout…']);
        var iframe = el('iframe', {
            src: widgetUrl,
            allow: 'payment *; clipboard-write',
            referrerpolicy: 'no-referrer',
            // Guardarian's checkout SPA navigates the TOP frame to
            // redirects.successful AFTER an async card-processing API
            // call resolves (inside a setTimeout / XHR callback), not
            // during a real click. By that time Chromium has expired the
            // "user activation" token, so -by-user-activation throws a
            // SecurityError. We have to use plain allow-top-navigation
            // here. Slightly less locked-down than ideal, but Guardarian
            // is the PSP we're explicitly trusting, and this is the
            // standard pattern for embedded checkout iframes.
            sandbox: 'allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-storage-access-by-user-activation allow-top-navigation'
        });
        iframe.addEventListener('load', function () {
            loader.classList.add('is-hidden');
            // Each in-iframe navigation (calculator → checkout → 3DS →
            // return) fires a load event. Bump the status poll on each so
            // status changes show up quickly in our backend record.
            pollSessionStatus();
        });
        var shell = el('div', { class: 'kg-onramp-iframe-shell' }, [loader, iframe]);

        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(banner);
        state.shell.body.appendChild(shell);

        attachGuardarianMessageListener();
    }

    function buildWidgetUrl() {
        var fromAmount = (state.session && state.session.from_amount) || (state.lastQuote && state.lastQuote.from_amount) || '';
        var fromCcy = (state.session && state.session.from_currency) || (state.lastQuote && state.lastQuote.from_currency) || 'USD';

        if (!PARTNER_API_KEY) {
            console.error('[KGOnramp] PARTNER_API_KEY missing — widget will fail. Save the API key in Cardano Mint → Payment Wallets → Card (Guardarian).');
        }

        // KG theming. Per Guardarian's widget-integration docs the value
        // formats are STRICT and not interchangeable:
        //   - calc_background / body_background / button_background:
        //         "hex_RRGGBB" or named color (NOT bare RRGGBB, NOT #RRGGBB)
        //   - active_tab_background:  "hex_RRGGBB" or "rgb(r,g,b)"
        //   - active_tab_color, select_color: "white" or "rgb(r,g,b)"
        //   - select_background:               "black" or "rgb(r,g,b)"
        //   - submit_button_color:             "black" or "white" ONLY
        // Earlier crashes were caused by sending bare-hex values to
        // submit_button_color, which the widget rejected by hiding the
        // Buy button entirely.
        var params = {
            partner_api_token:         PARTNER_API_KEY,
            is_iframe_checkout:        'true',
            default_side:              'buy',
            default_fiat_currency:     fromCcy,
            default_fiat_amount:       String(fromAmount),
            default_crypto_currency:   'ADA',
            default_crypto_network:    'ADA',
            payment_category:          'VISA_MC',
            skip_choose_payment_category: 'true',
            external_partner_link_id:  state.session && state.session.partner_link_id ? state.session.partner_link_id : '',

            // KG dark backgrounds for the calculator card surface.
            calc_background:           'hex_0f1923',
            body_background:           'hex_0f1923',
            // Currency dropdown buttons — KG cyan.
            button_background:         'hex_00e5cc',
            // Buy/Sell active tab — KG cyan with white text.
            active_tab_background:     'rgb(0,229,204)',
            active_tab_color:          'white',
            // Currency-picker dropdown body — dark background, white text.
            select_background:         'black',
            select_color:              'white',
            // Buy submit button text. Black/white only per docs; black
            // reads well against the default cyan button bg.
            submit_button_color:       'black',
            without_box_shadow:        'true',
        };

        if (PRESET_PAYOUT && state.opts.customerCardanoAddress) {
            params.payout_address = state.opts.customerCardanoAddress;
            params.skip_choose_payout_address = 'true';
        }

        // Tag the success URL with the partner link id so when Guardarian
        // navigates the top frame back here after payment, the auto-resume
        // block at the top of this file picks it up and re-opens the modal
        // straight into the "waiting for ADA" view (Blockfrost polling).
        var baseRet = window.location.href.split('#')[0].split('?')[0];
        var carryOver = window.location.search.replace(/^\?/, '');
        var partnerLinkId = state.session && state.session.partner_link_id ? state.session.partner_link_id : '';
        var successQs = (carryOver ? carryOver + '&' : '') + 'kg-onramp-return=' + encodeURIComponent(partnerLinkId);
        params.redirects_successful = baseRet + '?' + successQs;
        params.redirects_cancelled  = baseRet + (carryOver ? '?' + carryOver : '');
        params.redirects_failed     = baseRet + (carryOver ? '?' + carryOver : '');

        var qs = Object.keys(params)
            .filter(function (k) { return params[k] !== '' && params[k] != null; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');
        var url = WIDGET_BASE_URL + '?' + qs;
        try { (window.cardanoMint && window.cardanoMint.debug) && console.log('[KGOnramp] widget URL:', url); } catch (_) {}
        return url;
    }

    function attachGuardarianMessageListener() {
        if (state && state.messageListener) {
            window.removeEventListener('message', state.messageListener);
        }
        var listener = function (e) {
            try {
                var origin = (e.origin || '').toLowerCase();
                if (origin.indexOf('guardarian.com') === -1 && origin.indexOf('guardarian.') === -1) return;
                (window.cardanoMint && window.cardanoMint.debug) && console.log('[KGOnramp] postMessage from Guardarian:', e.data);
                pollSessionStatus();
            } catch (_) {}
        };
        window.addEventListener('message', listener);
        state.messageListener = listener;
    }

    // ─── Status polling (Guardarian-side) ────────────────────────────

    function startStatusPolling() {
        if (state.statusPoll) clearInterval(state.statusPoll);
        // Poll our backend (which proxies to Guardarian getTransaction) every 5s
        state.statusPoll = setInterval(function () { pollSessionStatus(); }, 5000);
        // First check after 3s so the customer sees responsiveness
        setTimeout(pollSessionStatus, 3000);
    }

    function pollSessionStatus() {
        if (!state || !state.session) return;
        api('onramp/sessions/' + encodeURIComponent(state.session.partner_link_id))
            .then(function (s) {
                if (!state || !state.session) return;
                state.session.status = s.status;
                // Once Guardarian reports "deposit captured" or later, the card
                // payment is done — switch to the friendlier "waiting for ADA"
                // view with Blockfrost polling.
                var done = ['exchanging', 'on_hold', 'sending', 'finished'];
                if (done.indexOf(s.status) !== -1) {
                    state.iframeAbandonOk = true; // safe to close iframe now
                    if (state.statusPoll) { clearInterval(state.statusPoll); state.statusPoll = null; }
                    renderWaiting(s);
                    startBalancePolling();
                } else if (s.status === 'failed' || s.status === 'cancelled' || s.status === 'expired') {
                    if (state.statusPoll) { clearInterval(state.statusPoll); state.statusPoll = null; }
                    renderError('Payment ' + s.status + '. You can try again.');
                }
            })
            .catch(function () { /* network blip; next tick will retry */ });
    }

    // ─── Phase: waiting (Blockfrost polling for ADA arrival) ────────

    function renderWaiting(sessionSnapshot) {
        setPhase('waiting');
        state.shell.titleNode.textContent = 'ADA is on the way';
        state.shell.subtitleNode.textContent = 'Waiting for funds to land in your wallet (1–3 min).';

        var expected = (sessionSnapshot && sessionSnapshot.to_amount_estimated)
            ? sessionSnapshot.to_amount_estimated
            : (state.session && state.session.to_amount_estimated) || null;

        var balanceNode = el('div', { class: 'kg-onramp-balance' }, [
            el('span', null, ['+0.00']),
            el('span', { class: 'kg-onramp-balance__suffix' }, ['ADA']),
        ]);
        state.balanceNode = balanceNode;
        state.expectedAdaText = expected ? fmtAda(expected) : '~';

        var block = el('div', { class: 'kg-onramp-waiting' }, [
            el('div', { class: 'kg-onramp-waiting__spinner' }),
            el('p', { class: 'kg-onramp-waiting__title' }, ['Looking for your ADA…']),
            el('p', { class: 'kg-onramp-waiting__sub' }, [
                expected ? ('You should receive about ' + fmtAda(expected) + '. Once it arrives, you\'re ready to mint.')
                         : ('Once your ADA arrives, you\'re ready to mint.')
            ]),
            balanceNode,
            el('div', { class: 'kg-onramp-actions' }, [
                el('button', { class: 'kg-onramp-btn kg-onramp-btn--ghost', onclick: function () { confirmClose(); } }, ['Close — I\'ll check my wallet'])
            ])
        ]);
        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(block);
    }

    function startBalancePolling() {
        if (state.balancePoll) clearInterval(state.balancePoll);
        // Poll Blockfrost via our proxy every 5s for up to 15 minutes
        state.balancePoll = setInterval(pollWalletBalance, 5000);
        state.balanceDeadline = setTimeout(function () {
            if (state.balancePoll) { clearInterval(state.balancePoll); state.balancePoll = null; }
            // Don't error out — Guardarian sometimes takes longer; just give
            // the customer a "still on its way" hint and let them close.
        }, 15 * 60 * 1000);
        // Kick off immediately
        pollWalletBalance();
    }

    function pollWalletBalance() {
        if (!state) return;
        var address = state.opts.customerCardanoAddress;
        var network = state.opts.network || DEFAULT_NETWORK;
        api('onramp/wallet-balance?address=' + encodeURIComponent(address) + '&network=' + encodeURIComponent(network))
            .then(function (bal) {
                if (!state) return;
                var current = parseInt(bal.lovelace, 10) || 0;
                var initial = state.initialLovelace || 0;
                var delta = Math.max(0, current - initial);
                if (state.balanceNode) {
                    state.balanceNode.firstChild.textContent = '+' + lovelaceToAda(delta).toLocaleString(undefined, { maximumFractionDigits: 2 });
                }
                // Heuristic: any positive delta past 1 ADA means the on-ramp
                // landed (or someone else sent the wallet ADA — still safe to
                // proceed; the mint flow will validate).
                if (delta >= 1000000) {
                    if (state.balancePoll) { clearInterval(state.balancePoll); state.balancePoll = null; }
                    renderSuccess(delta);
                }
            })
            .catch(function () { /* will retry next tick */ });
    }

    // ─── Phase: success ──────────────────────────────────────────────

    function renderSuccess(deltaLovelace) {
        setPhase('success');
        state.shell.titleNode.textContent = 'ADA received!';
        state.shell.subtitleNode.textContent = 'Your wallet is funded and ready to mint.';

        var receivedAda = lovelaceToAda(deltaLovelace);

        var block = el('div', { class: 'kg-onramp-success' }, [
            el('div', { class: 'kg-onramp-success__check' }, ['✓']),
            el('p', { class: 'kg-onramp-success__title' }, ['Funds confirmed in your wallet']),
            el('p', { class: 'kg-onramp-success__sub' }, [
                'You received ' + fmtAda(receivedAda) + '. You can now mint without leaving this page.'
            ]),
            el('div', { class: 'kg-onramp-balance' }, [
                el('span', null, ['+' + receivedAda.toLocaleString(undefined, { maximumFractionDigits: 2 })]),
                el('span', { class: 'kg-onramp-balance__suffix' }, ['ADA'])
            ]),
            el('div', { class: 'kg-onramp-actions' }, [
                el('button', {
                    class: 'kg-onramp-btn kg-onramp-btn--primary',
                    onclick: function () {
                        if (typeof state.opts.onComplete === 'function') {
                            try { state.opts.onComplete({ deltaLovelace: deltaLovelace, session: state.session }); } catch (e) {}
                        }
                        closeModal();
                    }
                }, ['Continue to mint'])
            ])
        ]);
        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(block);
    }

    // ─── Phase: error ────────────────────────────────────────────────

    function renderError(msg) {
        setPhase('error');
        state.shell.titleNode.textContent = 'Something went wrong';
        state.shell.subtitleNode.textContent = '';
        clearTimers();

        var block = el('div', { class: 'kg-onramp-quote' }, [
            el('p', { class: 'kg-onramp-error' }, [msg || 'Unknown error.']),
            el('div', { class: 'kg-onramp-actions' }, [
                el('button', { class: 'kg-onramp-btn kg-onramp-btn--ghost', onclick: function () { closeModal(); } }, ['Close']),
                el('button', { class: 'kg-onramp-btn kg-onramp-btn--primary', onclick: function () { renderQuote(); } }, ['Try again'])
            ])
        ]);
        state.shell.body.innerHTML = '';
        state.shell.body.appendChild(block);
    }
})();
