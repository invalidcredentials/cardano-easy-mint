<?php
namespace CardanoMintPay\Models;

use CardanoMintPay\Discounts\DiscountInstaller;

if (!defined('ABSPATH')) exit;

/**
 * DiscountModel
 *
 * CRUD for the discount trio (campaigns / codes / redemptions). Kept as one
 * cohesive model because the three tables are always touched together as a
 * single feature, unlike the alt-pay models which are used independently.
 *
 * Business logic (validation, pricing math, the reserve/commit/release
 * lifecycle) lives in CardanoMintPay\Discounts\DiscountService; this layer is
 * just typed, prepared SQL.
 */
class DiscountModel {

    public static function t_campaigns(): string   { return DiscountInstaller::table_campaigns(); }
    public static function t_codes(): string       { return DiscountInstaller::table_codes(); }
    public static function t_redemptions(): string { return DiscountInstaller::table_redemptions(); }

    /* ---------------------------------------------------------------- campaigns */

    public static function create_campaign(array $row): int {
        global $wpdb;
        DiscountInstaller::maybe_install();

        $data = [
            'title'         => sanitize_text_field((string) ($row['title'] ?? '')),
            'policy_id'     => sanitize_text_field((string) ($row['policy_id'] ?? '')),
            'asset_name'    => isset($row['asset_name']) && $row['asset_name'] !== '' ? sanitize_text_field((string) $row['asset_name']) : null,
            'network'       => sanitize_text_field((string) ($row['network'] ?? 'mainnet')),
            'discount_type' => sanitize_text_field((string) ($row['discount_type'] ?? 'percent')),
            'percent_off'   => isset($row['percent_off'])   && $row['percent_off']   !== '' ? (float) $row['percent_off']   : null,
            'fixed_off_usd' => isset($row['fixed_off_usd']) && $row['fixed_off_usd'] !== '' ? (float) $row['fixed_off_usd'] : null,
            'bogo_buy_qty'  => isset($row['bogo_buy_qty'])  && $row['bogo_buy_qty']  !== '' ? (int) $row['bogo_buy_qty']    : null,
            'bogo_free_qty' => isset($row['bogo_free_qty']) && $row['bogo_free_qty'] !== '' ? (int) $row['bogo_free_qty']   : null,
            'code_mode'     => sanitize_text_field((string) ($row['code_mode'] ?? 'batch')),
            'uses_per_code' => (int) ($row['uses_per_code'] ?? 1),
            'starts_at'     => !empty($row['starts_at'])  ? sanitize_text_field((string) $row['starts_at'])  : null,
            'expires_at'    => !empty($row['expires_at']) ? sanitize_text_field((string) $row['expires_at']) : null,
            'status'        => sanitize_text_field((string) ($row['status'] ?? 'active')),
            'created_at'    => current_time('mysql'),
        ];
        $ok = $wpdb->insert(self::t_campaigns(), $data);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get_campaign(int $id): ?array {
        global $wpdb;
        $tbl = self::t_campaigns();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /** Campaign list with derived code + redemption counts for the admin table. */
    public static function list_campaigns(int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $c = self::t_campaigns();
        $k = self::t_codes();
        $r = self::t_redemptions();
        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM `$k` WHERE campaign_id = c.id) AS code_count,
                       (SELECT COUNT(*) FROM `$r` WHERE campaign_id = c.id AND status = 'redeemed') AS redeemed_count
                FROM `$c` c
                ORDER BY c.created_at DESC
                LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
    }

    public static function set_campaign_status(int $id, string $status): bool {
        global $wpdb;
        return false !== $wpdb->update(self::t_campaigns(), ['status' => sanitize_text_field($status)], ['id' => $id]);
    }

    /* -------------------------------------------------------------------- codes */

    public static function insert_code(int $campaignId, string $code, int $usesAllowed): int {
        global $wpdb;
        $ok = $wpdb->insert(self::t_codes(), [
            'campaign_id'  => $campaignId,
            'code'         => strtoupper(sanitize_text_field($code)),
            'uses_allowed' => max(0, $usesAllowed),
            'uses_count'   => 0,
            'status'       => 'active',
            'created_at'   => current_time('mysql'),
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function code_exists(string $code): bool {
        global $wpdb;
        $tbl = self::t_codes();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$tbl` WHERE code = %s", strtoupper($code))) > 0;
    }

    public static function get_code_by_string(string $code): ?array {
        global $wpdb;
        $tbl = self::t_codes();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE code = %s LIMIT 1", strtoupper(trim($code))), ARRAY_A);
        return $row ?: null;
    }

    public static function get_code(int $id): ?array {
        global $wpdb;
        $tbl = self::t_codes();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function list_codes(int $campaignId, int $limit = 1000, int $offset = 0): array {
        global $wpdb;
        $tbl = self::t_codes();
        $sql = "SELECT * FROM `$tbl` WHERE campaign_id = %d ORDER BY id ASC LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $campaignId, $limit, $offset), ARRAY_A);
    }

    public static function set_code_status(int $id, string $status): bool {
        global $wpdb;
        return false !== $wpdb->update(self::t_codes(), ['status' => sanitize_text_field($status)], ['id' => $id]);
    }

    /**
     * Atomically commit one use of a code. The WHERE clause is the race guard:
     * it only increments when there's headroom (unlimited = uses_allowed 0, or
     * uses_count < uses_allowed), so two simultaneous commits can never push a
     * single-use code past 1. Auto-flips status to 'exhausted' on the last use.
     * Returns true only if exactly one row was updated (the caller won this).
     */
    public static function bump_code_use(int $codeId): bool {
        global $wpdb;
        $tbl = self::t_codes();
        $sql = "UPDATE `$tbl`
                SET uses_count = uses_count + 1,
                    status = CASE WHEN uses_allowed > 0 AND uses_count + 1 >= uses_allowed THEN 'exhausted' ELSE status END
                WHERE id = %d AND (uses_allowed = 0 OR uses_count < uses_allowed)";
        $affected = $wpdb->query($wpdb->prepare($sql, $codeId));
        return $affected === 1;
    }

    /* -------------------------------------------------------------- redemptions */

    public static function insert_reservation(array $row): int {
        global $wpdb;
        $ok = $wpdb->insert(self::t_redemptions(), [
            'code_id'        => (int) ($row['code_id'] ?? 0),
            'campaign_id'    => (int) ($row['campaign_id'] ?? 0),
            'policy_id'      => sanitize_text_field((string) ($row['policy_id'] ?? '')),
            'wallet_address' => isset($row['wallet_address']) ? sanitize_text_field((string) $row['wallet_address']) : null,
            'payment_method' => sanitize_text_field((string) ($row['payment_method'] ?? 'ada')),
            'quantity'       => (int) ($row['quantity'] ?? 1),
            'original_usd'   => (float) ($row['original_usd'] ?? 0),
            'discount_usd'   => (float) ($row['discount_usd'] ?? 0),
            'final_usd'      => (float) ($row['final_usd'] ?? 0),
            'status'         => 'reserved',
            'invoice_id'     => isset($row['invoice_id']) ? (int) $row['invoice_id'] : null,
            'reserved_at'    => current_time('mysql'),
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get_redemption(int $id): ?array {
        global $wpdb;
        $tbl = self::t_redemptions();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Count uses already taken or held for a code: committed redemptions plus
     * live (non-expired) reservations. Used by the reserve gate alongside the
     * code's own uses_count.
     */
    public static function count_live_uses(int $codeId, int $ttlMinutes): int {
        global $wpdb;
        $tbl = self::t_redemptions();
        $sql = "SELECT COUNT(*) FROM `$tbl`
                WHERE code_id = %d
                  AND (status = 'redeemed'
                       OR (status = 'reserved' AND reserved_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)))";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $codeId, $ttlMinutes));
    }

    public static function mark_redemption(int $id, string $status, array $extra = []): bool {
        global $wpdb;
        $data = ['status' => sanitize_text_field($status)];
        if (array_key_exists('tx_hash', $extra))    $data['tx_hash']     = $extra['tx_hash'] !== null ? sanitize_text_field((string) $extra['tx_hash']) : null;
        if (array_key_exists('invoice_id', $extra)) $data['invoice_id']  = $extra['invoice_id'] !== null ? (int) $extra['invoice_id'] : null;
        if ($status === 'redeemed')                 $data['redeemed_at'] = current_time('mysql');
        return false !== $wpdb->update(self::t_redemptions(), $data, ['id' => $id]);
    }

    /** Release reservations older than the TTL. Returns count released. */
    public static function release_stale(int $ttlMinutes): int {
        global $wpdb;
        $tbl = self::t_redemptions();
        $sql = "UPDATE `$tbl` SET status = 'released'
                WHERE status = 'reserved' AND reserved_at <= DATE_SUB(NOW(), INTERVAL %d MINUTE)";
        return (int) $wpdb->query($wpdb->prepare($sql, $ttlMinutes));
    }

    public static function list_redemptions(int $campaignId, int $limit = 500, int $offset = 0): array {
        global $wpdb;
        $r = self::t_redemptions();
        $k = self::t_codes();
        $sql = "SELECT red.*, c.code
                FROM `$r` red
                LEFT JOIN `$k` c ON c.id = red.code_id
                WHERE red.campaign_id = %d
                ORDER BY red.reserved_at DESC
                LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $campaignId, $limit, $offset), ARRAY_A);
    }
}
