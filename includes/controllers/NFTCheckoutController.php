<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Helpers\AnvilAPI;

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

        // Get CardanoPress connected wallet address
        add_action('wp_ajax_cardano_get_connected_wallet', [self::class, 'ajaxGetConnectedWallet']);
        add_action('wp_ajax_nopriv_cardano_get_connected_wallet', [self::class, 'ajaxGetConnectedWallet']);

        // Pinata IPFS upload
        add_action('wp_ajax_cardano_pin_to_ipfs', [self::class, 'ajaxPinToIPFS']);

        // Mint tracking - CSV export/import and history
        add_action('wp_ajax_cardano_export_mint_history', [self::class, 'ajaxExportMintHistory']);
        add_action('wp_ajax_cardano_import_mint_whitelist', [self::class, 'ajaxImportMintWhitelist']);
        add_action('wp_ajax_cardano_get_mint_history', [self::class, 'ajaxGetMintHistory']);

        // Archive system
        add_action('wp_ajax_cardano_archive_policy', [self::class, 'ajaxArchivePolicy']);
        add_action('wp_ajax_cardano_unarchive_policy', [self::class, 'ajaxUnarchivePolicy']);
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

    public static function ajaxGetConnectedWallet() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (function_exists('cardanoPress')) {
            $address = cardanoPress()->userProfile()->connectedWallet();
            if ($address) {
                wp_send_json_success(['address' => $address]);
            }
        }

        wp_send_json_error(['message' => 'No wallet connected']);
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
                error_log('Cardano Mint: Specific variant requested - Collection: ' . $collectionId . ', Variant: ' . $variant);
            }
        } else {
            // Random weighted selection from all variants (e.g., "1")
            $collectionId = intval($mintIdParam);
            $mint = MintModel::selectWeightedRandomAsset($collectionId);

            if (WP_DEBUG) {
                error_log('Cardano Mint: Weighted random selection for collection: ' . $collectionId);
            }
        }

        // Get merchant address from plugin settings (not from shortcode)
        $merchant_address = get_option('cardano_mint_merchant_address', '');

        // Debug logging
        if (WP_DEBUG) {
            error_log('Cardano Mint Controller Debug - Mint ID Param: ' . $mintIdParam);
            error_log('Cardano Mint Controller Debug - Selected Mint: ' . ($mint ? $mint['id'] : 'NULL'));
            error_log('Cardano Mint Controller Debug - Merchant Address: ' . $merchant_address);
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
        error_log("=== MINT TRANSACTION DEBUG ===");
        error_log("merchant_address: " . $merchant_address);
        error_log("customer_address: " . $customer_address);
        error_log("posted usd_price (ignored, server uses DB price): " . $posted_usd_price);
        error_log("policy_id: " . $policy_id);
        error_log("asset_id: " . $asset_id);

        // Validate inputs that must come from the client.
        if (!$merchant_address || !$customer_address || !$policy_id || $asset_id <= 0) {
            error_log("VALIDATION FAILED:");
            error_log("merchant_address valid: " . ($merchant_address ? 'YES' : 'NO'));
            error_log("customer_address valid: " . ($customer_address ? 'YES' : 'NO'));
            error_log("policy_id valid: " . ($policy_id ? 'YES' : 'NO'));
            error_log("asset_id > 0: " . ($asset_id > 0 ? 'YES' : 'NO'));
            wp_send_json_error(['message' => 'Missing or invalid parameters']);
        }

        // Get mint data for the specific asset by ID (NOT by policy!)
        error_log("=== FETCHING MINT DATA BY ASSET ID ===");
        error_log("Looking for asset ID: " . $asset_id);
        $mint_data = MintModel::getMintById($asset_id);

        if (!$mint_data) {
            error_log("ERROR: No mint data found for asset ID: " . $asset_id);
            wp_send_json_error(['message' => 'Asset not found']);
        }

        // Authoritative USD price comes from the mint record, NOT the client.
        // The live ADA conversion happens inside AnvilAPI::buildMintTransaction via getAdaPrice().
        $usd_price = floatval($mint_data['price'] ?? 0);
        if ($usd_price <= 0) {
            error_log("ERROR: Mint record has no USD price configured (asset_id=" . $asset_id . ")");
            wp_send_json_error(['message' => 'Mint is not priced. Please contact the site administrator.']);
        }
        if (abs($usd_price - $posted_usd_price) > 0.01) {
            error_log("NOTICE: Client-posted usd_price (" . $posted_usd_price . ") does not match DB price (" . $usd_price . "). Using DB price.");
        }
        error_log("Using authoritative usd_price from DB: " . $usd_price);

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
            error_log("[AltPay] legacy build: invoice $invoice_id -> service fee $service_fee_ada ADA");
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
        error_log("Checking mint limits for policy: " . $policy_id . ", wallet: " . $customer_address . ", allowed: " . $mints_allowed . ", qty: " . $quantity);

        $mint_check = MintModel::canWalletMint($policy_id, $customer_address, $mints_allowed);
        error_log("Mint limits check result: " . print_r($mint_check, true));

        if (!$mint_check['can_mint']) {
            error_log("Mint limits check failed: " . $mint_check['message']);
            wp_send_json_error(['message' => $mint_check['message']]);
        }
        // canWalletMint validates 1 mint of headroom; for qty>1 we also need
        // the requested batch to fit within the per-wallet allowance.
        if ($mints_allowed > 0 && isset($mint_check['remaining']) && $mint_check['remaining'] < $quantity) {
            wp_send_json_error(['message' => 'You can only mint ' . $mint_check['remaining'] . ' more from this collection. Lower the quantity.']);
        }

        error_log("FOUND asset!");
        error_log("Asset variant: " . ($mint_data['variant'] ?? 'NULL') . ", Name: " . ($mint_data['asset_name'] ?? $mint_data['title']));
        error_log("policy_json present: " . (isset($mint_data['policy_json']) ? 'YES' : 'NO'));
        if (isset($mint_data['policy_json'])) {
            error_log("policy_json length: " . strlen($mint_data['policy_json']));
        }
        error_log("=== END FETCHING MINT DATA ===");

        // DEBUG: Log what we're passing to Anvil
        error_log("About to call buildMintTransaction with:");
        error_log("  merchant_address: " . $merchant_address);
        error_log("  customer_address: " . $customer_address);
        error_log("  usd_price: " . $usd_price);
        error_log("  policy_id: " . $policy_id);

        // Build transaction via Anvil API with mint metadata. Quantity is passed
        // through so a single tx mints N unique assets for one signature.
        $response = AnvilAPI::buildMintTransaction($merchant_address, $customer_address, $usd_price, $policy_id, 'mint', $mint_data, $quantity);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        // DEBUG: Add price info and mint limits info to response for frontend debugging
        $response['debug_price_info'] = array(
            'usd_price_used'      => $usd_price,        // From DB (authoritative).
            'usd_price_posted'    => $posted_usd_price, // From client (ignored).
            'ada_usd_rate_cached' => (float) AnvilAPI::getAdaPrice(),
        );

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
                    error_log("✅ Mint recorded ({$i}/{$quantity}) for wallet: " . $wallet_address . " on policy: " . $policy_id);
                } else {
                    error_log("⚠️ WARNING: Failed to record mint ({$i}/{$quantity}) for wallet: " . $wallet_address);
                }
                MintModel::incrementMintCount($policy_id, $wallet_address);
                if ($asset_id > 0) {
                    $decremented = MintModel::decrementQuantity($asset_id);
                    if (!$decremented) {
                        error_log("Cardano Mint: WARNING - Failed to decrement quantity ({$i}/{$quantity}) for asset ID " . $asset_id);
                    }
                }
            }
            error_log("Cardano Mint: completed accounting for batch of {$quantity} on asset ID " . $asset_id);

            // Mark CIP-27 royalty token as minted for this policy (if it was the first mint)
            // This ensures subsequent mints for this policy won't mint another royalty token
            $royalty_marked = MintModel::markRoyaltyTokenMinted($policy_id);
            if ($royalty_marked) {
                error_log("✅ CIP-27 royalty token marked as minted for policy: " . $policy_id);
            } else {
                error_log("ℹ️ Royalty token already marked as minted for policy: " . $policy_id);
            }

            // Alt-pay: close out the invoice so it can't be reused for another mint.
            $invoice_id = intval($_POST['invoice_id'] ?? 0);
            if ($invoice_id > 0 && class_exists('CardanoMintPay\\Models\\ChainInvoiceModel')) {
                \CardanoMintPay\Models\ChainInvoiceModel::set_status($invoice_id, 'consumed');
                error_log("[AltPay] legacy submit: invoice $invoice_id marked consumed (tx $tx_hash)");
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
        error_log('=== EXPORT MINT HISTORY DEBUG ===');
        error_log('POST data: ' . print_r($_POST, true));

        check_ajax_referer('cardano_export_mint_history', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('Export failed: Insufficient permissions');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $mint_title = sanitize_text_field($_POST['mint_title'] ?? '');

        error_log('Policy ID: ' . $policy_id);
        error_log('Mint Title: ' . $mint_title);

        if (empty($policy_id)) {
            error_log('Export failed: Policy ID required');
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        // Generate CSV
        $csv = MintModel::exportMintHistoryCSV($policy_id, $mint_title);

        error_log('CSV generated, length: ' . strlen($csv));
        error_log('CSV preview: ' . substr($csv, 0, 200));

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
        error_log('=== IMPORT MINT WHITELIST DEBUG ===');
        error_log('POST data: ' . print_r($_POST, true));

        check_ajax_referer('cardano_import_mint_whitelist', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('Import failed: Insufficient permissions');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $csv_content = stripslashes($_POST['csv_content'] ?? '');

        error_log('Policy ID: ' . $policy_id);
        error_log('CSV content length: ' . strlen($csv_content));

        if (empty($policy_id) || empty($csv_content)) {
            error_log('Import failed: Missing policy ID or CSV content');
            wp_send_json_error(['message' => 'Policy ID and CSV content required']);
        }

        // Import CSV
        $result = MintModel::importMintWhitelistCSV($policy_id, $csv_content);

        error_log('Import result: ' . print_r($result, true));

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
        error_log('=== GET MINT HISTORY DEBUG ===');
        error_log('POST data: ' . print_r($_POST, true));

        check_ajax_referer('cardano_get_mint_history', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('Get history failed: Insufficient permissions');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');

        error_log('Policy ID: ' . $policy_id);

        if (empty($policy_id)) {
            error_log('Get history failed: Policy ID required');
            wp_send_json_error(['message' => 'Policy ID required']);
        }

        // Get history
        $history = MintModel::getMintHistoryForPolicy($policy_id);
        $unique_count = MintModel::getUniqueMinterCount($policy_id);

        error_log('History count: ' . count($history));
        error_log('Unique minters: ' . $unique_count);

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