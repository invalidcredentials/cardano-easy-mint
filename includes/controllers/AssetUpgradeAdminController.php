<?php
/**
 * Asset Upgrade admin controller.
 *
 * Phase 2 surface: register a policy by ID, decode its time-lock state via
 * Blockfrost, count assets, and list registered policies. The actual spec
 * editor (patch JSON / per-asset CSV) lands in phase 3; eligibility +
 * burn-and-re-mint frontend lands in phase 4+.
 *
 * Gated by `manage_options` + the same TOTP unlock transient that gates
 * the Payment Wallets page, so one 2FA enrollment covers both surfaces.
 * See docs/BUILD_PLAN.md for the feature design and locked decisions.
 */

namespace CardanoMintPay\Controllers;

use CardanoMintPay\AssetUpgrade\AssetUpgradeInstaller;
use CardanoMintPay\AssetUpgrade\MetadataResolver;
use CardanoMintPay\Helpers\BlockfrostClient;

if (!defined('ABSPATH')) exit;

class AssetUpgradeAdminController {

    const PAGE_SLUG       = 'cardano-asset-upgrades';
    const NONCE           = 'cardano_asset_upgrade_nonce';
    const CAP             = 'manage_options';
    const ASSETS_CACHE_TTL = HOUR_IN_SECONDS;

    public static function register(): void {
        add_action('admin_menu',                                   [self::class, 'register_menu'], 20);
        add_action('wp_ajax_cardano_upgrade_register_policy',      [self::class, 'ajax_register_policy']);
        add_action('wp_ajax_cardano_upgrade_list_policies',        [self::class, 'ajax_list_policies']);
        add_action('wp_ajax_cardano_upgrade_remove_policy',        [self::class, 'ajax_remove_policy']);
        add_action('wp_ajax_cardano_upgrade_list_assets',          [self::class, 'ajax_list_assets']);
        // Phase 3: spec editor
        add_action('wp_ajax_cardano_upgrade_get_policy_edit',      [self::class, 'ajax_get_policy_edit']);
        add_action('wp_ajax_cardano_upgrade_save_patch',           [self::class, 'ajax_save_patch']);
        add_action('wp_ajax_cardano_upgrade_save_per_asset_bundle',[self::class, 'ajax_save_per_asset_bundle']);
        add_action('wp_ajax_cardano_upgrade_delete_per_asset',     [self::class, 'ajax_delete_per_asset']);
        add_action('wp_ajax_cardano_upgrade_set_status',           [self::class, 'ajax_set_status']);
        add_action('wp_ajax_cardano_upgrade_preview_diff',         [self::class, 'ajax_preview_diff']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cardano-mint-plugin-setup',
            'Asset Upgrades',
            'Asset Upgrades',
            self::CAP,
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAP)) wp_die('Forbidden');

