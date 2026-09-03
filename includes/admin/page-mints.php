<?php
/**
 * Mint Manager + Active Mints admin pages.
 *
 * Moved out of cardano-nft-checkout.php in 4.6.0 unchanged; the plugin entry
 * file now only wires things together. Plugin paths resolve through the
 * CARDANO_MINT_PLUGIN_DIR / _URL constants defined there.
 */

if (!defined('ABSPATH')) exit;

function cardanomint_mint_manager_page() {
    // Enqueue media library scripts
    wp_enqueue_media();

    $metaurl = '';
    $expiration = '';
    $unlimited = '';
    $royalty = '';
    $royaltyaddr = '';
    $mintsallowedperwallet = '';
    $message = '';
    $editMode = false;
    $editData = null;

    // Handle edit mode
    if (isset($_GET['edit']) && !empty($_GET['edit'])) {
        $editId = intval($_GET['edit']);
        $editData = CardanoMintPay\Models\MintModel::getMintById($editId);

        if ($editData) {
            $editMode = true;
            // Pre-populate variables
            $metaurl = '';
            $expiration = $editData['expirationdate'];
            $unlimited = $editData['unlimited'];
            $royalty = $editData['royalty'];
            $royaltyaddr = $editData['royaltyaddress'];
            $mintsallowedperwallet = $editData['mintsallowedperwallet'];
        }
    }

    // Handle form submission
    if (isset($_POST['mintmanagersave']) && wp_verify_nonce($_POST['_wpnonce'], 'mintmanagersavenonce')) {
        // Add metadata columns to existing table if needed
        CardanoMintPay\Models\MintModel::add_metadata_columns();
        // Add multi-asset columns to existing table if needed
        CardanoMintPay\Models\MintModel::add_multi_asset_columns();

        // Debug: Log what we're receiving from the form
        cardanomint_log("=== MINT MANAGER FORM SUBMISSION ===");
        cardanomint_log("Policy ID from form: " . ($_POST['cardanonftpolicyid'] ?? 'NOT SET'));
        cardanomint_log("Policy JSON present: " . (isset($_POST['cardanonftpolicyjson']) ? 'YES' : 'NO'));
        if (isset($_POST['cardanonftpolicyjson'])) {
            cardanomint_log("Policy JSON length: " . strlen($_POST['cardanonftpolicyjson']));
            cardanomint_log("Policy JSON preview: " . substr($_POST['cardanonftpolicyjson'], 0, 200));
        }

        // Determine if this is a new policy or adding to existing
        $policyMode = sanitize_text_field($_POST['policy_mode'] ?? 'new');
        $existingCollectionId = isset($_POST['existing_collection_id']) ? intval($_POST['existing_collection_id']) : null;

        // Validate and decode the policy JSON to ensure it's valid
        $policy_json_raw = isset($_POST['cardanonftpolicyjson']) ? stripslashes($_POST['cardanonftpolicyjson']) : null;
        $policy_json_validated = null;

        if ($policy_json_raw) {
            $decoded = json_decode($policy_json_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['policyId']) && isset($decoded['schema'])) {
                // Re-encode to ensure clean JSON
                $policy_json_validated = wp_json_encode($decoded);
                cardanomint_log("Policy JSON validated successfully");
            } else {
                cardanomint_log("Policy JSON validation FAILED: " . json_last_error_msg(), 'error');
            }
        }

        // Build base mint data
        $mintData = [
            'title' => sanitize_text_field($_POST['cardanonfttitle'] ?? ''),
            'asset_name' => sanitize_text_field($_POST['cardanonftassetname'] ?? ''),
            'policyid' => sanitize_text_field($_POST['cardanonftpolicyid'] ?? ''),
            'expirationdate' => sanitize_text_field($_POST['cardanonftexpirationdate'] ?? ''),
            'unlimited' => isset($_POST['cardanonftunlimitedmint']) ? 1 : 0,
            'mintsallowedperwallet' => isset($_POST['cardanonftmintsallowedperwallet']) ? intval($_POST['cardanonftmintsallowedperwallet']) : 0,
            'price' => isset($_POST['cardanonftprice']) ? floatval($_POST['cardanonftprice']) : 60.00,
            'royalty' => sanitize_text_field($_POST['cardanonftroyaltyamount'] ?? ''),
            'royaltyaddress' => sanitize_text_field($_POST['cardanonftroyaltyaddress'] ?? ''),
            'image_id' => isset($_POST['cardanonftimageid']) && !empty($_POST['cardanonftimageid']) ? intval($_POST['cardanonftimageid']) : null,
            'collection_image_id' => isset($_POST['cardanonftcollectionimageid']) && !empty($_POST['cardanonftcollectionimageid']) ? intval($_POST['cardanonftcollectionimageid']) : null,
            'preview_image_id' => isset($_POST['cardanonftpreviewimageid']) && !empty($_POST['cardanonftpreviewimageid']) ? intval($_POST['cardanonftpreviewimageid']) : null,
            'preview_ipfs_cid_manual' => isset($_POST['preview_ipfs_cid_manual']) ? sanitize_text_field($_POST['preview_ipfs_cid_manual']) : null,
            'preview_media_type' => isset($_POST['preview_media_type']) ? sanitize_text_field($_POST['preview_media_type']) : null,
            'nft_metadata' => isset($_POST['cardanonftnftmetadata']) ? stripslashes($_POST['cardanonftnftmetadata']) : null,
            'policy_json' => $policy_json_validated,  // Validated JSON
            'quantity_total' => isset($_POST['cardanonftquantity']) ? intval($_POST['cardanonftquantity']) : 1,
            'status' => 'Active',
            'ipfs_cid' => isset($_POST['ipfs_cid']) ? sanitize_text_field($_POST['ipfs_cid']) : null,  // From Pinata pin button
            'ipfs_cid_manual' => isset($_POST['ipfs_cid_manual']) ? sanitize_text_field($_POST['ipfs_cid_manual']) : null,  // From manual paste input
            'media_type' => isset($_POST['media_type']) ? sanitize_text_field($_POST['media_type']) : null  // Format selector (used when ipfs_cid_manual is set)
        ];

        // Handle variant and collection_id based on mode
        if ($policyMode === 'existing' && $existingCollectionId) {
            // Adding to existing policy
            $mintData['collection_id'] = $existingCollectionId;
            $mintData['variant'] = CardanoMintPay\Models\MintModel::getNextVariant($existingCollectionId);

            // Inherit collection_image_id from parent variant (variant A)
            $parentVariant = CardanoMintPay\Models\MintModel::getMintById($existingCollectionId);
            if ($parentVariant && !empty($parentVariant['collection_image_id'])) {
                $mintData['collection_image_id'] = intval($parentVariant['collection_image_id']);
                cardanomint_log("Inheriting collection_image_id {$mintData['collection_image_id']} from parent variant A");
            }

            cardanomint_log("Adding variant {$mintData['variant']} to existing collection {$existingCollectionId}");
        } else {
            // Creating new policy - variant will be 'A', collection_id will be set to its own id after insert
            $mintData['collection_id'] = null;  // Will be set in insert_active_mint
            $mintData['variant'] = 'A';
            cardanomint_log("Creating new policy with first variant A");
        }

        cardanomint_log("Mint data being saved: " . print_r($mintData, true));

        // Check if this is an edit operation
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            $editId = intval($_POST['edit_id']);
            $result = CardanoMintPay\Models\MintModel::update_active_mint($editId, $mintData);
            if ($result !== false) {
                cardanomint_log("Asset updated successfully with ID: " . $editId);
                $message = 'Asset updated successfully!';
            } else {
                cardanomint_log("ERROR: Failed to update asset", 'error');
                $message = 'Error updating asset.';
            }
        } else {
            // Creating new asset
            $result = CardanoMintPay\Models\MintModel::insert_active_mint($mintData);
            if ($result) {
                cardanomint_log("Asset created successfully with ID: " . $result);
                $message = 'Asset created successfully!';
            } else {
                cardanomint_log("ERROR: Failed to create asset", 'error');
                $message = 'Error creating asset.';
            }
        }
        cardanomint_log("=== END MINT MANAGER FORM SUBMISSION ===");
    }
    
    include CARDANO_MINT_PLUGIN_DIR . 'includes/views/mint-manager.php';
}

