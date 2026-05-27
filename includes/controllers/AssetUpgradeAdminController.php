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
        wp_localize_script('cardano-asset-upgrade', 'KG_ASSET_UPGRADE', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE),
        ]);

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
