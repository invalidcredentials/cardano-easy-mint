<?php
namespace CardanoMintPay\Helpers;

class AnvilAPI {
    
    /**
     * Call Anvil API endpoint
     */
    public static function call($endpoint, $data, $plugin_type = 'mint') {
        $api_url = get_option("cardano_{$plugin_type}_anvil_api_url", 'https://preprod.api.ada-anvil.app/v2/services');
        $api_key = get_option("cardano_{$plugin_type}_anvil_api_key");
        
        error_log("Anvil API call details:");
        error_log("Endpoint: " . $endpoint);
        error_log("API URL: " . $api_url);
        error_log("Plugin type: " . $plugin_type);
        error_log("Data being sent: " . wp_json_encode($data, JSON_PRETTY_PRINT));
        
        if (!$api_key) {
            error_log("No API key configured for plugin type: " . $plugin_type);
            return new \WP_Error('no_api_key', 'Anvil API key not configured');
        }
        
        error_log("Making API call to: " . $api_url . '/' . $endpoint);
        $response = wp_remote_post($api_url . '/' . $endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Api-Key' => $api_key
            ),
            'body' => wp_json_encode($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('api_error', $decoded['message'] ?? 'API request failed');
        }
        
        return $decoded;
    }
    
    /**
     * Truncate metadata string to fit Cardano's 64-character limit
     */
    public static function truncateMetadata($string) {
        $max_length = 64;
        if (strlen($string) <= $max_length) {
            return $string;
        }
        
        // Truncate and add ellipsis
        return substr($string, 0, $max_length - 3) . '...';
    }

    /**
     * Convert CBOR-encoded address to Bech32 format using Anvil API
     */
    public static function convertAddressToBech32($address, $plugin_type = 'mint') {
        // Check if it's already in Bech32 format
        if (preg_match('/^addr[0-9a-z]+$/', $address)) {
            return $address;
        }
        
        // Try to convert using Anvil API
        $response = self::call('utils/addresses/parse', array('address' => $address), $plugin_type);
        
        if (is_wp_error($response)) {
            // If conversion fails, return original address
            return $address;
        }
        
        // Extract Bech32 address from response
        error_log("Checking response format for address conversion:");
        error_log("response['address'] exists: " . (isset($response['address']) ? 'YES' : 'NO'));
        error_log("response['bech32Address'] exists: " . (isset($response['bech32Address']) ? 'YES' : 'NO'));
        error_log("response['parsed']['address'] exists: " . (isset($response['parsed']['address']) ? 'YES' : 'NO'));
        error_log("response['payment'] exists: " . (isset($response['payment']) ? 'YES' : 'NO'));
        error_log("response['stake'] exists: " . (isset($response['stake']) ? 'YES' : 'NO'));
        
        if (isset($response['address'])) {
            error_log("Using response['address']: " . $response['address']);
            return $response['address'];
        } elseif (isset($response['bech32Address'])) {
            error_log("Using response['bech32Address']: " . $response['bech32Address']);
            return $response['bech32Address'];
        } elseif (isset($response['parsed']['address'])) {
            error_log("Using response['parsed']['address']: " . $response['parsed']['address']);
            return $response['parsed']['address'];
        } elseif (isset($response['payment']) && isset($response['stake'])) {
            // Handle the payment/stake format by constructing the full address
            // For now, return the original address and let Anvil handle the conversion
            error_log("Payment/stake format detected, returning original address for Anvil to handle: " . $address);
            return $address;
        }
        
        error_log("No Bech32 address found in response, returning original address: " . $address);
        return $address;
    }

