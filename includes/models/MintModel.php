<?php
namespace CardanoMintPay\Models;

class MintModel {
    // Table names
    public static function get_active_mints_table() {
        global $wpdb;
        return $wpdb->prefix . 'cardanonftactivemints';
    }

    public static function get_mint_counts_table() {
        global $wpdb;
        return $wpdb->prefix . 'cardanonftmintcounts';
    }

    public static function get_policy_wallets_table() {
        global $wpdb;
        return $wpdb->prefix . 'cardano_policy_wallets';
    }

    public static function get_mint_wallets_table() {
        global $wpdb;
        return $wpdb->prefix . 'cardano_mint_wallets';
    }

    public static function get_mint_policies_table() {
        global $wpdb;
        return $wpdb->prefix . 'cardano_mint_policies';
    }

    // Create active mints table on install (WITH METADATA COLUMNS + MULTI-ASSET SUPPORT)
    public static function install_active_mints_table() {
        global $wpdb;
        $table = self::get_active_mints_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            collection_id int(11) unsigned DEFAULT NULL,
            variant varchar(10) DEFAULT NULL,
            title varchar(255) NOT NULL,
            asset_name varchar(255) DEFAULT NULL,
            policyid varchar(64) NOT NULL,
            expirationdate datetime,
            unlimited tinyint(1) NOT NULL DEFAULT 0,
            mintsallowedperwallet int(11) unsigned NOT NULL DEFAULT 0,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            royalty varchar(20),
            royaltyaddress varchar(128),
            royalty_token_minted tinyint(1) NOT NULL DEFAULT 0,
            image_id int(11) unsigned DEFAULT NULL,
            ipfs_cid varchar(255) DEFAULT NULL,
            ipfs_cid_manual varchar(255) DEFAULT NULL,
            collection_image_id int(11) unsigned DEFAULT NULL,
            nft_metadata TEXT,
            policy_json TEXT,
            quantity_total int(11) unsigned NOT NULL DEFAULT 1,
            quantity_minted int(11) unsigned NOT NULL DEFAULT 0,
            status varchar(20),
            PRIMARY KEY (id),
            INDEX idx_collection_id (collection_id),
            INDEX idx_policyid (policyid),
            INDEX idx_variant (variant)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Create mint counts table on install
    public static function install_mint_counts_table() {
        global $wpdb;
        $table = self::get_mint_counts_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            policyid varchar(64) NOT NULL,
            walletaddress varchar(128) NOT NULL,
            mintcount int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY policyid_walletaddress (policyid, walletaddress)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Create policy wallets table on install
    public static function install_policy_wallets_table() {
        global $wpdb;
        $table = self::get_policy_wallets_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            wallet_name varchar(255) NOT NULL DEFAULT 'Default Policy Wallet',
            mnemonic_encrypted TEXT NOT NULL,
            skey_encrypted TEXT NOT NULL,
            payment_address varchar(128) NOT NULL,
            payment_keyhash varchar(64) NOT NULL,
            stake_address varchar(128),
            network varchar(20) NOT NULL DEFAULT 'preprod',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_network (network)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Add archived column if it doesn't exist (migration)
        $archived_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'archived'");
        if (empty($archived_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN archived tinyint(1) NOT NULL DEFAULT 0 AFTER network");
            $wpdb->query("ALTER TABLE $table ADD COLUMN archived_at datetime AFTER archived");
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_archived (archived)");
            error_log("Cardano Mint: Added 'archived' columns to policy wallets table");
        }

        // Add source column if it doesn't exist (migration for advanced import)
        $source_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'source'");
        if (empty($source_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN source varchar(20) NOT NULL DEFAULT 'generated' AFTER archived_at");
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN mnemonic_encrypted TEXT DEFAULT NULL");
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN payment_address varchar(128) DEFAULT ''");
            error_log("Cardano Mint: Added 'source' column and made mnemonic_encrypted nullable");
        }
    }

    // Create mint wallets tracking table on install
    public static function install_mint_wallets_table() {
        global $wpdb;
        $table = self::get_mint_wallets_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            policy_id varchar(64) NOT NULL,
            payment_address varchar(128) NOT NULL,
            stake_address varchar(128),
            total_allowed int(11) unsigned NOT NULL DEFAULT 1,
            total_minted int(11) unsigned NOT NULL DEFAULT 0,
            remaining int(11) unsigned NOT NULL,
            first_mint_date datetime,
            last_mint_date datetime,
            PRIMARY KEY (id),
            UNIQUE KEY unique_policy_wallet (policy_id, payment_address),
            INDEX idx_policy_id (policy_id),
            INDEX idx_payment_address (payment_address),
            INDEX idx_stake_address (stake_address)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Add image_id column to existing table (legacy support)
    public static function add_image_id_column() {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Check if column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'image_id'");

        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN image_id int(11) unsigned DEFAULT NULL AFTER royaltyaddress");
        }
    }

    /**
     * Add preview-image columns for video/audio mints (still thumbnail
     * shown in wallet/marketplace previews while files[] carries the
     * actual video). Idempotent — only adds columns if missing.
     */
    public static function add_preview_image_columns() {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Append columns at the end — no AFTER clause — so the migration
        // works regardless of which prior columns exist on a given install.
        $col1 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'preview_image_id'");
        if (empty($col1)) {
            $r = $wpdb->query("ALTER TABLE $table ADD COLUMN preview_image_id int(11) unsigned DEFAULT NULL");
            if ($r === false) error_log('[CardanoMint] add preview_image_id FAILED: ' . $wpdb->last_error);
        }

        $col2 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'preview_ipfs_cid_manual'");
        if (empty($col2)) {
            $r = $wpdb->query("ALTER TABLE $table ADD COLUMN preview_ipfs_cid_manual varchar(60) DEFAULT NULL");
            if ($r === false) error_log('[CardanoMint] add preview_ipfs_cid_manual FAILED: ' . $wpdb->last_error);
        }

        $col3 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'preview_media_type'");
        if (empty($col3)) {
            $r = $wpdb->query("ALTER TABLE $table ADD COLUMN preview_media_type varchar(50) DEFAULT NULL");
            if ($r === false) error_log('[CardanoMint] add preview_media_type FAILED: ' . $wpdb->last_error);
        }

        // Asset mime override — same idea as preview_media_type, but for
        // the NFT image when supplied via a manual IPFS CID. WP attachments
        // continue to auto-detect via get_post_mime_type(), but a pasted
        // CID has no inherent mime, so the operator selects it.
        $col4 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'media_type'");
        if (empty($col4)) {
            $r = $wpdb->query("ALTER TABLE $table ADD COLUMN media_type varchar(50) DEFAULT NULL");
            if ($r === false) error_log('[CardanoMint] add media_type FAILED: ' . $wpdb->last_error);
        }
    }

    /**
     * Add metadata and policy JSON columns to existing table
     */
    public static function add_metadata_columns() {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Check if nft_metadata column exists
        $metadata_column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'nft_metadata'");
        if (empty($metadata_column_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN nft_metadata TEXT AFTER image_id");
        }

        // Check if policy_json column exists
        $policy_column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'policy_json'");
        if (empty($policy_column_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN policy_json TEXT AFTER nft_metadata");
        }
    }

    /**
     * Add multi-asset columns to existing table (for upgrades)
     */
    public static function add_multi_asset_columns() {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Add collection_id column
        $collection_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'collection_id'");
        if (empty($collection_id_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN collection_id int(11) unsigned DEFAULT NULL AFTER id");
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_collection_id (collection_id)");
        }

        // Add variant column
        $variant_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'variant'");
        if (empty($variant_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN variant varchar(10) DEFAULT NULL AFTER collection_id");
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_variant (variant)");
        }

        // Add asset_name column
        $asset_name_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'asset_name'");
        if (empty($asset_name_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN asset_name varchar(255) DEFAULT NULL AFTER title");
        }

        // Add quantity_total column
        $quantity_total_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'quantity_total'");
        if (empty($quantity_total_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN quantity_total int(11) unsigned NOT NULL DEFAULT 1 AFTER policy_json");
        }

        // Add quantity_minted column
        $quantity_minted_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'quantity_minted'");
        if (empty($quantity_minted_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN quantity_minted int(11) unsigned NOT NULL DEFAULT 0 AFTER quantity_total");
        }

        // Add collection_image_id column (for mystery box/collection display)
        $collection_image_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'collection_image_id'");
        if (empty($collection_image_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN collection_image_id int(11) unsigned DEFAULT NULL AFTER image_id");
        }

        // Add ipfs_cid column (for Pinata IPFS storage)
        $ipfs_cid_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'ipfs_cid'");
        if (empty($ipfs_cid_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN ipfs_cid varchar(255) DEFAULT NULL AFTER image_id");
        }

        // Add ipfs_cid_manual column (for manually pasted IPFS hash)
        $ipfs_cid_manual_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'ipfs_cid_manual'");
        if (empty($ipfs_cid_manual_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN ipfs_cid_manual varchar(255) DEFAULT NULL AFTER ipfs_cid");
        }

        // Add royalty_token_minted column (for CIP-27 royalty token tracking)
        $royalty_token_minted_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'royalty_token_minted'");
        if (empty($royalty_token_minted_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN royalty_token_minted tinyint(1) NOT NULL DEFAULT 0 AFTER royaltyaddress");
        }

        // Add archived column (for archive system)
        $archived_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'archived'");
        if (empty($archived_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN archived tinyint(1) NOT NULL DEFAULT 0 AFTER status");
            error_log("Cardano Mint: Added 'archived' column to active mints table");
        }

        // Add index on policyid if not exists
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'idx_policyid'");
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_policyid (policyid)");
        }

        // Add index on archived if not exists (for performance)
        $archived_index = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'idx_archived'");
        if (empty($archived_index)) {
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_archived (archived)");
            error_log("Cardano Mint: Added index on 'archived' column");
        }
    }

    // Insert new active mint (WITH METADATA, POLICY JSON, AND MULTI-ASSET SUPPORT)
    public static function insert_active_mint($mintData) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Just-in-time migration safety net: if any of the newer columns
        // are missing on this install (e.g. plugin updated but admin_init
        // hasn't fired yet), add them before we try to write to them.
        // Idempotent — checks existence before each ALTER.
        self::add_preview_image_columns();

        $insertData = [
            'collection_id' => isset($mintData['collection_id']) ? intval($mintData['collection_id']) : null,
            'variant' => isset($mintData['variant']) ? $mintData['variant'] : null,
            'title' => $mintData['title'],
            'asset_name' => isset($mintData['asset_name']) ? $mintData['asset_name'] : null,
            'policyid' => $mintData['policyid'],
            'expirationdate' => $mintData['expirationdate'],
            'unlimited' => $mintData['unlimited'],
            'mintsallowedperwallet' => isset($mintData['mintsallowedperwallet']) ? intval($mintData['mintsallowedperwallet']) : 0,
            'price' => isset($mintData['price']) ? floatval($mintData['price']) : 0.00,
            'royalty' => $mintData['royalty'],
            'royaltyaddress' => $mintData['royaltyaddress'],
            'image_id' => isset($mintData['image_id']) ? intval($mintData['image_id']) : null,
            'ipfs_cid' => isset($mintData['ipfs_cid']) ? $mintData['ipfs_cid'] : null,
            'ipfs_cid_manual' => isset($mintData['ipfs_cid_manual']) ? $mintData['ipfs_cid_manual'] : null,
            'media_type' => isset($mintData['media_type']) ? $mintData['media_type'] : null,
            'collection_image_id' => isset($mintData['collection_image_id']) ? intval($mintData['collection_image_id']) : null,
            'preview_image_id' => isset($mintData['preview_image_id']) ? intval($mintData['preview_image_id']) : null,
            'preview_ipfs_cid_manual' => isset($mintData['preview_ipfs_cid_manual']) ? $mintData['preview_ipfs_cid_manual'] : null,
            'preview_media_type' => isset($mintData['preview_media_type']) ? $mintData['preview_media_type'] : null,
            'nft_metadata' => isset($mintData['nft_metadata']) ? $mintData['nft_metadata'] : null,
            'policy_json' => isset($mintData['policy_json']) ? $mintData['policy_json'] : null,
            'quantity_total' => isset($mintData['quantity_total']) ? intval($mintData['quantity_total']) : 1,
            'quantity_minted' => isset($mintData['quantity_minted']) ? intval($mintData['quantity_minted']) : 0,
            'status' => $mintData['status']
        ];

        $insert_result = $wpdb->insert($table, $insertData);
        if ($insert_result === false) {
            error_log('[CardanoMint] insert_active_mint FAILED. last_error=' . $wpdb->last_error);
            error_log('[CardanoMint] insert payload keys=' . implode(',', array_keys($insertData)));
        }
        $insertId = $wpdb->insert_id;

        // If this is a new collection (no collection_id provided), set collection_id to its own id
        if (empty($mintData['collection_id']) && $insertId) {
            $wpdb->update($table, ['collection_id' => $insertId], ['id' => $insertId]);
        }

        return $insertId;
    }

    // Update an active mint
    public static function update_active_mint($id, $mint) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Just-in-time column safety net (see note in insert_active_mint).
        self::add_preview_image_columns();

        $updateData = [
            'title' => $mint['title'],
            'asset_name' => isset($mint['asset_name']) ? $mint['asset_name'] : null,
            'policyid' => $mint['policyid'],
            'expirationdate' => $mint['expirationdate'],
            'unlimited' => $mint['unlimited'],
            'mintsallowedperwallet' => isset($mint['mintsallowedperwallet']) ? intval($mint['mintsallowedperwallet']) : 0,
            'price' => isset($mint['price']) ? floatval($mint['price']) : 0.00,
            'royalty' => $mint['royalty'],
            'royaltyaddress' => $mint['royaltyaddress'],
            'image_id' => isset($mint['image_id']) ? intval($mint['image_id']) : null,
            'ipfs_cid' => isset($mint['ipfs_cid']) ? $mint['ipfs_cid'] : null,
            'ipfs_cid_manual' => isset($mint['ipfs_cid_manual']) ? $mint['ipfs_cid_manual'] : null,
            'media_type' => isset($mint['media_type']) ? $mint['media_type'] : null,
            'collection_image_id' => isset($mint['collection_image_id']) ? intval($mint['collection_image_id']) : null,
            'preview_image_id' => isset($mint['preview_image_id']) ? intval($mint['preview_image_id']) : null,
            'preview_ipfs_cid_manual' => isset($mint['preview_ipfs_cid_manual']) ? $mint['preview_ipfs_cid_manual'] : null,
            'preview_media_type' => isset($mint['preview_media_type']) ? $mint['preview_media_type'] : null,
            'nft_metadata' => isset($mint['nft_metadata']) ? $mint['nft_metadata'] : null,
            'policy_json' => isset($mint['policy_json']) ? $mint['policy_json'] : null,
            'quantity_total' => isset($mint['quantity_total']) ? intval($mint['quantity_total']) : 1,
            'status' => $mint['status']
        ];

        // Don't allow updating collection_id, variant, or quantity_minted via this method
        // Those should be managed separately

        // Update the specific asset
        $result = $wpdb->update($table, $updateData, ['id' => $id]);
        if ($result === false) {
            error_log('[CardanoMint] update_active_mint FAILED. id=' . $id . ' last_error=' . $wpdb->last_error);
            error_log('[CardanoMint] update payload keys=' . implode(',', array_keys($updateData)));
        }

        // If this is variant A, cascade policy-level changes to all other variants
        $currentAsset = self::getMintById($id);
        if ($currentAsset && $currentAsset['variant'] === 'A') {
            error_log("Variant A updated - cascading policy-level changes to all variants with policy: " . $currentAsset['policyid']);

            // Policy-level fields that should cascade to all variants
            $policyLevelUpdates = [
                'title' => $mint['title'],
                'price' => isset($mint['price']) ? floatval($mint['price']) : 0.00,
                'royalty' => $mint['royalty'],
                'royaltyaddress' => $mint['royaltyaddress'],
                'mintsallowedperwallet' => isset($mint['mintsallowedperwallet']) ? intval($mint['mintsallowedperwallet']) : 0,
                'expirationdate' => $mint['expirationdate'],
                'unlimited' => $mint['unlimited'],
                'policy_json' => isset($mint['policy_json']) ? $mint['policy_json'] : null
            ];

            // Update all other variants with the same policy ID
            $updated = $wpdb->update(
                $table,
                $policyLevelUpdates,
                [
                    'policyid' => $currentAsset['policyid'],
                    'id' => ['!=', $id] // Exclude the current asset (already updated)
                ],
                ['%s', '%f', '%s', '%s', '%d', '%s', '%d', '%s'],
                ['%s', '%d']
            );

            // WordPress doesn't support != in where clause directly, so we need a raw query
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET
                        title = %s,
                        price = %f,
                        royalty = %s,
                        royaltyaddress = %s,
                        mintsallowedperwallet = %d,
                        expirationdate = %s,
                        unlimited = %d,
                        policy_json = %s
                    WHERE policyid = %s AND id != %d",
                    $policyLevelUpdates['title'],
                    $policyLevelUpdates['price'],
                    $policyLevelUpdates['royalty'],
                    $policyLevelUpdates['royaltyaddress'],
                    $policyLevelUpdates['mintsallowedperwallet'],
                    $policyLevelUpdates['expirationdate'],
                    $policyLevelUpdates['unlimited'],
                    $policyLevelUpdates['policy_json'],
                    $currentAsset['policyid'],
                    $id
                )
            );

            error_log("Policy-level updates cascaded to all variants with policy ID: " . $currentAsset['policyid']);
        }

        return $result;
    }

    // Delete an active mint
    public static function delete_active_mint($id) {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->delete($table, ['id' => intval($id)]);
    }

    // Get all active mints (non-archived), sorted by most recent
    public static function get_active_mints() {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->get_results("SELECT * FROM $table WHERE archived = 0 ORDER BY id DESC", ARRAY_A);
    }

    // Count slots used (number of unique active policies)
    public static function count_active_mints() {
        global $wpdb;
        $table = self::get_active_mints_table();
        return (int) $wpdb->get_var("SELECT COUNT(DISTINCT policyid) FROM $table WHERE archived = 0");
    }

    // Alias for clearer naming (count unique active policies)
    public static function count_active_policies() {
        return self::count_active_mints();
    }

    // Delete all mints
    public static function delete_all_active_mints() {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->query("DELETE FROM $table");
    }

    // ============================================
    // Archive System Functions
    // ============================================

    /**
     * Archive an entire policy (all variants)
     * @param string $policy_id The policy ID to archive
     * @return int|false Number of rows updated, or false on error
     */
    public static function archivePolicy($policy_id) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Check 100 archived limit
        if (self::count_archived_policies() >= 100) {
            return new \WP_Error('archive_limit', 'Maximum 100 archived policies. Delete some before archiving more.');
        }

        return $wpdb->update(
            $table,
            ['archived' => 1],
            ['policyid' => $policy_id],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Unarchive an entire policy (all variants)
     * @param string $policy_id The policy ID to unarchive
     * @return int|false|WP_Error Number of rows updated, false on error, or WP_Error if slot limit reached
     */
    public static function unarchivePolicy($policy_id) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Check if slots available
        if (self::count_active_policies() >= 10) {
            return new \WP_Error('slot_limit', 'Cannot unarchive: Maximum 10 active policies. Archive or delete another policy first.');
        }

        return $wpdb->update(
            $table,
            ['archived' => 0],
            ['policyid' => $policy_id],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Get all archived policies
     * @return array Array of archived policy data
     */
    public static function get_archived_policies() {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->get_results(
            "SELECT * FROM $table WHERE archived = 1 ORDER BY id DESC",
            ARRAY_A
        );
    }

    /**
     * Count number of unique archived policies
     * @return int Number of archived policies
     */
    public static function count_archived_policies() {
        global $wpdb;
        $table = self::get_active_mints_table();
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT policyid) FROM $table WHERE archived = 1"
        );
    }

    /**
     * Check if a policy is archived
     * @param string $policy_id The policy ID to check
     * @return bool True if archived, false otherwise
     */
    public static function isPolicyArchived($policy_id) {
        global $wpdb;
        $table = self::get_active_mints_table();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE policyid = %s AND archived = 1",
            $policy_id
        ));
        return (int)$count > 0;
    }

    // Get mint by ID
    public static function getMintById($mintId) {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($mintId)),
            ARRAY_A
        );
    }

    /**
     * Mint NFT (DEPRECATED - Legacy method)
     * 
     * This method is deprecated and no longer used in the current minting flow.
     * The current flow uses Anvil API endpoints:
     * - cardano_build_mint_transaction
     * - cardano_submit_mint_transaction
     * 
     * This method is kept for backward compatibility only.
     * 
     * @deprecated Use AnvilAPI::buildMintTransaction() and AnvilAPI::submitTransaction() instead
     */
    public static function mintNFT($policyId, $wallet, $metadata, $price) {
        // Return error indicating this method is deprecated
        return [
            'success' => false,
            'error' => 'This minting method is deprecated. Please use the current Anvil API flow.'
        ];
    }

    // Get NFT price (live CoinGecko integration) - DEPRECATED, use AnvilAPI::getAdaPrice()
    public static function getNFTPrice() {
        return \CardanoMintPay\Helpers\AnvilAPI::getAdaPrice();
    }
    
    /**
     * Check mint limits for a wallet/policy combination
     */
    public static function checkMintLimits($policyId, $wallet) {
        global $wpdb;
        $activeMintsTable = self::get_active_mints_table();
        $mintCountsTable = self::get_mint_counts_table();

        error_log("checkMintLimits called with policyId: " . $policyId . ", wallet: " . $wallet);

        // Fetch mint configuration
        $mintConfig = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT mintsallowedperwallet, unlimited FROM $activeMintsTable WHERE policyid = %s AND status = 'Active' ORDER BY id DESC LIMIT 1",
                $policyId
            ),
            ARRAY_A
        );

        error_log("Mint config query result: " . print_r($mintConfig, true));

        if (!$mintConfig) {
            error_log("No mint configuration found for policy: " . $policyId);
            return [
                'success' => false,
                'error' => 'Mint configuration not found for this policy.'
            ];
        }

        $perWalletLimit = intval($mintConfig['mintsallowedperwallet'] ?? 0);
        $isUnlimited = intval($mintConfig['unlimited'] ?? 0) == 1;

        error_log("perWalletLimit: " . $perWalletLimit . ", isUnlimited: " . ($isUnlimited ? 'true' : 'false'));

        // If unlimited mints are allowed, skip per-wallet limit check
        if ($isUnlimited) {
            error_log("Unlimited mints enabled, skipping per-wallet limit check");
            return ['success' => true];
        }

        // If no per-wallet limit is set, allow unlimited mints per wallet
        if ($perWalletLimit <= 0) {
            error_log("No per-wallet limit set, allowing unlimited mints per wallet");
            return ['success' => true];
        }

        // Check current mint count for this wallet+policy
        $currentCount = intval($wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(mintcount, 0) FROM $mintCountsTable WHERE policyid = %s AND walletaddress = %s",
                $policyId,
                $wallet
            )
        ));

        if ($currentCount >= $perWalletLimit) {
            return [
                'success' => false,
                'error' => "You've reached the maximum number of allowable mints ({$perWalletLimit}). You currently have {$currentCount} mints."
            ];
        }

        return ['success' => true];
    }
    
    /**
     * Increment mint count for a wallet/policy combination
     */
    public static function incrementMintCount($policyId, $wallet) {
        global $wpdb;
        $mintCountsTable = self::get_mint_counts_table();

        // Insert or update the mint count (upsert)
        return $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $mintCountsTable (policyid, walletaddress, mintcount) VALUES (%s, %s, %d)
                ON DUPLICATE KEY UPDATE mintcount = mintcount + 1",
                $policyId,
                $wallet,
                1
            )
        );
    }

    /**
     * Get all assets for a collection_id
     */
    public static function getAssetsByCollectionId($collectionId) {
        global $wpdb;
        $table = self::get_active_mints_table();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE collection_id = %d ORDER BY variant ASC", intval($collectionId)),
            ARRAY_A
        );
    }

