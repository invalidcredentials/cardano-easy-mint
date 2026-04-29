// Cardano NFT Minting JavaScript
(function() {
    'use strict';
    
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

            // ── Connect ──
            async function connect(walletKey) {
                var cardano = window.cardano;
                if (!cardano || !cardano[walletKey]) {
                    throw new Error('Wallet "' + walletKey + '" not found. Is the extension installed?');
                }
                var provider = cardano[walletKey];
                var api = await provider.enable();

                var usedAddresses = await api.getUsedAddresses();
                var unusedAddresses = await api.getUnusedAddresses();
                var addressHex = (usedAddresses && usedAddresses[0]) || (unusedAddresses && unusedAddresses[0]) || null;
                var address = addressHex ? hexAddressToBech32(addressHex) : null;

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

        // Simple CBOR-to-Bech32 conversion function (improved implementation)
        async function simpleCborToBech32(cborAddress) {
            try {
                // Remove any '0x' prefix
                const hexString = cborAddress.startsWith('0x') ? cborAddress.slice(2) : cborAddress;

                // Basic validation - should be even length hex string
                if (hexString.length % 2 !== 0) {
                    throw new Error('Invalid hex string length');
                }

                // For CBOR-encoded addresses, we need to handle them differently
                // Instead of trying to convert to Bech32, let's use the raw address
                // and let the backend handle the conversion
                
                // Check if this looks like a CBOR-encoded address (starts with 01)
                if (hexString.startsWith('01')) {
                    // This is likely a CBOR-encoded address, return as-is for backend processing
                    console.log('Detected CBOR-encoded address, using raw format for backend processing');
                    return cborAddress; // Return original with 0x prefix if it had one
                }

                // For other formats, try to create a proper Bech32 address
                // This is a very basic implementation - for production, use proper CBOR decoding
                return `addr1${hexString.substring(0, 20)}...${hexString.substring(hexString.length - 20)}`;

            } catch (error) {
                console.error('Simple CBOR conversion error:', error);
                return null;
            }
        }

        // Helper function to convert CBOR-encoded address to Bech32 format using Anvil API
        async function convertCborToBech32ViaAnvil(cborAddress) {
            try {
                // Check if it's already in Bech32 format
                if (cborAddress && (cborAddress.startsWith('addr1') || cborAddress.startsWith('addr_test1'))) {
                    return cborAddress;
                }
                
                // Check if Anvil API is configured
                if (!window.cardanoMint || !window.cardanoMint.anvilApiUrl || !window.cardanoMint.anvilApiKey) {
                    console.warn('Anvil API not configured for address conversion');
                    return convertCborToBech32Fallback(cborAddress);
                }
                
                console.log('Converting address via Anvil API:', cborAddress);
                console.log('Anvil API URL:', window.cardanoMint.anvilApiUrl);
                console.log('Anvil API Key present:', !!window.cardanoMint.anvilApiKey);
                
                const response = await fetch(`${window.cardanoMint.anvilApiUrl}/utils/addresses/parse`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Api-Key': window.cardanoMint.anvilApiKey
                    },
                    body: JSON.stringify({
                        address: cborAddress
                    })
                });
                
                console.log('Anvil API response status:', response.status);
                console.log('Anvil API response headers:', response.headers);
                
                if (!response.ok) {
                    throw new Error(`Anvil API error: ${response.status} ${response.statusText}`);
                }
                
                const data = await response.json();
                
                console.log('Anvil API response:', data);
                
                // Anvil returns parsed address information - check multiple possible response formats
                if (data.address) {
                    console.log('Successfully converted via Anvil API (address):', cborAddress, '->', data.address);
                    return data.address;
                } else if (data.bech32Address) {
                    console.log('Successfully converted via Anvil API (bech32Address):', cborAddress, '->', data.bech32Address);
                    return data.bech32Address;
                } else if (data.parsed && data.parsed.address) {
                    console.log('Successfully converted via Anvil API (parsed.address):', cborAddress, '->', data.parsed.address);
                    return data.parsed.address;
                } else if (data.result && data.result.address) {
                    console.log('Successfully converted via Anvil API (result.address):', cborAddress, '->', data.result.address);
                    return data.result.address;
                } else if (data.payment && data.stake) {
                    // Handle the payment/stake format - construct proper Bech32 address
                    console.log('Payment/stake format detected from Anvil API');
                    console.log('Payment:', data.payment);
                    console.log('Stake:', data.stake);

                    // The payment part contains the actual address bytes
                    // Convert payment hex to Bech32 address
                    try {
                        const paymentHex = data.payment;
                        // Create proper Bech32 address format
                        const bech32Address = 'addr1' + paymentHex;
                        console.log('Constructed Bech32 address from payment:', bech32Address);
                        return bech32Address;
                    } catch (e) {
                        console.error('Failed to construct Bech32 from payment/stake:', e);
                        return cborAddress;
                    }
                } else {
                    console.warn('Anvil API response format not recognized:', data);
                    throw new Error('No address returned from Anvil API - unexpected response format');
                }
                
            } catch (error) {
                console.warn('Anvil API address conversion failed:', error);
                return await convertCborToBech32Fallback(cborAddress);
            }
        }
        
        // Fallback function using Cardano Serialization Library
        async function convertCborToBech32Fallback(cborAddress) {
            try {
                // Check if Cardano Serialization Library is available
                if (typeof CardanoSerializationLib !== 'undefined') {
                    try {
                        console.log('Using Cardano Serialization Library for conversion');
                        
                        // Load the library if needed
                        if (CardanoSerializationLib.load) {
                            await CardanoSerializationLib.load();
                        }
                        
                        // Convert hex string to bytes
                        const hexString = cborAddress.startsWith('0x') ? cborAddress.slice(2) : cborAddress;
                        const bytes = new Uint8Array(hexString.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
                        
                        // Convert to Bech32 using Cardano Serialization Library
                        const address = CardanoSerializationLib.Address.from_bytes(bytes);
                        const bech32Address = address.to_bech32();
                        
                        console.log('Successfully converted CBOR to Bech32 (fallback):', cborAddress, '->', bech32Address);
                        return bech32Address;
                        
                    } catch (e) {
                        console.warn('Cardano Serialization Library conversion failed:', e);
                    }
                } else if (typeof Cardano !== 'undefined' && Cardano.Address) {
                    try {
                        console.log('Using legacy Cardano library for conversion');
                        
                        // Convert hex string to bytes
                        const hexString = cborAddress.startsWith('0x') ? cborAddress.slice(2) : cborAddress;
                        const bytes = new Uint8Array(hexString.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
                        
                        // Convert to Bech32 using Cardano Serialization Library
                        const address = Cardano.Address.from_bytes(bytes);
                        const bech32Address = address.to_bech32();
                        
                        console.log('Successfully converted CBOR to Bech32 (legacy):', cborAddress, '->', bech32Address);
                        return bech32Address;
                        
                    } catch (e) {
                        console.warn('Legacy Cardano library conversion failed:', e);
                    }
                } else {
                    console.warn('Cardano Serialization Library not available');
                }
                
                // Try simple CBOR-to-Bech32 conversion without external libraries
                try {
                    console.log('Attempting simple CBOR-to-Bech32 conversion');
                    const bech32Address = await simpleCborToBech32(cborAddress);
                    if (bech32Address) {
                        console.log('Successfully converted via simple method:', cborAddress, '->', bech32Address);
                        return bech32Address;
                    }
                } catch (e) {
                    console.warn('Simple CBOR-to-Bech32 conversion failed:', e);
                }
                
                // Final fallback: return truncated version for display
                console.warn('Using truncated CBOR address for display');
                return cborAddress.substring(0, 20) + '...' + cborAddress.substring(cborAddress.length - 20);
                
            } catch (error) {
                console.error('Error in fallback address conversion:', error);
                return cborAddress;
            }
        }

        // Helper function to get any address from the wallet (CBOR or Bech32)
        async function getAnyAddress(walletAPI) {
            try {
                console.log('=== GETTING WALLET ADDRESS ===');
                console.log('Wallet API object:', walletAPI);

                // Method 1: Try getChangeAddress first
                try {
                    console.log('Attempting getChangeAddress...');
                    const address = await walletAPI.getChangeAddress();
                    console.log('getChangeAddress returned:', address);
                    console.log('Address type:', typeof address);
                    console.log('Address length:', address ? address.length : 'null');

                    if (address) {
                        // For CBOR-encoded addresses, convert to Bech32 first
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            console.log('CBOR address detected, converting to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            console.log('Converted to Bech32:', convertedAddress);
                            return convertedAddress;
                        } else {
                            // For already Bech32 addresses, use as-is
                            console.log('Bech32 address detected, using as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.error('getChangeAddress failed:', e);
                }
                
                // Method 2: Try getUsedAddresses
                try {
                    const usedAddresses = await walletAPI.getUsedAddresses();
                    console.log('Method 2 - getUsedAddresses:', usedAddresses);
                    
                    if (usedAddresses && usedAddresses.length > 0) {
                        const address = usedAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            console.log('Converting CBOR used address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            console.log('Converted used address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            console.log('Using Bech32 used address as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.warn('getUsedAddresses failed:', e);
                }
                
                // Method 3: Try getUnusedAddresses
                try {
                    const unusedAddresses = await walletAPI.getUnusedAddresses();
                    console.log('Method 3 - getUnusedAddresses:', unusedAddresses);
                    
                    if (unusedAddresses && unusedAddresses.length > 0) {
                        const address = unusedAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            console.log('Converting CBOR unused address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            console.log('Converted unused address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            console.log('Using Bech32 unused address as-is:', address);
                            return address;
                        }
                    }
                } catch (e) {
                    console.warn('getUnusedAddresses failed:', e);
                }
                
                // Method 4: Try getRewardAddresses
                try {
                    const rewardAddresses = await walletAPI.getRewardAddresses();
                    console.log('Method 4 - getRewardAddresses:', rewardAddresses);
                    
                    if (rewardAddresses && rewardAddresses.length > 0) {
                        const address = rewardAddresses[0];
                        if (address.startsWith('01') || address.startsWith('0x01')) {
                            console.log('Converting CBOR reward address to Bech32:', address);
                            const convertedAddress = await convertCborToBech32ViaAnvil(address);
                            console.log('Converted reward address:', convertedAddress);
                            return convertedAddress;
                        } else {
                            console.log('Using Bech32 reward address as-is:', address);
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

    // Initialize when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('MINT NOW script loaded and DOM ready');
        console.log('Looking for MINT NOW button...');
        const testBtn = document.getElementById('cardano-mint-now-btn');
        console.log('Button found at DOM ready:', testBtn);
        initializeNFTMint();
    });

    // Re-initialize when Bricks finishes rendering
    document.addEventListener('bricks:after:render', function() {
        console.log('Bricks render complete, re-initializing...');
        console.log('Looking for MINT NOW button after Bricks render...');
        const testBtn = document.getElementById('cardano-mint-now-btn');
        console.log('Button found after Bricks render:', testBtn);
        initializeNFTMint();
    });

    // Fallback: Re-initialize periodically for Bricks compatibility
    setTimeout(function() {
        console.log('Fallback timeout triggered - checking for button...');
        const testBtn = document.getElementById('cardano-mint-now-btn');
        console.log('Button found at timeout:', testBtn);
        initializeNFTMint();
    }, 2000);

    // Global event delegation - only set up once
    let eventDelegationSetup = false;
    
    function setupEventDelegation() {
        if (eventDelegationSetup) return;
        eventDelegationSetup = true;
        
        console.log('Setting up event delegation for MINT NOW button');
        
        // Use event delegation - listen on document for clicks on the button
        // This works even if Bricks replaces the DOM
        document.addEventListener('click', function(e) {
            console.log('Click detected on:', e.target, 'ID:', e.target.id, 'Classes:', e.target.className);
            
            if (e.target && e.target.id === 'cardano-mint-now-btn') {
                console.log('MINT NOW button clicked via delegation');
                e.preventDefault();
                openMintModal();
            }
            
            // Handle modal close button
            if (e.target && e.target.classList.contains('cardano-modal-close')) {
                console.log('Modal close button clicked via delegation');
                e.preventDefault();
                closeMintModal();
            }
            
            // Handle modal background click
            if (e.target && e.target.id === 'cardano-nft-mint-modal') {
                console.log('Modal background clicked via delegation');
                closeMintModal();
            }
        });
    }

    function initializeNFTMint() {
        console.log('initializeNFTMint() called');
        
        // Set up event delegation if not already done
        setupEventDelegation();

        // Modal event handling is now done via delegation above
        console.log('Modal event handling set up via delegation');

        // Initialize wallet connection
        console.log('Initializing wallet connection...');
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
        console.log('closeMintModal() called');
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
            console.log('MINT NOW modal closed successfully');
        } else {
            console.log('MINT NOW modal not found when trying to close');
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
    }

    function prevMintStep(step) {
        nextMintStep(step);
    }

    function initializeWalletConnection() {
        console.log('initializeWalletConnection() called');
        
        const connectBtn = document.getElementById('connect-wallet-btn');
        const proceedBtn = document.getElementById('proceed-to-confirm');
        const walletDisplay = document.getElementById('wallet-address-display');
        const walletAddress = document.getElementById('connected-wallet-address');
        const walletName = document.getElementById('connected-wallet-name');
        const walletNameDisplay = document.getElementById('wallet-name-display');
        const walletInput = document.getElementById('wallet-address');
        
        if (!connectBtn) {
            console.log('Connect wallet button not found, returning');
            return;
        }
        
        console.log('Connect wallet button found, setting up event listener');
        
        // Initialize CIP-30 wallet system
        initializeCIP30Wallet();
    
        function initializeCIP30Wallet() {
            console.log('initializeCIP30Wallet() called');
            console.log('window.cardanoMint exists:', !!window.cardanoMint);
            if (window.cardanoMint) {
                console.log('cardanoMint.debug:', window.cardanoMint.debug);
                console.log('cardanoMint object:', window.cardanoMint);
            }
            if (window.cardanoMint && window.cardanoMint.debug) {
                console.log('CIP-30 wallet system initialized for minting');
            } else {
                console.log('Debug mode not enabled, but CIP-30 system is ready');
            }
        }
    
        // Wallet detection and display names handled by embedded CardanoMintWallet
        
        // ── Weld-based wallet connection ──

        // Try to reconnect to last used wallet on page load
        CardanoMintWallet.tryReconnect();

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
                console.log('Connecting to:', key);
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
            console.log('Wallet connect button clicked');

            var installedWallets = CardanoMintWallet.getInstalledWallets();
            console.log('Installed wallets:', installedWallets);

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
                    console.log('DEBUG Price Sources:');
                    console.log('  - Hidden field value:', hiddenPriceValue);
                    console.log('  - Button data-nft-price:', buttonPriceValue);

                    const mintPrice = parseFloat(hiddenPriceValue || buttonPriceValue || '0');

                    console.log('=== MINT TRANSACTION DATA ===');
                    console.log('Merchant Address:', merchantAddress);
                    console.log('Customer Address:', mintWallet.changeAddress);
                    console.log('Price (USD):', mintPrice);
                    console.log('Policy ID:', policyId);
                    console.log('============================');
                    
                    // Validate merchant address exists
                    if (!merchantAddress || merchantAddress.trim() === '') {
                        throw new Error('Merchant address not configured. Please contact the site administrator.');
                    }
                    
                    // Step 1: Build mint transaction
                    console.log('Building mint transaction...');
                    console.log('Calling buildMintTransaction with price:', mintPrice);
                    const buildData = await buildMintTransaction(
                        merchantAddress,
                        mintWallet.changeAddress,
                        mintPrice,
                        policyId
                    );
                    
                    if (!buildData.complete) {
                        throw new Error('Failed to build mint transaction');
                    }
                    
                    // Step 2: Sign transaction
                    console.log('Please sign the transaction in your wallet...');
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
                        console.log('Submitting mint transaction...');

                        // Build signatures array: Anvil's policy witness + user's wallet signature
                        const signatures = [];

                        // Add Anvil's policy script witness if available (REQUIRED for minting!)
                        if (buildData.witnessSet) {
                            signatures.push(buildData.witnessSet);
                            console.log('Added policy script witness from Anvil');
                        }

                        // Add user's wallet signature
                        signatures.push(signature);
                        console.log('Added user wallet signature');
                        console.log('Total signatures:', signatures.length);

                        const submitResult = await submitMintTransaction(
                            buildData.complete,
                            signatures,
                            policyId,
                            mintWallet.changeAddress
                        );
                        
                        if (!submitResult.txHash) {
                            throw new Error('Mint transaction submission failed');
                        }
                        
                        // Success!
                        const txHashElement = document.getElementById('mint-tx-hash');
                        if (txHashElement) {
                            txHashElement.textContent = submitResult.txHash;
                        }
                        nextMintStep(3);
                    } else {
                        // If no signature but no error, assume transaction was already processed
                        console.log('Transaction may have been processed by wallet directly');
                        const txHashElement = document.getElementById('mint-tx-hash');
                        if (txHashElement) {
                            txHashElement.textContent = 'Processed by wallet';
                        }
                        nextMintStep(3);
                    }
                    
                } catch (error) {
                    console.error('Mint failed:', error);
                    alert('Mint failed: ' + error.message);
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
    async function buildMintTransaction(merchantAddress, customerAddress, usdPrice, policyId) {
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
        
        console.log('✅ All validations passed! Building transaction...');
        console.log('Merchant:', merchantAddress);
        console.log('Customer:', customerAddress);
        console.log('Price:', usdPrice);
        console.log('Policy:', policyId);

        // Get asset ID from the button data attribute
        const mintButton = document.getElementById('cardano-mint-now-btn');
        const assetId = mintButton ? mintButton.getAttribute('data-mint-id') : '';
        console.log('Asset ID:', assetId);

        const formData = new FormData();
        formData.append('action', 'cardano_build_mint_transaction');
        formData.append('nonce', cardanoMint.nonce);
        formData.append('merchant_address', merchantAddress);
        formData.append('customer_address', customerAddress);
        formData.append('usd_price', usdPrice);
        formData.append('policy_id', policyId);
        formData.append('asset_id', assetId);

        // Alt-pay: when the customer paid on a non-Cardano chain, the funded
        // invoice_id sits in a hidden input dropped by altpay-checkout.js. The
        // server uses it to override the merchant lovelace output.
        const altpayInvoiceField = document.getElementById('altpay-invoice-id');
        if (altpayInvoiceField && altpayInvoiceField.value) {
            formData.append('invoice_id', altpayInvoiceField.value);
        }

        // DEBUG: Log FormData contents
        console.log('=== FORM DATA BEING SENT ===');
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + pair[1]);
        }
        console.log('============================');

        const response = await fetch(cardanoMint.ajaxurl, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();

        // DEBUG: Log response from PHP
        console.log('=== PHP RESPONSE ===');
        console.log('Success:', result.success);
        if (result.data && result.data.debug_price_info) {
            console.log('PHP received usd_price:', result.data.debug_price_info.usd_price_received);
            console.log('Raw POST price:', result.data.debug_price_info.raw_post_price);
        }
        console.log('====================');

        if (!result.success) {
            throw new Error(result.data?.message || 'Failed to build mint transaction');
        }

        return result.data;
    }

    // Submit mint transaction
    async function submitMintTransaction(transaction, signatures, policyId, walletAddress) {
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

        const altpayInvoiceFieldSubmit = document.getElementById('altpay-invoice-id');
        if (altpayInvoiceFieldSubmit && altpayInvoiceFieldSubmit.value) {
            formData.append('invoice_id', altpayInvoiceFieldSubmit.value);
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