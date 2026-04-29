<?php
namespace CardanoMintPay\Models;

use CardanoMintPay\AltPay\AltPayInstaller;

if (!defined('ABSPATH')) exit;

/**
 * ChainTxLogModel
 *
 * Append-only audit trail for every observed in/out/refund/sweep tx tied to
 * an invoice. raw_payload stores whatever the chain RPC returned, redacted of
 * key material before insert.
 */
class ChainTxLogModel {

    public static function table(): string {
        return AltPayInstaller::table_tx_log();
    }

    public static function append(array $row): int {
        global $wpdb;
        AltPayInstaller::maybe_install();

        $data = [
            'invoice_id'    => (int) ($row['invoice_id'] ?? 0),
            'tx_hash'       => sanitize_text_field($row['tx_hash'] ?? ''),
            'direction'     => sanitize_text_field($row['direction'] ?? 'in'),
            'amount_minor'  => (string) ($row['amount_minor'] ?? '0'),
            'confirmations' => isset($row['confirmations']) ? (int) $row['confirmations'] : null,
            'raw_payload'   => isset($row['raw_payload']) ? wp_json_encode($row['raw_payload']) : null,
            'seen_at'       => current_time('mysql'),
        ];
        $ok = $wpdb->insert(self::table(), $data, ['%d','%s','%s','%s','%d','%s','%s']);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function for_invoice(int $invoiceId): array {
        global $wpdb;
        $tbl = self::table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$tbl` WHERE invoice_id = %d ORDER BY seen_at DESC",
            $invoiceId
        ), ARRAY_A);
    }
}