    /**
     * Get next variant letter for a collection
     */
    public static function getNextVariant($collectionId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        $variants = $wpdb->get_col(
            $wpdb->prepare("SELECT variant FROM $table WHERE collection_id = %d AND variant IS NOT NULL ORDER BY variant ASC", intval($collectionId))
        );

        // If no variants yet, start with 'A'
        if (empty($variants)) {
            return 'A';
        }

        // Find the next available letter
        $lastVariant = end($variants);
        return chr(ord($lastVariant) + 1);
    }

    /**
     * Get all unique policies (grouped by policyid)
     */
    public static function getAllPolicies() {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Get the first asset of each policy (ordered by variant to get 'A' first, or earliest if no 'A')
        // This uses a subquery to get only the first asset per policy
        return $wpdb->get_results("
            SELECT t1.*,
                   (SELECT COUNT(*) FROM $table t2 WHERE t2.policyid = t1.policyid) as asset_count
            FROM $table t1
            INNER JOIN (
                SELECT MIN(id) as min_id, policyid
                FROM $table
                GROUP BY policyid
            ) t2 ON t1.id = t2.min_id
            ORDER BY t1.id DESC
        ", ARRAY_A);
    }

    /**
     * Get all assets grouped by policy
     */
    public static function getAssetsGroupedByPolicy() {
        global $wpdb;
        $table = self::get_active_mints_table();

        return $wpdb->get_results("
            SELECT * FROM $table
            ORDER BY policyid ASC, variant ASC
        ", ARRAY_A);
    }

    /**
     * Decrement quantity after a successful mint
     */
    public static function decrementQuantity($assetId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET quantity_minted = quantity_minted + 1 WHERE id = %d AND quantity_minted < quantity_total",
                intval($assetId)
            )
        );
    }