/**
 * Split active mints into [manageable, orphaned]. A mint is manageable if its
 * policy_json keyhash matches the active policy wallet OR its policy has an
 * imported signing key on file (skey-backed policies don't need a generated
 * wallet — the saved skey signs at submit time).
 */
function cardanomint_partition_mints($all_mints, $active_wallet, $skey_policy_ids) {
    $mints = [];
    $orphaned = [];
    $active_keyhash = $active_wallet ? ($active_wallet['payment_keyhash'] ?? '') : '';
    foreach ((array) $all_mints as $mint) {
        $kh = CardanoMintPay\Models\MintModel::extractKeyhashFromPolicyJson($mint['policy_json'] ?? '');
        $matches_wallet = $active_keyhash && $kh === $active_keyhash;
        $has_skey = in_array(strtolower((string) ($mint['policyid'] ?? '')), $skey_policy_ids, true);
        if ($matches_wallet || $has_skey) {
            $mints[] = $mint;
        } else {
            $orphaned[] = $mint;
        }
    }
    return [$mints, $orphaned];
}

function cardanomint_active_mints_page() {
    // Get current network and active wallet
    $network = get_option('cardano-mint-networkenvironment', 'preprod');
    $active_wallet = CardanoMintPay\Models\MintModel::getActivePolicyWallet($network);

    // Policies whose signing key was imported (skey+script / manual). These are
    // mintable without a generated policy wallet — the saved skey overrides it
    // at submit time — so they must NOT be treated as orphaned here.
    $skey_policy_ids = CardanoMintPay\Models\MintModel::getImportedSkeyPolicyIds();

    // Filter mints by active wallet's keyhash (the "cartridge save file" logic),
    // PLUS any policy backed by an imported skey.
    list($mints, $orphaned_mints) = cardanomint_partition_mints(
        CardanoMintPay\Models\MintModel::get_active_mints(),
        $active_wallet,
        $skey_policy_ids
    );

    // Count only policies from active wallet (not orphaned)
    $slots_used = CardanoMintPay\Models\MintModel::countActivePoliciesFromActiveWallets($network);
    $archived_count = CardanoMintPay\Models\MintModel::count_archived_policies();
    $max_slots = 10; // You can make this configurable
    $slots_left = $max_slots - $slots_used;
    $admin_url = admin_url();
    
    // Mint updates removed - table is now read-only for security
    
    // Handle mint deletion
    if (isset($_GET['deletemint'])) {
        $deleted = CardanoMintPay\Models\MintModel::delete_active_mint($_GET['deletemint']);
        if ($deleted) {
            wp_redirect(admin_url('admin.php?page=cardano-mint-page-2&deleted=1'));
            exit;
        }
    }
    
    // Handle delete all mints
    if (isset($_GET['deleteallmints']) && $_GET['deleteallmints'] == '1') {
        CardanoMintPay\Models\MintModel::delete_all_active_mints();
        wp_redirect(admin_url('admin.php?page=cardano-mint-page-2&deleted=1'));
        exit;
    }
    
    // Reload mints after any changes (need to re-filter by wallet + skey).
    list($mints, $orphaned_mints) = cardanomint_partition_mints(
        CardanoMintPay\Models\MintModel::get_active_mints(),
        $active_wallet,
        $skey_policy_ids
    );

    // Recalculate slots after deletion
    $slots_used = CardanoMintPay\Models\MintModel::countActivePoliciesFromActiveWallets($network);
    $slots_left = $max_slots - $slots_used;

    include CARDANO_MINT_PLUGIN_DIR . 'includes/views/active-mints-list.php';
}

