<?php
namespace CardanoMintPay\Models;

use CardanoMintPay\AltPay\AltPayInstaller;

if (!defined('ABSPATH')) exit;

/**
 * ChainInvoiceModel
 *
 * Per-mint payment intent rows. Status is the source of truth for whether the
 * customer can proceed to /mint/build:
 *
 *   pending   issued, no payment seen
 *   funded    on-chain payment within +/- tolerance, ready to mint
 *   underpaid customer paid less than expected outside tolerance
 *   overpaid  customer paid more than expected outside tolerance
 *   expired   no payment within window
 *   cancelled customer abandoned (HD index is not freed)
 *   consumed  the Cardano mint tx submitted; row is closed
 *   refunded  funds returned from the child address
 *   swept     funds moved to the operator's cold address
 */
class ChainInvoiceModel {

    public static function table(): string {
        return AltPayInstaller::table_invoices();
    }

    public static function insert(array $row): int {
        global $wpdb;
        AltPayInstaller::maybe_install();

        $data = [
            'parent_wallet_id'         => (int) ($row['parent_wallet_id'] ?? 0),
            'chain'                    => sanitize_text_field($row['chain'] ?? ''),
            'derivation_index'         => (int) ($row['derivation_index'] ?? 0),
            'address'                  => sanitize_text_field($row['address'] ?? ''),
            'currency'                 => sanitize_text_field($row['currency'] ?? ''),
            'expected_amount_minor'    => (string) ($row['expected_amount_minor'] ?? '0'),
            'rate_locked'              => (float) ($row['rate_locked'] ?? 0),
            'rate_expires_at'          => $row['rate_expires_at'] ?? current_time('mysql'),
            'mint_id'                  => (int) ($row['mint_id'] ?? 0),
            'customer_cardano_address' => sanitize_text_field($row['customer_cardano_address'] ?? ''),
            'status'                   => sanitize_text_field($row['status'] ?? 'pending'),
            'created_at'               => current_time('mysql'),
            'expires_at'               => $row['expires_at'] ?? current_time('mysql'),
        ];

        $ok = $wpdb->insert(self::table(), $data, ['%d','%s','%d','%s','%s','%s','%f','%s','%d','%s','%s','%s','%s']);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function get_by_address(string $chain, string $address): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$tbl` WHERE chain = %s AND address = %s",
            $chain,
            $address
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function set_status(int $id, string $status, array $extra = []): bool {
        global $wpdb;
        $data = ['status' => sanitize_text_field($status)];
        $fmt  = ['%s'];
        foreach ($extra as $k => $v) {
            $data[$k] = $v;
            $fmt[]    = is_int($v) ? '%d' : '%s';
        }
        return false !== $wpdb->update(self::table(), $data, ['id' => $id], $fmt, ['%d']);
    }

    public static function find_pending(int $limit = 50): array {
        global $wpdb;
        $tbl = self::table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$tbl` WHERE status = %s AND expires_at > NOW() ORDER BY created_at ASC LIMIT %d",
            'pending',
            $limit
        ), ARRAY_A);
    }

    public static function expire_past_due(): int {
        global $wpdb;
        $tbl = self::table();
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE `$tbl` SET status = %s WHERE status = %s AND expires_at <= NOW()",
            'expired',
            'pending'
        ));
        return (int) $rows;
    }

    public static function list_filtered(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $tbl = self::table();
        $where = ['1=1'];
        $args  = [];
        if (!empty($filters['chain'])) { $where[] = 'chain = %s';  $args[] = $filters['chain']; }
        if (!empty($filters['status'])) { $where[] = 'status = %s'; $args[] = $filters['status']; }
        if (!empty($filters['mint_id'])) { $where[] = 'mint_id = %d'; $args[] = (int) $filters['mint_id']; }
        $args[] = $limit;
        $args[] = $offset;
        $sql = "SELECT * FROM `$tbl` WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }
}
