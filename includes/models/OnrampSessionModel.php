<?php
namespace CardanoMintPay\Models;

use CardanoMintPay\Onramp\OnrampInstaller;

if (!defined('ABSPATH')) exit;

/**
 * OnrampSessionModel
 *
 * One row per Guardarian transaction. Status mirrors Guardarian's lifecycle:
 *
 *   new                    we POSTed /v1/transaction, customer hasn't loaded checkout yet
 *   waiting_for_customer   customer on hosted checkout, hasn't entered card / KYC
 *   waiting_for_deposit    card processing in progress
 *   exchanging             fiat received, FX in flight
 *   on_hold                AML/risk review (can resolve to sending or failed)
 *   sending                ADA broadcast to customer's wallet
 *   finished               terminal success — ADA confirmed in customer wallet by Guardarian
 *   failed                 terminal failure
 *   expired                customer abandoned
 *   cancelled              customer cancelled
 *   refunded               funds returned to customer
 *
 * "finished" on the Guardarian side does NOT mean the mint happened — the
 * customer still has to click Mint with their newly-funded wallet. Mint
 * triggering is entirely client-side after Blockfrost confirms ADA arrival.
 */
class OnrampSessionModel {

    public const TERMINAL_STATUSES = ['finished', 'failed', 'expired', 'cancelled', 'refunded'];

    public static function table(): string {
        return OnrampInstaller::table_sessions();
    }

    public static function insert(array $row): int {
        global $wpdb;
        OnrampInstaller::maybe_install();

        $now = current_time('mysql');
        $data = [
            'provider'                 => sanitize_text_field($row['provider'] ?? 'guardarian'),
            'provider_tx_id'           => isset($row['provider_tx_id']) ? sanitize_text_field((string) $row['provider_tx_id']) : null,
            'external_partner_link_id' => sanitize_text_field($row['external_partner_link_id'] ?? ''),
            'mint_id'                  => isset($row['mint_id']) ? (int) $row['mint_id'] : null,
            'customer_cardano_address' => sanitize_text_field($row['customer_cardano_address'] ?? ''),
            'payout_address'           => sanitize_text_field($row['payout_address'] ?? ''),
            'from_currency'            => sanitize_text_field($row['from_currency'] ?? 'USD'),
            'from_amount'              => (float) ($row['from_amount'] ?? 0),
            'to_currency'              => sanitize_text_field($row['to_currency'] ?? 'ADA'),
            'to_amount_estimated'      => isset($row['to_amount_estimated']) ? (string) $row['to_amount_estimated'] : null,
            'to_amount_actual'         => isset($row['to_amount_actual']) ? (string) $row['to_amount_actual'] : null,
            'status'                   => sanitize_text_field($row['status'] ?? 'new'),
            'redirect_url'             => isset($row['redirect_url']) ? esc_url_raw((string) $row['redirect_url']) : null,
            'meta'                     => isset($row['meta']) ? wp_json_encode($row['meta']) : null,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $ok = $wpdb->insert(self::table(), $data);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function get_by_provider_tx(string $provider, string $providerTxId): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$tbl` WHERE provider = %s AND provider_tx_id = %s LIMIT 1",
            $provider,
            $providerTxId
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function get_by_partner_link(string $externalPartnerLinkId): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$tbl` WHERE external_partner_link_id = %s LIMIT 1",
            $externalPartnerLinkId
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Update an existing row. Always bumps updated_at. Pass any subset of
     * mutable columns; unknown keys are silently dropped to keep callers
     * loose-coupled.
     */
    public static function update(int $id, array $changes): bool {
        global $wpdb;
        if ($id <= 0 || empty($changes)) return false;

        $allowed = [
            'provider_tx_id', 'status', 'to_amount_estimated', 'to_amount_actual',
            'redirect_url', 'meta', 'last_webhook_at', 'mint_triggered_at',
        ];
        $data = [];
        foreach ($changes as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'meta' && is_array($v)) {
                $data[$k] = wp_json_encode($v);
            } elseif ($k === 'redirect_url') {
                $data[$k] = $v === null ? null : esc_url_raw((string) $v);
            } else {
                $data[$k] = $v;
            }
        }
        if (empty($data)) return false;

        $data['updated_at'] = current_time('mysql');
        return false !== $wpdb->update(self::table(), $data, ['id' => $id]);
    }

    public static function set_status(int $id, string $status, array $extra = []): bool {
        $extra['status'] = sanitize_text_field($status);
        return self::update($id, $extra);
    }

    /**
     * List sessions for the operator-facing admin view. Filterable by
     * status; ordered newest first.
     */
    public static function list_filtered(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $tbl = self::table();
        $where = ['1=1'];
        $args = [];
        if (!empty($filters['status'])) { $where[] = 'status = %s'; $args[] = $filters['status']; }
        if (!empty($filters['provider'])) { $where[] = 'provider = %s'; $args[] = $filters['provider']; }
        if (!empty($filters['mint_id'])) { $where[] = 'mint_id = %d'; $args[] = (int) $filters['mint_id']; }
        if (!empty($filters['customer_cardano_address'])) {
            $where[] = 'customer_cardano_address = %s';
            $args[] = $filters['customer_cardano_address'];
        }
        $args[] = $limit;
        $args[] = $offset;
        $sql = "SELECT * FROM `$tbl` WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }

    /** Sessions still in flight (non-terminal) and not too old. */
    public static function find_active(int $maxAgeHours = 24, int $limit = 100): array {
        global $wpdb;
        $tbl = self::table();
        $terminal = "'" . implode("','", self::TERMINAL_STATUSES) . "'";
        // Terminal list is a fixed enum, safe to inline; everything else parameterized.
        $sql = "SELECT * FROM `$tbl`
                WHERE status NOT IN ($terminal)
                  AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
                ORDER BY created_at ASC
                LIMIT %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $maxAgeHours, $limit), ARRAY_A);
    }
}