    /**
     * Build transaction for NFT minting with CIP-25 metadata
     */
    public static function buildMintTransaction($merchant_address, $customer_address, $usd_price, $policy_id, $plugin_type = 'mint', $mint_data = null, $quantity = 1) {
        $quantity = max(1, min(5, intval($quantity)));
        error_log("buildMintTransaction called with:");
        error_log("merchant_address: " . $merchant_address);
        error_log("customer_address: " . $customer_address);
        error_log("usd_price: " . $usd_price);
        error_log("policy_id: " . $policy_id);
        error_log("plugin_type: " . $plugin_type);

        // CRITICAL: Convert all addresses to Bech32 format for consistency
        error_log("Converting addresses to Bech32 format for consistency...");

        // Convert merchant address to Bech32 if needed
        if (!preg_match('/^addr[0-9a-z]+$/', $merchant_address)) {
            $merchant_address = self::convertAddressToBech32($merchant_address, $plugin_type);
            error_log("Converted merchant address to Bech32: " . $merchant_address);
        }

        // Convert customer address to Bech32 if needed
        if (!preg_match('/^addr[0-9a-z]+$/', $customer_address)) {
            $customer_address = self::convertAddressToBech32($customer_address, $plugin_type);
            error_log("Converted customer address to Bech32: " . $customer_address);
        }

        error_log("Final addresses - Merchant: " . $merchant_address . ", Customer: " . $customer_address);

        // Get current ADA price
        error_log("Getting ADA price...");
        $ada_price = self::getAdaPrice();
        error_log("ADA price: " . $ada_price);
        $ada_amount = $usd_price / $ada_price;
        error_log("ADA amount: " . $ada_amount);

        // Fee calculation for Cardano Mint - includes additional 1 ADA minting fee per asset
        $minting_fee = 1.0; // Additional 1 ADA fee per minted NFT
        $per_asset_ada = $ada_amount + $minting_fee;
        $total_ada_amount = $per_asset_ada * $quantity;
        error_log("Minting fee: " . $minting_fee . " ADA per asset");
        error_log("Quantity: " . $quantity);
        error_log("Total ADA amount (price + minting fee, x qty): " . $total_ada_amount);

        // Alt-paid override: when the customer paid for the NFT on a different
        // chain, this Cardano tx only carries the configured ADA service fee
        // to merchant + receipts to customer + the minted assets. Honored when
        // callers set `_altpay_service_fee_ada_override` on $mint_data. The
        // override is the per-asset service fee; we still multiply by qty so
        // a batch buy pays N service fees (per pb 2026-04-29).
        $altpay_override_used = false;
        if (is_array($mint_data) && !empty($mint_data['_altpay_service_fee_ada_override'])) {
            $altpay_override_used = true;
            $total_ada_amount = ((float) $mint_data['_altpay_service_fee_ada_override']) * $quantity;
            error_log("[AltPay] merchant output overridden to " . $total_ada_amount . " ADA (alt-chain payment, qty " . $quantity . ")");
        }

        // 1 ADA receipt per minted NFT — Cardano min-ADA scales with the
        // number of native tokens in the UTxO; Anvil bumps this if needed.
        $receipt_amount = 1.0 * $quantity;
        error_log("Receipt amount: " . $receipt_amount);

        // Generate unique asset name(s) for this mint. For qty>1 we suffix
        // _001 / _002 / ... so each NFT in the batch has a distinct on-chain
        // asset id. The base prefix is shared per-tx (timestamp + customer/policy
        // hash) so wallets can group them visually as one batch.
        $asset_name_base = 'NFT_' . time() . '_' . substr(md5($customer_address . $policy_id), 0, 8);
        $asset_names = array();
        for ($i = 1; $i <= $quantity; $i++) {
            $asset_names[] = $quantity > 1
                ? $asset_name_base . '_' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)
                : $asset_name_base;
        }
        // Keep $asset_name_raw set to the first name for any downstream code
        // that still references the single-asset shape (metadata defaults etc).
        $asset_name_raw = $asset_names[0];
        error_log("Asset names ({$quantity}): " . implode(', ', $asset_names));

