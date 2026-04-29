(function () {
    'use strict';

    // Customer-side payment-method picker for the [cardano-mint] shortcode.
    // Talks to /wp-json/cardano-mint/v1/altpay/{quote,status,cancel}.
    //
    // Lifecycle:
    //   1. Customer clicks a chain chip (BTC / ETH / SOL).
    //   2. Pay panel opens in PRE-INIT state explaining the flow + showing
    //      a single "Pay with X" button.
    //   3. Clicking that fires /altpay/quote, persists the session, and
    //      flips the panel to ACTIVE state with address, amount, status.
    //   4. ACTIVE state polls /altpay/status every 10s. "Cancel payment
    //      session" link returns to step 2 after a confirm.
    //
    // Sessions are stored per-{mint, chain} so a customer can have BTC,
    // ETH, and SOL pending simultaneously. Resume banners render one per
    // active session below the mint button.

    const cfg = window.cardanoAltPayCheckout || {};

    /* ── Currency formatting ────────────────────────────────────── */

    const CHAIN_DECIMALS = { btc: 8, eth: 18, sol: 9 };
    const DISPLAY_DECIMALS = { btc: 8, eth: 6, sol: 4 };

    function formatChainAmount(chain, minorStr) {
        const decimals = CHAIN_DECIMALS[chain] || 0;
        if (!minorStr || minorStr === '0') return { major: '0', minor: '0', symbol: chain.toUpperCase() };
        let major;
        try {
            const big = BigInt(minorStr);
            const base = BigInt(10) ** BigInt(decimals);
            const whole = (big / base).toString();
            const frac = (big % base).toString().padStart(decimals, '0');
            const displayDp = DISPLAY_DECIMALS[chain] != null ? DISPLAY_DECIMALS[chain] : decimals;
            const fracTrimmed = frac.slice(0, displayDp).replace(/0+$/, '');
            major = fracTrimmed ? whole + '.' + fracTrimmed : whole;
        } catch (e) {
            const f = Number(minorStr) / Math.pow(10, decimals);
            const dp = DISPLAY_DECIMALS[chain] != null ? DISPLAY_DECIMALS[chain] : 8;
            major = f.toFixed(dp).replace(/0+$/, '').replace(/\.$/, '');
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

    /* ── Per-chain localStorage persistence ─────────────────────── */

    const STORAGE_PREFIX = 'kg_altpay_active_';
    function storageKey(mintId, chain) { return STORAGE_PREFIX + String(mintId) + '_' + chain; }

    function persistInvoice(mintId, chain, payload) {
        try { localStorage.setItem(storageKey(mintId, chain), JSON.stringify(payload)); } catch (e) {}
    }
    function readInvoice(mintId, chain) {
        try {
            const raw = localStorage.getItem(storageKey(mintId, chain));
            if (!raw) return null;
            const obj = JSON.parse(raw);
            if (!obj || !obj.invoice_id) return null;
            if (obj.expires_at && new Date(obj.expires_at).getTime() < Date.now()) return null;
            return obj;
        } catch (e) { return null; }
    }
    function clearInvoice(mintId, chain) {
        try { localStorage.removeItem(storageKey(mintId, chain)); } catch (e) {}
    }
    function listActiveInvoices(mintId) {
        const out = [];
        for (const chain of ['btc','eth','sol']) {
            const inv = readInvoice(mintId, chain);
            if (inv) out.push({ chain: chain, invoice: inv });
        }
        return out;
    }

    /* ── Wake lock (best effort) ────────────────────────────────── */

    let wakeLockHandle = null;
    async function tryWakeLock() {
        if (wakeLockHandle) return;
        if (!('wakeLock' in navigator)) return;
        try { wakeLockHandle = await navigator.wakeLock.request('screen'); }
        catch (e) {}
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

        const proceedBtn    = document.getElementById('proceed-to-confirm');
        const walletDisplay = document.getElementById('wallet-address-display');
        const invoiceField  = document.getElementById('altpay-invoice-id');
        const chainField    = document.getElementById('altpay-chain');
        const payPanel      = document.getElementById('altpay-pay-panel');
        const preinit       = payPanel.querySelector('[data-altpay-state="preinit"]');
        const active        = payPanel.querySelector('[data-altpay-state="active"]');
        const addrEl        = picker.querySelector('.altpay-address');
        const amountEl      = picker.querySelector('.altpay-amount-display');
        const statusText    = picker.querySelector('.altpay-status-text');
        const observedEl    = picker.querySelector('.altpay-observed');
        const mintId        = parseInt(picker.getAttribute('data-mint-id') || '0', 10);

        renderResumeBanners();

        // Reveal picker when wallet display flips visible.
        const observer = new MutationObserver(function () {
            if (walletDisplay && walletDisplay.style.display !== 'none') {
                picker.removeAttribute('hidden');
                // Auto-restore the most recently created session when the
                // modal opens. The user can still click another chip.
                const all = listActiveInvoices(mintId);
                if (all.length > 0) {
                    const newest = all.sort(function (a, b) {
                        return new Date(b.invoice.expires_at).getTime() - new Date(a.invoice.expires_at).getTime();
                    })[0];
                    selectChain(newest.chain);
                }
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

        function getCustomerCardanoAddress() {
            const el = document.getElementById('connected-wallet-address');
            return el ? (el.textContent || '').trim() : '';
        }

        function renderAmount(chain, minor) {
            const f = formatChainAmount(chain, minor || '0');
            amountEl.innerHTML = f.major + ' <span class="altpay-amount-symbol">' + f.symbol + '</span>'
                + '<span class="altpay-amount-minor">(' + Number(f.minor).toLocaleString('en-US') + ' ' + chainMinorLabel(chain) + ')</span>';
        }

        function setChipActive(chain) {
            picker.querySelectorAll('.altpay-chip').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-altpay-chain') === chain);
            });
            chainField.value = chain;
            // Update the "Pay with X" label inside preinit.
            picker.querySelectorAll('.altpay-chain-label').forEach(function (el) {
                el.textContent = chain.toUpperCase();
            });
        }

        function showState(state) {
            if (state === 'preinit') {
                payPanel.removeAttribute('hidden');
                preinit.removeAttribute('hidden');
                active.setAttribute('hidden', '');
            } else if (state === 'active') {
                payPanel.removeAttribute('hidden');
                preinit.setAttribute('hidden', '');
                active.removeAttribute('hidden');
            } else {
                payPanel.setAttribute('hidden', '');
            }
        }

        function selectChain(chain) {
            setChipActive(chain);

            if (chain === 'ada') {
                stopPolling();
                stopCountdown();
                showState('hidden');
                invoiceField.value = '';
                setProceedEnabled(true, 'Continue to Mint');
                releaseWakeLock();
                return;
            }

            const stored = readInvoice(mintId, chain);
            if (stored) {
                attachToActiveInvoice(chain, stored);
            } else {
                showState('preinit');
                invoiceField.value = '';
                setProceedEnabled(false, 'Waiting for ' + chain.toUpperCase() + ' payment');
                stopPolling();
                stopCountdown();
            }
        }

        // Rewrite the step-2 receipt so the customer doesn't see the
        // full ADA price after they already paid on another chain.
        // The merchant tx that gets built only takes the configured ADA
        // service fee (default 5) + Anvil + ~0.17 network + 1 ADA receipt.
        function rewriteReceiptForAltPay(chain) {
            if (!chain || chain === 'ada') return;
            const $usd = document.getElementById('review-nft-price-usd');
            const $ada = document.getElementById('review-nft-price-ada');
            const $totUsd = document.getElementById('review-total-usd');
            const $totAda = document.getElementById('review-total-ada');
            const $anvilAda = document.getElementById('review-anvil-ada');
            const $netAda   = document.getElementById('review-network-ada');
            if (!$usd || !$ada || !$totUsd || !$totAda) return;

            const SERVICE_FEE_ADA = 5; // matches cardano_mint_service_fee_ada default; live override comes from server build
            const anvilAda = parseFloat((($anvilAda && $anvilAda.textContent) || '1.15')) || 1.15;
            const netAda   = parseFloat((($netAda   && $netAda.textContent  ) || '0.22')) || 0.22;
            const totalAda = SERVICE_FEE_ADA + anvilAda + netAda;

            $usd.innerHTML = '<span style="color:#0a7d22;">Paid via ' + chain.toUpperCase() + ' &#10003;</span>';
            $ada.innerHTML = '<span style="color:#0a7d22;">$0 due in ADA</span>';
            $totUsd.innerHTML = '<span style="font-size:14px; color:#888;">' + chain.toUpperCase() + ' covered, plus</span>';
            $totAda.textContent = '~' + totalAda.toFixed(2) + ' ADA service';

            // Drop a small banner above the order summary if not already there.
            if (!document.getElementById('altpay-receipt-banner')) {
                const summary = document.querySelector('.mint-receipt-card .receipt-header');
                if (summary) {
                    const banner = document.createElement('div');
                    banner.id = 'altpay-receipt-banner';
                    banner.style.cssText = 'background:#0a7d22; color:#fff; padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:13px; text-align:center;';
                    banner.textContent = chain.toUpperCase() + ' payment received. Sign the Cardano tx below to receive your NFT.';
                    summary.parentNode.insertBefore(banner, summary);
                }
            }
        }

        function attachToActiveInvoice(chain, stored) {
            // Bail if the connected Cardano wallet differs from the one the
            // session was bound to — the server-side check would fail anyway.
            const currentCardano = getCustomerCardanoAddress();
            if (currentCardano && stored.customer_cardano_address && currentCardano !== stored.customer_cardano_address) {
                showState('preinit');
                return;
            }

            invoiceField.value = stored.invoice_id;
            addrEl.textContent = stored.address;
            renderAmount(chain, stored.expected_amount_minor);
            startCountdown(stored.expires_at);
            setStatus('pending', 'restored — checking status…');
            setProceedEnabled(false, 'Waiting for ' + chain.toUpperCase() + ' payment');
            showState('active');
            startPolling(stored.invoice_id);
            tryWakeLock();
            // If the stored invoice was already funded server-side, the next
            // poll tick will rewrite the receipt; do it eagerly here too.
            rewriteReceiptForAltPay(chain);
        }

        function startPaymentSession() {
            const chain = chainField.value;
            if (chain === 'ada' || !chain) return;
            const customerCardano = getCustomerCardanoAddress();
            if (!customerCardano) { alert('Connect your Cardano wallet first.'); return; }

            const startBtn = preinit.querySelector('[data-altpay-action="start"]');
            if (startBtn) { startBtn.disabled = true; startBtn.textContent = 'Generating address…'; }

            rest('/altpay/quote', 'POST', {
                mint_id: mintId,
                payment_method: chain,
                customer_cardano_address: customerCardano,
            }).then(function (q) {
                const stored = {
                    invoice_id: q.invoice_id,
                    chain: chain,
                    address: q.address,
                    expected_amount_minor: q.expected_amount_minor,
                    expires_at: q.expires_at,
                    customer_cardano_address: customerCardano,
                };
                persistInvoice(mintId, chain, stored);
                attachToActiveInvoice(chain, stored);
                renderResumeBanners();
            }).catch(function (e) {
                if (startBtn) {
                    startBtn.disabled = false;
                    startBtn.innerHTML = 'Pay with <span class="altpay-chain-label">' + chain.toUpperCase() + '</span>';
                }
                alert('Could not start ' + chain.toUpperCase() + ' payment: ' + e.message);
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
            stopPolling();
            pollHandle = setInterval(function () { pollOnce(invoiceId); }, 10000);
            pollOnce(invoiceId);
        }
        function stopPolling() {
            if (pollHandle) clearInterval(pollHandle);
            pollHandle = null;
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
                    stopPolling();
                    stopCountdown();
                    setProceedEnabled(true, 'Payment received — Continue to Mint');
                    releaseWakeLock();
                    rewriteReceiptForAltPay(chainField.value);
                } else if (['expired', 'cancelled', 'consumed'].indexOf(s.status) !== -1) {
                    stopPolling();
                    stopCountdown();
                    clearInvoice(mintId, chainField.value);
                    renderResumeBanners();
                    if (s.status !== 'consumed') showState('preinit');
                    setProceedEnabled(false, 'Continue to Mint');
                    releaseWakeLock();
                }
            }).catch(function () {});
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
            const c = active.querySelector('.altpay-countdown');
            if (c) c.remove();
        }
        function updateCountdownLine(expMs) {
            let el = active.querySelector('.altpay-countdown');
            if (!el) {
                el = document.createElement('p');
                el.className = 'altpay-countdown';
                active.appendChild(el);
            }
            const remaining = expMs - Date.now();
            el.textContent = 'This payment window expires in ' + formatRemaining(remaining) + '. Keep this tab open or use Resume to come back.';
            if (remaining <= 0) stopCountdown();
        }

        /* ── Click handlers ─────────────────────────────────────── */

        picker.addEventListener('click', function (ev) {
            const chipBtn = ev.target.closest('[data-altpay-chain]');
            if (chipBtn) { selectChain(chipBtn.getAttribute('data-altpay-chain')); return; }

            const action = ev.target.closest('[data-altpay-action]');
            if (!action) return;
            const which = action.getAttribute('data-altpay-action');

            if (which === 'start') {
                startPaymentSession();
            } else if (which === 'back') {
                selectChain('ada');
            } else if (which === 'copy-address') {
                copyText(addrEl.textContent || '').then(function () {
                    action.textContent = 'Copied';
                    setTimeout(function () { action.textContent = 'Copy'; }, 1500);
                });
            } else if (which === 'cancel') {
                const chain = chainField.value;
                const inv = invoiceField.value;
                if (!window.confirm('Cancel this ' + chain.toUpperCase() + ' payment session? The deposit address will be discarded.')) return;
                if (inv) rest('/altpay/cancel', 'POST', { invoice_id: parseInt(inv, 10) }).catch(function () {});
                clearInvoice(mintId, chain);
                renderResumeBanners();
                stopPolling();
                stopCountdown();
                invoiceField.value = '';
                showState('preinit');
                setProceedEnabled(false, 'Waiting for ' + chain.toUpperCase() + ' payment');
                releaseWakeLock();
            }
        });

        // Default state.
        setProceedEnabled(true, 'Continue to Mint');
        setChipActive('ada');

        /* ── Resume banners (one per active session) ────────────── */

        function renderResumeBanners() {
            removeResumeBanners();
            const wrapper = document.querySelector('.cardano-shortcode-wrapper');
            if (!wrapper) return;
            const all = listActiveInvoices(mintId);
            if (all.length === 0) return;

            const host = document.createElement('div');
            host.className = 'altpay-resume-host';
            host.dataset.altpayResumeHost = '1';

            all.forEach(function (entry) {
                const chain = entry.chain;
                const banner = document.createElement('div');
                banner.className = 'altpay-resume-banner';
                banner.dataset.altpayResumeChain = chain;
                banner.innerHTML =
                    '<span class="altpay-resume-dot" aria-hidden="true"></span>' +
                    '<span class="altpay-resume-msg">Pending <strong>' + chain.toUpperCase() + '</strong> payment in progress.</span>' +
                    '<button type="button" class="altpay-btn" data-altpay-resume="' + chain + '">Resume mint</button>' +
                    '<button type="button" class="altpay-resume-cancel" data-altpay-resume-cancel="' + chain + '" title="Cancel this session">&times;</button>';
                host.appendChild(banner);
            });

            wrapper.parentNode.insertBefore(host, wrapper.nextSibling);

            host.addEventListener('click', function (ev) {
                const resumeBtn = ev.target.closest('[data-altpay-resume]');
                if (resumeBtn) {
                    const chain = resumeBtn.getAttribute('data-altpay-resume');
                    const mintNowBtn = document.getElementById('cardano-mint-now-btn');
                    if (mintNowBtn) mintNowBtn.click();
                    // Defer chip selection to give the modal a tick to mount.
                    setTimeout(function () { selectChain(chain); }, 300);
                    return;
                }
                const cancelBtn = ev.target.closest('[data-altpay-resume-cancel]');
                if (cancelBtn) {
                    const chain = cancelBtn.getAttribute('data-altpay-resume-cancel');
                    if (!window.confirm('Cancel the pending ' + chain.toUpperCase() + ' payment? You will need to start a fresh session to pay with ' + chain.toUpperCase() + ' for this mint.')) return;
                    const stored = readInvoice(mintId, chain);
                    if (stored && stored.invoice_id) {
                        rest('/altpay/cancel', 'POST', { invoice_id: stored.invoice_id }).catch(function () {});
                    }
                    clearInvoice(mintId, chain);
                    renderResumeBanners();
                }
            });
        }
        function removeResumeBanners() {
            document.querySelectorAll('[data-altpay-resume-host="1"]').forEach(function (n) { n.remove(); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
