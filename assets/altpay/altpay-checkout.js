(function () {
    'use strict';

    // Customer-side payment-method picker for the [cardano-mint] shortcode.
    // Talks to /wp-json/cardano-mint/v1/altpay/{quote,status,cancel}.
    //
    // The picker only renders when an admin has flipped on
    // cardano_mint_altpay_enabled AND configured at least one chain wallet.
    // ADA stays the default so existing Cardano-only sites are unaffected.
    //
    // Persistence: a pending invoice is stored in localStorage under
    // kg_altpay_active_<mint_id> so closing the modal or refreshing the
    // page doesn't burn the address. The server side of /altpay/quote is
    // also idempotent (returns the existing pending invoice for the same
    // mint+chain+customer tuple), so even a wiped browser will reattach
    // to the right HD-derived address.

    const cfg = window.cardanoAltPayCheckout || {};

    /* ── Currency formatting ────────────────────────────────────── */

    const CHAIN_DECIMALS = { btc: 8, eth: 18, sol: 9 };
    function formatChainAmount(chain, minorStr) {
        const decimals = CHAIN_DECIMALS[chain] || 0;
        if (!minorStr || minorStr === '0') return { major: '0', minor: '0', symbol: chain.toUpperCase() };
        let major;
        try {
            const big = BigInt(minorStr);
            const base = BigInt(10) ** BigInt(decimals);
            const whole = (big / base).toString();
            const frac = (big % base).toString().padStart(decimals, '0').replace(/0+$/, '');
            major = frac ? whole + '.' + frac : whole;
        } catch (e) {
            const f = Number(minorStr) / Math.pow(10, decimals);
            major = f.toString();
        }
        return { major: major, minor: minorStr, symbol: chain.toUpperCase() };
    }
    function chainMinorLabel(chain) {
        return chain === 'btc' ? 'sats' : chain === 'eth' ? 'wei' : 'lamports';
    }

    /* ── REST helpers ───────────────────────────────────────────── */

    function rest(path, method, body) {
        const opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        return fetch(cfg.restUrl + path, opts).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || (j && j.error)) throw new Error((j && j.error) || ('HTTP ' + r.status));
                return j;
            });
        });
    }

    /* ── Clipboard ──────────────────────────────────────────────── */

    function copyText(text) {
        const tryNative = (window.isSecureContext || window.location.protocol === 'https:')
            && navigator.clipboard && navigator.clipboard.writeText;
        if (tryNative) return navigator.clipboard.writeText(text).catch(legacyCopy.bind(null, text));
        return legacyCopy(text);
    }
    function legacyCopy(text) {
        return new Promise(function (resolve, reject) {
            try {
                const ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.focus(); ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand failed'));
            } catch (e) { reject(e); }
        });
    }

    /* ── localStorage persistence ───────────────────────────────── */

    const STORAGE_PREFIX = 'kg_altpay_active_';
    function storageKey(mintId) { return STORAGE_PREFIX + String(mintId); }

    function persistInvoice(mintId, payload) {
        try { localStorage.setItem(storageKey(mintId), JSON.stringify(payload)); } catch (e) {}
    }
    function readInvoice(mintId) {
        try {
            const raw = localStorage.getItem(storageKey(mintId));
            if (!raw) return null;
            const obj = JSON.parse(raw);
            if (!obj || !obj.invoice_id) return null;
            // Expiry check on the client; the server is still authoritative.
            if (obj.expires_at && new Date(obj.expires_at).getTime() < Date.now()) return null;
            return obj;
        } catch (e) { return null; }
    }
    function clearInvoice(mintId) {
        try { localStorage.removeItem(storageKey(mintId)); } catch (e) {}
    }

    /* ── Wake lock (best effort) ────────────────────────────────── */

    let wakeLockHandle = null;
    async function tryWakeLock() {
        if (wakeLockHandle) return;
        if (!('wakeLock' in navigator)) return;
        try { wakeLockHandle = await navigator.wakeLock.request('screen'); }
        catch (e) { /* user can decline; not fatal */ }
    }
    function releaseWakeLock() {
        if (wakeLockHandle && typeof wakeLockHandle.release === 'function') {
            try { wakeLockHandle.release(); } catch (e) {}
        }
        wakeLockHandle = null;
    }

    /* ── Countdown formatting ───────────────────────────────────── */

    function formatRemaining(ms) {
        if (ms <= 0) return 'expired';
        const totalSec = Math.floor(ms / 1000);
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;
        if (h > 0) return h + 'h ' + (m < 10 ? '0' + m : m) + 'm';
        if (m > 0) return m + 'm ' + (s < 10 ? '0' + s : s) + 's';
        return s + 's';
    }

    /* ── Init ───────────────────────────────────────────────────── */

    function init() {
        const picker = document.getElementById('altpay-picker');
        if (!picker) return;

        const mintBtn       = document.getElementById('cardano-mint-now-btn');
        const proceedBtn    = document.getElementById('proceed-to-confirm');
        const walletDisplay = document.getElementById('wallet-address-display');
        const invoiceField  = document.getElementById('altpay-invoice-id');
        const chainField    = document.getElementById('altpay-chain');
        const payPanel      = document.getElementById('altpay-pay-panel');
        const addrEl        = picker.querySelector('.altpay-address');
        const amountEl      = picker.querySelector('.altpay-amount-display');
        const statusText    = picker.querySelector('.altpay-status-text');
        const observedEl    = picker.querySelector('.altpay-observed');
        const mintId        = parseInt(picker.getAttribute('data-mint-id') || '0', 10);

        // ── Resume banner injected outside the modal ─────────────
        injectResumeBanner(mintId);

        // Show picker once the wallet display flips visible.
        const observer = new MutationObserver(function () {
            if (walletDisplay && walletDisplay.style.display !== 'none') {
                picker.removeAttribute('hidden');
                // If a pending invoice already exists in storage, restore it.
                tryRestoreFromStorage();
            }
        });
        if (walletDisplay) observer.observe(walletDisplay, { attributes: true, attributeFilter: ['style'] });

        function setProceedEnabled(enabled, label) {
            if (!proceedBtn) return;
            proceedBtn.disabled = !enabled;
            proceedBtn.style.opacity = enabled ? '1' : '0.6';
            proceedBtn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
            if (label) proceedBtn.textContent = label;
        }

        let pollHandle = null;
        let countdownHandle = null;
        let currentExpiresAt = null;

        function getCustomerCardanoAddress() {
            const el = document.getElementById('connected-wallet-address');
            return el ? (el.textContent || '').trim() : '';
        }

        function tryRestoreFromStorage() {
            const stored = readInvoice(mintId);
            if (!stored) return;
            if (stored.chain === 'ada') return;

            // Don't restore if the connected wallet differs from when the
            // invoice was created — the bind would fail server-side anyway.
            const currentCardano = getCustomerCardanoAddress();
            if (currentCardano && stored.customer_cardano_address && currentCardano !== stored.customer_cardano_address) {
                return;
            }

            picker.querySelectorAll('.altpay-chip').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-altpay-chain') === stored.chain);
            });
            chainField.value = stored.chain;
            invoiceField.value = stored.invoice_id;
            payPanel.removeAttribute('hidden');

            addrEl.textContent = stored.address;
            renderAmount(stored.chain, stored.expected_amount_minor);

            currentExpiresAt = stored.expires_at;
            startCountdown(currentExpiresAt);

            setStatus('pending', 'restored — checking status…');
            setProceedEnabled(false, 'Waiting for ' + stored.chain.toUpperCase() + ' payment');
            startPolling(stored.invoice_id);
            tryWakeLock();
        }

        function renderAmount(chain, minor) {
            const f = formatChainAmount(chain, minor || '0');
            amountEl.innerHTML = f.major + ' <span class="altpay-amount-symbol">' + f.symbol + '</span>'
                + '<span class="altpay-amount-minor">(' + Number(f.minor).toLocaleString('en-US') + ' ' + chainMinorLabel(chain) + ')</span>';
        }

        function selectChain(chain) {
            picker.querySelectorAll('.altpay-chip').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-altpay-chain') === chain);
            });
            chainField.value = chain;

            if (chain === 'ada') {
                hidePayPanel();
                invoiceField.value = '';
                clearInvoice(mintId);
                setProceedEnabled(true, 'Continue to Mint');
                releaseWakeLock();
                return;
            }

            const customerCardano = getCustomerCardanoAddress();
            if (!customerCardano) { alert('Connect your Cardano wallet first.'); return; }

            payPanel.removeAttribute('hidden');
            addrEl.textContent  = '…';
            amountEl.textContent = 'fetching quote…';
            setStatus('pending', 'requesting quote…');
            observedEl.setAttribute('hidden', '');
            setProceedEnabled(false, 'Waiting for ' + chain.toUpperCase() + ' payment');

            rest('/altpay/quote', 'POST', {
                mint_id: mintId,
                payment_method: chain,
                customer_cardano_address: customerCardano,
            }).then(function (q) {
                invoiceField.value = q.invoice_id;
                addrEl.textContent  = q.address;
                renderAmount(chain, q.expected_amount_minor || '0');

                currentExpiresAt = q.expires_at;
                startCountdown(currentExpiresAt);

                persistInvoice(mintId, {
                    invoice_id: q.invoice_id,
                    chain: chain,
                    address: q.address,
                    expected_amount_minor: q.expected_amount_minor,
                    expires_at: q.expires_at,
                    customer_cardano_address: customerCardano,
                });

                setStatus('pending', q.reused ? 'resumed earlier invoice…' : 'waiting for payment…');
                startPolling(q.invoice_id);
                tryWakeLock();
                injectResumeBanner(mintId);
            }).catch(function (e) {
                setStatus('error', 'quote failed: ' + e.message);
                amountEl.textContent = 'failed';
                setProceedEnabled(false, 'Continue to Mint');
            });
        }

        function setStatus(kind, text) {
            statusText.classList.remove('is-pending', 'is-funded', 'is-error');
            if (kind === 'pending') statusText.classList.add('is-pending');
            else if (kind === 'funded') statusText.classList.add('is-funded');
            else if (kind === 'error') statusText.classList.add('is-error');
            statusText.textContent = text;
        }

        function startPolling(invoiceId) {
            if (pollHandle) clearInterval(pollHandle);
            pollHandle = setInterval(function () { pollOnce(invoiceId); }, 10000);
            pollOnce(invoiceId);
        }

        function pollOnce(invoiceId) {
            rest('/altpay/status?invoice_id=' + encodeURIComponent(invoiceId)).then(function (s) {
                if (!s) return;
                const kind = s.status === 'funded' ? 'funded'
                    : ['expired','cancelled','underpaid','overpaid'].indexOf(s.status) !== -1 ? 'error'
                    : 'pending';
                setStatus(kind, s.status || 'unknown');

                if (s.observed_amount_minor) {
                    const chain = chainField.value;
                    const f = formatChainAmount(chain, s.observed_amount_minor);
                    observedEl.removeAttribute('hidden');
                    observedEl.textContent = ' observed ' + f.major + ' ' + f.symbol;
                }
                if (s.status === 'funded') {
                    clearInterval(pollHandle); pollHandle = null;
                    stopCountdown();
                    setProceedEnabled(true, 'Payment received — Continue to Mint');
                    releaseWakeLock();
                } else if (['expired', 'cancelled'].indexOf(s.status) !== -1) {
                    clearInterval(pollHandle); pollHandle = null;
                    stopCountdown();
                    clearInvoice(mintId);
                    removeResumeBanner();
                    setProceedEnabled(false, 'Continue to Mint');
                    releaseWakeLock();
                } else if (s.status === 'consumed') {
                    clearInterval(pollHandle); pollHandle = null;
                    stopCountdown();
                    clearInvoice(mintId);
                    removeResumeBanner();
                    releaseWakeLock();
                }
            }).catch(function () { /* transient errors are fine */ });
        }

        function startCountdown(expiresAtIso) {
            stopCountdown();
            if (!expiresAtIso) return;
            const expMs = new Date(expiresAtIso).getTime();
            updateCountdownLine(expMs);
            countdownHandle = setInterval(function () { updateCountdownLine(expMs); }, 1000);
        }
        function stopCountdown() {
            if (countdownHandle) clearInterval(countdownHandle);
            countdownHandle = null;
            const c = picker.querySelector('.altpay-countdown');
            if (c) c.remove();
        }
        function updateCountdownLine(expMs) {
            let el = picker.querySelector('.altpay-countdown');
            if (!el) {
                el = document.createElement('p');
                el.className = 'altpay-countdown';
                payPanel.appendChild(el);
            }
            const remaining = expMs - Date.now();
            el.textContent = 'This payment window expires in ' + formatRemaining(remaining) + '. Keep this tab open or use Resume to come back.';
            if (remaining <= 0) stopCountdown();
        }

        function hidePayPanel() {
            payPanel.setAttribute('hidden', '');
            if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
            stopCountdown();
        }

        picker.addEventListener('click', function (ev) {
            const chipBtn = ev.target.closest('[data-altpay-chain]');
            if (chipBtn) { selectChain(chipBtn.getAttribute('data-altpay-chain')); return; }

            const action = ev.target.getAttribute('data-altpay-action');
            if (action === 'copy-address') {
                copyText(addrEl.textContent || '').then(function () {
                    ev.target.textContent = 'Copied';
                    setTimeout(function () { ev.target.textContent = 'Copy'; }, 1500);
                });
            } else if (action === 'cancel') {
                const inv = invoiceField.value;
                if (inv) rest('/altpay/cancel', 'POST', { invoice_id: parseInt(inv, 10) }).catch(function () {});
                invoiceField.value = '';
                clearInvoice(mintId);
                removeResumeBanner();
                hidePayPanel();
                releaseWakeLock();
                selectChain('ada');
            }
        });

        // Default state.
        setProceedEnabled(true, 'Continue to Mint');
    }

    /* ── Resume banner (lives outside the modal) ───────────────── */

    function injectResumeBanner(mintId) {
        removeResumeBanner();
        const stored = readInvoice(mintId);
        if (!stored || stored.chain === 'ada') return;
        const wrapper = document.querySelector('.cardano-shortcode-wrapper');
        if (!wrapper) return;

        const banner = document.createElement('div');
        banner.className = 'altpay-resume-banner';
        banner.dataset.altpayResume = '1';
        banner.innerHTML =
            '<span class="altpay-resume-dot" aria-hidden="true"></span>' +
            '<span class="altpay-resume-msg">' +
                'Pending ' + stored.chain.toUpperCase() + ' payment in progress. ' +
            '</span>' +
            '<button type="button" class="altpay-btn" data-altpay-resume>Resume mint</button>';
        wrapper.parentNode.insertBefore(banner, wrapper.nextSibling);

        banner.addEventListener('click', function (ev) {
            const target = ev.target.closest('[data-altpay-resume]');
            if (!target) return;
            const mintNowBtn = document.getElementById('cardano-mint-now-btn');
            if (mintNowBtn) mintNowBtn.click();
        });
    }
    function removeResumeBanner() {
        document.querySelectorAll('[data-altpay-resume="1"]').forEach(function (n) { n.remove(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