        // Get mint-specific metadata if available
        $nft_name = $asset_name_raw;
        $nft_image = '';
        $nft_media_type = 'image/png';
        $nft_description = 'NFT minted via ' . get_bloginfo('name');
        $nft_metadata_attributes = array();  // Additional attributes from metadata builder
        $policy_script = null;

        if ($mint_data) {
            // Use mint title as default NFT name
            $nft_name = $mint_data['title'] ?? $asset_name_raw;

            // Get image URL - priority: Manual IPFS > Pinata IPFS > WordPress CDN
            if (isset($mint_data['ipfs_cid_manual']) && !empty($mint_data['ipfs_cid_manual'])) {
                // Priority 1: Manually pasted IPFS hash
                $nft_image = 'ipfs://' . $mint_data['ipfs_cid_manual'];
                error_log("Using manually pasted IPFS image: " . $nft_image);
            } elseif (isset($mint_data['ipfs_cid']) && !empty($mint_data['ipfs_cid'])) {
                // Priority 2: Pinata IPFS CID (CIDv0 format: Qm...)
                $nft_image = 'ipfs://' . $mint_data['ipfs_cid'];
                error_log("Using Pinata IPFS image: " . $nft_image);
            } elseif (isset($mint_data['image_id']) && $mint_data['image_id']) {
                // Priority 3: WordPress CDN URL
                $image_url = wp_get_attachment_url($mint_data['image_id']);
                if ($image_url) {
                    $nft_image = $image_url;
                    error_log("Using WordPress CDN image: " . $nft_image);
                }
            }

            // Resolve mediaType. Priority:
            //   1. Operator-selected `media_type` saved on the mint row.
            //      This is the only reliable source when the asset comes
            //      from a pasted IPFS CID (no inherent mime to detect).
            //   2. WP attachment mime via get_post_mime_type() — works for
            //      uploaded files where WP knows the type.
            if (!empty($mint_data['media_type'])) {
                $nft_media_type = (string) $mint_data['media_type'];
            } elseif (isset($mint_data['image_id']) && $mint_data['image_id']) {
                $attachment_mime = get_post_mime_type($mint_data['image_id']);
                if ($attachment_mime) {
                    $nft_media_type = $attachment_mime;
                }
            }

            // Parse NFT metadata from metadata builder
            if (isset($mint_data['nft_metadata']) && !empty($mint_data['nft_metadata'])) {
                $user_metadata = json_decode($mint_data['nft_metadata'], true);
                if (is_array($user_metadata)) {
                    error_log("User metadata from builder: " . wp_json_encode($user_metadata, JSON_PRETTY_PRINT));

                    // Override with user-defined values if present
                    if (isset($user_metadata['name'])) {
                        $nft_name = $user_metadata['name'];
                    }
                    if (isset($user_metadata['description'])) {
                        $nft_description = $user_metadata['description'];
                    }
                    if (isset($user_metadata['image']) && empty($nft_image)) {
                        // Only use metadata image if no WordPress media library image
                        $nft_image = $user_metadata['image'];
                    }
                    if (isset($user_metadata['mediaType']) && !empty($user_metadata['mediaType'])) {
                        // Explicit user override wins over attachment detection
                        $nft_media_type = $user_metadata['mediaType'];
                    }

                    // Collect any additional attributes (skip the reserved CIP-25 keys
                    // and our preview overrides — we handle those explicitly below)
                    $reserved_keys = array('name', 'description', 'image', 'mediaType', 'files', 'previewImage', 'previewMediaType');
                    foreach ($user_metadata as $key => $value) {
                        if (!in_array($key, $reserved_keys)) {
                            $nft_metadata_attributes[$key] = $value;
                        }
                    }
                }
            }

            // Get policy script schema if available
            // The policy_json field contains the full policy generation response
            // We need to extract the 'schema' field for preloadedScripts
            if (isset($mint_data['policy_json'])) {
                $policy_data = json_decode($mint_data['policy_json'], true);
                if (isset($policy_data['schema'])) {
                    $policy_script = $policy_data['schema'];
                    error_log("Extracted policy schema from policy_json");
                } else {
                    error_log("WARNING: policy_json exists but no 'schema' field found!");
                    error_log("policy_json content: " . wp_json_encode($policy_data, JSON_PRETTY_PRINT));
                }
            }
        }

