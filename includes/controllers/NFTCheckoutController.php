<?php
namespace CardanoMintPay\Controllers;

if (!defined('ABSPATH')) exit;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Helpers\AnvilAPI;
use CardanoMintPay\Discounts\DiscountService;

class NFTCheckoutController {

    public static function register() {
        add_shortcode('cardano-mint', [self::class, 'renderNFTCheckout']);
        add_action('wp_ajax_cardanonftmint', [self::class, 'ajaxMintNFT']);
        add_action('wp_ajax_nopriv_cardanonftmint', [self::class, 'ajaxMintNFT']);
        add_action('wp_ajax_cardanogetnftprice', [self::class, 'ajaxGetNFTPrice']);
        add_action('wp_ajax_nopriv_cardanogetnftprice', [self::class, 'ajaxGetNFTPrice']);

        // New Anvil API endpoints for real minting
        add_action('wp_ajax_cardano_build_mint_transaction', [self::class, 'ajaxBuildMintTransaction']);
        add_action('wp_ajax_nopriv_cardano_build_mint_transaction', [self::class, 'ajaxBuildMintTransaction']);
        add_action('wp_ajax_cardano_submit_mint_transaction', [self::class, 'ajaxSubmitMintTransaction']);
        add_action('wp_ajax_nopriv_cardano_submit_mint_transaction', [self::class, 'ajaxSubmitMintTransaction']);

        // Admin endpoint to get policy data
        add_action('wp_ajax_cardano_get_policy_data', [self::class, 'ajaxGetPolicyData']);

        // Server-side CBOR -> Bech32 address conversion (Anvil proxy). Keeps the
        // Anvil API key on the server instead of localizing it into the page.
        add_action('wp_ajax_cardano_convert_address', [self::class, 'ajaxConvertAddress']);
        add_action('wp_ajax_nopriv_cardano_convert_address', [self::class, 'ajaxConvertAddress']);

        // Pinata IPFS upload
        add_action('wp_ajax_cardano_pin_to_ipfs', [self::class, 'ajaxPinToIPFS']);

        // Mint tracking - CSV export/import and history
        add_action('wp_ajax_cardano_export_mint_history', [self::class, 'ajaxExportMintHistory']);
        add_action('wp_ajax_cardano_import_mint_whitelist', [self::class, 'ajaxImportMintWhitelist']);
        add_action('wp_ajax_cardano_get_mint_history', [self::class, 'ajaxGetMintHistory']);

        // Archive system
        add_action('wp_ajax_cardano_archive_policy', [self::class, 'ajaxArchivePolicy']);
        add_action('wp_ajax_cardano_unarchive_policy', [self::class, 'ajaxUnarchivePolicy']);

        // Bulk JSON import (mint-ready, verbatim) — batched from the Mint Manager
        add_action('wp_ajax_cardano_mint_import_assets', [self::class, 'ajaxImportAssets']);
    }

    /**
     * Bulk-import mint-ready assets from JSON. Each asset is stored as its own
     * quantity-1 row with metadata_mode='verbatim' so it mints exactly as
     * provided (explicit on-chain token name + the metadata object unchanged).
     *
     * Called in batches by the Mint Manager "Import JSON" path. The first
     * batch with collection_id=0 + policy_mode=new creates the collection
     * (variant A) and returns its id; subsequent batches pass that id back.
     *
     * POST:
     *   assets            JSON array of { assetName, metadata }
     *   collection_id     0 to create a new collection, else append to it
     *   policy_mode       'new' | 'existing'
     *   policyid, policy_json, expirationdate, unlimited, price, royalty,
     *   royaltyaddress, mintsallowedperwallet, title   (policy-level fields)
     */
    public static function ajaxImportAssets() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        MintModel::add_multi_asset_columns();

        $assets_raw = isset($_POST['assets']) ? wp_unslash($_POST['assets']) : '';
        $assets = json_decode($assets_raw, true);
        if (!is_array($assets) || empty($assets)) {
            wp_send_json_error(['message' => 'No valid assets in this batch (expected a JSON array).']);
        }

        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;
        $policy_mode   = sanitize_text_field($_POST['policy_mode'] ?? 'existing');
        $policyid      = sanitize_text_field($_POST['policyid'] ?? '');
        if (!preg_match('/^[a-f0-9]{56}$/i', $policyid)) {
            wp_send_json_error(['message' => 'A valid 56-char Policy ID is required before importing.']);
        }

