<?php
namespace CardanoMintPay\AssetUpgrade;

if (!defined('ABSPATH')) exit;

/**
 * AssetUpgradeInstaller
 *
 * Creates the 2 tables behind the burn-and-re-mint Asset Upgrade feature
 * and runs idempotent migrations. Mirrors the just-in-time install pattern
 * already used by AltPayInstaller: safe to call from activation, admin_init,
 * and from the top of CRUD methods as a safety net.
 *
 * Tables:
 *   wp_cardano_asset_upgrades       per-asset (or policy-wide) upgrade specs
 *   wp_cardano_asset_upgrade_log    append-only customer upgrade attempts
 *
 * See docs/BUILD_PLAN.md for the feature design.
 */
class AssetUpgradeInstaller {

    const FLAG_OPTION    = 'cardano_mint_asset_upgrade_schema_version';
    const SCHEMA_VERSION = '2';

    public static function table_specs(): string {
        global $wpdb;
        return $wpdb->prefix . 'cardano_asset_upgrades';
    }

    public static function table_log(): string {
        global $wpdb;
        return $wpdb->prefix . 'cardano_asset_upgrade_log';
    }

    /** Run on plugin activation. */
    public static function install(): void {
        self::create_tables();
        update_option(self::FLAG_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Run on every admin_init as a safety net for sites where activation
     * never fired with the new code (e.g. uploaded over WP File Manager).
     * Cheap: option read + early-return when version matches.
     */
    public static function maybe_install(): void {
        if (get_option(self::FLAG_OPTION) === self::SCHEMA_VERSION) return;
        self::install();
    }

    private static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $specs   = self::table_specs();
        $log     = self::table_log();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Upgrade specs. asset_name = '' marks the policy-wide patch row;
        // per-asset rows (asset_name != '') take precedence at resolve time.
        // Using empty string instead of NULL so the UNIQUE KEY actually
        // enforces one-row-per-(policy, asset) — MySQL treats NULL as
        // distinct from NULL for uniqueness purposes.
        dbDelta("CREATE TABLE $specs (
            id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            policy_id               VARCHAR(56)  NOT NULL,
            asset_name              VARCHAR(128) NOT NULL DEFAULT '',
            network                 VARCHAR(16)  NOT NULL DEFAULT 'mainnet',
            upgrade_label           VARCHAR(120) NOT NULL,
            current_metadata        LONGTEXT     NULL,
            new_metadata            LONGTEXT     NOT NULL,
            mode                    VARCHAR(8)   NOT NULL DEFAULT 'patch',
            status                  VARCHAR(16)  NOT NULL DEFAULT 'draft',
            policy_locks_at_slot    BIGINT       NULL,
            asset_count             INT UNSIGNED NULL,
            created_at              DATETIME     NOT NULL,
            updated_at              DATETIME     NOT NULL,
            completed_at            DATETIME     NULL,
            UNIQUE KEY uniq_policy_asset (policy_id, asset_name),
            KEY idx_policy_status (policy_id, status)
        ) $charset;");

        // Append-only event log. One row per build / submit / confirm / fail
        // for any customer-triggered upgrade attempt. Closed by status; never
        // overwritten. upgrade_id is nullable so we can log resolve-time
        // failures that didn't match a spec yet.
        dbDelta("CREATE TABLE $log (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            upgrade_id      BIGINT UNSIGNED NULL,
            policy_id       VARCHAR(56)  NOT NULL,
            asset_name      VARCHAR(128) NOT NULL,
            wallet_address  VARCHAR(120) NOT NULL,
            tx_hash         VARCHAR(64)  NULL,
            status          VARCHAR(16)  NOT NULL,
            error_message   TEXT         NULL,
            created_at      DATETIME     NOT NULL,
            KEY idx_policy_asset (policy_id, asset_name),
            KEY idx_wallet (wallet_address),
            KEY idx_upgrade (upgrade_id)
        ) $charset;");
    }
}