        // Build CIP-25 metadata for the mint object.
        //
        // For an IMAGE asset: top-level image + mediaType points at the
        // asset itself, files[] mirrors it for explorer compatibility.
        //
        // For a VIDEO/AUDIO asset: the asset can't be the top-level
        // image (wallets only render PNG/JPG/GIF for the thumbnail).
        // If the operator supplied a `previewImage` (+ optional
        // `previewMediaType`) in the metadata builder, we use it as
        // the top-level thumbnail and let files[0] carry the actual
        // video. Without a preview supplied we omit the top-level pair
        // entirely — the mint will succeed but wallets won't show a
        // preview thumbnail.
        $is_av_asset = (
            strpos($nft_media_type, 'video/') === 0 ||
            strpos($nft_media_type, 'audio/') === 0
        );

        // Resolve the preview image. Priority:
        //   1. preview_ipfs_cid_manual on the mint row (manual paste)
        //      mime: preview_media_type column if set, else image/png
        //   2. preview_image_id WP attachment URL + auto-detected mime
        //   3. previewImage / previewMediaType inside user nft_metadata JSON
        //      (legacy / advanced override)
        $preview_image      = '';
        $preview_media_type = 'image/png';

        if (!empty($mint_data['preview_ipfs_cid_manual'])) {
            $preview_image      = 'ipfs://' . $mint_data['preview_ipfs_cid_manual'];
            $preview_media_type = !empty($mint_data['preview_media_type'])
                ? (string) $mint_data['preview_media_type']
                : 'image/png';
        } elseif (!empty($mint_data['preview_image_id'])) {
            $url = wp_get_attachment_url($mint_data['preview_image_id']);
            if ($url) {
                $preview_image      = $url;
                $mime               = get_post_mime_type($mint_data['preview_image_id']);
                if ($mime) $preview_media_type = $mime;
            }
        }
        if ($preview_image === '' && isset($user_metadata) && is_array($user_metadata)) {
            if (!empty($user_metadata['previewImage'])) {
                $preview_image      = (string) $user_metadata['previewImage'];
                $preview_media_type = !empty($user_metadata['previewMediaType'])
                    ? (string) $user_metadata['previewMediaType']
                    : 'image/png';
            }
        }

        $cip25_metadata = array(
            'name'        => $nft_name,
            'description' => $nft_description,
        );
        // Top-level image priority (regardless of asset type):
        //   1. Explicit preview wins — gives the operator full control
        //      over the wallet thumbnail even on image assets.
        //   2. Otherwise, image assets fall back to using the asset itself.
        //   3. AV assets without a preview omit the top-level pair so
        //      the metadata isn't ambiguous (no thumbnail rendered).
        if ($preview_image !== '') {
            $cip25_metadata['image']     = $preview_image;
            $cip25_metadata['mediaType'] = $preview_media_type;
        } elseif (!$is_av_asset) {
            $cip25_metadata['image']     = $nft_image;
            $cip25_metadata['mediaType'] = $nft_media_type;
        }

        // Add any additional user-defined metadata attributes
        foreach ($nft_metadata_attributes as $key => $value) {
            $cip25_metadata[$key] = $value;
        }

        // Add files array for explorer compatibility. For video/audio
        // assets this is the only place the asset is referenced.
        if (!empty($nft_image)) {
            $cip25_metadata['files'] = array(
                array(
                    'name'      => $nft_name,
                    'mediaType' => $nft_media_type,
                    'src'       => $nft_image
                )
            );
        }

        error_log("CIP-25 metadata: " . wp_json_encode($cip25_metadata, JSON_PRETTY_PRINT));