    /**
     * Get available assets for a collection (has quantity remaining)
     */
    public static function getAvailableAssets($collectionId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE collection_id = %d AND quantity_minted < quantity_total AND status = 'Active' ORDER BY variant ASC",
                intval($collectionId)
            ),
            ARRAY_A
        );
    }

    /**
     * Get a specific variant asset
     */
    public static function getAssetByCollectionAndVariant($collectionId, $variant) {
        global $wpdb;
        $table = self::get_active_mints_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE collection_id = %d AND variant = %s LIMIT 1",
                intval($collectionId),
                $variant
            ),
            ARRAY_A
        );
    }

    /**
     * Select random asset based on weighted probability (quantity-based)
     */
    public static function selectWeightedRandomAsset($collectionId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Get all available assets with remaining quantity
        $assets = self::getAvailableAssets($collectionId);

        if (empty($assets)) {
            return null;
        }

        // Build weighted array based on remaining quantity
        $weightedAssets = [];
        foreach ($assets as $asset) {
            $remainingQty = $asset['quantity_total'] - $asset['quantity_minted'];
            // Add asset ID to array, repeated by remaining quantity
            for ($i = 0; $i < $remainingQty; $i++) {
                $weightedAssets[] = $asset['id'];
            }
        }

        // Pick random from weighted array
        $randomIndex = array_rand($weightedAssets);
        $selectedAssetId = $weightedAssets[$randomIndex];

        // Return the full asset data
        return self::getMintById($selectedAssetId);
    }

    /**
     * Check if CIP-27 royalty token has been minted for a policy
     */
    public static function hasRoyaltyTokenBeenMinted($policyId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT royalty_token_minted FROM $table WHERE policyid = %s LIMIT 1",
                $policyId
            )
        );

        return intval($result) === 1;
    }

    /**
     * Mark CIP-27 royalty token as minted for a policy
     */
    public static function markRoyaltyTokenMinted($policyId) {
        global $wpdb;
        $table = self::get_active_mints_table();

        return $wpdb->update(
            $table,
            ['royalty_token_minted' => 1],
            ['policyid' => $policyId],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Get policy wallet by network
     */
    public static function getPolicyWallet($network = 'preprod') {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE network = %s ORDER BY id DESC LIMIT 1",
                $network
            ),
            ARRAY_A
        );
    }

    /**
     * Insert new policy wallet
     */
    public static function insertPolicyWallet($walletData) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        $data = [
            'wallet_name' => $walletData['wallet_name'] ?? 'Default Policy Wallet',
            'skey_encrypted' => $walletData['skey_encrypted'],
            'payment_address' => $walletData['payment_address'] ?? '',
            'payment_keyhash' => $walletData['payment_keyhash'],
            'stake_address' => $walletData['stake_address'] ?? '',
            'network' => $walletData['network'],
            'source' => $walletData['source'] ?? 'generated',
            'created_at' => current_time('mysql')
        ];

        // mnemonic_encrypted is null for imported wallets
        if ( isset( $walletData['mnemonic_encrypted'] ) && $walletData['mnemonic_encrypted'] !== null ) {
            $data['mnemonic_encrypted'] = $walletData['mnemonic_encrypted'];
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Get policy wallet by payment keyhash (for duplicate checking on import)
     */
    public static function getPolicyWalletByKeyhash($keyhash, $network = null) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        if ( $network === null ) {
            $network = get_option( 'cardano-mint-networkenvironment', 'preprod' );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE payment_keyhash = %s AND network = %s AND archived = 0 LIMIT 1",
                $keyhash, $network
            ),
            ARRAY_A
        );
    }

    /**
     * Delete policy wallet
     */
    public static function deletePolicyWallet($id) {
        global $wpdb;
        $table = self::get_policy_wallets_table();
        return $wpdb->delete($table, ['id' => intval($id)]);
    }

    // ========================================
    // POLICY WALLET ARCHIVE FUNCTIONS
    // ========================================

    /**
     * Get active policy wallet for network (non-archived)
     */
    public static function getActivePolicyWallet($network = 'preprod') {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE network = %s AND archived = 0 ORDER BY id DESC LIMIT 1",
                $network
            ),
            ARRAY_A
        );
    }

    /**
     * Get all archived policy wallets
     */
    public static function getArchivedPolicyWallets($network = null) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        if ($network) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE archived = 1 AND network = %s ORDER BY archived_at DESC",
                    $network
                ),
                ARRAY_A
            );
        } else {
            return $wpdb->get_results(
                "SELECT * FROM $table WHERE archived = 1 ORDER BY archived_at DESC",
                ARRAY_A
            );
        }
    }

    /**
     * Count archived policy wallets
     */
    public static function countArchivedPolicyWallets($network = null) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        if ($network) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE archived = 1 AND network = %s",
                    $network
                )
            );
        } else {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE archived = 1"
            );
        }
    }

    /**
     * Archive a policy wallet
     * NOTE: This does NOT archive the policies - they stay "active" but unmintable until wallet is restored
     */
    public static function archivePolicyWallet($wallet_id) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        // Check 10 archived limit
        if (self::countArchivedPolicyWallets() >= 10) {
            return new \WP_Error('archive_limit', 'Maximum 10 archived policy wallets. Delete some before archiving more.');
        }

        return $wpdb->update(
            $table,
            [
                'archived' => 1,
                'archived_at' => current_time('mysql')
            ],
            ['id' => intval($wallet_id)],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Unarchive a policy wallet
     * Enforces 1 active wallet per network limit
     */
    public static function unarchivePolicyWallet($wallet_id) {
        global $wpdb;
        $table = self::get_policy_wallets_table();

        // Get the wallet being unarchived
        $wallet = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($wallet_id)),
            ARRAY_A
        );

        if (!$wallet) {
            return new \WP_Error('wallet_not_found', 'Wallet not found.');
        }

        // Check if another wallet is already active for this network
        $active_wallet = self::getActivePolicyWallet($wallet['network']);
        if ($active_wallet && $active_wallet['id'] != $wallet_id) {
            return new \WP_Error(
                'active_wallet_exists',
                'Cannot unarchive: Another wallet is already active for ' . $wallet['network'] . ' network. Archive it first.'
            );
        }

        return $wpdb->update(
            $table,
            [
                'archived' => 0,
                'archived_at' => null
            ],
            ['id' => intval($wallet_id)],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Extract keyhash from policy JSON
     * Parses the policy_json field and extracts the signature keyHash
     */
    public static function extractKeyhashFromPolicyJson($policy_json) {
        if (empty($policy_json)) {
            return null;
        }

        $policy = json_decode($policy_json, true);
        if (!$policy) {
            return null;
        }

        // Try schema.scripts first (new format)
        if (isset($policy['schema']['scripts']) && is_array($policy['schema']['scripts'])) {
            foreach ($policy['schema']['scripts'] as $script) {
                if (isset($script['type']) && $script['type'] === 'sig' && isset($script['keyHash'])) {
                    return $script['keyHash'];
                }
            }
        }

        // Try top-level scripts (old format fallback)
        if (isset($policy['scripts']) && is_array($policy['scripts'])) {
            foreach ($policy['scripts'] as $script) {
                if (isset($script['type']) && $script['type'] === 'sig' && isset($script['keyHash'])) {
                    return $script['keyHash'];
                }
            }
        }

        // Try top-level policyKeyHash (direct field fallback)
        if (isset($policy['policyKeyHash'])) {
            return $policy['policyKeyHash'];
        }

        return null;
    }

    /**
     * Get all policies that belong to a specific wallet (by keyhash matching)
     * This is the "cartridge save file" function - loads policies for a wallet
     */
    public static function getPoliciesByWalletKeyhash($keyhash, $include_archived = false) {
        global $wpdb;
        $table = self::get_active_mints_table();

        // Get all mints and filter by keyhash
        $archived_filter = $include_archived ? "" : "WHERE archived = 0";
        $all_mints = $wpdb->get_results(
            "SELECT * FROM $table $archived_filter ORDER BY id DESC",
            ARRAY_A
        );

        $matching_mints = [];
        foreach ($all_mints as $mint) {
            $mint_keyhash = self::extractKeyhashFromPolicyJson($mint['policy_json']);
            if ($mint_keyhash === $keyhash) {
                $matching_mints[] = $mint;
            }
        }

        return $matching_mints;
    }

    /**
     * Count policies belonging to a wallet
     */
    public static function countPoliciesByWalletKeyhash($keyhash, $include_archived = false) {
        $policies = self::getPoliciesByWalletKeyhash($keyhash, $include_archived);

        // Count unique policy IDs
        $unique_policies = [];
        foreach ($policies as $policy) {
            $unique_policies[$policy['policyid']] = true;
        }

        return count($unique_policies);
    }

    /**
     * Count ONLY policies from active wallets (not orphaned)
     * This is what should be shown in the slot counter
     */
    public static function countActivePoliciesFromActiveWallets($network = null) {
        global $wpdb;

        if (!$network) {
            $network = get_option('cardano-mint-networkenvironment', 'preprod');
        }

        // Get active wallet for network
        $active_wallet = self::getActivePolicyWallet($network);

        if (!$active_wallet) {
            return 0;
        }

        // Get all active mints and count those matching active wallet
        $all_mints = self::get_active_mints();
        $unique_policies = [];

        foreach ($all_mints as $mint) {
            $mint_keyhash = self::extractKeyhashFromPolicyJson($mint['policy_json']);
            if ($mint_keyhash === $active_wallet['payment_keyhash']) {
                $unique_policies[$mint['policyid']] = true;
            }
        }

        return count($unique_policies);
    }

    // ========================================
    // MINT WALLETS TRACKING FUNCTIONS
    // ========================================

    /**
     * Check if a policy ID already exists in active mints
     *
     * @param string $policy_id The policy ID to check
     * @return bool True if exists, false otherwise
     */
    public static function policyIdExists($policy_id) {
        global $wpdb;
        $table = self::get_active_mints_table();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE policyid = %s",
            $policy_id
        ));

        return $count > 0;
    }

    /**
     * Get mint wallet record for a specific policy and wallet address
     *
     * @param string $policy_id Policy ID
     * @param string $payment_address Customer payment address
     * @return array|null Record if exists, null otherwise
     */
    public static function getMintWalletRecord($policy_id, $payment_address) {
        global $wpdb;
        $table = self::get_mint_wallets_table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE policy_id = %s AND payment_address = %s",
            $policy_id,
            $payment_address
        ), ARRAY_A);
    }

    /**
     * Check if wallet can mint (has remaining mints available)
     *
     * @param string $policy_id Policy ID
     * @param string $payment_address Customer payment address
     * @param int $mints_allowed Total mints allowed per wallet (0 = unlimited)
     * @return array ['can_mint' => bool, 'remaining' => int, 'message' => string]
     */
    public static function canWalletMint($policy_id, $payment_address, $mints_allowed) {
        // If unlimited (0), always allow
        if ($mints_allowed === 0) {
            return [
                'can_mint' => true,
                'remaining' => -1, // -1 indicates unlimited
                'message' => 'Unlimited mints allowed'
            ];
        }

        $record = self::getMintWalletRecord($policy_id, $payment_address);

        // First time minter
        if (!$record) {
            return [
                'can_mint' => true,
                'remaining' => $mints_allowed,
                'message' => 'First mint for this wallet'
            ];
        }

        // Check remaining
        if ($record['remaining'] > 0) {
            return [
                'can_mint' => true,
                'remaining' => intval($record['remaining']),
                'message' => sprintf('You have %d mint(s) remaining', $record['remaining'])
            ];
        }

        return [
            'can_mint' => false,
            'remaining' => 0,
            'message' => sprintf('Mint limit reached. You have minted %d/%d NFTs from this collection.',
                $record['total_minted'],
                $record['total_allowed'])
        ];
    }

    /**
     * Record a mint attempt (creates or updates record)
     *
     * @param string $policy_id Policy ID
     * @param string $payment_address Customer payment address
     * @param string $stake_address Customer stake address (optional)
     * @param int $mints_allowed Total mints allowed per wallet
     * @return bool Success
     */
    public static function recordMint($policy_id, $payment_address, $stake_address, $mints_allowed) {
        global $wpdb;
        $table = self::get_mint_wallets_table();

        $record = self::getMintWalletRecord($policy_id, $payment_address);

        // First mint - create new record
        if (!$record) {
            $remaining = ($mints_allowed === 0) ? -1 : $mints_allowed - 1; // -1 for unlimited

            return $wpdb->insert($table, [
                'policy_id' => $policy_id,
                'payment_address' => $payment_address,
                'stake_address' => $stake_address,
                'total_allowed' => $mints_allowed,
                'total_minted' => 1,
                'remaining' => $remaining,
                'first_mint_date' => current_time('mysql'),
                'last_mint_date' => current_time('mysql')
            ]);
        }

        // Update existing record
        $new_total_minted = $record['total_minted'] + 1;
        $new_remaining = ($mints_allowed === 0) ? -1 : $record['remaining'] - 1;

        return $wpdb->update(
            $table,
            [
                'total_minted' => $new_total_minted,
                'remaining' => $new_remaining,
                'last_mint_date' => current_time('mysql')
            ],
            [
                'id' => $record['id']
            ]
        );
    }

    /**
     * Get all mint records for a policy (for export/viewing)
     *
     * @param string $policy_id Policy ID
     * @return array Array of records
     */
    public static function getMintHistoryForPolicy($policy_id) {
        global $wpdb;
        $table = self::get_mint_wallets_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE policy_id = %s ORDER BY first_mint_date DESC",
            $policy_id
        ), ARRAY_A);
    }

    /**
     * Get unique minter count for a policy
     *
     * @param string $policy_id Policy ID
     * @return int Number of unique wallets that have minted
     */
    public static function getUniqueMinterCount($policy_id) {
        global $wpdb;
        $table = self::get_mint_wallets_table();

        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE policy_id = %s",
            $policy_id
        )));
    }

    /**
     * Export mint history as CSV for a policy
     *
     * @param string $policy_id Policy ID
     * @param string $mint_title Mint title for header
     * @return string CSV content
     */
    public static function exportMintHistoryCSV($policy_id, $mint_title = '') {
        $records = self::getMintHistoryForPolicy($policy_id);

        // Build CSV with headers
        $csv = "Mint Title: " . ($mint_title ?: 'Untitled Mint') . "\n";
        $csv .= "Policy ID: $policy_id\n";
        $csv .= "Export Date: " . current_time('Y-m-d H:i:s') . "\n";
        $csv .= "Total Unique Minters: " . count($records) . "\n";
        $csv .= "\n";

        // Column headers
        $csv .= "Payment Address,Stake Address,Total Allowed,Total Minted,Remaining,First Mint Date,Last Mint Date\n";

        // Data rows
        foreach ($records as $record) {
            $csv .= sprintf(
                '"%s","%s",%d,%d,%d,"%s","%s"' . "\n",
                $record['payment_address'],
                $record['stake_address'] ?: '',
                $record['total_allowed'],
                $record['total_minted'],
                $record['remaining'],
                $record['first_mint_date'] ?: '',
                $record['last_mint_date'] ?: ''
            );
        }

        return $csv;
    }

    /**
     * Import mint whitelist from CSV (replaces all existing records for policy)
     *
     * @param string $policy_id Policy ID
     * @param string $csv_content CSV file content
     * @return array ['success' => bool, 'imported' => int, 'errors' => array]
     */
    public static function importMintWhitelistCSV($policy_id, $csv_content) {
        global $wpdb;
        $table = self::get_mint_wallets_table();

        $lines = explode("\n", $csv_content);
        $imported = 0;
        $errors = [];

        // Find the header row (skip metadata lines)
        $header_row_index = 0;
        foreach ($lines as $index => $line) {
            if (strpos($line, 'Payment Address') !== false) {
                $header_row_index = $index;
                break;
            }
        }

        // Delete existing records for this policy
        $wpdb->delete($table, ['policy_id' => $policy_id]);

        // Import new records (start after header)
        for ($i = $header_row_index + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            // Parse CSV line (handle quoted values)
            $data = str_getcsv($line);

            if (count($data) < 7) {
                $errors[] = "Line " . ($i + 1) . ": Invalid format (expected 7 columns)";
                continue;
            }

            list($payment_address, $stake_address, $total_allowed, $total_minted, $remaining, $first_mint_date, $last_mint_date) = $data;

            // Validate required fields
            if (empty($payment_address)) {
                $errors[] = "Line " . ($i + 1) . ": Missing payment address";
                continue;
            }

            // Insert record
            $result = $wpdb->insert($table, [
                'policy_id' => $policy_id,
                'payment_address' => trim($payment_address),
                'stake_address' => trim($stake_address),
                'total_allowed' => intval($total_allowed),
                'total_minted' => intval($total_minted),
                'remaining' => intval($remaining),
                'first_mint_date' => $first_mint_date ?: null,
                'last_mint_date' => $last_mint_date ?: null
            ]);

            if ($result) {
                $imported++;
            } else {
                $errors[] = "Line " . ($i + 1) . ": Database insert failed";
            }
        }

        return [
            'success' => $imported > 0,
            'imported' => $imported,
            'errors' => $errors
        ];
    }

    // ========================================
    // IMPORTED MINT POLICIES TABLE
    // ========================================

    public static function install_mint_policies_table() {
        global $wpdb;
        $table = self::get_mint_policies_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            policy_id varchar(56) NOT NULL,
            policy_schema longtext NOT NULL,
            policy_script_cbor text DEFAULT '',
            skey_encrypted text DEFAULT NULL,
            key_type varchar(20) NOT NULL DEFAULT 'extended',
            source varchar(20) NOT NULL DEFAULT 'generated',
            expiration_date datetime DEFAULT NULL,
            expiration_slot bigint(20) unsigned NOT NULL DEFAULT 0,
            items_minted int unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            network varchar(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_policy_id (policy_id),
            KEY idx_network_status (network, status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function insertMintPolicy($data) {
        global $wpdb;
        $table = self::get_mint_policies_table();

        $wpdb->insert($table, array(
            'name'              => $data['name'] ?? '',
            'policy_id'         => $data['policy_id'] ?? '',
            'policy_schema'     => $data['policy_schema'] ?? '',
            'policy_script_cbor'=> $data['policy_script_cbor'] ?? '',
            'skey_encrypted'    => $data['skey_encrypted'] ?? null,
            'key_type'          => $data['key_type'] ?? 'extended',
            'source'            => $data['source'] ?? 'generated',
            'expiration_date'   => $data['expiration_date'] ?? null,
            'expiration_slot'   => $data['expiration_slot'] ?? 0,
            'status'            => $data['status'] ?? 'active',
            'network'           => $data['network'] ?? 'preprod',
            'created_at'        => current_time('mysql'),
        ));

        return $wpdb->insert_id;
    }

    public static function getMintPolicy($id) {
        global $wpdb;
        $table = self::get_mint_policies_table();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($id)),
            ARRAY_A
        );
    }

    public static function getMintPolicyByPolicyId($policy_id) {
        global $wpdb;
        $table = self::get_mint_policies_table();
        $network = get_option('cardano-mint-networkenvironment', 'preprod');
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE policy_id = %s AND network = %s AND status = 'active' LIMIT 1",
                $policy_id, $network
            ),
            ARRAY_A
        );
    }

    public static function getAllActiveMintPolicies($network = null) {
        global $wpdb;
        $table = self::get_mint_policies_table();
        if ($network === null) {
            $network = get_option('cardano-mint-networkenvironment', 'preprod');
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE network = %s AND status = 'active' ORDER BY created_at DESC",
                $network
            ),
            ARRAY_A
        ) ?: array();
    }
}