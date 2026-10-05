<?php
namespace CardanoMintPay\Discounts;

if (!defined('ABSPATH')) exit;

/**
 * DiscountInstaller
 *
 * Schema for e-commerce-style discount codes. Mirrors the OnrampInstaller /
 * AltPayInstaller idempotent install + maybe_install pattern so it's safe to
 * call from activation, admin_init, and from the top of model writes.
 *
 * Three tables:
 *
 *   wp_cm_discount_campaigns    the rule + tracking unit (one "campaign" /
 *                               program, e.g. "Launch Week $5 Mints")
 *   wp_cm_discount_codes        the code strings (one row per code; a batch of
 *                               50 is 50 rows, a shared code like LAUNCH20 is 1)
 *   wp_cm_discount_redemptions  reservations + committed redemptions (the audit
 *                               + results trail that powers the campaign report)
 *
 * Nothing here touches the chain. A code only ever rewrites the MSRP / merchant
 * payment portion of the price (server-side); the Anvil + service fees and the
 * per-NFT receipt are untouched.
 */
class DiscountInstaller {

    const FLAG_OPTION    = 'cardano_mint_discount_schema_version';
    const SCHEMA_VERSION = '1';

    public static function table_campaigns(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_discount_campaigns';
    }

    public static function table_codes(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_discount_codes';
    }

    public static function table_redemptions(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_discount_redemptions';
    }

    public static function install(): void {
        self::create_tables();
        update_option(self::FLAG_OPTION, self::SCHEMA_VERSION);
    }

    public static function maybe_install(): void {
        if (get_option(self::FLAG_OPTION) === self::SCHEMA_VERSION) return;
        self::install();
    }

    private static function create_tables(): void {
        global $wpdb;

        $charset     = $wpdb->get_charset_collate();
        $campaigns   = self::table_campaigns();
        $codes       = self::table_codes();
        $redemptions = self::table_redemptions();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // asset_name is reserved for future per-asset scope (DC-D7); v1 leaves
        // it NULL = policy-wide. discount_type is an enum-by-convention
        // ('percent' | 'fixed' | 'bogo'); bogo_* columns are reserved for
        // phase 3 and stay NULL for v1. uses_per_code: 1 = single-use, 0 =
        // unlimited (capped only by expiry).
        dbDelta("CREATE TABLE $campaigns (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title           VARCHAR(190) NOT NULL,
            policy_id       VARCHAR(56)  NOT NULL,
            asset_name      VARCHAR(255) NULL,
            network         VARCHAR(20)  NOT NULL DEFAULT 'mainnet',
            discount_type   VARCHAR(16)  NOT NULL,
            percent_off     DECIMAL(5,2) NULL,
            fixed_off_usd   DECIMAL(10,2) NULL,
            bogo_buy_qty    INT NULL,
            bogo_free_qty   INT NULL,
            code_mode       VARCHAR(16)  NOT NULL DEFAULT 'batch',
            uses_per_code   INT UNSIGNED NOT NULL DEFAULT 1,
            starts_at       DATETIME NULL,
            expires_at      DATETIME NULL,
            status          VARCHAR(16)  NOT NULL DEFAULT 'active',
            created_at      DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY policy_id (policy_id),
            KEY status (status)
        ) $charset;");

        // code is stored uppercased and is globally UNIQUE so a single input
        // resolves unambiguously to one campaign. uses_count is the number of
        // COMMITTED redemptions; the reservation count is derived live from the
        // redemptions table so an abandoned checkout never permanently burns it.
        dbDelta("CREATE TABLE $codes (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id   BIGINT UNSIGNED NOT NULL,
            code          VARCHAR(32)  NOT NULL,
            uses_allowed  INT UNSIGNED NOT NULL DEFAULT 1,
            uses_count    INT UNSIGNED NOT NULL DEFAULT 0,
            status        VARCHAR(16)  NOT NULL DEFAULT 'active',
            created_at    DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY campaign_id (campaign_id)
        ) $charset;");

        // status lifecycle: reserved -> redeemed (committed at mint submit) or
        // reserved -> released (TTL sweeper / abandon). invoice_id links an
        // alt-pay reservation (phase 2). Everything needed for the campaign
        // results report lives here.
        dbDelta("CREATE TABLE $redemptions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_id         BIGINT UNSIGNED NOT NULL,
            campaign_id     BIGINT UNSIGNED NOT NULL,
            policy_id       VARCHAR(56)  NOT NULL,
            wallet_address  VARCHAR(128) NULL,
            payment_method  VARCHAR(8)   NOT NULL DEFAULT 'ada',
            quantity        INT UNSIGNED NOT NULL DEFAULT 1,
            original_usd    DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_usd    DECIMAL(10,2) NOT NULL DEFAULT 0,
            final_usd       DECIMAL(10,2) NOT NULL DEFAULT 0,
            status          VARCHAR(12)  NOT NULL DEFAULT 'reserved',
            invoice_id      BIGINT UNSIGNED NULL,
            tx_hash         VARCHAR(80)  NULL,
            reserved_at     DATETIME NOT NULL,
            redeemed_at     DATETIME NULL,
            PRIMARY KEY  (id),
            KEY code_id (code_id),
            KEY campaign_id (campaign_id),
            KEY status (status),
            KEY invoice_id (invoice_id),
            KEY wallet_address (wallet_address)
        ) $charset;");
    }
}