        // CRITICAL DEBUG: Log the merchant payment calculation
        error_log("===== MERCHANT PAYMENT CALCULATION =====");
        error_log("usd_price input: " . $usd_price);
        error_log("ada_price (from API): " . $ada_price);
        error_log("ada_amount (NFT price in ADA): " . $ada_amount);
        error_log("Merchant will receive (lovelace): " . intval($total_ada_amount * 1000000));
        error_log("Customer will receive (lovelace): " . intval($receipt_amount * 1000000));
        error_log("Note: Anvil API will add network fees automatically");
        error_log("========================================");

        // Check if we need to mint CIP-27 royalty token (first mint for this policy)
        $royalty_rate = isset($mint_data['royalty']) ? floatval($mint_data['royalty']) : 0;
        $royalty_address = isset($mint_data['royaltyaddress']) ? $mint_data['royaltyaddress'] : '';
        $is_first_mint = !\CardanoMintPay\Models\MintModel::hasRoyaltyTokenBeenMinted($policy_id);

        error_log("===== CIP-27 ROYALTY TOKEN CHECK =====");
        error_log("royalty_rate: " . $royalty_rate . "%");
        error_log("royalty_address: " . $royalty_address);
        error_log("is_first_mint: " . ($is_first_mint ? 'YES' : 'NO'));
        error_log("should_mint_royalty_token: " . (($is_first_mint && $royalty_rate > 0 && !empty($royalty_address)) ? 'YES' : 'NO'));
        error_log("======================================");

        // Build N customer-output asset entries + N CIP-25 mint entries (one
        // per NFT in this batch). Each gets a unique asset name; metadata
        // tagged with #N/qty so wallets show "Shield #001 of 5", etc.
        $customer_assets = array();
        $mint_array = array();
        foreach ($asset_names as $idx => $aname) {
            $customer_assets[] = array(
                'policyId' => $policy_id,
                'assetName' => array(
                    'name' => $aname,
                    'format' => 'utf8'
                ),
                'quantity' => 1
            );

            // Per-asset metadata: clone the base CIP-25 blob and, for batches,
            // append a sequential identifier so each minted NFT renders with
            // its own name in wallets even when the underlying art is shared.
            $asset_meta = $cip25_metadata;
            if ($quantity > 1) {
                $asset_meta['name'] = (isset($cip25_metadata['name']) ? $cip25_metadata['name'] : $nft_name)
                    . ' #' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT);
            }

