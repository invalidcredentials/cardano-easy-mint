<?php
/**
 * MintBuildRegistry.php
 *
 * Server-side record of every transaction this site built and is willing to
 * co-sign with a policy key. The policy key signs whatever body it is handed,
 * so the submit paths must only sign a tx the server itself built, and only
 * once. Each build stores its context (policy, asset, quantity, wallet,
 * invoice, discount) keyed by tx id; submit claims that record atomically and
 * takes its accounting from it, never from client-posted fields.
 *
 * Records live in wp_options (autoload off) and are read/deleted with direct
 * queries, so the claim is a single DELETE whose affected-row count decides
 * the winner, independent of any object cache. Abandoned records expire after
 * TTL and are swept on the 5-minute cron.
 */

namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class MintBuildRegistry {

    const BUILD_PREFIX = '_cem_build_';
    const BURN_PREFIX  = '_cem_burned_';
    const TTL          = 1800;  // 30 min to sign + submit a built tx.
    const BURN_TTL     = 7200;  // 2 h to re-mint after a submitted burn.

    /** Tx id (hex) of an unsigned/complete tx, or '' if unparseable. */
    public static function txId(string $tx_hex): string {
        require_once __DIR__ . '/CardanoTransactionSignerPHP.php';
        return CardanoTransactionSignerPHP::txId(strtolower(trim($tx_hex)));
    }

    /**
     * Pull the tx hex out of an Anvil transactions/build response and
     * remember it with $ctx. Returns false if no parseable tx was found.
     */
    public static function rememberBuild($anvil_response, array $ctx, int $ttl = self::TTL): bool {
        if (!is_array($anvil_response)) return false;
        foreach (['complete', 'transaction', 'stripped', 'cborHex'] as $field) {
            if (!empty($anvil_response[$field]) && is_string($anvil_response[$field])) {
                return self::remember($anvil_response[$field], $ctx, $ttl);
            }
        }
        return false;
    }

    public static function remember(string $tx_hex, array $ctx, int $ttl = self::TTL): bool {
        $tx_id = self::txId($tx_hex);
        if ($tx_id === '') return false;
        $ctx['tx_id'] = $tx_id;
        return self::put(self::BUILD_PREFIX . $tx_id, $ctx, $ttl);
    }

    /**
     * Atomically take the build record for this tx. Returns its context, or
     * null when the tx was never built here, already submitted, or expired.
     */
    public static function claim(string $tx_hex): ?array {
        $tx_id = self::txId($tx_hex);
        if ($tx_id === '') return null;
        return self::take(self::BUILD_PREFIX . $tx_id);
    }

    /** Put a claimed record back so the customer can retry a failed submit. */
    public static function release(array $ctx): void {
        if (empty($ctx['tx_id']) || (int) ($ctx['expires'] ?? 0) <= time()) return;
        self::put(self::BUILD_PREFIX . $ctx['tx_id'], $ctx, (int) $ctx['expires'] - time());
    }

    /** Record that the burn leg of an asset upgrade was accepted by the chain. */
    public static function markBurned(int $burn_log_id, array $ctx): void {
        self::put(self::BURN_PREFIX . $burn_log_id, $ctx, self::BURN_TTL);
    }

    /** Read a burn marker without consuming it (re-mint build). */
    public static function burnRecord(int $burn_log_id): ?array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::BURN_PREFIX . $burn_log_id
        ));
        $ctx = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($ctx) || (int) ($ctx['expires'] ?? 0) <= time()) return null;
        return $ctx;
    }

    /** Consume a burn marker (re-mint submit). One re-mint per burn. */
    public static function takeBurn(int $burn_log_id): ?array {
        return self::take(self::BURN_PREFIX . $burn_log_id);
    }

    public static function restoreBurn(int $burn_log_id, array $ctx): void {
        if ((int) ($ctx['expires'] ?? 0) <= time()) return;
        self::put(self::BURN_PREFIX . $burn_log_id, $ctx, (int) $ctx['expires'] - time());
    }

    /** Cron: drop expired build records and burn markers. */
    public static function sweep(): void {
        global $wpdb;
        foreach ([self::BUILD_PREFIX, self::BURN_PREFIX] as $prefix) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
                $wpdb->esc_like($prefix) . '%'
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $ctx = json_decode((string) $row['option_value'], true);
                if (!is_array($ctx) || (int) ($ctx['expires'] ?? 0) <= time()) {
                    $wpdb->delete($wpdb->options, ['option_name' => $row['option_name']]);
                }
            }
        }
    }

    private static function put(string $name, array $ctx, int $ttl): bool {
        global $wpdb;
        if ($ttl <= 0) return false;
        $ctx['expires'] = time() + $ttl;
        return false !== $wpdb->replace($wpdb->options, [
            'option_name'  => $name,
            'option_value' => wp_json_encode($ctx),
            'autoload'     => 'no',
        ]);
    }

    private static function take(string $name): ?array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ));
        if (!is_string($raw)) return null;
        // Only the request whose DELETE removes the row wins the claim.
        if (1 !== (int) $wpdb->delete($wpdb->options, ['option_name' => $name])) return null;
        $ctx = json_decode($raw, true);
        if (!is_array($ctx) || (int) ($ctx['expires'] ?? 0) <= time()) return null;
        return $ctx;
    }
}
