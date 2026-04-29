(function () {
    'use strict';

    // Customer-side payment-method picker for the [cardano-mint] shortcode.
    // Talks to /wp-json/cardano-mint/v1/altpay/{quote,status,cancel}.
    //
    // The picker only renders when an admin has flipped on
    // cardano_mint_altpay_enabled AND configured at least one chain wallet.
    // ADA stays the default so existing Cardano-only sites are unaffected.

    const cfg = window.cardanoAltPayCheckout || {};

    // Convert chain-native smallest unit (sats / wei / lamports) into
    // human-readable major units. Uses BigInt for ETH so we don't lose
    // precision on 1e18 wei values.
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
            // Fall back to float math for tiny values where BigInt parsing fails.
            const f = Number(minorStr) / Math.pow(10, decimals);
            major = f.toString();
        }
        return { major: major, minor: minorStr, symbol: chain.toUpperCase() };
    }

    function chainMinorLabel(chain) {
        return chain === 'btc' ? 'sats' : chain === 'eth' ? 'wei' : 'lamports';
    }

    function rest(path, method, body) {
        const opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        return fetch(cfg.restUrl + path, opts).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || (j && j.error)) {
                    throw new Error((j && j.error) || ('HTTP ' + r.status));
                }
                return j;
            });
        });
    }

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
                ta.value = text; ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.focus(); ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand failed'));
            } catch (e) { reject(e); }
        });
    }

    function init() {
        const picker = document.getElementById('altpay-picker');
        if (!picker) return; // altpay not enabled / no chain wallets configured

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

        // Show the picker once a Cardano wallet has connected. We watch the
        // wallet display element flipping to display:block via mutation, since
        // cardano-nft-mint.js owns the wallet flow and we don't want to fight it.
        const observer = new MutationObserver(function () {
            if (walletDisplay && walletDisplay.style.display !== 'none') {
                picker.removeAttribute('hidden');
            }
        });
        if (walletDisplay) {
            observer.observe(walletDisplay, { attributes: true, attributeFilter: ['style'] });
        }

        // Disable the "Continue to Mint" button until either ADA picked or invoice funded.
        function setProceedEnabled(enabled, label) {
            if (!proceedBtn) return;
            proceedBtn.disabled = !enabled;
            proceedBtn.style.opacity = enabled ? '1' : '0.6';
            proceedBtn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
            if (label) proceedBtn.textContent = label;
        }

        let pollHandle = null;

        function selectChain(chain) {
            picker.querySelectorAll('.altpay-chip').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-altpay-chain') === chain);
            });
            chainField.value = chain;

            if (chain === 'ada') {
                hidePayPanel();
                invoiceField.value = '';
                setProceedEnabled(true, 'Continue to Mint');
                return;
            }

            const customerCardano = (document.getElementById('connected-wallet-address') || {}).textContent || '';
            if (!customerCardano) {
                alert('Connect your Cardano wallet first.');
                return;
            }

            payPanel.removeAttribute('hidden');
            addrEl.textContent  = '…';
            amountEl.textContent = 'fetching quote…';
            statusText.textContent = 'requesting quote…';
            observedEl.style.display = 'none';
            setProceedEnabled(false, 'Waiting for ' + chain.toUpperCase() + ' payment');

            rest('/altpay/quote', 'POST', {
                mint_id: mintId,
                payment_method: chain,
                customer_cardano_address: customerCardano,
            }).then(function (q) {
                invoiceField.value = q.invoice_id;
                addrEl.textContent  = q.address;

                const f = formatChainAmount(chain, q.expected_amount_minor || '0');
                // Major units in big text + minor units underneath for verification.
                amountEl.innerHTML = f.major + ' <span class="altpay-amount-symbol">' + f.symbol + '</span>'
                    + '<span class="altpay-amount-minor">(' + Number(f.minor).toLocaleString('en-US') + ' ' + chainMinorLabel(chain) + ')</span>';

                setStatus('pending', 'waiting for payment…');
                startPolling(q.invoice_id);
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
                    setProceedEnabled(true, 'Payment received — Continue to Mint');
                } else if (['expired', 'cancelled'].indexOf(s.status) !== -1) {
                    clearInterval(pollHandle); pollHandle = null;
                    setProceedEnabled(false, 'Continue to Mint');
                }
            }).catch(function () { /* transient errors are fine; next tick will retry */ });
        }

        function hidePayPanel() {
            payPanel.setAttribute('hidden', '');
            if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
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
                hidePayPanel();
                selectChain('ada');
            }
        });

        // Default state: ADA proceed enabled.
        setProceedEnabled(true, 'Continue to Mint');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
