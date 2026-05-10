<?php
namespace CardanoMintPay\Onramp;

if (!defined('ABSPATH')) exit;

/**
 * OnrampInstaller
 *
 * Schema for fiat-to-crypto on-ramp sessions. Mirrors AltPayInstaller's
 * idempotent install + maybe_install pattern so it's safe to call from
 * activation, admin_init, and from the top of CRUD methods.
 *
 * Tables:
 *   wp_cm_onramp_sessions  one row per "buy ADA with card" attempt
 *
 * The on-ramp is conceptually different from AltPay:
 *   AltPay  customer pays a derived child address in BTC/ETH/SOL, operator
 *           sweeps and triggers the mint server-side.
 *   Onramp  customer buys ADA via card on Guardarian; ADA lands directly in
 *           the customer's own connected Cardano wallet. The mint flow that
 *           follows is unchanged — they just have ADA now. The session row
 *           exists for audit, support, and the "waiting for ADA to arrive"
 *           UI on the client side after Guardarian's success redirect.
 */
class OnrampInstaller {

    const FLAG_OPTION = 'cardano_mint_onramp_schema_version';
    const SCHEMA_VERSION = '1';

    public static function table_sessions(): string {
        global $wpdb;
        return $wpdb->prefix . 'cm_onramp_sessions';
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

        $charset = $wpdb->get_charset_collate();
        $sessions = self::table_sessions();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // mint_id is nullable: a customer could click "Buy ADA" outside of
        // a specific mint context (e.g. from a generic on-ramp widget), and
        // the session is still useful for audit. Address is the customer's
        // own wallet — payout goes to them, not the operator.
        //
        // to_amount_* are VARCHAR even though ADA fits comfortably in BIGINT
        // for sane amounts; matching the AltPay convention keeps amount math
        // consistently string-based across both subsystems.
        //
        // external_partner_link_id is what we send to Guardarian and what
        // they echo back in webhooks; it's our correlation key. UNIQUE so we
        // can't accidentally reuse one across two Guardarian transactions.
        dbDelta("CREATE TABLE $sessions (
            id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider                    VARCHAR(32)  NOT NULL DEFAULT 'guardarian',
            provider_tx_id              VARCHAR(64)  NULL,
            external_partner_link_id    VARCHAR(64)  NOT NULL,
            mint_id                     INT UNSIGNED NULL,
            customer_cardano_address    VARCHAR(128) NOT NULL,
            payout_address              VARCHAR(128) NOT NULL,
            from_currency               VARCHAR(8)   NOT NULL,
            from_amount                 DECIMAL(18,2) NOT NULL,
            to_currency                 VARCHAR(8)   NOT NULL DEFAULT 'ADA',
            to_amount_estimated         VARCHAR(40)  NULL,
            to_amount_actual            VARCHAR(40)  NULL,
            status                      VARCHAR(32)  NOT NULL DEFAULT 'new',
            redirect_url                TEXT         NULL,
            meta                        LONGTEXT     NULL,
            last_webhook_at             DATETIME     NULL,
            mint_triggered_at           DATETIME     NULL,
            created_at                  DATETIME     NOT NULL,
            updated_at                  DATETIME     NOT NULL,
            UNIQUE KEY external_partner_link_id (external_partner_link_id),
            KEY provider_tx_id (provider, provider_tx_id),
            KEY status_created (status, created_at),
            KEY mint_id (mint_id),
            KEY customer_address (customer_cardano_address)
        ) $charset;");
    }
}
