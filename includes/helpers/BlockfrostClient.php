<?php
/**
 * Blockfrost client.
 *
 * Used for ADA payment-wallet read operations only (address balance lookup
 * for the dashboard). Transaction build + submit go through the Anvil API
 * helper since we already authenticate there.
 *
 * Project IDs are network-specific and configured in the plugin settings.
 * The free Blockfrost tier (50K req/day, 10/sec) is plenty for this use.
 */

namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class BlockfrostClient {

    /**
     * Look up the lovelace balance at a single payment address.
     *
     * Returns ['lovelace' => '<minor units string>', 'address' => $address]
     * on success, or ['lovelace' => '0', ...] when the address has no UTxOs
     * (Blockfrost returns 404 in that case, which we treat as zero balance).
     *
     * Returns ['error' => '...'] on misconfiguration / network failure so
     * the dashboard can show the failure inline rather than blowing up.
     */
    public static function addressBalance(string $address, string $network): array {
        if ($address === '') return ['lovelace' => '0', 'address' => $address, 'error' => 'no address'];

        $project_id = self::projectIdFor($network);
        if ($project_id === '') {
            return [
                'lovelace' => '0',
                'address'  => $address,
                'error'    => "Blockfrost project ID for {$network} is not set in Settings.",
            ];
        }

        $base = self::baseUrlFor($network);
        if ($base === '') return ['lovelace' => '0', 'address' => $address, 'error' => "unknown network: {$network}"];

        $url = $base . '/addresses/' . rawurlencode($address);
        $response = wp_remote_get($url, [
            'headers' => ['project_id' => $project_id],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return ['lovelace' => '0', 'address' => $address, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 404) {
            // Address has never received funds. Treat as zero, not an error.
            return ['lovelace' => '0', 'address' => $address];
        }
        if ($code !== 200) {
            $decoded = json_decode($body, true);
            $msg = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : ('http ' . $code);
            return ['lovelace' => '0', 'address' => $address, 'error' => $msg];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['amount']) || !is_array($data['amount'])) {
            return ['lovelace' => '0', 'address' => $address, 'error' => 'unexpected response shape'];
        }

        $lovelace = '0';
        foreach ($data['amount'] as $entry) {
            if (is_array($entry) && ($entry['unit'] ?? '') === 'lovelace') {
                $lovelace = (string) ($entry['quantity'] ?? '0');
                break;
            }
        }
        return ['lovelace' => $lovelace, 'address' => $address];
    }

    public static function isConfiguredFor(string $network): bool {
        return self::projectIdFor($network) !== '';
    }

    /* ─── Asset Upgrade reads ─────────────────────────────────────────────
     *
     * Three thin GETs used by the Asset Upgrade (burn & re-mint) flow:
     *   - assetsByPolicy: list every asset under a policy (paginated)
     *   - assetMetadata:  current on-chain CIP-25 metadata for one asset
     *   - policyScript:   the native script JSON for time-lock decoding
     *
     * Each call returns ['ok' => bool, 'data' => mixed, 'error' => ?string,
     * 'status' => int]. Caller doesn't need to know whether 404 means
     * "not found yet" vs. "you misconfigured" — error string covers it.
     */

    /**
     * List assets under a policy, one Blockfrost page at a time.
     * Returns ['ok'=>true, 'data'=>[{asset, quantity}, ...]] on success.
     *
     * count and page mirror Blockfrost: count max 100, page 1-indexed.
     * Caller is responsible for iterating pages until an empty array
     * comes back — Blockfrost has no total-count header.
     */
    public static function assetsByPolicy(string $policyId, string $network, int $page = 1, int $count = 100): array {
        return self::get('/assets/policy/' . rawurlencode($policyId), $network, [
            'page'  => max(1, $page),
            'count' => max(1, min(100, $count)),
        ]);
    }

    /**
     * Latest on-chain metadata for a single asset. The asset id is the
     * 56-char policy id concatenated with the hex-encoded asset name, no
     * separator — Blockfrost's wire format.
     *
     * The returned 'data' object includes onchain_metadata (the CIP-25
     * 721 block as a parsed object), quantity (current circulating
     * supply), fingerprint, and policy_id / asset_name split out.
     */
    public static function assetMetadata(string $assetId, string $network): array {
        return self::get('/assets/' . rawurlencode($assetId), $network);
    }

    /**
     * Fetch the native script JSON for a policy. Blockfrost expects the
     * policy id as the script hash (they're the same value for native
     * policies). Caller can pass the result to decodePolicyLockSlot() to
     * pull out a time-lock slot if present.
     */
    public static function policyScript(string $policyId, string $network): array {
        return self::get('/scripts/' . rawurlencode($policyId) . '/json', $network);
    }

    /**
     * List every asset held under a stake address, walking pagination.
     * Used by the Asset Upgrade eligibility endpoint to find what the
     * customer's connected wallet actually owns across all their addresses
     * (Cardano wallets have many payment addresses under one stake key).
     *
     * Returns ['ok'=>true, 'data'=>[ {unit, quantity}, ... ]] on success.
     * Returns ['ok'=>false, ...] with status=404 when the stake address
     * has no on-chain activity yet (an empty wallet); caller can treat
     * that as "no eligible upgrades" rather than an error.
     */
    public static function assetsAtStakeAddress(string $stakeAddress, string $network): array {
        $all = [];
        $page = 1;
        while (true) {
            $resp = self::get(
                '/accounts/' . rawurlencode($stakeAddress) . '/addresses/assets',
                $network,
                ['page' => $page, 'count' => 100]
            );
            if (!$resp['ok']) {
                // 404 from this endpoint means "stake address has never had
                // on-chain activity" — treat as empty list, not an error.
                if (($resp['status'] ?? 0) === 404) {
                    return ['ok' => true, 'data' => [], 'status' => 404, 'error' => null];
                }
                return $resp;
            }
            $batch = is_array($resp['data']) ? $resp['data'] : [];
            foreach ($batch as $row) {
                if (is_array($row) && isset($row['unit'])) $all[] = $row;
            }
            if (count($batch) < 100) break;
            $page++;
            if ($page > 200) break; // 20k asset hard cap
        }
        return ['ok' => true, 'data' => $all, 'status' => 200, 'error' => null];
    }

    /**
     * Walk a native script tree looking for the first "before" clause.
     * Returns the slot number after which the policy is locked, or null
     * if no time-lock exists.
     *
     * The result of /scripts/{hash}/json is wrapped in a top-level "json"
     * key. We accept either the wrapper or the bare script for caller
     * convenience.
     *
     * NOTE: For complex policies using "any" combinators with multiple
     * time-locks, this returns the first one found, which may not be
     * the actual lock semantics. Adequate for the typical "all of: sig,
     * before slot" CIP-25 structure. Refine in a later phase if we
     * encounter weirder scripts in the wild.
     */
    public static function decodePolicyLockSlot($script): ?int {
        if (!is_array($script)) return null;
        if (isset($script['json']) && is_array($script['json'])) $script = $script['json'];
        if (!isset($script['type'])) return null;

        if ($script['type'] === 'before') {
            return isset($script['slot']) ? (int) $script['slot'] : null;
        }
        if (in_array($script['type'], ['all', 'any', 'atLeast'], true) && isset($script['scripts']) && is_array($script['scripts'])) {
            foreach ($script['scripts'] as $sub) {
                $slot = self::decodePolicyLockSlot($sub);
                if ($slot !== null) return $slot;
            }
        }
        return null;
    }

    /* ─── internals ───────────────────────────────────────────────────── */

    /**
     * Shared Blockfrost GET. Returns:
     *   ['ok' => bool, 'data' => mixed, 'status' => int, 'error' => ?string]
     * Network errors and non-2xx responses map to ok=false with an error
     * string. 404 is surfaced as ok=false with status=404 so callers can
     * treat "not found" distinctly from "request blew up".
     */
    private static function get(string $path, string $network, array $query = []): array {
        $project_id = self::projectIdFor($network);
        if ($project_id === '') {
            return ['ok' => false, 'error' => "Blockfrost project ID for {$network} is not set in Settings.", 'status' => 0, 'data' => null];
        }
        $base = self::baseUrlFor($network);
        if ($base === '') {
            return ['ok' => false, 'error' => "unknown network: {$network}", 'status' => 0, 'data' => null];
        }

        $url = $base . $path;
        if (!empty($query)) $url .= '?' . http_build_query($query);

        $response = wp_remote_get($url, [
            'headers' => ['project_id' => $project_id],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'status' => 0, 'data' => null];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code === 200) {
            return ['ok' => true, 'data' => $data, 'status' => 200, 'error' => null];
        }
        $msg = is_array($data) && isset($data['message']) ? (string) $data['message'] : ('http ' . $code);
        return ['ok' => false, 'error' => $msg, 'status' => $code, 'data' => $data];
    }

    private static function projectIdFor(string $network): string {
        switch ($network) {
            case 'mainnet': return (string) get_option('cardano_mint_altpay_blockfrost_mainnet', '');
            case 'preprod': return (string) get_option('cardano_mint_altpay_blockfrost_preprod', '');
            case 'preview': return (string) get_option('cardano_mint_altpay_blockfrost_preview', '');
        }
        return '';
    }

    private static function baseUrlFor(string $network): string {
        switch ($network) {
            case 'mainnet': return 'https://cardano-mainnet.blockfrost.io/api/v0';
            case 'preprod': return 'https://cardano-preprod.blockfrost.io/api/v0';
            case 'preview': return 'https://cardano-preview.blockfrost.io/api/v0';
        }
        return '';
    }
}