        // Validate the policy JSON the same way the form save does.
        $policy_json_validated = null;
        $policy_json_raw = isset($_POST['policy_json']) ? wp_unslash($_POST['policy_json']) : '';
        if ($policy_json_raw) {
            $decoded = json_decode($policy_json_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['policyId'], $decoded['schema'])) {
                $policy_json_validated = wp_json_encode($decoded);
            }
        }

        $policy_fields = [
            'title'                 => sanitize_text_field($_POST['title'] ?? ''),
            'policyid'              => $policyid,
            'policy_json'           => $policy_json_validated,
            'expirationdate'        => sanitize_text_field($_POST['expirationdate'] ?? ''),
            'unlimited'             => !empty($_POST['unlimited']) ? 1 : 0,
            'price'                 => isset($_POST['price']) ? floatval($_POST['price']) : 0.00,
            'royalty'               => sanitize_text_field($_POST['royalty'] ?? ''),
            'royaltyaddress'        => sanitize_text_field($_POST['royaltyaddress'] ?? ''),
            'mintsallowedperwallet' => isset($_POST['mintsallowedperwallet']) ? intval($_POST['mintsallowedperwallet']) : 0,
            // Optional collection (mystery-box) image shared by every imported asset —
            // shown in the minter instead of each asset's own image when set.
            'collection_image_id'   => (isset($_POST['collection_image_id']) && $_POST['collection_image_id'] !== '') ? intval($_POST['collection_image_id']) : null,
        ];

        $inserted = 0;
        $errors   = [];
        foreach ($assets as $i => $asset) {
            $label = '#' . ($i + 1);
            if (!is_array($asset) || empty($asset['assetName']) || !isset($asset['metadata']) || !is_array($asset['metadata'])) {
                $errors[] = "$label: missing assetName or metadata object.";
                continue;
            }
            $assetName = trim((string) $asset['assetName']);

            // Cardano token-name rules: 1–32 BYTES, and we keep it to safe
            // printable chars so the utf8 round-trip on-chain is lossless.
            if ($assetName === '' || strlen($assetName) > 32) {
                $errors[] = "$label ($assetName): token name must be 1–32 bytes.";
                continue;
            }
            if (!preg_match('/^[\x20-\x7E]+$/', $assetName)) {
                $errors[] = "$label ($assetName): token name has non-printable/again non-ASCII characters.";
                continue;
            }
            if (MintModel::onchainAssetNameExists($policyid, $assetName)) {
                $errors[] = "$label ($assetName): already exists under this policy — skipped.";
                continue;
            }

            // First asset of a brand-new collection becomes variant A and
            // seeds collection_id; everything after is a numeric variant.
            $is_first_new = ($collection_id === 0 && $inserted === 0 && $policy_mode === 'new');
            $variant = $is_first_new ? 'A' : (string) (MintModel::getCollectionAssetCount($collection_id) + 1);

            $mintData = array_merge($policy_fields, [
                'collection_id'  => $collection_id ?: null,
                'variant'        => $variant,
                'asset_name'     => $assetName,                       // explicit on-chain token name
                'nft_metadata'   => wp_json_encode($asset['metadata']), // stored VERBATIM
                'metadata_mode'  => 'verbatim',
                'quantity_total' => 1,
                'quantity_minted'=> 0,
                'status'         => 'Active',
            ]);

            $new_id = MintModel::insert_active_mint($mintData);
            if (!$new_id) {
                $errors[] = "$label ($assetName): database insert failed.";
                continue;
            }
            $inserted++;
            // Seed collection_id from the first inserted row so the rest of
            // this batch (and later batches) append to the same collection.
            if ($collection_id === 0) {
                $collection_id = (int) $new_id;
            }
        }

        wp_send_json_success([
            'inserted'      => $inserted,
            'collection_id' => $collection_id,
            'errors'        => $errors,
        ]);
    }

    public static function ajaxPinToIPFS() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');

        if (!$image_id) {
            wp_send_json_error(['message' => 'No image selected']);
        }

        $file_path = get_attached_file($image_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(['message' => 'Image file not found']);
        }

        $result = \CardanoMintPay\Helpers\PinataAPI::uploadImage($file_path, $name);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: convert a CBOR/hex-encoded Cardano address to Bech32 via Anvil.
     * Public (the mint modal runs for logged-out visitors) but nonce-gated and
     * rate-limited. The Anvil API key never leaves the server.
     */
    public static function ajaxConvertAddress() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!AJAXController::checkRateLimit('convert_address', 30, MINUTE_IN_SECONDS)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment and try again.'], 429);
        }

        $address = isset($_POST['address']) ? trim(sanitize_text_field(wp_unslash($_POST['address']))) : '';
        if ($address === '' || strlen($address) > 256
            || !preg_match('/^(?:(?:0x)?[0-9a-fA-F]+|addr(?:_test)?1[0-9a-z]+)$/', $address)) {
            wp_send_json_error(['message' => 'Invalid address.'], 400);
        }

        $converted = AnvilAPI::convertAddressToBech32($address);
        if (!is_string($converted) || !preg_match('/^addr(?:_test)?1[0-9a-z]+$/', $converted)) {
            wp_send_json_error(['message' => 'Address could not be converted.'], 422);
        }

        wp_send_json_success(['address' => $converted]);
    }

    public static function renderNFTCheckout($atts) {
        $atts = shortcode_atts([
            'mint-id' => '',
            'nftname' => 'Cardano NFT',
            'price' => 0.00,
            'policyid' => '',
            'metadata_url' => '',
            'class' => ''
        ], $atts);

        $mintIdParam = $atts['mint-id'];
        $specificVariant = null;

        // Parse mint-id for variant syntax: "1" vs "1-A"
        if (strpos($mintIdParam, '-') !== false) {
            // Specific variant requested (e.g., "1-A")
            list($collectionId, $variant) = explode('-', $mintIdParam, 2);
            $mint = MintModel::getAssetByCollectionAndVariant(intval($collectionId), strtoupper($variant));
            $specificVariant = strtoupper($variant);

            if (WP_DEBUG) {
                cardanomint_log('Cardano Mint: Specific variant requested - Collection: ' . $collectionId . ', Variant: ' . $variant);
            }
        } else {
            // Random weighted selection from all variants (e.g., "1")
            $collectionId = intval($mintIdParam);
            $mint = MintModel::selectWeightedRandomAsset($collectionId);

            if (WP_DEBUG) {
                cardanomint_log('Cardano Mint: Weighted random selection for collection: ' . $collectionId);
            }
        }

        // Get merchant address from plugin settings (not from shortcode)
        $merchant_address = get_option('cardano_mint_merchant_address', '');

        // Debug logging
        if (WP_DEBUG) {
            cardanomint_log('Cardano Mint Controller Debug - Mint ID Param: ' . $mintIdParam);
            cardanomint_log('Cardano Mint Controller Debug - Selected Mint: ' . ($mint ? $mint['id'] : 'NULL'));
            cardanomint_log('Cardano Mint Controller Debug - Merchant Address: ' . $merchant_address);
        }

        // Pass merchant address to the view
        $atts['merchantaddress'] = $merchant_address;
        
        ob_start();
        include(plugin_dir_path(__FILE__) . '/../views/nft-mint-form.php');
        $output = ob_get_clean();
        
        // Apply custom class if provided
        $wrapper_class = 'cardano-shortcode-wrapper';
        if (!empty($atts['class'])) {
            $wrapper_class .= ' ' . sanitize_html_class($atts['class']);
        }
        
        // Enqueue CSS and JavaScript for mint functionality (only if not already enqueued)
        if (!wp_style_is('cardano-checkout-css', 'enqueued')) {
            wp_enqueue_style('cardano-checkout-css', plugin_dir_url(__FILE__) . '../../assets/cardano-checkout.css', [], '1.0.0');
        }

        // Alt-pay checkout JS / CSS — only when the operator turned the
        // feature on AND has at least one chain wallet configured. Otherwise
        // the existing ADA-only flow is byte-for-byte unchanged.
        if (get_option('cardano_mint_altpay_enabled', '0') === '1' && class_exists('CardanoMintPay\\Models\\ChainWalletModel')) {
            $any_chain = false;
            foreach (['btc', 'eth', 'sol'] as $c) {
                if (!empty(\CardanoMintPay\Models\ChainWalletModel::list_for_chain($c, false))) { $any_chain = true; break; }
            }
            if ($any_chain) {
                $altpay_css_path = plugin_dir_path(__FILE__) . '../../assets/altpay/altpay-checkout.css';
                $altpay_js_path  = plugin_dir_path(__FILE__) . '../../assets/altpay/altpay-checkout.js';
                wp_enqueue_style('cardano-altpay-checkout-css', plugin_dir_url(__FILE__) . '../../assets/altpay/altpay-checkout.css', [], file_exists($altpay_css_path) ? filemtime($altpay_css_path) : '0.1.0');
                wp_enqueue_script('cardano-altpay-checkout-js', plugin_dir_url(__FILE__) . '../../assets/altpay/altpay-checkout.js', [], file_exists($altpay_js_path) ? filemtime($altpay_js_path) : '0.1.0', true);
                wp_localize_script('cardano-altpay-checkout-js', 'cardanoAltPayCheckout', [
                    'restUrl'       => esc_url_raw(rest_url('cardano-mint/v1')),
                    'serviceFeeAda' => (int) get_option('cardano_mint_service_fee_ada', 5),
                ]);
            }
        }

        // On-ramp (Guardarian fiat-to-ADA). Enqueued only when the operator
        // turned the feature on AND a Guardarian API key is configured —
        // otherwise the existing checkout is byte-for-byte unchanged.
        if (
            get_option('cardano_mint_onramp_enabled', '0') === '1'
            && class_exists('CardanoMintPay\\Onramp\\GuardarianClient')
            && \CardanoMintPay\Onramp\GuardarianClient::isConfigured()
        ) {
            $or_css_path = plugin_dir_path(__FILE__) . '../../assets/onramp/onramp-modal.css';
            $or_js_path  = plugin_dir_path(__FILE__) . '../../assets/onramp/onramp-modal.js';
            wp_enqueue_style(
                'cardano-onramp-modal-css',
                plugin_dir_url(__FILE__) . '../../assets/onramp/onramp-modal.css',
                [],
                file_exists($or_css_path) ? filemtime($or_css_path) : '0.1.0'
            );
            wp_enqueue_script(
                'cardano-onramp-modal-js',
                plugin_dir_url(__FILE__) . '../../assets/onramp/onramp-modal.js',
                [],
                file_exists($or_js_path) ? filemtime($or_js_path) : '0.1.0',
                true
            );
            // Default network mirrors the AltPay convention. The mint shortcode
            // can override it per-call by passing a customer wallet on a
            // different network — the modal trusts the address it's given.
            $anvil_url = strtolower((string) get_option('cardano_mint_anvil_api_url', ''));
            $is_mainnet = (
                strpos($anvil_url, 'preprod') === false
                && strpos($anvil_url, 'preview') === false
                && strpos($anvil_url, 'sancho') === false
            );
            wp_localize_script('cardano-onramp-modal-js', 'cardanoOnrampPublic', [
                'restRoot'       => esc_url_raw(rest_url('cardano-mint/v1/')),
                'nonce'          => wp_create_nonce('wp_rest'),
                'defaultNetwork' => $is_mainnet ? 'mainnet' : 'preprod',
                'feeBufferAda'   => \CardanoMintPay\Onramp\GuardarianService::feeBufferAda(),
                'presetPayout'   => \CardanoMintPay\Onramp\GuardarianService::isPresetPayoutEnabled(),
                // Partner API key is exposed to the frontend on purpose:
                // Guardarian's documented iframe surface is the calculator
                // widget at guardarian.com/calculator/v1, which takes
                // partner_api_token as a query param. Same security model
                // as a Stripe publishable key — the partner key only has
                // exchange-creation scope and is rate-limited per IP.
                'partnerApiKey'  => \CardanoMintPay\Onramp\GuardarianClient::apiKey(),
                'widgetBaseUrl'  => 'https://guardarian.com/calculator/v1',
            ]);
        }

        // Note: Script and localization are handled by the main plugin file

        // Prevent WordPress from adding auto-paragraphs to our shortcode output
        return '<div class="' . esc_attr($wrapper_class) . '">' . $output . '</div>';
    }

    /**
     * Legacy mint NFT endpoint (DEPRECATED)
     * 
     * This endpoint is deprecated and no longer used in the current minting flow.
     * The current flow uses:
     * - cardano_build_mint_transaction
     * - cardano_submit_mint_transaction
     * 
     * @deprecated This endpoint is kept for backward compatibility only
     */
    public static function ajaxMintNFT() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');
        
        // Return error indicating this endpoint is deprecated
        wp_send_json_error([
            'error' => 'This minting endpoint is deprecated. Please use the current Anvil API flow.'
        ]);
    }

    public static function ajaxGetNFTPrice() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');
        // Use shared AnvilAPI for consistent pricing
        $price = AnvilAPI::getAdaPrice();
        wp_send_json_success(['price' => $price]);
    }
    
    /**
     * Build mint transaction via Anvil API
     */
    public static function ajaxBuildMintTransaction() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        $merchant_address = sanitize_text_field($_POST['merchant_address'] ?? '');
        $customer_address = sanitize_text_field($_POST['customer_address'] ?? '');
        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $posted_usd_price = floatval($_POST['usd_price'] ?? 0); // Kept only for debug comparison.

        // Per-tx quantity (hard cap 5 in the UI; the per-wallet limit is
        // checked separately below). Customers wanting more re-mint in
        // additional txs.
        $quantity = max(1, min(5, intval($_POST['quantity'] ?? 1)));

        // Debug logging
        cardanomint_log("=== MINT TRANSACTION DEBUG ===");
        cardanomint_log("merchant_address: " . $merchant_address);
        cardanomint_log("customer_address: " . $customer_address);
        cardanomint_log("posted usd_price (ignored, server uses DB price): " . $posted_usd_price);
        cardanomint_log("policy_id: " . $policy_id);
        cardanomint_log("asset_id: " . $asset_id);

        // Network gate: reject the build if the client's Cardano address is
        // not on the same network as this site. Without this check, a customer
        // whose wallet is on the wrong network can pay off-chain (BTC/ETH/SOL),
        // bind the invoice to a wrong-network address, and either get stuck
        // unable to sign or end up with a wallet-network mismatch we silently
        // accept. Better to fail early with a clear message.
        $site_network = strtolower((string) get_option('cardano_mint_anvil_api_url', ''));
        $site_is_mainnet = (strpos($site_network, 'preprod') === false && strpos($site_network, 'preview') === false && strpos($site_network, 'sancho') === false);
        $addr_is_testnet = (stripos($customer_address, 'addr_test1') === 0);
        if ($site_is_mainnet === $addr_is_testnet) {
            $expected_label = $site_is_mainnet ? 'Mainnet' : 'Pre-production';
            $actual_label   = $addr_is_testnet ? 'Testnet'  : 'Mainnet';
            wp_send_json_error([
                'message' => 'Wallet network mismatch: your wallet is on ' . $actual_label
                    . ' but this site is on ' . $expected_label
                    . '. Switch your wallet network and reconnect.'
            ]);
        }

        // Validate inputs that must come from the client.
        if (!$merchant_address || !$customer_address || !$policy_id || $asset_id <= 0) {
            cardanomint_log("VALIDATION FAILED:", 'error');
            cardanomint_log("merchant_address valid: " . ($merchant_address ? 'YES' : 'NO'));
            cardanomint_log("customer_address valid: " . ($customer_address ? 'YES' : 'NO'));
            cardanomint_log("policy_id valid: " . ($policy_id ? 'YES' : 'NO'));
            cardanomint_log("asset_id > 0: " . ($asset_id > 0 ? 'YES' : 'NO'));
            wp_send_json_error(['message' => 'Missing or invalid parameters']);
        }

        // Get mint data for the specific asset by ID (NOT by policy!)
        cardanomint_log("=== FETCHING MINT DATA BY ASSET ID ===");
        cardanomint_log("Looking for asset ID: " . $asset_id);
        $mint_data = MintModel::getMintById($asset_id);

        if (!$mint_data) {
            cardanomint_log("ERROR: No mint data found for asset ID: " . $asset_id, 'error');
            wp_send_json_error(['message' => 'Asset not found']);
        }

        // Authoritative USD price comes from the mint record, NOT the client.
        // The live ADA conversion happens inside AnvilAPI::buildMintTransaction via getAdaPrice().
        $usd_price = floatval($mint_data['price'] ?? 0);
        if ($usd_price <= 0) {
            cardanomint_log("ERROR: Mint record has no USD price configured (asset_id=" . $asset_id . ")", 'error');
            wp_send_json_error(['message' => 'Mint is not priced. Please contact the site administrator.']);
        }
        if (abs($usd_price - $posted_usd_price) > 0.01) {
            cardanomint_log("NOTICE: Client-posted usd_price (" . $posted_usd_price . ") does not match DB price (" . $usd_price . "). Using DB price.");
        }
        cardanomint_log("Using authoritative usd_price from DB: " . $usd_price);

        // Alt-pay path: validate the funded invoice and override the merchant
        // lovelace output with the configured ADA service fee. The bulk of
        // the customer's payment already cleared on the alt chain.
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $altpay_invoice = null;
        if ($invoice_id > 0 && class_exists('CardanoMintPay\\Models\\ChainInvoiceModel')) {
            $altpay_invoice = \CardanoMintPay\Models\ChainInvoiceModel::get($invoice_id);
            if (!$altpay_invoice) {
                wp_send_json_error(['message' => 'Alt-pay invoice not found.']);
            }
            if ($altpay_invoice['status'] !== 'funded') {
                wp_send_json_error(['message' => 'Alt-pay invoice not funded yet (status: ' . $altpay_invoice['status'] . ').']);
            }
            if ($altpay_invoice['customer_cardano_address'] !== $customer_address) {
                wp_send_json_error(['message' => 'Alt-pay invoice does not belong to this Cardano address.']);
            }
            $service_fee_ada = (float) get_option('cardano_mint_service_fee_ada', 5);
            if ($service_fee_ada < 2)  $service_fee_ada = 2;
            if ($service_fee_ada > 20) $service_fee_ada = 20;
            $mint_data['_altpay_service_fee_ada_override'] = $service_fee_ada;
            cardanomint_log("[AltPay] legacy build: invoice $invoice_id -> service fee $service_fee_ada ADA");
        }

        // Validate remaining supply covers this batch BEFORE we touch wallet limits.
        $remaining = intval($mint_data['quantity_total'] ?? 0) - intval($mint_data['quantity_minted'] ?? 0);
        if ($remaining < $quantity) {
            $msg = $remaining <= 0
                ? 'This mint is sold out.'
                : 'Only ' . $remaining . ' left for this mint. Lower the quantity and try again.';
            wp_send_json_error(['message' => $msg]);
        }

        // Check per-wallet mint limits BEFORE building transaction
        $mints_allowed = intval($mint_data['mintsallowedperwallet'] ?? 0);
        cardanomint_log("Checking mint limits for policy: " . $policy_id . ", wallet: " . $customer_address . ", allowed: " . $mints_allowed . ", qty: " . $quantity);

        $mint_check = MintModel::canWalletMint($policy_id, $customer_address, $mints_allowed);
        cardanomint_log("Mint limits check result: " . print_r($mint_check, true));

        if (!$mint_check['can_mint']) {
            cardanomint_log("Mint limits check failed: " . $mint_check['message'], 'error');
            wp_send_json_error(['message' => $mint_check['message']]);
        }
        // canWalletMint validates 1 mint of headroom; for qty>1 we also need
        // the requested batch to fit within the per-wallet allowance.
        if ($mints_allowed > 0 && isset($mint_check['remaining']) && $mint_check['remaining'] < $quantity) {
            wp_send_json_error(['message' => 'You can only mint ' . $mint_check['remaining'] . ' more from this collection. Lower the quantity.']);
        }

        cardanomint_log("FOUND asset!");
        cardanomint_log("Asset variant: " . ($mint_data['variant'] ?? 'NULL') . ", Name: " . ($mint_data['asset_name'] ?? $mint_data['title']));
        cardanomint_log("policy_json present: " . (isset($mint_data['policy_json']) ? 'YES' : 'NO'));
        if (isset($mint_data['policy_json'])) {
            cardanomint_log("policy_json length: " . strlen($mint_data['policy_json']));
        }
        cardanomint_log("=== END FETCHING MINT DATA ===");

        // DEBUG: Log what we're passing to Anvil
        cardanomint_log("About to call buildMintTransaction with:");
        cardanomint_log("  merchant_address: " . $merchant_address);
        cardanomint_log("  customer_address: " . $customer_address);
        cardanomint_log("  usd_price: " . $usd_price);
        cardanomint_log("  policy_id: " . $policy_id);

        // Discount code (ADA path). Validated + reserved server-side; the code
        // only reduces the MSRP component of $usd_price — the +1 ADA/asset
        // minting fee is added downstream in buildMintTransaction AFTER the
        // USD→ADA conversion, so fees survive even a 100%-off code (DC-D2).
        // Alt-paid mints discount at quote time instead (phase 2), so skip here.
        $discount_redemption_id = 0;
        $discount_code = strtoupper(trim((string) ($_POST['discount_code'] ?? '')));
        if ($discount_code !== '' && $invoice_id <= 0) {
            $reserve = DiscountService::reserve($discount_code, $policy_id, $usd_price, $quantity, 'ada', $customer_address);
            if (empty($reserve['ok'])) {
                wp_send_json_error(['message' => $reserve['error'] ?? 'That code isn\'t valid.']);
            }
            $usd_price = (float) $reserve['pricing']['final_per_asset_usd'];
            $discount_redemption_id = (int) $reserve['redemption_id'];
            cardanomint_log("[Discount] code {$discount_code} reserved (redemption {$discount_redemption_id}); per-asset price -> {$usd_price}");
        }

        // Build transaction via Anvil API with mint metadata. Quantity is passed
        // through so a single tx mints N unique assets for one signature.
        $response = AnvilAPI::buildMintTransaction($merchant_address, $customer_address, $usd_price, $policy_id, 'mint', $mint_data, $quantity);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        // DEBUG: Add price info and mint limits info to response for frontend debugging
        $response['debug_price_info'] = array(
            'usd_price_used'      => $usd_price,        // From DB (authoritative), post-discount.
            'usd_price_posted'    => $posted_usd_price, // From client (ignored).
            'ada_usd_rate_cached' => (float) AnvilAPI::getAdaPrice(),
        );

        // Carry the discount reservation back so the submit step can commit it
        // once the signed tx lands. Held for DiscountService::RESERVATION_TTL_MIN.
        $response['discount_redemption_id'] = $discount_redemption_id;

        $response['debug_mint_limits'] = array(
            'policy_id' => $policy_id,
            'wallet_address' => $customer_address,
            'mints_allowed_per_wallet' => $mints_allowed,
            'can_mint' => $mint_check['can_mint'],
            'remaining' => $mint_check['remaining'],
            'message' => $mint_check['message']
        );

        wp_send_json_success($response);
    }
    
    /**
     * Submit mint transaction and update database
     */
    public static function ajaxSubmitMintTransaction() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        $transaction = sanitize_text_field($_POST['transaction'] ?? '');
        $signatures = json_decode(stripslashes($_POST['signatures'] ?? '[]'), true);
        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $quantity = max(1, min(5, intval($_POST['quantity'] ?? 1)));

        if (!$transaction || !$policy_id || !$wallet_address) {
            wp_send_json_error(['message' => 'Missing required data']);
        }

        // Submit transaction via Anvil API (pass policy_id for imported policy override)
        $response = AnvilAPI::submitTransaction($transaction, $signatures, 'mint', $policy_id);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        // If transaction successful, update mint counts and quantity.
        // Anvil has used both `txHash` and `hash` over time; accept either.
        $tx_hash = '';
        if (is_array($response)) {
            $tx_hash = $response['txHash'] ?? $response['tx_hash'] ?? $response['hash'] ?? '';
        }
        if ($tx_hash) {
            // Get mint data to retrieve mints allowed per wallet and stake address
            $mint_data = MintModel::getMintById($asset_id);
            $mints_allowed = intval($mint_data['mintsallowedperwallet'] ?? 0);

            // TODO: Extract stake address from wallet (for now, use null)
            // In future, get this from CIP-30 wallet API or parse from payment address
            $stake_address = null;

            // Record N mint rows + decrement supply by N. Tx atomically minted
            // $quantity assets for this wallet, so the tracking has to mirror that.
            for ($i = 0; $i < $quantity; $i++) {
                $recorded = MintModel::recordMint($policy_id, $wallet_address, $stake_address, $mints_allowed);
                if ($recorded) {
                    cardanomint_log("✅ Mint recorded ({$i}/{$quantity}) for wallet: " . $wallet_address . " on policy: " . $policy_id);
                } else {
                    cardanomint_log("⚠️ WARNING: Failed to record mint ({$i}/{$quantity}) for wallet: " . $wallet_address, 'error');
                }
                MintModel::incrementMintCount($policy_id, $wallet_address);
                if ($asset_id > 0) {
                    $decremented = MintModel::decrementQuantity($asset_id);
                    if (!$decremented) {
                        cardanomint_log("Cardano Mint: WARNING - Failed to decrement quantity ({$i}/{$quantity}) for asset ID " . $asset_id, 'error');
                    }
                }
            }
            cardanomint_log("Cardano Mint: completed accounting for batch of {$quantity} on asset ID " . $asset_id);

            // Mark CIP-27 royalty token as minted for this policy (if it was the first mint)
            // This ensures subsequent mints for this policy won't mint another royalty token
            $royalty_marked = MintModel::markRoyaltyTokenMinted($policy_id);
            if ($royalty_marked) {
                cardanomint_log("✅ CIP-27 royalty token marked as minted for policy: " . $policy_id);
            } else {
                cardanomint_log("ℹ️ Royalty token already marked as minted for policy: " . $policy_id);
            }

            // Alt-pay: close out the invoice so it can't be reused for another mint.
            $invoice_id = intval($_POST['invoice_id'] ?? 0);
            if ($invoice_id > 0 && class_exists('CardanoMintPay\\Models\\ChainInvoiceModel')) {
                \CardanoMintPay\Models\ChainInvoiceModel::set_status($invoice_id, 'consumed');
                cardanomint_log("[AltPay] legacy submit: invoice $invoice_id marked consumed (tx $tx_hash)");
                // Commit any discount reservation tied to this alt-pay invoice.
                $dcommit = DiscountService::commit_for_invoice($invoice_id, $tx_hash);
                cardanomint_log("[Discount] commit for invoice $invoice_id: " . ($dcommit ? 'ok' : 'none'));
            }

            // Discount: commit the reservation now that the mint is on-chain, so
            // a single-use code is permanently spent. Wallet-bound + atomic, so a
            // tampered id just no-ops. If the reservation expired (TTL) the price
            // is already locked in the signed tx, so this is best-effort.
            $discount_redemption_id = intval($_POST['discount_redemption_id'] ?? 0);
            if ($discount_redemption_id > 0) {
                $committed = DiscountService::commit($discount_redemption_id, $wallet_address, $tx_hash);
                cardanomint_log("[Discount] commit redemption {$discount_redemption_id}: " . ($committed ? 'ok' : 'no-op'));
            }
        }

        wp_send_json_success($response);
    }

    /**
     * Register the policy generation AJAX endpoint
     * Add this to the register() method in NFTCheckoutController
     */
    public static function registerPolicyGeneration() {
        add_action('wp_ajax_cardano_generate_policy', [self::class, 'ajaxGeneratePolicy']);
    }

    /**
     * AJAX endpoint to generate a new Cardano policy via Anvil API
     */
    public static function ajaxGeneratePolicy() {
        check_ajax_referer('cardanomint_generate_policy', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get expiration date from request (optional)
        $expiration_date = isset($_POST['expiration_date']) ? sanitize_text_field($_POST['expiration_date']) : null;

        // Generate policy via Anvil API
        $result = AnvilAPI::generatePolicy($expiration_date);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
        }

        // Check if this policy ID already exists in the database
        if (isset($result['policyId']) && MintModel::policyIdExists($result['policyId'])) {
            wp_send_json_error([
                'message' => '⚠️ This policy ID already exists in your database. Please select a different expiration date to generate a unique policy.',
                'duplicate_policy' => true,
                'existing_policy_id' => $result['policyId']
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX endpoint to get policy data for a collection (for populating "add to existing" form)
     */
    public static function ajaxGetPolicyData() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;

        if (!$collection_id) {
            wp_send_json_error(['message' => 'Missing collection ID']);
        }

        // Get the first asset of this collection (variant A) to get policy-level data
        $assets = MintModel::getAssetsByCollectionId($collection_id);

        if (empty($assets)) {
            wp_send_json_error(['message' => 'No assets found for this collection']);
        }

        // Return the first asset's policy-level data
        $firstAsset = $assets[0];

        wp_send_json_success([
            'title' => $firstAsset['title'],
            'price' => $firstAsset['price'],
            'royalty' => $firstAsset['royalty'],
            'royaltyaddress' => $firstAsset['royaltyaddress'],
            'mintsallowedperwallet' => $firstAsset['mintsallowedperwallet']
        ]);
    }

    /**
     * AJAX endpoint to export mint history as CSV
     */
    public static function ajaxExportMintHistory() {

        check_ajax_referer('cardano_export_mint_history', 'nonce');

        if (!current_user_can('manage_options')) {
            cardanomint_log('Export failed: Insufficient permissions', 'error');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $mint_title = sanitize_text_field($_POST['mint_title'] ?? '');

        cardanomint_log('Policy ID: ' . $policy_id);
        cardanomint_log('Mint Title: ' . $mint_title);

        if (empty($policy_id)) {
            cardanomint_log('Export failed: Policy ID required', 'error');
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        // Generate CSV
        $csv = MintModel::exportMintHistoryCSV($policy_id, $mint_title);

        cardanomint_log('CSV generated, length: ' . strlen($csv));
        cardanomint_log('CSV preview: ' . substr($csv, 0, 200));

        // Return CSV content (will be downloaded by JavaScript)
        wp_send_json_success([
            'csv' => $csv,
            'filename' => sanitize_file_name($mint_title ?: 'mint-history') . '_' . substr($policy_id, 0, 8) . '.csv'
        ]);
    }

    /**
     * AJAX endpoint to import mint whitelist from CSV
     */
    public static function ajaxImportMintWhitelist() {

        check_ajax_referer('cardano_import_mint_whitelist', 'nonce');

        if (!current_user_can('manage_options')) {
            cardanomint_log('Import failed: Insufficient permissions', 'error');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $csv_content = stripslashes($_POST['csv_content'] ?? '');

        cardanomint_log('Policy ID: ' . $policy_id);
        cardanomint_log('CSV content length: ' . strlen($csv_content));

        if (empty($policy_id) || empty($csv_content)) {
            cardanomint_log('Import failed: Missing policy ID or CSV content', 'error');
            wp_send_json_error(['message' => 'Policy ID and CSV content required']);
        }

        // Import CSV
        $result = MintModel::importMintWhitelistCSV($policy_id, $csv_content);

        cardanomint_log('Import result: ' . print_r($result, true));

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf('Successfully imported %d wallet(s)', $result['imported']),
                'imported' => $result['imported'],
                'errors' => $result['errors']
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Import failed: ' . implode(', ', $result['errors']),
                'errors' => $result['errors']
            ]);
        }
    }

    /**
     * AJAX endpoint to get mint history for a policy (for modal display)
     */
    public static function ajaxGetMintHistory() {

        check_ajax_referer('cardano_get_mint_history', 'nonce');

        if (!current_user_can('manage_options')) {
            cardanomint_log('Get history failed: Insufficient permissions', 'error');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');

        cardanomint_log('Policy ID: ' . $policy_id);

        if (empty($policy_id)) {
            cardanomint_log('Get history failed: Policy ID required', 'error');
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        // Get history
        $history = MintModel::getMintHistoryForPolicy($policy_id);
        $unique_count = MintModel::getUniqueMinterCount($policy_id);

        cardanomint_log('History count: ' . count($history));
        cardanomint_log('Unique minters: ' . $unique_count);

        wp_send_json_success([
            'history' => $history,
            'unique_minters' => $unique_count,
            'policy_id' => $policy_id
        ]);
    }

    /**
     * Archive a policy (all variants)
     */
    public static function ajaxArchivePolicy() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');

        if (empty($policy_id)) {
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        $result = MintModel::archivePolicy($policy_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result === false || $result === 0) {
            wp_send_json_error(['message' => 'Failed to archive policy. Policy may not exist.']);
        }

        wp_send_json_success([
            'message' => 'Policy archived successfully',
            'variants_archived' => $result
        ]);
    }

    /**
     * Unarchive a policy (all variants)
     */
    public static function ajaxUnarchivePolicy() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');

        if (empty($policy_id)) {
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        $result = MintModel::unarchivePolicy($policy_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result === false || $result === 0) {
            wp_send_json_error(['message' => 'Failed to unarchive policy. Policy may not exist.']);
        }

        wp_send_json_success([
            'message' => 'Policy unarchived successfully',
            'variants_unarchived' => $result
        ]);
    }
}