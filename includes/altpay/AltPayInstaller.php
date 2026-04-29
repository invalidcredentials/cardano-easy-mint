<?php
namespace CardanoMintPay\AltPay;

if (!defined('ABSPATH')) exit;

/**
 * AltPayInstaller
 *
 * Creates the 3 alt-chain payment tables and runs idempotent column migrations.
 * Mirrors the just-in-time migration pattern already used by MintModel for the
 * preview-image columns: safe to call from activation, admin_init, and from
 * the top of CRUD methods as a safety net.
 *
 * Tables:
 *   wp_cm_chain_wallets   parent HD wallets (one row per generated/imported xprv)
 *   wp_cm_chain_invoices  per-mint payment intents (one row per derived address)
 *   wp_cm_chain_tx_log    audit trail of in/out/refund/sweep transactions
 */
class AltPayInstaller {

    const FLAG_OPTION = 'cardano_mint_altpay_schema_version';
    const SCHEMA_VERSION = '1';

    public static function table_wallets(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_chain_wallets';
    }

    public static function table_invoices(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_chain_invoices';
    }

    public static function table_tx_log(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_chain_tx_log';
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
        $wallets = self::table_wallets();
        $invoices = self::table_invoices();
        $tx_log = self::table_tx_log();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Parent HD wallets, one row per generated/imported xprv per chain.
        dbDelta("CREATE TABLE $wallets (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chain           VARCHAR(8)   NOT NULL,
            name            VARCHAR(255) NOT NULL,
            network         VARCHAR(20)  NOT NULL,
            xprv_encrypted  TEXT         NOT NULL,
            xpub            VARCHAR(255) NULL,
            next_index      INT UNSIGNED NOT NULL DEFAULT 0,
            source          VARCHAR(16)  NOT NULL,
            archived        TINYINT(1)   NOT NULL DEFAULT 0,
            created_at      DATETIME     NOT NULL,
            KEY chain_archived (chain, archived)
        ) $charset;");

        // Per-mint payment intents. expected_amount_minor is VARCHAR so ETH wei
        // does not overflow BIGINT.
        dbDelta("CREATE TABLE $invoices (
            id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_wallet_id            INT UNSIGNED NOT NULL,
            chain                       VARCHAR(8)   NOT NULL,
            derivation_index            INT UNSIGNED NOT NULL,
            address                     VARCHAR(128) NOT NULL,
            currency                    VARCHAR(8)   NOT NULL,
            expected_amount_minor       VARCHAR(40)  NOT NULL,
            rate_locked                 DECIMAL(18,8) NOT NULL,
            rate_expires_at             DATETIME     NOT NULL,
            mint_id                     INT UNSIGNED NOT NULL,
            customer_cardano_address    VARCHAR(128) NOT NULL,
            status                      VARCHAR(16)  NOT NULL,
            observed_tx                 VARCHAR(80)  NULL,
            observed_amount_minor       VARCHAR(40)  NULL,
            observed_at                 DATETIME     NULL,
            confirmations               INT          NULL,
            created_at                  DATETIME     NOT NULL,
            expires_at                  DATETIME     NOT NULL,
            UNIQUE KEY chain_address (chain, address),
            KEY status_expires (status, expires_at),
            KEY mint_id (mint_id)
        ) $charset;");

        // Audit trail. amount_minor uses the same VARCHAR pattern.
        dbDelta("CREATE TABLE $tx_log (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id    INT UNSIGNED NOT NULL,
            tx_hash       VARCHAR(80)  NOT NULL,
            direction     VARCHAR(8)   NOT NULL,
            amount_minor  VARCHAR(40)  NOT NULL,
            confirmations INT          NULL,
            raw_payload   LONGTEXT     NULL,
            seen_at       DATETIME     NOT NULL,
            KEY invoice_direction (invoice_id, direction)
        ) $charset;");
    }
}