            $mint_array[] = array(
                'version' => 'cip25',  // CRITICAL: tells Anvil to generate 721 metadata
                'policyId' => $policy_id,
                'quantity' => 1,
                'assetName' => array(
                    'name' => $aname,
                    'format' => 'utf8'
                ),
                'metadata' => $asset_meta
            );
        }

        // Add CIP-27 royalty token if this is the first mint and royalty is configured
        if ($is_first_mint && $royalty_rate > 0 && !empty($royalty_address)) {
            error_log("🏷️ Adding CIP-27 royalty token to mint...");

            // CIP-27 royalty token metadata
            $royalty_metadata = array(
                '777' => array(  // CIP-27 standard label
                    'rate' => strval($royalty_rate / 100),  // Convert 5.5% to "0.055"
                    'addr' => $royalty_address
                ),
                'name' => 'Royalty Token',
                'description' => 'CIP-27 Royalty Token for ' . ($mint_data['title'] ?? 'Collection'),
                'minted_at' => current_time('c'),
                'royalty_standard' => 'CIP-27'
            );

            // Add royalty token to mint array
            $mint_array[] = array(
                'version' => 'cip25',  // Use CIP-25 with custom metadata handling
                'assetName' => array(
                    'name' => '',  // Empty name for royalty token per CIP-27
                    'format' => 'utf8'
                ),
                'metadata' => $royalty_metadata,
                'policyId' => $policy_id,
                'quantity' => 1
            );

            // Add royalty token to customer's assets (will be auto-burned on-chain)
            $customer_assets[] = array(
                'policyId' => $policy_id,
                'assetName' => array(
                    'name' => '',  // Empty name
                    'format' => 'utf8'
                ),
                'quantity' => 1
            );

            error_log("✅ CIP-27 royalty token added successfully!");
            error_log("Royalty metadata: " . wp_json_encode($royalty_metadata, JSON_PRETTY_PRINT));
        }

        // Build transaction request with NFT minting using Anvil API format
        $transaction_request = array(
            'changeAddress' => $customer_address,
            'outputs' => array(
                array(
                    'address' => $merchant_address,
                    'lovelace' => intval($total_ada_amount * 1000000)
                ),
                array(
                    'address' => $customer_address,
                    'lovelace' => intval($receipt_amount * 1000000),
                    'assets' => $customer_assets
                )
            ),
            'mint' => $mint_array
        );

        // Add preloaded scripts if policy script is available (REQUIRED for minting)
        if ($policy_script) {
            $transaction_request['preloadedScripts'] = array(
                array(
                    'type' => 'simple',
                    'script' => $policy_script,  // JSON schema, not CBOR hex
                    'hash' => $policy_id
                )
            );
            error_log("Added preloaded script to transaction request");
        } else {
            error_log("WARNING: No policy script provided - minting will likely fail!");
        }

        error_log("Transaction request built successfully");
        error_log("Full transaction request: " . wp_json_encode($transaction_request, JSON_PRETTY_PRINT));
        error_log("Calling Anvil API to build transaction...");
        $result = self::call('transactions/build', $transaction_request, $plugin_type);
        error_log("Anvil API call result: " . print_r($result, true));
        return $result;
    }
    
    /**
     * Submit transaction to blockchain
     * For mint transactions, adds policy wallet signature before submission
     */
    public static function submitTransaction($transaction, $signatures, $plugin_type = 'mint', $policy_id = '') {
        // For mint transactions, add policy wallet signature
        if ($plugin_type === 'mint') {
            error_log('=== ADDING POLICY WALLET SIGNATURE ===');

            $skey_hex = '';
            $network = get_option('cardano-mint-networkenvironment', 'preprod');

            // Check if this policy has its own imported skey (override)
            if ($policy_id) {
                $imported_policy = \CardanoMintPay\Models\MintModel::getMintPolicyByPolicyId($policy_id);
                if ($imported_policy && !empty($imported_policy['skey_encrypted'])) {
                    error_log('Using imported policy skey for policy: ' . $policy_id);
                    $skey_hex = \CardanoMintPay\Helpers\EncryptionHelper::decrypt($imported_policy['skey_encrypted']);
                }
            }

            // Fall back to internal policy wallet if no imported skey
            if (empty($skey_hex)) {
                $policy_wallet = \CardanoMintPay\Models\MintModel::getPolicyWallet($network);

                if (!$policy_wallet) {
                    error_log('No policy wallet found - cannot sign transaction');
                    return new \WP_Error(
                        'no_policy_wallet',
                        'Policy wallet not configured. Cannot sign minting transaction.'
                    );
                }

                $skey_hex = \CardanoMintPay\Helpers\EncryptionHelper::decrypt($policy_wallet['skey_encrypted']);
            }

            if (empty($skey_hex)) {
                error_log('Failed to decrypt policy wallet signing key');
                return new \WP_Error(
                    'decryption_failed',
                    'Could not decrypt policy wallet signing key.'
                );
            }

            // Sign transaction using cross-platform CLI helper
            error_log('Signing transaction with policy wallet...');

            $result = CardanoCLI::signTransaction($transaction, $skey_hex);

            error_log('Sign CLI result: ' . json_encode($result));

            if (!$result || !isset($result['success']) || !$result['success']) {
                $error_msg = isset($result['error']) ? $result['error'] : 'Unknown signing error';
                error_log('Transaction signing failed: ' . $error_msg);
                return new \WP_Error(
                    'sign_failed',
                    'Transaction signing failed: ' . $error_msg
                );
            }

            // Add policy wallet's witness set to signatures array (NOT the full signed tx)
            if (isset($result['witnessSetHex'])) {
                // Prepend policy wallet witness to the signatures array
                array_unshift($signatures, $result['witnessSetHex']);
                error_log('✅ Policy wallet witness set added to signatures');
                error_log('Policy witness set: ' . $result['witnessSetHex']);
            } else {
                error_log('⚠️ WARNING: No witnessSetHex in signing result');
            }
        }

        // Submit transaction with all signatures
        // IMPORTANT: Transaction should remain UNSIGNED, witnesses are passed separately
        $submit_data = array(
            'transaction' => $transaction,  // Keep unsigned transaction
            'signatures' => is_array($signatures) ? $signatures : array()
        );

        error_log('=== SUBMITTING TO ANVIL ===');
        error_log('Transaction (first 100 chars): ' . substr($transaction, 0, 100) . '...');
        error_log('Number of signatures: ' . count($submit_data['signatures']));
        foreach ($submit_data['signatures'] as $i => $sig) {
            error_log('Signature ' . $i . ' (first 60 chars): ' . substr($sig, 0, 60) . '...');
        }
        error_log('===========================');

        return self::call('transactions/submit', $submit_data, $plugin_type);
    }
    
    /**
     * Get ADA price with caching (shared between both plugins)
     */
    public static function getAdaPrice() {
        $cached_price = get_transient('cardano_anvil_ada_price');
        
        if ($cached_price !== false && $cached_price > 0) {
            return floatval($cached_price);
        }
        
        // Fetch from CoinGecko API
        $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=cardano&vs_currencies=usd', array(
            'timeout' => 10,
            'user-agent' => 'WordPress/Cardano-MintPay-Plugin'
        ));
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['cardano']['usd']) && $data['cardano']['usd'] > 0) {
                $price = floatval($data['cardano']['usd']);
                set_transient('cardano_anvil_ada_price', $price, 300); // 5 minutes
                return $price;
            }
        }
        
        return 1.0; // Fallback
    }

    /**
     * Generate a new Cardano policy via Anvil API with time lock
     * 
     * @param string $expiration_date Optional expiration date (ISO format). Defaults to 1 year from now.
     * @return array|WP_Error Policy data on success, WP_Error on failure
     */
    public static function generatePolicy($expiration_date = null) {
        error_log('=== POLICY GENERATION START ===');
        
        // Get merchant address for policy owner
        $merchant_address = get_option('cardano_mint_merchant_address', '');
        
        if (empty($merchant_address)) {
            return new \WP_Error(
                'no_merchant_address',
                'Merchant address not configured. Please set your wallet address in Plugin Setup.'
            );
        }
        
        error_log('Merchant address: ' . $merchant_address);
        
        // Set default expiration to 1 year from now if not provided
        if (empty($expiration_date)) {
            $expiration_date = date('Y-m-d\TH:i:s\Z', strtotime('+1 year'));
        }
        
        error_log('Expiration date: ' . $expiration_date);
        
        // Step 1: Parse address to get payment key hash
        $address_parse_response = self::call('utils/addresses/parse', array(
            'address' => $merchant_address
        ), 'mint');
        
        if (is_wp_error($address_parse_response)) {
            error_log('Address parsing failed: ' . $address_parse_response->get_error_message());
            return $address_parse_response;
        }
        
        error_log('Address parse response: ' . wp_json_encode($address_parse_response, JSON_PRETTY_PRINT));
        
        // Extract payment credential key hash
        $payment_key_hash = null;
        if (isset($address_parse_response['payment']['keyHash'])) {
            $payment_key_hash = $address_parse_response['payment']['keyHash'];
        } elseif (isset($address_parse_response['payment'])) {
            $payment_key_hash = $address_parse_response['payment'];
        } elseif (isset($address_parse_response['keyHash'])) {
            $payment_key_hash = $address_parse_response['keyHash'];
        }
        
        if (!$payment_key_hash) {
            error_log('Could not extract key hash from address parse response');
            return new \WP_Error(
                'no_key_hash',
                'Could not extract payment key hash from merchant address'
            );
        }
        
        error_log('Extracted payment key hash: ' . $payment_key_hash);
        
        // Step 2: Convert expiration date to slot number
        $expiration_timestamp_ms = strtotime($expiration_date) * 1000; // Convert to milliseconds
        
        $slot_response = self::call('utils/network/time-to-slot', array(
            'time' => $expiration_timestamp_ms
        ), 'mint');
        
        if (is_wp_error($slot_response)) {
            error_log('Time to slot conversion failed: ' . $slot_response->get_error_message());
            return $slot_response;
        }
        
        error_log('Slot response: ' . wp_json_encode($slot_response, JSON_PRETTY_PRINT));
        
        $expiration_slot = $slot_response['slot'] ?? null;
        
        if (!$expiration_slot) {
            error_log('Could not extract slot from time-to-slot response');
            return new \WP_Error(
                'no_slot',
                'Could not convert expiration date to slot number'
            );
        }
        
        error_log('Expiration slot: ' . $expiration_slot);

        // Step 3: Get policy wallet key hash for signature requirement
        $network = get_option('cardano-mint-networkenvironment', 'preprod');
        $policy_wallet = \CardanoMintPay\Models\MintModel::getPolicyWallet($network);

        if (!$policy_wallet) {
            error_log('No policy wallet found for network: ' . $network);
            return new \WP_Error(
                'no_policy_wallet',
                'Policy wallet not configured for ' . $network . ' network. Please generate a policy wallet first.'
            );
        }

        $policy_keyhash = $policy_wallet['payment_keyhash'];
        error_log('Policy wallet keyhash: ' . $policy_keyhash);

        // Step 4: Create policy schema with BOTH signature requirement AND time lock
        // This requires the policy wallet signature AND minting before expiration
        // Prevents unauthorized minting while still allowing time-limited minting
        $policy_schema = array(
            'type' => 'all',
            'scripts' => array(
                array(
                    'type' => 'sig',
                    'keyHash' => $policy_keyhash
                ),
                array(
                    'type' => 'before',
                    'slot' => $expiration_slot
                )
            )
        );

        error_log('Policy schema (with signature + time lock): ' . wp_json_encode($policy_schema, JSON_PRETTY_PRINT));

        // Step 5: Serialize the policy schema to get policy ID
        $serialize_response = self::call('utils/native-scripts/serialize', array(
            'schema' => $policy_schema
        ), 'mint');
        
        if (is_wp_error($serialize_response)) {
            error_log('Policy serialization failed: ' . $serialize_response->get_error_message());
            return $serialize_response;
        }
        
        error_log('Serialize response: ' . wp_json_encode($serialize_response, JSON_PRETTY_PRINT));
        
        // Extract policy ID and script from response
        $policy_id = $serialize_response['policyId'] ?? null;
        $policy_script = $serialize_response['script'] ?? null;
        
        if (!$policy_id || !$policy_script) {
            error_log('Could not extract policy ID or script from serialize response');
            return new \WP_Error(
                'no_policy_data',
                'Could not extract policy ID or script from serialization response'
            );
        }
        
        error_log('=== POLICY GENERATION SUCCESS ===');
        error_log('Policy ID: ' . $policy_id);

        return array(
            'policyId' => $policy_id,
            'script' => $policy_script,
            'schema' => $policy_schema,
            'expiresAt' => $expiration_date,
            'slot' => $expiration_slot,
            'keyHash' => $payment_key_hash,
            'policyKeyHash' => $policy_keyhash  // Policy wallet keyhash used for signature requirement
        );
    }
}