        // Same TOTP gate as Payment Wallets — one enrollment covers both.
        if (AltPayAdminController::isTotpEnabled() && !AltPayAdminController::isTotpUnlocked()) {
            self::render_totp_required();
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__));
        wp_enqueue_style(
            'cardano-asset-upgrade',
            $base_url . 'assets/asset-upgrade/admin.css',
            [],
            (string) filemtime(plugin_dir_path(dirname(__DIR__)) . 'assets/asset-upgrade/admin.css')
        );
        wp_enqueue_script(
            'cardano-asset-upgrade',
            $base_url . 'assets/asset-upgrade/admin.js',
            ['jquery'],
            (string) filemtime(plugin_dir_path(dirname(__DIR__)) . 'assets/asset-upgrade/admin.js'),
            true
        );
        $action    = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        $policy_id = isset($_GET['policy_id']) ? sanitize_text_field((string) $_GET['policy_id']) : '';

        wp_localize_script('cardano-asset-upgrade', 'KG_ASSET_UPGRADE', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE),
            'page_url'    => admin_url('admin.php?page=' . self::PAGE_SLUG),
            'action'      => $action,
            'policy_id'   => $policy_id,
        ]);

        if ($action === 'edit' && preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            include plugin_dir_path(dirname(__DIR__)) . 'includes/views/asset-upgrade/page-edit.php';
            return;
        }

        include plugin_dir_path(dirname(__DIR__)) . 'includes/views/asset-upgrade/page-main.php';
    }

    private static function render_totp_required(): void {
        $pay_url = admin_url('admin.php?page=cardano-payment-wallets');
        echo '<div class="wrap"><h1>Asset Upgrades</h1>';
        echo '<div class="notice notice-warning"><p><strong>Two-factor unlock required.</strong> ';
        echo 'Unlock on the <a href="' . esc_url($pay_url) . '">Payment Wallets page</a> and come back. ';
        echo 'The unlock covers both pages for 30 minutes.</p></div></div>';
    }

    /* ─── AJAX ─────────────────────────────────────────────────────────── */

    /**
     * Register a policy: validate the ID, fetch the script for time-lock
     * decode, sample one assets-page to verify the policy exists and grab
     * a count, then upsert a draft policy-wide spec row. Idempotent.
     */
    public static function ajax_register_policy(): void {
        self::ajax_guard();

        $policy_id = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        $network   = isset($_POST['network'])   ? sanitize_text_field((string) $_POST['network'])   : 'mainnet';
        $label     = isset($_POST['label'])     ? sanitize_text_field((string) $_POST['label'])     : '';

        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Policy ID must be 56 hex characters.']);
        }
        if (!in_array($network, ['mainnet', 'preprod', 'preview'], true)) {
            wp_send_json_error(['message' => 'Network must be mainnet, preprod, or preview.']);
        }
        if (!BlockfrostClient::isConfiguredFor($network)) {
            wp_send_json_error(['message' => "Blockfrost project ID for {$network} is not set in Settings → Payment Wallets."]);
        }

        // Time-lock decode. Refuse if the script call fails entirely
        // (network error / 404 → unknown policy). 404 from the script
        // endpoint means the policy ID doesn't exist on-chain on this network.
        $script_resp = BlockfrostClient::policyScript($policy_id, $network);
        if (!$script_resp['ok']) {
            $msg = $script_resp['status'] === 404
                ? "Policy ID not found on {$network}. Check the ID and network selection."
                : ('Blockfrost script lookup failed: ' . ($script_resp['error'] ?? 'unknown'));
            wp_send_json_error(['message' => $msg]);
        }
        $lock_slot = BlockfrostClient::decodePolicyLockSlot($script_resp['data']);

        // Asset count: pull the first page to verify the policy has assets,
        // then walk pages until empty so we have a real count for the UI.
        // For huge collections this still completes in a couple seconds at
        // 100/page. If we ever hit a 10k+ collection this will need a
        // background queue, but MVP eats the wait synchronously.
        $count = 0;
        $page  = 1;
        while (true) {
            $resp = BlockfrostClient::assetsByPolicy($policy_id, $network, $page, 100);
            if (!$resp['ok']) {
                wp_send_json_error(['message' => 'Blockfrost assets lookup failed: ' . ($resp['error'] ?? 'unknown')]);
            }
            $batch = is_array($resp['data']) ? $resp['data'] : [];
            $count += count($batch);
            if (count($batch) < 100) break;
            $page++;
            if ($page > 200) break; // 20k asset hard cap; refuse rather than spin
        }

        if ($count === 0) {
            wp_send_json_error(['message' => 'Policy exists but has zero assets minted under it. Nothing to upgrade.']);
        }

        // Upsert policy-wide draft row. asset_name = '' marks the
        // policy-wide patch slot; per-asset rows go alongside later.
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $now   = current_time('mysql', true);

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE policy_id = %s AND asset_name = ''",
            $policy_id
        ));

        $label = $label !== '' ? $label : ('Policy ' . substr($policy_id, 0, 10) . '…');

        if ($existing_id > 0) {
            $wpdb->update($table, [
                'network'              => $network,
                'upgrade_label'        => $label,
                'policy_locks_at_slot' => $lock_slot,
                'asset_count'          => $count,
                'updated_at'           => $now,
            ], ['id' => $existing_id]);
            $row_id = $existing_id;
        } else {
            $wpdb->insert($table, [
                'policy_id'            => $policy_id,
                'asset_name'           => '',
                'network'              => $network,
                'upgrade_label'        => $label,
                'current_metadata'     => null,
                'new_metadata'         => '{}',
                'mode'                 => 'patch',
                'status'               => 'draft',
                'policy_locks_at_slot' => $lock_slot,
                'asset_count'          => $count,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $row_id = (int) $wpdb->insert_id;
        }

        // Invalidate any cached asset listing for this policy so the
        // "View assets" pane reflects what we just snapshotted.
        delete_transient(self::assets_cache_key($policy_id, $network));

        wp_send_json_success([
            'id'                   => $row_id,
            'policy_id'            => $policy_id,
            'network'              => $network,
            'label'                => $label,
            'asset_count'          => $count,
            'policy_locks_at_slot' => $lock_slot,
            'lock_state'           => self::describe_lock_state($lock_slot, $network),
        ]);
    }

    public static function ajax_list_policies(): void {
        self::ajax_guard();
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $rows = $wpdb->get_results(
            "SELECT id, policy_id, network, upgrade_label, status, asset_count,
                    policy_locks_at_slot, created_at, updated_at
             FROM $table
             WHERE asset_name = ''
             ORDER BY created_at DESC",
            ARRAY_A
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = array_merge($r, [
                'lock_state' => self::describe_lock_state(
                    isset($r['policy_locks_at_slot']) ? (int) $r['policy_locks_at_slot'] : null,
                    (string) $r['network']
                ),
            ]);
        }
        wp_send_json_success(['policies' => $out]);
    }

    /**
     * Delete every spec row for a policy_id. The append-only upgrade log is
     * intentionally untouched — history of past upgrades survives the spec
     * being removed.
     */
    public static function ajax_remove_policy(): void {
        self::ajax_guard();
        $policy_id = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Invalid policy ID.']);
        }
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $deleted = (int) $wpdb->delete($table, ['policy_id' => $policy_id], ['%s']);
        delete_transient(self::assets_cache_key($policy_id, 'mainnet'));
        delete_transient(self::assets_cache_key($policy_id, 'preprod'));
        delete_transient(self::assets_cache_key($policy_id, 'preview'));
        wp_send_json_success(['deleted_rows' => $deleted]);
    }

    /**
     * List assets under a registered policy, with optional pagination.
     * Cached in a transient so admin panel views are snappy without burning
     * Blockfrost rate-limit budget. Refresh by passing refresh=1.
     */
    public static function ajax_list_assets(): void {
        self::ajax_guard();
        $policy_id = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        $refresh   = !empty($_POST['refresh']) && $_POST['refresh'] === '1';

        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Invalid policy ID.']);
        }

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $network = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT network FROM $table WHERE policy_id = %s AND asset_name = ''",
            $policy_id
        ));
        if ($network === '') {
            wp_send_json_error(['message' => 'Policy not registered yet.']);
        }

        $cache_key = self::assets_cache_key($policy_id, $network);
        $cached    = $refresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            wp_send_json_success(['assets' => $cached, 'cached' => true]);
        }

        // Same paginated walk as ajax_register_policy. Caller decides
        // whether to use cache or refresh.
        $assets = [];
        $page = 1;
        while (true) {
            $resp = BlockfrostClient::assetsByPolicy($policy_id, $network, $page, 100);
            if (!$resp['ok']) {
                wp_send_json_error(['message' => 'Blockfrost assets lookup failed: ' . ($resp['error'] ?? 'unknown')]);
            }
            $batch = is_array($resp['data']) ? $resp['data'] : [];
            foreach ($batch as $row) {
                if (!is_array($row) || empty($row['asset'])) continue;
                $assets[] = [
                    'asset'      => (string) $row['asset'],
                    'asset_name' => substr((string) $row['asset'], strlen($policy_id)),
                    'quantity'   => isset($row['quantity']) ? (string) $row['quantity'] : '0',
                ];
            }
            if (count($batch) < 100) break;
            $page++;
            if ($page > 200) break;
        }

        set_transient($cache_key, $assets, self::ASSETS_CACHE_TTL);
        wp_send_json_success(['assets' => $assets, 'cached' => false]);
    }

    /* ─── Phase 3: spec editor ─────────────────────────────────────────── */

    /**
     * Return everything the edit view needs in one round-trip: the
     * policy-wide row plus all per-asset rows. JSON columns are returned
     * decoded so the JS doesn't have to JSON.parse twice.
     */
    public static function ajax_get_policy_edit(): void {
        self::ajax_guard();
        $policy_id = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Invalid policy ID.']);
        }

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, policy_id, asset_name, network, upgrade_label, current_metadata,
                    new_metadata, mode, status, policy_locks_at_slot, asset_count,
                    created_at, updated_at
             FROM $table WHERE policy_id = %s ORDER BY asset_name ASC",
            $policy_id
        ), ARRAY_A);

        $policy_row = null;
        $per_asset  = [];
        foreach ($rows as $r) {
            $r['new_metadata_parsed']     = self::safe_decode((string) $r['new_metadata']);
            $r['current_metadata_parsed'] = $r['current_metadata'] === null ? null : self::safe_decode((string) $r['current_metadata']);
            if ((string) $r['asset_name'] === '') {
                $policy_row = $r;
            } else {
                $r['asset_name_ascii'] = MetadataResolver::hexToAscii((string) $r['asset_name']);
                $per_asset[] = $r;
            }
        }

        if (!$policy_row) {
            wp_send_json_error(['message' => 'Policy not registered yet. Use the main page to register it first.']);
        }

        wp_send_json_success([
            'policy'    => $policy_row,
            'per_asset' => $per_asset,
        ]);
    }

    /**
     * Save the policy-wide patch JSON. Patch is the "merge me onto every
     * asset's current metadata" diff. We validate it's an object (not an
     * array / primitive) and store as JSON. The status passed in lets the
     * admin toggle draft/active/paused in the same action.
     */
    public static function ajax_save_patch(): void {
        self::ajax_guard();
        $policy_id   = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        $patch_json  = isset($_POST['patch_json']) ? wp_unslash((string) $_POST['patch_json']) : '';
        $status      = isset($_POST['status']) ? sanitize_text_field((string) $_POST['status']) : 'draft';

        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Invalid policy ID.']);
        }
        if (!self::is_valid_status($status)) {
            wp_send_json_error(['message' => 'Invalid status. Must be draft / active / paused / completed.']);
        }

        $decoded = json_decode($patch_json, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => 'Patch JSON must be a valid JSON object.']);
        }
        if (array_keys($decoded) === range(0, count($decoded) - 1) && !empty($decoded)) {
            wp_send_json_error(['message' => 'Patch JSON must be an object (e.g. {"image": "ipfs://..."}), not a list.']);
        }
        if ($status === 'active' && empty($decoded)) {
            wp_send_json_error(['message' => 'Cannot activate with an empty patch — nothing would change. Add at least one field.']);
        }

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $now   = current_time('mysql', true);
        $updated = $wpdb->update(
            $table,
            [
                'new_metadata' => wp_json_encode($decoded),
                'mode'         => 'patch',
                'status'       => $status,
                'updated_at'   => $now,
            ],
            ['policy_id' => $policy_id, 'asset_name' => ''],
            ['%s', '%s', '%s', '%s'],
            ['%s', '%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'Database update failed: ' . $wpdb->last_error]);
        }
        wp_send_json_success(['status' => $status, 'rows_changed' => (int) $updated]);
    }

    /**
     * Parse a pasted CIP-25 bundle (the canonical {"721":{<policy>:{<asset_ascii>:{...}}}}
     * shape) and upsert per-asset spec rows for the matched assets. Returns
     * the count of rows inserted / updated / skipped so admin sees what
     * just happened.
     */
    public static function ajax_save_per_asset_bundle(): void {
        self::ajax_guard();
        $policy_id   = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        $bundle_json = isset($_POST['bundle_json']) ? wp_unslash((string) $_POST['bundle_json']) : '';
        $status      = isset($_POST['status']) ? sanitize_text_field((string) $_POST['status']) : 'draft';

        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            wp_send_json_error(['message' => 'Invalid policy ID.']);
        }
        if (!self::is_valid_status($status)) {
            wp_send_json_error(['message' => 'Invalid status.']);
        }

        try {
            $entries = MetadataResolver::parseCip25Bundle($bundle_json, $policy_id);
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $now   = current_time('mysql', true);

        // We need the policy-wide row's network / lock-slot so per-asset
        // rows inherit them rather than having admins re-specify.
        $policy_row = $wpdb->get_row($wpdb->prepare(
            "SELECT network, policy_locks_at_slot FROM $table WHERE policy_id = %s AND asset_name = ''",
            $policy_id
        ), ARRAY_A);
        if (!$policy_row) {
            wp_send_json_error(['message' => 'Register the policy first before adding per-asset rows.']);
        }

        $inserted = 0; $updated = 0;
        foreach ($entries as $entry) {
            $asset_name = $entry['asset_name'];
            $metadata   = $entry['metadata'];
            $label      = 'Asset ' . substr($asset_name, 0, 16);

            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE policy_id = %s AND asset_name = %s",
                $policy_id, $asset_name
            ));

            if ($existing_id > 0) {
                $wpdb->update($table, [
                    'new_metadata' => wp_json_encode($metadata),
                    'mode'         => 'full',
                    'status'       => $status,
                    'updated_at'   => $now,
                ], ['id' => $existing_id]);
                $updated++;
            } else {
                $wpdb->insert($table, [
                    'policy_id'            => $policy_id,
                    'asset_name'           => $asset_name,
                    'network'              => $policy_row['network'],
                    'upgrade_label'        => $label,
                    'current_metadata'     => null,
                    'new_metadata'         => wp_json_encode($metadata),
                    'mode'                 => 'full',
                    'status'               => $status,
                    'policy_locks_at_slot' => $policy_row['policy_locks_at_slot'],
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
                $inserted++;
            }
        }

        wp_send_json_success([
            'inserted'    => $inserted,
            'updated'     => $updated,
            'total_rows'  => count($entries),
            'status'      => $status,
        ]);
    }

    public static function ajax_delete_per_asset(): void {
        self::ajax_guard();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid row id.']);
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        // Refuse to delete the policy-wide row through this endpoint —
        // use Remove policy on the main page for that.
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, asset_name FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Row not found.']);
        if ((string) $row['asset_name'] === '') {
            wp_send_json_error(['message' => 'Cannot delete the policy-wide row from here. Use Remove policy on the main page.']);
        }
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        wp_send_json_success(['deleted' => (int) $deleted]);
    }

    public static function ajax_set_status(): void {
        self::ajax_guard();
        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field((string) $_POST['status']) : '';
        if ($id <= 0)                            wp_send_json_error(['message' => 'Invalid row id.']);
        if (!self::is_valid_status($status))     wp_send_json_error(['message' => 'Invalid status.']);

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, new_metadata FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Row not found.']);

        if ($status === 'active') {
            $parsed = json_decode((string) $row['new_metadata'], true);
            if (!is_array($parsed) || empty($parsed)) {
                wp_send_json_error(['message' => 'Cannot activate with empty new_metadata. Define a patch or per-asset spec first.']);
            }
        }

        $now = current_time('mysql', true);
        $wpdb->update($table, ['status' => $status, 'updated_at' => $now], ['id' => $id]);
        wp_send_json_success(['status' => $status]);
    }

    /**
     * Preview the resolved metadata for one asset. Fetches current chain
     * metadata via Blockfrost, applies the matching spec (per-asset row
     * wins, then policy-wide patch), and returns both sides so the JS
     * can render a side-by-side diff.
     */
    public static function ajax_preview_diff(): void {
        self::ajax_guard();
        $policy_id  = isset($_POST['policy_id']) ? sanitize_text_field((string) $_POST['policy_id']) : '';
        $asset_name = isset($_POST['asset_name']) ? sanitize_text_field((string) $_POST['asset_name']) : '';

        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id))      wp_send_json_error(['message' => 'Invalid policy ID.']);
        if (!preg_match('/^[a-f0-9]+$/i', $asset_name) || strlen($asset_name) > 128) {
            wp_send_json_error(['message' => 'Invalid asset name (must be hex, ≤128 chars).']);
        }

        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $policy_row = $wpdb->get_row($wpdb->prepare(
            "SELECT network, new_metadata, mode FROM $table WHERE policy_id = %s AND asset_name = ''",
            $policy_id
        ), ARRAY_A);
        if (!$policy_row) wp_send_json_error(['message' => 'Policy not registered.']);

        $network = (string) $policy_row['network'];
        $asset_id = $policy_id . $asset_name;
        $resp = BlockfrostClient::assetMetadata($asset_id, $network);
        if (!$resp['ok']) {
            wp_send_json_error([
                'message' => 'Blockfrost asset lookup failed: ' . ($resp['error'] ?? 'unknown'),
                'status'  => $resp['status'] ?? 0,
            ]);
        }
        $current_meta = isset($resp['data']['onchain_metadata']) && is_array($resp['data']['onchain_metadata'])
            ? $resp['data']['onchain_metadata']
            : [];

        // Per-asset row wins; falls back to policy-wide patch applied to current.
        $per_asset_row = $wpdb->get_row($wpdb->prepare(
            "SELECT new_metadata, mode, status FROM $table WHERE policy_id = %s AND asset_name = %s",
            $policy_id, $asset_name
        ), ARRAY_A);

        $resolved      = null;
        $resolved_from = '';

        if ($per_asset_row && ((string) $per_asset_row['mode']) === 'full') {
            $resolved = self::safe_decode((string) $per_asset_row['new_metadata']);
            $resolved_from = 'per_asset_full (status=' . $per_asset_row['status'] . ')';
        } else {
            $patch = self::safe_decode((string) $policy_row['new_metadata']);
            if (!is_array($patch)) $patch = [];
            $resolved = MetadataResolver::applyPatch($current_meta, $patch);
            $resolved_from = 'policy_wide_patch';
        }

        wp_send_json_success([
            'asset_name'      => $asset_name,
            'asset_name_ascii'=> MetadataResolver::hexToAscii($asset_name),
            'current'         => $current_meta,
            'resolved'        => $resolved,
            'resolved_from'   => $resolved_from,
        ]);
    }

    /* ─── helpers ──────────────────────────────────────────────────────── */

    private static function ajax_guard(): void {
        if (!current_user_can(self::CAP)) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer(self::NONCE, 'nonce');
        if (AltPayAdminController::isTotpEnabled() && !AltPayAdminController::isTotpUnlocked()) {
            wp_send_json_error(['message' => '2FA unlock required'], 401);
        }
    }

    private static function assets_cache_key(string $policy_id, string $network): string {
        return 'cardano_au_assets_' . $network . '_' . substr($policy_id, 0, 16);
    }

    private static function is_valid_status(string $s): bool {
        return in_array($s, ['draft', 'active', 'paused', 'completed', 'locked'], true);
    }

    private static function safe_decode(string $json) {
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Render the policy lock state as a human label + a machine flag.
     * Cardano slot is 1s on mainnet; close enough for "locks in X days".
     */
    private static function describe_lock_state(?int $slot, string $network): array {
        if ($slot === null) {
            return ['flag' => 'no_lock', 'label' => 'No time-lock'];
        }
        $current_slot = self::estimate_current_slot($network);
        if ($current_slot === null) {
            return ['flag' => 'unknown_lock', 'label' => "Locks at slot {$slot}"];
        }
        if ($slot <= $current_slot) {
            return ['flag' => 'locked', 'label' => 'LOCKED — assets are frozen on-chain'];
        }
        $seconds_remaining = $slot - $current_slot;
        $days = (int) floor($seconds_remaining / 86400);
        if ($days >= 1) {
            return ['flag' => 'unlocked_with_deadline', 'label' => "Locks in ~{$days} day" . ($days === 1 ? '' : 's')];
        }
        $hours = (int) floor($seconds_remaining / 3600);
        return ['flag' => 'unlocked_with_deadline', 'label' => "Locks in ~{$hours} hour" . ($hours === 1 ? '' : 's')];
    }

    /**
     * Rough current-slot estimate using Shelley-era genesis anchors. Good
     * enough for "lock in X days" UI. Customer-build-time check should use
     * a fresh Blockfrost call against /blocks/latest for the precise slot.
     */
    private static function estimate_current_slot(string $network): ?int {
        // Shelley start: mainnet 2020-07-29 21:44:51 UTC, slot 4492800
        //                preprod 2022-06-21 00:00:00 UTC, slot 86400
        //                preview 2022-08-09 00:00:00 UTC, slot 0
        $anchors = [
            'mainnet' => ['ts' => 1596059091, 'slot' => 4492800],
            'preprod' => ['ts' => 1655769600, 'slot' => 86400],
            'preview' => ['ts' => 1660003200, 'slot' => 0],
        ];
        if (!isset($anchors[$network])) return null;
        $a = $anchors[$network];
        return $a['slot'] + (time() - $a['ts']);
    }
}
