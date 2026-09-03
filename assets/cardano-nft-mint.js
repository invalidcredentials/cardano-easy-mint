// Cardano NFT Minting JavaScript
(function() {
    'use strict';

    // Debug tracing. console.log noise only appears when WP_DEBUG is on (localized
    // as cardanoMint.debug); console.warn / console.error are always emitted.
    var CM_DEBUG = !!(window.cardanoMint && window.cardanoMint.debug);
    function cmDebug() {
        if (CM_DEBUG && window.console && console.log) { console.log.apply(console, arguments); }
    }
    
        // Variables for NFT minting (scoped to this function)
        let mintWallet = null;
        let isMintProcessing = false;

        /**
         * CardanoMintWallet — Embedded CIP-30 wallet connection layer.
         * Ported from Weld for WP (bech32.js + extensions.js + wallet.js).
         * Zero dependencies, fully self-contained.
         */
        var CardanoMintWallet = (function() {
            // ── Bech32 Encoding ──
            var BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
            var BECH32_GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

            function bech32Polymod(values) {
                var chk = 1;
                for (var j = 0; j < values.length; j++) {
                    var v = values[j], top = chk >> 25;
                    chk = ((chk & 0x1ffffff) << 5) ^ v;
                    for (var i = 0; i < 5; i++) if ((top >> i) & 1) chk ^= BECH32_GEN[i];
                }
                return chk;
            }

            function bech32HrpExpand(hrp) {
                var out = [];
                for (var i = 0; i < hrp.length; i++) out.push(hrp.charCodeAt(i) >> 5);
                out.push(0);
                for (var i = 0; i < hrp.length; i++) out.push(hrp.charCodeAt(i) & 31);
                return out;
            }

            function bech32ConvertBits(data, fromBits, toBits, pad) {
                var acc = 0, bits = 0, result = [], maxv = (1 << toBits) - 1;
                for (var j = 0; j < data.length; j++) {
                    var value = data[j];
                    acc = (acc << fromBits) | value;
                    bits += fromBits;
                    while (bits >= toBits) { bits -= toBits; result.push((acc >> bits) & maxv); }
                }
                if (pad && bits > 0) result.push((acc << (toBits - bits)) & maxv);
                return result;
            }

            function bech32Encode(hrp, data5bit) {
                var checksum = [];
                var values = bech32HrpExpand(hrp).concat(data5bit).concat([0, 0, 0, 0, 0, 0]);
                var mod = bech32Polymod(values) ^ 1;
                for (var p = 0; p < 6; p++) checksum.push((mod >> (5 * (5 - p))) & 31);
                var combined = data5bit.concat(checksum);
                var result = hrp + '1';
                for (var i = 0; i < combined.length; i++) result += BECH32_CHARSET.charAt(combined[i]);
                return result;
            }

            function hexToBytes(hex) {
                var bytes = [];
                for (var i = 0; i < hex.length; i += 2) bytes.push(parseInt(hex.substring(i, i + 2), 16));
                return bytes;
            }

            function hexAddressToBech32(hexAddress) {
                if (!hexAddress || typeof hexAddress !== 'string') return hexAddress;
                if (hexAddress.startsWith('addr')) return hexAddress;
                if (!/^[0-9a-fA-F]+$/.test(hexAddress)) return hexAddress;
                var bytes = hexToBytes(hexAddress);
                if (bytes.length === 0) return hexAddress;
                var headerByte = bytes[0];
                var networkId = headerByte & 0x0f;
                var addressType = (headerByte >> 4) & 0x0f;
                var prefix;
                if (addressType === 0x0e || addressType === 0x0f) {
                    prefix = networkId === 1 ? 'stake' : 'stake_test';
                } else {
                    prefix = networkId === 1 ? 'addr' : 'addr_test';
                }
                var data5bit = bech32ConvertBits(bytes, 8, 5, true);
                if (!data5bit) return hexAddress;
                return bech32Encode(prefix, data5bit);
            }

            // ── Wallet Extension Detection ──
            function getInstalledWallets() {
                var cardano = window.cardano;
                if (!cardano) return [];
                var wallets = [];
                var keys = Object.keys(cardano);
                for (var k = 0; k < keys.length; k++) {
                    var key = keys[k];
                    var provider = cardano[key];
                    if (!provider || typeof provider.enable !== 'function') continue;
                    if (key === 'enable' || key === '_events') continue;
                    wallets.push({
                        key: key,
                        name: provider.name || key,
                        icon: provider.icon || null,
                    });
                }
                return wallets;
            }

            // ── Wallet State ──
            var walletState = {
                isConnected: false, address: null, addressHex: null,
                stakeAddress: null, balanceLovelace: null, balanceAda: null,
                walletKey: null, walletName: null, walletIcon: null, handler: null,
            };
            var listeners = [];

            function notify() {
                var copy = Object.assign({}, walletState);
                for (var i = 0; i < listeners.length; i++) {
                    try { listeners[i](copy); } catch (e) { console.error('[CardanoMint] Listener error:', e); }
                }
            }

            function subscribe(fn) {
                listeners.push(fn);
                fn(Object.assign({}, walletState));
                return function() { listeners = listeners.filter(function(f) { return f !== fn; }); };
            }

            function getState() { return Object.assign({}, walletState); }

            // ── CBOR Balance Parser ──
            function decodeCborUint(hex) {
                var first = parseInt(hex.substring(0, 2), 16);
                var major = first >> 5;
                if (major !== 0) return '0';
                var additional = first & 0x1f;
                if (additional <= 23) return String(additional);
                if (additional === 24) return String(parseInt(hex.substring(2, 4), 16));
                if (additional === 25) return String(parseInt(hex.substring(2, 6), 16));
                if (additional === 26) return String(parseInt(hex.substring(2, 10), 16));
                if (additional === 27) return BigInt('0x' + hex.substring(2, 18)).toString();
                return '0';
            }

            function parseBalanceCbor(hex) {
                if (!hex) return null;
                try {
                    var firstByte = parseInt(hex.substring(0, 2), 16);
                    var coinHex = firstByte === 0x82 ? hex.substring(2) : hex;
                    return decodeCborUint(coinHex);
                } catch (e) { return null; }
            }

            // Maps the site's configured network to the CIP-30 networkId
            // value the wallet returns. 1 = mainnet, 0 = any testnet.
            function expectedCardanoNetworkId() {
                var n = (window.cardanoMint && window.cardanoMint.network) || '';
                n = String(n).toLowerCase();
                return n === 'mainnet' ? 1 : 0;
            }

            function expectedNetworkLabel() {
                var n = (window.cardanoMint && window.cardanoMint.network) || '';
                n = String(n).toLowerCase();
                if (n === 'mainnet') return 'Mainnet';
                if (n === 'preprod') return 'Pre-production';
                if (n === 'preview') return 'Preview';
                return n || 'Testnet';
            }

            // True when the bech32 address belongs to the site's configured
            // network. We check both the wallet-reported networkId AND the
            // resolved address prefix because some wallets misreport one or
            // the other when the user has multiple network profiles.
            function isAddressOnExpectedNetwork(bech32) {
                if (!bech32) return false;
                var expected = expectedCardanoNetworkId();
                var isTestnetAddr = /^addr_test1|^stake_test1/.test(bech32);
                return expected === 1 ? !isTestnetAddr : isTestnetAddr;
            }

            // ── Connect ──
            async function connect(walletKey) {
                var cardano = window.cardano;
                if (!cardano || !cardano[walletKey]) {
                    throw new Error('Wallet "' + walletKey + '" not found. Is the extension installed?');
                }
                var provider = cardano[walletKey];
                var api = await provider.enable();

                // Network gate: every alt-pay invoice + Cardano mint tx is
                // bound to the customer's address at quote time. If the wallet
                // is on the wrong network we end up holding off-chain payment
                // bound to an address that can never sign on this network, and
                // the customer is stuck. Bail BEFORE we touch addresses or
                // record any state.
                var expectedId = expectedCardanoNetworkId();
                var actualId  = null;
                try {
                    if (typeof api.getNetworkId === 'function') {
                        actualId = await api.getNetworkId();
                    }
                } catch (e) {
                    console.warn('[CardanoMint] getNetworkId threw:', e);
                }
                if (actualId !== null && actualId !== expectedId) {
                    var actualLabel = (actualId === 1) ? 'Mainnet' : 'Testnet';
                    throw new Error(
                        'Wallet is on ' + actualLabel + ' but this site is on ' +
                        expectedNetworkLabel() + '. Switch your wallet network and try again.'
                    );
                }

                var usedAddresses = await api.getUsedAddresses();
                var unusedAddresses = await api.getUnusedAddresses();
                var addressHex = (usedAddresses && usedAddresses[0]) || (unusedAddresses && unusedAddresses[0]) || null;
                var address = addressHex ? hexAddressToBech32(addressHex) : null;

                // Belt-and-suspenders: verify the resolved address prefix.
                // Catches wallets that respond to getNetworkId() but expose an
                // address profile from a different network than the active one.
                if (address && !isAddressOnExpectedNetwork(address)) {
                    throw new Error(
                        'Wallet returned a ' + (address.startsWith('addr_test1') ? 'Testnet' : 'Mainnet') +
                        ' address but this site is on ' + expectedNetworkLabel() +
                        '. Switch your wallet network and try again.'
                    );
                }

                var balanceCbor = await api.getBalance();
                var balanceLovelace = parseBalanceCbor(balanceCbor);

                var stakeAddressHex = null;
                if (typeof api.getRewardAddresses === 'function') {
                    var rewardAddresses = await api.getRewardAddresses();
                    stakeAddressHex = (rewardAddresses && rewardAddresses[0]) || null;
                }
                var stakeAddress = stakeAddressHex ? hexAddressToBech32(stakeAddressHex) : null;

                walletState = {
                    isConnected: true, address: address, addressHex: addressHex,
                    stakeAddress: stakeAddress, balanceLovelace: balanceLovelace,
                    balanceAda: balanceLovelace !== null ? (Number(balanceLovelace) / 1000000).toFixed(6) : null,
                    walletKey: walletKey, walletName: provider.name || walletKey,
                    walletIcon: provider.icon || null, handler: api,
                };

                try { localStorage.setItem('cardano_mint_last_wallet', walletKey); } catch(e) {}
                notify();
                return walletState;
            }

            function disconnect() {
                walletState = {
                    isConnected: false, address: null, addressHex: null,
                    stakeAddress: null, balanceLovelace: null, balanceAda: null,
                    walletKey: null, walletName: null, walletIcon: null, handler: null,
                };
                try { localStorage.removeItem('cardano_mint_last_wallet'); } catch(e) {}
                notify();
            }

            async function tryReconnect() {
                try {
                    var last = localStorage.getItem('cardano_mint_last_wallet');
                    if (last && window.cardano && window.cardano[last]) {
                        await connect(last);
                    }
                } catch(e) {
                    localStorage.removeItem('cardano_mint_last_wallet');
                }
            }

            async function signTx(txCbor, partialSign) {
                if (!walletState.handler) throw new Error('No wallet connected.');
                return walletState.handler.signTx(txCbor, partialSign !== false);
            }

            return {
                connect: connect, disconnect: disconnect, tryReconnect: tryReconnect,
                getState: getState, subscribe: subscribe, signTx: signTx,
                getInstalledWallets: getInstalledWallets, hexAddressToBech32: hexAddressToBech32,
            };
        })();

        // Helper function to convert a CBOR-encoded address to Bech32. The call goes
        // through the plugin's own AJAX proxy (cardano_convert_address) so the Anvil
        // API key stays on the server and is never shipped to the browser.
        async function convertCborToBech32ViaAnvil(cborAddress) {
            try {
                // Check if it's already in Bech32 format
                if (cborAddress && (cborAddress.startsWith('addr1') || cborAddress.startsWith('addr_test1'))) {
                    return cborAddress;
                }

                if (!window.cardanoMint || !window.cardanoMint.ajaxurl || !window.cardanoMint.nonce) {
                    console.warn('cardanoMint config missing; passing the raw address through');
                    return cborAddress;
                }

                const fd = new FormData();
                fd.append('action', 'cardano_convert_address');
                fd.append('nonce', window.cardanoMint.nonce);
                fd.append('address', cborAddress);

                const response = await fetch(window.cardanoMint.ajaxurl, { method: 'POST', body: fd });
                if (!response.ok) {
                    throw new Error('Address conversion error: ' + response.status + ' ' + response.statusText);
                }

                const data = await response.json();
                if (data && data.success && data.data && typeof data.data.address === 'string' && data.data.address) {
                    return data.data.address;
                }
                throw new Error((data && data.data && data.data.message) || 'No address returned from conversion proxy');

            } catch (error) {
                console.warn('Server-side address conversion failed, passing the raw address through:', error);
                return cborAddress;
            }
        }
        
        // Helper function to get any address from the wallet (CBOR or Bech32)
        async function getAnyAddress(walletAPI) {
            try {
                cmDebug('=== GETTING WALLET ADDRESS ===');
                cmDebug('Wallet API object:', walletAPI);

                // Method 1: Try getChangeAddress first
                try {
                    cmDebug('Attempting getChangeAddress...');
                    const address = await walletAPI.getChangeAddress();
                    cmDebug('getChangeAddress returned:', address);
                    cmDebug('Address type:', typeof address);
                    cmDebug('Address length:', address ? address.length : 'null');

                    if (address) {
                        // For CBOR-encoded addresses, convert to Bech32 first
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            cmDebug('CBOR address detected, converting to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            cmDebug('Converted to Bech32:', convertedAddress);
                            return convertedAddress;
                        } else {
                            // For already Bech32 addresses, use as-is
                            cmDebug('Bech32 address detected, using as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.error('getChangeAddress failed:', e);
                }
                
                // Method 2: Try getUsedAddresses
                try {
                    const usedAddresses = await walletAPI.getUsedAddresses();
                    cmDebug('Method 2 - getUsedAddresses:', usedAddresses);
                    
                    if (usedAddresses && usedAddresses.length > 0) {
                        const address = usedAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            cmDebug('Converting CBOR used address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            cmDebug('Converted used address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            cmDebug('Using Bech32 used address as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.warn('getUsedAddresses failed:', e);
                }
                
                // Method 3: Try getUnusedAddresses
                try {
                    const unusedAddresses = await walletAPI.getUnusedAddresses();
                    cmDebug('Method 3 - getUnusedAddresses:', unusedAddresses);
                    
                    if (unusedAddresses && unusedAddresses.length > 0) {
                        const address = unusedAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            cmDebug('Converting CBOR unused address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            cmDebug('Converted unused address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            cmDebug('Using Bech32 unused address as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.warn('getUnusedAddresses failed:', e);
                }
                
                // Method 4: Try getRewardAddresses
                try {
                    const rewardAddresses = await walletAPI.getRewardAddresses();
                    cmDebug('Method 4 - getRewardAddresses:', rewardAddresses);
                    
                    if (rewardAddresses && rewardAddresses.length > 0) {
                        const address = rewardAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            cmDebug('Converting CBOR reward address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            cmDebug('Converted reward address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            cmDebug('Using Bech32 reward address as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.warn('getRewardAddresses failed:', e);
                }
                
                console.error('No address found through any method');
                return null;
                
            } catch (error) {
                console.error('Error getting address:', error);
                return null;
            }
        }

    // True when the customer paid on a non-Cardano chain. In that case the
    // alt-pay overlay (altpay-checkout.js) has already rewritten the receipt
    // to mark the NFT line "Paid via SOL ✓" and shown a Cross-Chain Service
    // Fee line. We MUST NOT touch totals or qty in that mode — the customer
    // already paid for exactly 1 NFT off-chain, and stomping the receipt
    // would re-bill them.
    function isAltPayActive() {
        const inv = document.getElementById('altpay-invoice-id');
        if (inv && inv.value) return true;
        if (document.getElementById('review-altpay-service-line')) return true;
        return false;
    }

    // Quantity stepper: tracks how many NFTs the customer wants in this tx
    // (1-5 cap; per-wallet limit is enforced separately on the server). The
    // line items in Step 2 are pre-rendered with per-unit values stored on
    // data-unit-usd / data-unit-ada attributes; this function multiplies them
    // by the current qty and rewrites the visible numbers + the running total.
    // ───────── Discount code (customer) ─────────
    // appliedDiscount holds the validated *rule* so the price preview can be
    // recomputed at any quantity client-side. The server re-validates + reserves
    // authoritatively at build time, so this is preview-only.
    let appliedDiscount = null; // { code, type, percent, fixed, label }

    function calcDiscountUsd(nftUsd) {
        if (!appliedDiscount || nftUsd <= 0) return 0;
        if (appliedDiscount.type === 'percent') return nftUsd * (appliedDiscount.percent / 100);
        if (appliedDiscount.type === 'fixed')   return Math.min(appliedDiscount.fixed, nftUsd);
        return 0;
    }

    // Renders the discount line off the NFT price component ONLY (the MSRP — fees
    // are never discounted) and returns the {usd, ada} to subtract from totals.
    function renderDiscountRow(qty) {
        const row = document.getElementById('review-discount-row');
        if (!row) return { usd: 0, ada: 0 };
        if (!appliedDiscount) { row.style.display = 'none'; return { usd: 0, ada: 0 }; }
        const usdEl = document.getElementById('review-nft-price-usd');
        const adaEl = document.getElementById('review-nft-price-ada');
        const unitUsd = usdEl ? (parseFloat(usdEl.dataset.unitUsd) || 0) : 0;
        const unitAda = adaEl ? (parseFloat(adaEl.dataset.unitAda || (usdEl && usdEl.dataset.unitAda)) || 0) : 0;
        const nftUsd = unitUsd * qty, nftAda = unitAda * qty;
        const dUsd = calcDiscountUsd(nftUsd);
        const dAda = nftUsd > 0 ? dUsd * (nftAda / nftUsd) : 0;
        if (dUsd <= 0) { row.style.display = 'none'; return { usd: 0, ada: 0 }; }
        row.style.display = '';
        const lbl = document.getElementById('review-discount-label');
        const du = document.getElementById('review-discount-usd');
        const da = document.getElementById('review-discount-ada');
        if (lbl) lbl.textContent = 'Discount (' + appliedDiscount.code + ')';
        if (du) du.textContent = '-$' + dUsd.toFixed(2) + ' USD';
        if (da) da.textContent = '-' + dAda.toFixed(2) + ' ADA';
        return { usd: dUsd, ada: dAda };
    }

    async function applyDiscountCode() {
        const input  = document.getElementById('discount-code-input');
        const status = document.getElementById('discount-status');
        const btn    = document.getElementById('discount-apply-btn');
        if (!input || !status || !btn) return;

        // Acting as "Remove" when a code is already applied.
        if (appliedDiscount) {
            appliedDiscount = null;
            const applied = document.getElementById('discount-code-applied');
            const rid = document.getElementById('discount-redemption-id');
            if (applied) applied.value = '';
            if (rid) rid.value = '';
            input.disabled = false; input.value = '';
            btn.textContent = 'Apply';
            status.className = 'discount-entry-status'; status.textContent = '';
            recomputeReviewTotals();
            return;
        }

        const code = (input.value || '').trim().toUpperCase();
        if (!code) { status.className = 'discount-entry-status is-error'; status.textContent = 'Enter a code.'; return; }

        const mintButton = document.getElementById('cardano-mint-now-btn');
        const assetId = mintButton ? mintButton.getAttribute('data-mint-id') : '';
        const qtyInput = document.getElementById('qty-input');
        const qty = qtyInput ? Math.max(1, Math.min(5, parseInt(qtyInput.value, 10) || 1)) : 1;

        status.className = 'discount-entry-status'; status.textContent = 'Checking…';
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('action', 'cardano_discount_validate');
            fd.append('nonce', cardanoMint.nonce);
            fd.append('code', code);
            fd.append('asset_id', assetId);
            fd.append('quantity', String(qty));
            fd.append('payment_method', 'ada');
            const r = await fetch(cardanoMint.ajaxurl, { method: 'POST', body: fd });
            const res = await r.json();
            btn.disabled = false;
            if (!res.success) {
                status.className = 'discount-entry-status is-error';
                // The server always sends a specific reason on a real rejection
                // (not found / expired / used / wrong collection / etc.). No
                // message means the request itself didn't land — say that instead
                // of falsely claiming the code is invalid.
                status.textContent = (res && res.data && res.data.message)
                    || 'Couldn\'t check that code right now — please try again.';
                return;
            }
            appliedDiscount = {
                code:    res.data.code,
                type:    res.data.discount_type,
                percent: parseFloat(res.data.percent_off) || 0,
                fixed:   parseFloat(res.data.fixed_off_usd) || 0,
                label:   res.data.label
            };
            const applied = document.getElementById('discount-code-applied');
            if (applied) applied.value = appliedDiscount.code;
            input.disabled = true; input.value = appliedDiscount.code;
            btn.textContent = 'Remove';
            status.className = 'discount-entry-status is-success';
            status.textContent = '✓ ' + appliedDiscount.label + ' applied';
            recomputeReviewTotals();
        } catch (e) {
            btn.disabled = false;
            status.className = 'discount-entry-status is-error';
            status.textContent = 'Network error. Try again.';
        }
    }

    // Bind once via delegation so it works no matter when Step 2 renders.
    (function bindDiscountUI() {
        if (window.__cmDiscountBound) return;
        window.__cmDiscountBound = true;
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'discount-apply-btn') { e.preventDefault(); applyDiscountCode(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target && e.target.id === 'discount-code-input') { e.preventDefault(); applyDiscountCode(); }
        });
    })();

    function recomputeReviewTotals() {
        if (isAltPayActive()) return; // alt-pay overlay owns the receipt
        const qtyInput = document.getElementById('qty-input');
        if (!qtyInput) return;
        const qty = Math.max(1, Math.min(5, parseInt(qtyInput.value, 10) || 1));
        qtyInput.value = qty;

        const fmtUsd = function (n) { return '$' + n.toFixed(2) + ' USD'; };
        const fmtAda = function (n, p) { return n.toFixed(p) + ' ADA'; };

        const ids = [
            { usd: 'review-nft-price-usd', ada: 'review-nft-price-ada', adaPrec: 4 },
            { usd: 'review-anvil-usd',     ada: 'review-anvil-ada',     adaPrec: 2 },
            // Network fee scales slightly per asset but Anvil estimates the
            // real value at build time. Keep the displayed estimate flat —
            // a small under/over here is normal and the customer sees the
            // exact fee in their wallet before signing.
        ];
        let totalUsd = 0, totalAda = 0;
        ids.forEach(function (row) {
            const usdEl = document.getElementById(row.usd);
            const adaEl = document.getElementById(row.ada);
            if (!usdEl || !adaEl) return;
            const unitUsd = parseFloat(usdEl.dataset.unitUsd) || 0;
            const unitAda = parseFloat(adaEl.dataset.unitAda || usdEl.dataset.unitAda) || 0;
            const u = unitUsd * qty;
            const a = unitAda * qty;
            usdEl.textContent = fmtUsd(u);
            adaEl.textContent = fmtAda(a, row.adaPrec);
            totalUsd += u;
            totalAda += a;
        });
        // Network fee is one-tx flat (Anvil-estimated, not multiplied).
        const netUsdEl = document.getElementById('review-network-usd');
        const netAdaEl = document.getElementById('review-network-ada');
        if (netUsdEl && netAdaEl) {
            const netUsd = parseFloat(netUsdEl.dataset.unitUsd) || 0;
            const netAda = parseFloat(netAdaEl.dataset.unitAda) || 0;
            netUsdEl.textContent = '~' + fmtUsd(netUsd);
            netAdaEl.textContent = '~' + fmtAda(netAda, 2);
            totalUsd += netUsd;
            totalAda += netAda;
        }

        // Apply any validated discount to the NFT price component, then total.
        const disc = renderDiscountRow(qty);
        totalUsd = Math.max(0, totalUsd - disc.usd);
        totalAda = Math.max(0, totalAda - disc.ada);

        const totUsdEl = document.getElementById('review-total-usd');
        const totAdaEl = document.getElementById('review-total-ada');
        if (totUsdEl) totUsdEl.textContent = fmtUsd(totalUsd);
        if (totAdaEl) totAdaEl.textContent = fmtAda(totalAda, 2);

        // NFT line label reflects qty so the customer sees what they're paying for.
        const lineLabel = document.getElementById('review-nft-price-label');
        if (lineLabel) lineLabel.textContent = qty > 1 ? ('NFT Mint Price × ' + qty) : 'NFT Mint Price';
    }

    // Run every Step 2 entry. Hiding for alt-pay must happen even if event
    // listeners are already bound from a previous open of the modal, so the
    // visibility check is intentionally outside the dataset.bound guard.
    function setupQuantityStepper() {
        const dec = document.getElementById('qty-dec');
        const inc = document.getElementById('qty-inc');
        const inp = document.getElementById('qty-input');
        const row = document.getElementById('receipt-quantity-row');
        const hint = document.getElementById('quantity-hint');
        if (!dec || !inc || !inp) return;

        // Visibility/qty pin for alt-pay (re-checked on every Step 2 entry).
        if (isAltPayActive()) {
            inp.value = '1';
            if (row)  row.style.display = 'none';
            if (hint) hint.style.display = 'none';
        } else {
            if (row)  row.style.display = '';
            if (hint) hint.style.display = '';
        }

        if (dec.dataset.bound) return;
        dec.dataset.bound = '1';
        const setQty = function (n) {
            if (isAltPayActive()) { inp.value = '1'; return; }
            inp.value = Math.max(1, Math.min(5, n));
            recomputeReviewTotals();
        };
        dec.addEventListener('click', function () { setQty(parseInt(inp.value, 10) - 1); });
        inc.addEventListener('click', function () { setQty(parseInt(inp.value, 10) + 1); });
    }

    // This script is enqueued site-wide, but only ever needs to run on a page
    // that actually renders the mint widget. Previously it ran its whole init —
    // and spammed the console (and historically auto-connected a wallet) — on
    // EVERY page. Gate on the mint widget's presence so non-mint pages (home,
    // trade, account) are a silent no-op.
    //
    // We don't use Bricks anymore, so the old bricks:after:render hook and the
    // 2s re-init fallback are gone — the mint shortcode is in the initial DOM.
    function hasMintWidget() {
        return !!(
            document.getElementById('cardano-mint-now-btn') ||
            document.getElementById('connect-wallet-btn') ||
            document.getElementById('cardano-nft-mint-form')
        );
    }

    function bootMint() {
        if (!hasMintWidget()) return; // silent no-op off the mint page
        initializeNFTMint();
        setupQuantityStepper();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootMint);
    } else {
        bootMint();
    }

    // Global event delegation - only set up once
    let eventDelegationSetup = false;
    
    function setupEventDelegation() {
        if (eventDelegationSetup) return;
        eventDelegationSetup = true;
        
        cmDebug('Setting up event delegation for MINT NOW button');
        
        // Use event delegation - listen on document for clicks on the button
        // This works even if Bricks replaces the DOM
        document.addEventListener('click', function(e) {
            cmDebug('Click detected on:', e.target, 'ID:', e.target.id, 'Classes:', e.target.className);
            
            if (e.target && e.target.id === 'cardano-mint-now-btn') {
                cmDebug('MINT NOW button clicked via delegation');
                e.preventDefault();
                openMintModal();
            }
            
            // Handle modal close button
            if (e.target && e.target.classList.contains('cardano-modal-close')) {
                cmDebug('Modal close button clicked via delegation');
                e.preventDefault();
                closeMintModal();
            }
            
            // Handle modal background click
            if (e.target && e.target.id === 'cardano-nft-mint-modal') {
                cmDebug('Modal background clicked via delegation');
                closeMintModal();
            }
        });
    }

    function initializeNFTMint() {
        cmDebug('initializeNFTMint() called');
        
        // Set up event delegation if not already done
        setupEventDelegation();

        // Modal event handling is now done via delegation above
        cmDebug('Modal event handling set up via delegation');

        // Initialize wallet connection
        cmDebug('Initializing wallet connection...');
        initializeWalletConnection();
    }

    function openMintModal() {
        const modal = document.getElementById('cardano-nft-mint-modal');
        if (modal) {
            modal.style.display = 'flex';
            // Allow modal to scroll but keep body scrollable too
            document.body.style.overflow = 'auto';
            nextMintStep(1);
        }
    }

    function closeMintModal() {
        cmDebug('closeMintModal() called');
        const modal = document.getElementById('cardano-nft-mint-modal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scroll
            // Reset form
            const form = document.getElementById('cardano-nft-mint-form');
            if (form) {
                form.reset();
            }
            nextMintStep(1);
            cmDebug('MINT NOW modal closed successfully');
        } else {
            cmDebug('MINT NOW modal not found when trying to close');
        }
    }

    function nextMintStep(step) {
        // Hide all steps
        document.querySelectorAll('.mint-step').forEach(s => s.style.display = 'none');
        document.querySelectorAll('.mint-steps .step').forEach(s => s.classList.remove('active'));
        
        // Show current step
        const stepElement = document.getElementById('mint-step-' + step);
        if (stepElement) {
            stepElement.style.display = 'block';
        }

        const stepIndicator = document.querySelector('.mint-steps .step[data-step="' + step + '"]');
        if (stepIndicator) {
            stepIndicator.classList.add('active');
        }

        // Wire up the qty stepper now that Step 2 is in the DOM (Bricks may
        // have replaced it after our DOMContentLoaded hook ran). Idempotent
        // via dataset.bound flag inside setupQuantityStepper.
        if (step === 2) {
            setupQuantityStepper();
            recomputeReviewTotals();
        }
    }

    function prevMintStep(step) {
        nextMintStep(step);
    }

    function initializeWalletConnection() {
        cmDebug('initializeWalletConnection() called');
        
        const connectBtn = document.getElementById('connect-wallet-btn');
        const proceedBtn = document.getElementById('proceed-to-confirm');
        const walletDisplay = document.getElementById('wallet-address-display');
        const walletAddress = document.getElementById('connected-wallet-address');
        const walletName = document.getElementById('connected-wallet-name');
        const walletNameDisplay = document.getElementById('wallet-name-display');
        const walletInput = document.getElementById('wallet-address');
        
        if (!connectBtn) {
            cmDebug('Connect wallet button not found, returning');
            return;
        }

        // One-shot: initializeNFTMint() fires on DOMContentLoaded, on a (now dead)
        // bricks:after:render event, AND on a blind 2s setTimeout. Without this guard
        // the connect/proceed click handlers below were bound 2-3x — so a single click
        // fired multiple connect attempts (the "looped so many times connecting") and
        // the state subscription stacked. Wire the mint connector exactly once.
        if (initializeWalletConnection._wired) return;
        initializeWalletConnection._wired = true;

        cmDebug('Connect wallet button found, setting up event listener');
        
        // Initialize CIP-30 wallet system
        initializeCIP30Wallet();
    
        function initializeCIP30Wallet() {
            cmDebug('initializeCIP30Wallet() called');
            cmDebug('window.cardanoMint exists:', !!window.cardanoMint);
            if (window.cardanoMint) {
                cmDebug('cardanoMint.debug:', window.cardanoMint.debug);
                cmDebug('cardanoMint object:', window.cardanoMint);
            }
            if (window.cardanoMint && window.cardanoMint.debug) {
                cmDebug('CIP-30 wallet system initialized for minting');
            } else {
                cmDebug('Debug mode not enabled, but CIP-30 system is ready');
            }
        }
    
        // Wallet detection and display names handled by embedded CardanoMintWallet
        
        // ── Wallet connection (the global site nav owns connection) ──
        //
        // Do NOT auto-reconnect this plugin's own vendored connector on page load.
        // The old tryReconnect() ran on every page the mint markup lands on (it is no
        // longer gated by Bricks — we don't use Bricks anymore — and stale page cache
        // can carry the form onto other pages), reconnecting its OWN last wallet and
        // hijacking whatever the user picked in the nav. On a preprod-configured local
        // it also thrashed against mainnet wallets (network mismatch), which is why it
        // was invisible on prod. Connection now happens ONLY when the user explicitly
        // clicks "Connect Wallet" in the mint flow.

        // Subscribe to wallet state changes — update UI automatically
        CardanoMintWallet.subscribe(function(state) {
            if (state.isConnected && state.address) {
                mintWallet = {
                    name: state.walletKey,
                    displayName: state.walletName,
                    changeAddress: state.address,
                    displayAddress: state.address,
                    fullAddress: state.address,
                    api: state.handler,
                    signTx: async function(tx) {
                        return await CardanoMintWallet.signTx(tx, true);
                    }
                };

                if (walletName) walletName.textContent = state.walletName;
                if (walletNameDisplay) walletNameDisplay.style.display = 'block';
                if (walletAddress) walletAddress.textContent = state.address;
                if (walletDisplay) walletDisplay.style.display = 'block';
                if (walletInput) walletInput.value = state.address;
                if (connectBtn) {
                    connectBtn.innerHTML = 'Wallet Connected &#10003;<span class="wallet-connected-hint">click to switch wallets</span>';
                    connectBtn.classList.add('is-connected');
                    connectBtn.dataset.connected = '1';
                }
                if (proceedBtn) proceedBtn.style.display = 'block';

                // Append a small disconnect link directly under the green
                // button on first connect. Hidden when not connected.
                var disconnectLink = document.getElementById('wallet-disconnect-link');
                if (!disconnectLink && connectBtn && connectBtn.parentNode) {
                    disconnectLink = document.createElement('button');
                    disconnectLink.type = 'button';
                    disconnectLink.id = 'wallet-disconnect-link';
                    disconnectLink.className = 'wallet-disconnect-link';
                    disconnectLink.textContent = 'Disconnect wallet';
                    disconnectLink.addEventListener('click', function () {
                        if (window.confirm('Disconnect this wallet?')) {
                            CardanoMintWallet.disconnect();
                        }
                    });
                    connectBtn.parentNode.insertBefore(disconnectLink, connectBtn.nextSibling);
                }
                if (disconnectLink) disconnectLink.style.display = 'inline-block';
            } else {
                // Wallet was disconnected — return the button to its initial state.
                if (connectBtn) {
                    connectBtn.textContent = 'Connect Wallet';
                    connectBtn.classList.remove('is-connected');
                    delete connectBtn.dataset.connected;
                    connectBtn.disabled = false;
                }
                if (walletNameDisplay) walletNameDisplay.style.display = 'none';
                if (walletDisplay) walletDisplay.style.display = 'none';
                if (proceedBtn) proceedBtn.style.display = 'none';
                var dl = document.getElementById('wallet-disconnect-link');
                if (dl) dl.style.display = 'none';
                mintWallet = null;
            }
        });

        // Inject wallet-picker styles once
        if (!document.getElementById('cardano-mint-picker-styles')) {
            var pickerStyles = document.createElement('style');
            pickerStyles.id = 'cardano-mint-picker-styles';
            pickerStyles.textContent = [
                '.cm-wallet-picker { margin-top: 12px; }',
                '.cm-wallet-picker-title { font-size: 13px; color: #6b7280; margin: 0 0 10px; }',
                '.cm-wallet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }',
                '.cm-wallet-card { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 10px; background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all .15s ease; font: inherit; color: inherit; }',
                '.cm-wallet-card:hover { border-color: #2DB0B8; background: #fff; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.06); }',
                '.cm-wallet-card:disabled { opacity: .6; cursor: wait; }',
                '.cm-wallet-card img { width: 36px; height: 36px; object-fit: contain; }',
                '.cm-wallet-card-fallback { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #2DB0B8; background: #e6fafb; border-radius: 50%; }',
                '.cm-wallet-card-name { font-size: 13px; font-weight: 600; color: #1f2937; text-transform: capitalize; }',
                '.cm-wallet-picker-cancel { margin-top: 12px; background: none; border: none; color: #6b7280; font-size: 12px; text-decoration: underline; cursor: pointer; padding: 4px 0; }',
                '.cm-wallet-picker-empty { font-size: 13px; color: #dc3545; background: #fff5f5; padding: 10px 12px; border-radius: 6px; border: 1px solid #fecaca; }',
            ].join('\n');
            document.head.appendChild(pickerStyles);
        }

        function renderWalletPicker(wallets) {
            var existing = document.getElementById('cm-wallet-picker');
            if (existing) existing.remove();

            var picker = document.createElement('div');
            picker.id = 'cm-wallet-picker';
            picker.className = 'cm-wallet-picker';

            var title = document.createElement('p');
            title.className = 'cm-wallet-picker-title';
            title.textContent = 'Choose a wallet to connect:';
            picker.appendChild(title);

            var grid = document.createElement('div');
            grid.className = 'cm-wallet-grid';

            wallets.forEach(function (w) {
                var card = document.createElement('button');
                card.type = 'button';
                card.className = 'cm-wallet-card';
                card.setAttribute('data-wallet-key', w.key);

                if (w.icon) {
                    var img = document.createElement('img');
                    img.src = w.icon;
                    img.alt = w.name;
                    card.appendChild(img);
                } else {
                    var fallback = document.createElement('div');
                    fallback.className = 'cm-wallet-card-fallback';
                    fallback.textContent = (w.name || w.key).charAt(0).toUpperCase();
                    card.appendChild(fallback);
                }

                var name = document.createElement('span');
                name.className = 'cm-wallet-card-name';
                name.textContent = w.name || w.key;
                card.appendChild(name);

                card.addEventListener('click', function () {
                    connectToWallet(w.key, w.name, card);
                });

                grid.appendChild(card);
            });

            picker.appendChild(grid);

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'cm-wallet-picker-cancel';
            cancel.textContent = 'Cancel';
            cancel.addEventListener('click', function () {
                picker.remove();
                connectBtn.textContent = 'Connect Wallet';
                connectBtn.disabled = false;
                connectBtn.style.display = '';
            });
            picker.appendChild(cancel);

            // Insert picker right after the connect button
            connectBtn.parentNode.insertBefore(picker, connectBtn.nextSibling);
        }

        async function connectToWallet(key, displayName, cardEl) {
            if (cardEl) {
                cardEl.disabled = true;
                var nameEl = cardEl.querySelector('.cm-wallet-card-name');
                if (nameEl) nameEl.textContent = 'Connecting…';
            }
            try {
                cmDebug('Connecting to:', key);
                await CardanoMintWallet.connect(key);
                var picker = document.getElementById('cm-wallet-picker');
                if (picker) picker.remove();
                // UI updates happen via the subscribe callback above
            } catch (error) {
                console.error('Wallet connection failed:', error);
                alert('Could not connect to ' + (displayName || key) + ': ' + error.message);
                if (cardEl) {
                    cardEl.disabled = false;
                    var nameEl2 = cardEl.querySelector('.cm-wallet-card-name');
                    if (nameEl2) nameEl2.textContent = displayName || key;
                }
            }
        }

        // Connect button — show wallet picker grid
        connectBtn.addEventListener('click', async function () {
            cmDebug('Wallet connect button clicked');

            var installedWallets = CardanoMintWallet.getInstalledWallets();
            cmDebug('Installed wallets:', installedWallets);

            // Remove any stale picker
            var stale = document.getElementById('cm-wallet-picker');
            if (stale) stale.remove();

            if (installedWallets.length === 0) {
                var empty = document.createElement('div');
                empty.id = 'cm-wallet-picker';
                empty.className = 'cm-wallet-picker';
                var msg = document.createElement('div');
                msg.className = 'cm-wallet-picker-empty';
                msg.textContent = 'No Cardano wallets detected. Install Eternl, Lace, Nami, Flint, Typhon, Begin, Vespr, or another CIP-30 wallet extension, then reload the page.';
                empty.appendChild(msg);
                connectBtn.parentNode.insertBefore(empty, connectBtn.nextSibling);
                return;
            }

            // If exactly one wallet is installed, skip the picker — nothing to choose from.
            if (installedWallets.length === 1) {
                connectBtn.textContent = 'Connecting…';
                connectBtn.disabled = true;
                try {
                    await CardanoMintWallet.connect(installedWallets[0].key);
                } catch (error) {
                    console.error('Wallet connection failed:', error);
                    alert('Wallet connection failed: ' + error.message);
                    connectBtn.textContent = 'Connect Wallet';
                    connectBtn.disabled = false;
                }
                return;
            }

            // Multiple wallets — render the grid picker
            renderWalletPicker(installedWallets);
        });


        if (proceedBtn) {
            proceedBtn.addEventListener('click', function() {
                nextMintStep(2);
                const confirmWalletAddress = document.getElementById('confirm-wallet-address');
                if (confirmWalletAddress && walletInput) {
                    confirmWalletAddress.textContent = walletInput.value;
                }
            });
        }
    
        // Real mint confirmation with payment processing
        const confirmMintBtn = document.getElementById('confirm-mint-btn');
        if (confirmMintBtn) {
            confirmMintBtn.addEventListener('click', async function() {
                if (isMintProcessing) return;
                
                const confirmBtn = document.getElementById('confirm-mint-btn');
                confirmBtn.textContent = 'Processing Mint...';
                confirmBtn.disabled = true;
                isMintProcessing = true;
                
                try {
                    if (!mintWallet || !mintWallet.changeAddress) {
                        throw new Error('Wallet not confirmed');
                    }

                    // Network re-check: even if the wallet was on the right
                    // network at connect time, the user can switch wallet
                    // networks between Step 1 and Step 2 without telling us
                    // (Tommy's bug). Refuse to build if the bech32 address no
                    // longer matches site network so we never produce a tx
                    // that's certain to fail at signing time and orphan an
                    // alt-pay invoice.
                    var expectedSiteNetwork = (window.cardanoMint && window.cardanoMint.network) || 'preprod';
                    var addrIsTestnet = /^addr_test1/.test(mintWallet.changeAddress);
                    var siteIsMainnet = String(expectedSiteNetwork).toLowerCase() === 'mainnet';
                    if (siteIsMainnet === addrIsTestnet) {
                        throw new Error(
                            'Your wallet switched networks. The connected address is ' +
                            (addrIsTestnet ? 'Testnet' : 'Mainnet') +
                            ' but this site needs ' +
                            (siteIsMainnet ? 'Mainnet' : 'Pre-production') +
                            '. Switch your wallet network and reconnect.'
                        );
                    }

                    // CRITICAL FIX: Get merchant address from the MINT NOW button's data attribute
                    const mintButton = document.getElementById('cardano-mint-now-btn');
                    const merchantAddress = mintButton?.dataset.merchantAddress || '';
                    
                    // Get other mint data from hidden fields/data attributes
                    const policyId = document.getElementById('policy-id')?.value ||
                                   mintButton?.dataset.policyId || '';

                    // DEBUG: Check all price sources
                    const hiddenPriceField = document.getElementById('nft-price');
                    const hiddenPriceValue = hiddenPriceField?.value;
                    const buttonPriceValue = mintButton?.dataset.nftPrice;
                    cmDebug('DEBUG Price Sources:');
                    cmDebug('  - Hidden field value:', hiddenPriceValue);
                    cmDebug('  - Button data-nft-price:', buttonPriceValue);

                    const mintPrice = parseFloat(hiddenPriceValue || buttonPriceValue || '0');

                    cmDebug('=== MINT TRANSACTION DATA ===');
                    cmDebug('Merchant Address:', merchantAddress);
                    cmDebug('Customer Address:', mintWallet.changeAddress);
                    cmDebug('Price (USD):', mintPrice);
                    cmDebug('Policy ID:', policyId);
                    cmDebug('============================');
                    
                    // Validate merchant address exists
                    if (!merchantAddress || merchantAddress.trim() === '') {
                        throw new Error('Merchant address not configured. Please contact the site administrator.');
                    }
                    
                    // Step 1: Build mint transaction
                    cmDebug('Building mint transaction...');
                    cmDebug('Calling buildMintTransaction with price:', mintPrice);
                    const qtyInputEl = document.getElementById('qty-input');
                    const mintQty    = qtyInputEl ? parseInt(qtyInputEl.value, 10) || 1 : 1;
                    const buildData = await buildMintTransaction(
                        merchantAddress,
                        mintWallet.changeAddress,
                        mintPrice,
                        policyId,
                        mintQty
                    );
                    
                    if (!buildData.complete) {
                        throw new Error('Failed to build mint transaction');
                    }
                    
                    // Step 2: Sign transaction
                    cmDebug('Please sign the transaction in your wallet...');
                    cmDebug('[CardanoMint] buildData FULL:', JSON.parse(JSON.stringify(buildData || {})));
                    if (buildData && buildData.complete) {
                        var txHex = buildData.complete;
                        cmDebug('[CardanoMint] tx CBOR length:', txHex.length);
                        cmDebug('[CardanoMint] tx CBOR (paste into a decoder):\n', txHex);
                    } else {
                        console.warn('[CardanoMint] no `complete` field on buildData', buildData);
                    }
                    let signature;
                    try {
                        signature = await mintWallet.signTx(buildData.complete);
                    } catch (signError) {
                        // Check if this is a user decline error after successful submission
                        if (signError.name === 'TxSignError' && signError.code === 2) {
                            console.warn('Transaction signing declined by user, but transaction may have already been submitted');
                            signature = null;
                        } else {
                            throw signError;
                        }
                    }
                    
                    // Step 3: Submit transaction (only if we have a signature)
                    if (signature) {
                        cmDebug('Submitting mint transaction...');

                        // Build signatures array: Anvil's policy witness + user's wallet signature
                        const signatures = [];

                        // Add Anvil's policy script witness if available (REQUIRED for minting!)
                        if (buildData.witnessSet) {
                            signatures.push(buildData.witnessSet);
                            cmDebug('Added policy script witness from Anvil');
                        }

                        // Add user's wallet signature
                        signatures.push(signature);
                        cmDebug('Added user wallet signature');
                        cmDebug('Total signatures:', signatures.length);

                        const submitResult = await submitMintTransaction(
                            buildData.complete,
                            signatures,
                            policyId,
                            mintWallet.changeAddress,
                            mintQty
                        );
                        
                        if (!submitResult.txHash) {
                            throw new Error('Mint transaction submission failed');
                        }

                        // Success!
                        const txHashElement = document.getElementById('mint-tx-hash');
                        if (txHashElement) {
                            txHashElement.textContent = submitResult.txHash;
                        }

                        // Tell altpay-checkout.js to wipe its receipt overlay
                        // and clear the localStorage invoice for the chain it
                        // used. Without this, opening the modal again to mint
                        // a second time would still display the previous SOL/
                        // ETH/BTC "covered" totals while the customer actually
                        // has no off-chain payment in flight.
                        try {
                            const altpayChainEl   = document.getElementById('altpay-chain');
                            const altpayInvoiceEl = document.getElementById('altpay-invoice-id');
                            document.dispatchEvent(new CustomEvent('kg:mint-completed', {
                                detail: {
                                    txHash:    submitResult.txHash,
                                    chain:     altpayChainEl   ? altpayChainEl.value   : 'ada',
                                    invoiceId: altpayInvoiceEl ? altpayInvoiceEl.value : ''
                                }
                            }));
                        } catch (e) { /* CustomEvent should always be available; ignore in case of older browsers */ }

                        nextMintStep(3);
                    } else {
                        // If no signature but no error, assume transaction was already processed
                        cmDebug('Transaction may have been processed by wallet directly');
                        const txHashElement = document.getElementById('mint-tx-hash');
                        if (txHashElement) {
                            txHashElement.textContent = 'Processed by wallet';
                        }
                        nextMintStep(3);
                    }
                    
                } catch (error) {
                    console.error('Mint failed (full error):', error);

                    // CIP-30 wallet error codes per the standard. -2 / -3 / -4 come
                    // from the wallet itself, not from our server. Translate them
                    // to something a customer can act on.
                    var walletCode = error && (typeof error.code === 'number' ? error.code : null);
                    var walletInfo = error && (error.info || (error.data && error.data.info));
                    var hint = '';
                    if (walletCode === -1) hint = 'Wallet API error — try reconnecting your wallet.';
                    else if (walletCode === -2) hint = 'Your wallet rejected the transaction. The most common cause is not enough ADA in this wallet to cover the service fee + receipt + network fee. Make sure this wallet has at least 8 ADA available, then try again.';
                    else if (walletCode === -3) hint = 'Refused by the wallet. Check that the wallet is unlocked and on the correct network.';
                    else if (walletCode === -4) hint = 'Account change in the wallet — pick the correct account and reconnect.';
                    else if (walletCode === 1) hint = 'Wallet returned an invalid request shape. Reload and try again.';
                    else if (walletCode === 2) hint = 'You declined the signature in your wallet. No transaction was sent.';

                    var msg;
                    if (hint) {
                        msg = hint;
                    } else if (error && typeof error === 'object') {
                        msg = error.message || (walletCode !== null ? 'wallet error code ' + walletCode : null) || (error.toString && error.toString());
                        if (walletInfo) msg = (msg ? msg + ' — ' : '') + (typeof walletInfo === 'string' ? walletInfo : JSON.stringify(walletInfo));
                        if (error.data && error.data.message) msg = error.data.message;
                    } else if (typeof error === 'string') {
                        msg = error;
                    }
                    if (!msg || msg === '[object Object]') {
                        msg = 'unknown error — open the browser console for details';
                    }
                    alert('Mint failed: ' + msg);
                } finally {
                    if (confirmBtn) {
                        confirmBtn.textContent = 'CONFIRM MINT';
                        confirmBtn.disabled = false;
                    }
                    isMintProcessing = false;
                }
            });
        }
    }

    // Build mint transaction
    async function buildMintTransaction(merchantAddress, customerAddress, usdPrice, policyId, quantity) {
        if (!window.cardanoMint) {
            throw new Error('Cardano Mint configuration not found');
        }
        
        // CRITICAL FIX: Validate ALL inputs before making API call
        if (!merchantAddress || merchantAddress.trim() === '') {
            console.error('Merchant address is missing or empty');
            throw new Error('Merchant address not configured. Please contact the site administrator.');
        }
        
        if (!customerAddress || customerAddress.trim() === '') {
            console.error('Customer address is missing or empty');
            throw new Error('Wallet address is required');
        }
        
        if (!usdPrice || usdPrice <= 0) {
            console.error('Invalid USD price:', usdPrice);
            throw new Error('Invalid price');
        }
        
        if (!policyId || policyId.trim() === '') {
            console.error('Policy ID is missing or empty');
            throw new Error('Policy ID is required');
        }
        
        cmDebug('✅ All validations passed! Building transaction...');
        cmDebug('Merchant:', merchantAddress);
        cmDebug('Customer:', customerAddress);
        cmDebug('Price:', usdPrice);
        cmDebug('Policy:', policyId);

        // Get asset ID from the button data attribute
        const mintButton = document.getElementById('cardano-mint-now-btn');
        const assetId = mintButton ? mintButton.getAttribute('data-mint-id') : '';
        cmDebug('Asset ID:', assetId);

        const formData = new FormData();
        formData.append('action', 'cardano_build_mint_transaction');
        formData.append('nonce', cardanoMint.nonce);
        formData.append('merchant_address', merchantAddress);
        formData.append('customer_address', customerAddress);
        formData.append('usd_price', usdPrice);
        formData.append('policy_id', policyId);
        formData.append('asset_id', assetId);
        formData.append('quantity', String(Math.max(1, Math.min(5, parseInt(quantity, 10) || 1))));

        // Alt-pay: when the customer paid on a non-Cardano chain, the funded
        // invoice_id sits in a hidden input dropped by altpay-checkout.js. The
        // server uses it to override the merchant lovelace output.
        const altpayInvoiceField = document.getElementById('altpay-invoice-id');
        if (altpayInvoiceField && altpayInvoiceField.value) {
            formData.append('invoice_id', altpayInvoiceField.value);
        }

        // Discount code (ADA path). Server re-validates + reserves and returns
        // the redemption id, which we stash for the submit step to commit.
        if (appliedDiscount && appliedDiscount.code && !(altpayInvoiceField && altpayInvoiceField.value)) {
            formData.append('discount_code', appliedDiscount.code);
        }

        // DEBUG: Log FormData contents
        cmDebug('=== FORM DATA BEING SENT ===');
        for (let pair of formData.entries()) {
            cmDebug(pair[0] + ': ' + pair[1]);
        }
        cmDebug('============================');

        const response = await fetch(cardanoMint.ajaxurl, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();

        // DEBUG: Log response from PHP
        cmDebug('=== PHP RESPONSE ===');
        cmDebug('Success:', result.success);
        if (result.data && result.data.debug_price_info) {
            cmDebug('PHP received usd_price:', result.data.debug_price_info.usd_price_received);
            cmDebug('Raw POST price:', result.data.debug_price_info.raw_post_price);
        }
        cmDebug('====================');

        if (!result.success) {
            throw new Error(result.data?.message || 'Failed to build mint transaction');
        }

        // Stash the discount reservation id for the submit step to commit.
        if (result.data && result.data.discount_redemption_id) {
            const ridEl = document.getElementById('discount-redemption-id');
            if (ridEl) ridEl.value = result.data.discount_redemption_id;
        }

        return result.data;
    }

    // Submit mint transaction
    async function submitMintTransaction(transaction, signatures, policyId, walletAddress, quantity) {
        if (!window.cardanoMint) {
            throw new Error('Cardano Mint configuration not found');
        }
        
        // Get asset ID from the button data attribute
        const mintButton = document.getElementById('cardano-mint-now-btn');
        const assetId = mintButton ? mintButton.getAttribute('data-mint-id') : '';

        const formData = new FormData();
        formData.append('action', 'cardano_submit_mint_transaction');
        formData.append('nonce', cardanoMint.nonce);
        formData.append('transaction', transaction);
        formData.append('signatures', JSON.stringify(signatures));
        formData.append('policy_id', policyId);
        formData.append('wallet_address', walletAddress);
        formData.append('asset_id', assetId);
        formData.append('quantity', String(Math.max(1, Math.min(5, parseInt(quantity, 10) || 1))));

        const altpayInvoiceFieldSubmit = document.getElementById('altpay-invoice-id');
        if (altpayInvoiceFieldSubmit && altpayInvoiceFieldSubmit.value) {
            formData.append('invoice_id', altpayInvoiceFieldSubmit.value);
        }

        // Commit the discount reservation (if any) now that the tx is signed.
        const ridElSubmit = document.getElementById('discount-redemption-id');
        if (ridElSubmit && ridElSubmit.value) {
            formData.append('discount_redemption_id', ridElSubmit.value);
        }

        const response = await fetch(cardanoMint.ajaxurl, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.data?.message || 'Failed to submit mint transaction');
        }
        
        return result.data;
    }

    // Make functions globally available
    window.nextMintStep = nextMintStep;
    window.prevMintStep = prevMintStep;
    window.closeMintModal = closeMintModal;

})(); // End of IIFE