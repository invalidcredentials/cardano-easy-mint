<?php
namespace CardanoMintPay\Models;

use CardanoMintPay\AltPay\AltPayInstaller;
use CardanoMintPay\Helpers\EncryptionHelper;

if (!defined('ABSPATH')) exit;

/**
 * ChainWalletModel
 *
 * CRUD over wp_cm_chain_wallets, plus the next-index allocator that providers
 * call when issuing a new invoice. Indexes are monotonic per parent wallet:
 * cancelled invoices do not free their derivation index.
 */
class ChainWalletModel {

    public static function table(): string {
        return AltPayInstaller::table_wallets();
    }

    /**
     * Insert a parent wallet row. xprv must be plaintext on the way in;
     * encryption happens here so callers cannot accidentally store cleartext.
     *
     * @return int New row id
     */
    public static function insert(array $row): int {
        global $wpdb;
        AltPayInstaller::maybe_install();

        $xprv = (string) ($row['xprv'] ?? '');
        if ($xprv === '') {
            error_log('[CardanoMint AltPay] ChainWalletModel::insert called without xprv');
            return 0;
        }

        $data = [
            'chain'          => sanitize_text_field($row['chain'] ?? ''),
            'name'           => sanitize_text_field($row['name'] ?? ''),
            'network'        => sanitize_text_field($row['network'] ?? 'mainnet'),
            'xprv_encrypted' => EncryptionHelper::encrypt($xprv),
            'xpub'           => sanitize_text_field($row['xpub'] ?? ''),
            'next_index'     => 0,
            'source'         => sanitize_text_field($row['source'] ?? 'generated'),
            'archived'       => 0,
            'created_at'     => current_time('mysql'),
        ];
        $ok = $wpdb->insert(self::table(), $data, ['%s','%s','%s','%s','%s','%d','%s','%d','%s']);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** Decrypt and return the plaintext xprv for the given wallet row, or '' on miss. */
    public static function get_xprv(int $walletId): string {
        global $wpdb;
        $tbl = self::table();
        $enc = $wpdb->get_var($wpdb->prepare("SELECT xprv_encrypted FROM `$tbl` WHERE id = %d", $walletId));
        if (!$enc) return '';
        return (string) EncryptionHelper::decrypt($enc);
    }

    public static function get(int $walletId): ?array {
        global $wpdb;
        $tbl = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tbl` WHERE id = %d", $walletId), ARRAY_A);
        return $row ?: null;
    }

    public static function list_for_chain(string $chain, bool $include_archived = false): array {
        global $wpdb;
        $tbl = self::table();
        $sql = "SELECT * FROM `$tbl` WHERE chain = %s";
        $args = [$chain];
        if (!$include_archived) {
            $sql .= " AND archived = 0";
        }
        $sql .= " ORDER BY id DESC";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }

    public static function set_archived(int $walletId, bool $archived): bool {
        global $wpdb;
        return false !== $wpdb->update(self::table(), ['archived' => $archived ? 1 : 0], ['id' => $walletId], ['%d'], ['%d']);
    }

    /**
     * Move a wallet to a different network. Safe for BTC testnet <-> signet
     * (shared HRP `tb` and shared coin_type 1) and for ETH mainnet <-> sepolia
     * (BIP-32 secp256k1 paths are identical, addresses don't change).
     * NOT safe across mainnet <-> testnet for BTC because mainnet uses coin
     * type 0; the caller should warn the operator before flipping.
     */
    public static function set_network(int $walletId, string $network): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            ['network' => sanitize_text_field($network)],
            ['id'      => $walletId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Allocate the next derivation index for a parent wallet and bump the
     * counter atomically. Uses an UPDATE … WHERE next_index = expected loop
     * to avoid a race when two quotes land at the same instant.
     *
     * @return int Allocated index, or -1 on failure.
     */
    public static function allocate_next_index(int $walletId): int {
        global $wpdb;
        $tbl = self::table();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $current = $wpdb->get_var($wpdb->prepare("SELECT next_index FROM `$tbl` WHERE id = %d", $walletId));
            if ($current === null) return -1;
            $current = (int) $current;
            $next = $current + 1;
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE `$tbl` SET next_index = %d WHERE id = %d AND next_index = %d",
                $next,
                $walletId,
                $current
            ));
            if ($rows === 1) return $current;
        }
        error_log('[CardanoMint AltPay] allocate_next_index lost race 5 times for wallet ' . $walletId);
        return -1;
    }
}
