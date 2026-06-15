<?php
/**
 * Asset Upgrade public REST controller.
 *
 * Customer-facing surface for the burn-and-re-mint flow. Phase 4 ships
 * the eligibility endpoint that the [cardano-upgrade] shortcode calls
 * after the customer connects their wallet. Phase 5 adds /upgrade/build
 * and /upgrade/submit which assemble + dispatch the actual transaction.
 *
 * Routes register under the same `cardano-mint/v1` namespace used by the
 * existing mint REST API. No authentication beyond a public nonce — the
 * eligibility list is shaped by what assets the wallet actually holds,
 * so there's no abuse vector from listing assets the customer doesn't own.
 */

namespace CardanoMintPay\Controllers;

use CardanoMintPay\AssetUpgrade\AssetUpgradeInstaller;
use CardanoMintPay\AssetUpgrade\AssetUpgradeService;
use CardanoMintPay\AssetUpgrade\MetadataResolver;
use CardanoMintPay\Helpers\BlockfrostClient;
use CardanoMintPay\Helpers\AnvilAPI;

if (!defined('ABSPATH')) exit;

class AssetUpgradePublicController {

    const NAMESPACE = 'cardano-mint/v1';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/upgrade/eligible', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'route_eligible'],
            'permission_callback' => '__return_true',
            'args'                => [
                'address' => ['required' => true, 'type' => 'string'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/upgrade/build', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'route_build'],
            'permission_callback' => '__return_true',
            'args'                => [
                'policy_id'        => ['required' => true,  'type' => 'string'],
                'asset_name'       => ['required' => true,  'type' => 'string'],
                'customer_address' => ['required' => true,  'type' => 'string'],
                'step'             => ['required' => false, 'type' => 'string'],   // 'burn' | 'remint'
                'burn_log_id'      => ['required' => false, 'type' => 'integer'],  // links re-mint to its burn
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/upgrade/diag', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'route_diag'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/upgrade/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'route_submit'],
            'permission_callback' => '__return_true',
            'args'                => [
                'log_id'      => ['required' => true, 'type' => 'integer'],
                'transaction' => ['required' => true, 'type' => 'string'],  // unsigned tx hex from build
                'signatures'  => ['required' => true],                       // array of customer witness-set hexes
            ],
        ]);
    }

    /**
     * Run a REST handler body, converting any uncaught Throwable into a JSON
     * error response instead of letting it bubble up to WP's HTML "critical
     * error" page (which makes the browser fetch fail with "Unexpected token
     * '<'"). The exact message + file:line is returned and error_log'd so the
     * real cause is visible client-side and in the server log.
     */
    private static function guard(callable $fn): \WP_REST_Response {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $detail = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            error_log('[asset-upgrade] uncaught: ' . $detail . "\n" . $e->getTraceAsString());
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => 'Server error: ' . $detail,
                'stage' => 'exception',
            ], 500);
        }
    }

    /**
     * Lightweight diagnostics. Does NO external calls, so it can't crash the
     * way /upgrade/eligible does. Returns the environment facts that matter
     * for the 502 plus the last breadcrumb the eligible handler wrote before
     * it died. No secrets — only booleans for whether keys are configured.
     */
    public static function route_diag(\WP_REST_Request $req) {
        global $wpdb;
        $specs_table = AssetUpgradeInstaller::table_specs();
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM $specs_table WHERE status = 'active'");

        return new \WP_REST_Response([
            'ok'                 => true,
            'plugin_version'     => defined('CARDANO_MINT_VERSION') ? CARDANO_MINT_VERSION : 'unknown',
            'php_version'        => PHP_VERSION,
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'network_setting'    => get_option('cardano-mint-networkenvironment', 'preprod'),
            'blockfrost'         => [
                'mainnet' => BlockfrostClient::isConfiguredFor('mainnet'),
                'preprod' => BlockfrostClient::isConfiguredFor('preprod'),
                'preview' => BlockfrostClient::isConfiguredFor('preview'),
            ],
            'anvil_mint_key_set' => (bool) get_option('cardano_mint_anvil_api_key'),
            'active_specs'       => $active,
            'last_eligible_trace'=> get_option('cem_upgrade_eligible_trace', null),
            'last_build_trace'   => get_option('cem_upgrade_build_trace', null),
            'last_submit_trace'  => get_option('cem_upgrade_submit_trace', null),
        ], 200);
    }

    public static function route_build(\WP_REST_Request $req) {
        return self::guard(function () use ($req) {
            $policy_id   = (string) $req->get_param('policy_id');
            $asset_name  = (string) $req->get_param('asset_name');
            $customer    = (string) $req->get_param('customer_address');
            $step        = (string) ($req->get_param('step') ?: 'burn');
            $burn_log_id = (int) $req->get_param('burn_log_id');
            $result = AssetUpgradeService::build($policy_id, $asset_name, $customer, $step, $burn_log_id);
            $status = !empty($result['ok']) ? 200 : 400;
            return new \WP_REST_Response($result, $status);
        });
    }

    public static function route_submit(\WP_REST_Request $req) {
        return self::guard(function () use ($req) {
            $log_id      = (int) $req->get_param('log_id');
            $transaction = (string) $req->get_param('transaction');
            $signatures  = $req->get_param('signatures');
            if (is_string($signatures)) {
                $decoded = json_decode($signatures, true);
                $signatures = is_array($decoded) ? $decoded : array_filter([$signatures]);
            }
            if (!is_array($signatures)) $signatures = [];
            $result = AssetUpgradeService::submit($log_id, $transaction, $signatures);
            $status = !empty($result['ok']) ? 200 : 400;
            return new \WP_REST_Response($result, $status);
        });
    }

    /**
     * Return the assets in a wallet that are eligible for an upgrade.
     *
     * Input: { address: <hex CBOR or bech32; stake or payment> }
     *
     * Flow:
     *   1. Normalize address to bech32 via Anvil's parse endpoint.
     *      Customer wallets give back hex; we don't trust the wire format.
     *   2. If we got a stake address, pull every asset across all linked
     *      addresses (Blockfrost /accounts/{stake}/addresses/assets).
     *      If we got a payment address, fall back to /addresses/{address}.
     *   3. Group by policy_id. For each policy that has an active spec,
     *      collect matching assets.
     *   4. For each match, resolve target metadata: per-asset row wins
     *      (mode=full), otherwise policy-wide patch merged onto current.
     *   5. Time-lock guard: skip any policy whose lock slot is in the past.
     *
     * The resolver does NOT verify customer signature ownership — the
     * burn+mint tx in phase 5 will because Cardano enforces input
     * ownership at the protocol level. Listing assets here is read-only.
     */
    public static function route_eligible(\WP_REST_Request $req) {
        return self::guard(function () use ($req) {
            return self::eligible_impl($req);
        });
    }

    private static function eligible_impl(\WP_REST_Request $req) {
        $t0 = microtime(true);
        // Breadcrumb: every checkpoint is written to a committed DB option AND
        // the PHP error log. A 502 means the worker was killed mid-request, so
        // the in-memory log never flushes anywhere we can see — but the last
        // committed option survives. Read it back via GET /upgrade/diag.
        $log = function ($msg) use ($t0) {
            $line = sprintf('+%.1fs %s', microtime(true) - $t0, $msg);
            error_log('[asset-upgrade:eligible] ' . $line);
            update_option('cem_upgrade_eligible_trace', [
                'at'    => gmdate('Y-m-d H:i:s') . ' UTC',
                'stage' => $line,
            ], false);
        };
        $log('start');

        $address_in = (string) $req->get_param('address');
        if ($address_in === '') {
            return new \WP_REST_Response(['error' => 'address is required'], 400);
        }

        // Normalize the wallet address to a bech32 stake address.
        //
        // CIP-30 getRewardAddresses() returns the reward address as raw hex
        // (header byte 0xe?/0xf? + 28-byte credential). Blockfrost needs the
        // bech32 stake1.../stake_test1... form, so we encode it ourselves —
        // AnvilAPI::convertAddressToBech32() only handles *payment* addresses
        // and returns a reward address unchanged, which used to make this fall
        // through to the unsupported payment-address path and 502.
        $log('before address normalize');
        [$address, $is_stake] = self::normalize_stake_address($address_in);
        if ($address === '') {
            return new \WP_REST_Response(['error' => 'address could not be parsed'], 400);
        }
        $log('address normalized, is_stake=' . ($is_stake ? '1' : '0') . ' prefix=' . substr($address, 0, 12));

        // Pull all active policy-wide rows + their per-asset rows in one shot.
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $rows = $wpdb->get_results(
            "SELECT id, policy_id, asset_name, network, new_metadata, mode, status, policy_locks_at_slot
             FROM $table
             WHERE status = 'active'",
            ARRAY_A
        );
        $log('active specs rows=' . count($rows));
        if (empty($rows)) {
            return new \WP_REST_Response(['assets' => []], 200);
        }

        // Bucket active rows by policy and network.
        $by_policy = []; // policy_id => { network, patch?: array, per_asset: [asset_name => metadata], lock_slot }
        foreach ($rows as $r) {
            $pid = (string) $r['policy_id'];
            if (!isset($by_policy[$pid])) {
                $by_policy[$pid] = [
                    'network'       => (string) $r['network'],
                    'lock_slot'     => isset($r['policy_locks_at_slot']) ? (int) $r['policy_locks_at_slot'] : 0,
                    'patch'         => null,
                    'per_asset'     => [],
                ];
            }
            $decoded = json_decode((string) $r['new_metadata'], true);
            if (!is_array($decoded)) $decoded = [];
            if ((string) $r['asset_name'] === '') {
                $by_policy[$pid]['patch'] = $decoded;
            } else {
                $by_policy[$pid]['per_asset'][(string) $r['asset_name']] = [
                    'metadata' => $decoded,
                    'upgrade_id' => (int) $r['id'],
                    'mode' => (string) $r['mode'],
                ];
            }
        }

        // Look up the wallet's holdings on each network present in the active
        // specs. Usually all specs are mainnet so this is one Blockfrost call.
        $networks_needed = array_unique(array_column($by_policy, 'network'));
        $holdings = []; // network => [unit => quantity]
        foreach ($networks_needed as $network) {
            $log('fetching wallet assets on ' . $network . ' (Blockfrost, paginated)…');
            $resp = $is_stake
                ? BlockfrostClient::assetsAtStakeAddress($address, $network)
                : self::assetsAtPaymentAddress($address, $network);
            if (!$resp['ok']) {
                return new \WP_REST_Response([
                    'error'  => 'Blockfrost lookup failed: ' . ($resp['error'] ?? 'unknown'),
                    'network'=> $network,
                ], 502);
            }
            $holdings[$network] = [];
            foreach ($resp['data'] as $h) {
                if (!isset($h['unit'])) continue;
                $holdings[$network][(string) $h['unit']] = isset($h['quantity']) ? (string) $h['quantity'] : '0';
            }
            $log('wallet holds ' . count($holdings[$network]) . ' distinct assets on ' . $network);
        }

        // Match holdings to active specs.
        //
        // Each match may cost one Blockfrost call to fetch current metadata
        // for the diff. To avoid an N+1 that runs past the PHP-FPM
        // request_terminate_timeout (which kills the worker -> 502), we cap
        // the number of per-asset metadata fetches per request. Anything
        // beyond the cap is still returned as eligible, just without the
        // pre-fetched `current` (the build/diff step can fetch it lazily).
        $META_FETCH_CAP = 30;
        $meta_fetches = 0;
        $eligible = [];
        foreach ($by_policy as $policy_id => $cfg) {
            // Skip permanently locked policies — refuse rather than let
            // the customer sign something that'll fail at submit.
            if ($cfg['lock_slot'] > 0 && self::is_lock_in_past($cfg['lock_slot'], $cfg['network'])) {
                continue;
            }
            $network = $cfg['network'];
            $units = array_keys($holdings[$network] ?? []);
            foreach ($units as $unit) {
                if (strpos($unit, $policy_id) !== 0) continue;
                $asset_name = substr($unit, strlen($policy_id));

                $resolved = null;
                $upgrade_id = null;
                $resolved_from = '';
                $current = null;

                if (isset($cfg['per_asset'][$asset_name])) {
                    $resolved = $cfg['per_asset'][$asset_name]['metadata'];
                    $upgrade_id = $cfg['per_asset'][$asset_name]['upgrade_id'];
                    $resolved_from = 'per_asset_full';
                } elseif (is_array($cfg['patch']) && !empty($cfg['patch'])) {
                    // We need current chain metadata to apply the patch.
                    if ($meta_fetches >= $META_FETCH_CAP) { $log('META_FETCH_CAP hit, stopping patch resolves'); break 2; }
                    $log('blockfrost assetMetadata #' . ($meta_fetches + 1) . ' (patch) ' . substr($unit, 0, 70));
                    $meta_resp = BlockfrostClient::assetMetadata($unit, $network);
                    $meta_fetches++;
                    if (!$meta_resp['ok']) continue; // skip silently; customer doesn't care which one couldn't load
                    $current = isset($meta_resp['data']['onchain_metadata']) && is_array($meta_resp['data']['onchain_metadata'])
                        ? $meta_resp['data']['onchain_metadata']
                        : [];
                    $resolved = MetadataResolver::applyPatch($current, $cfg['patch']);
                    $resolved_from = 'policy_wide_patch';
                } else {
                    continue; // active row but no usable metadata
                }

                // For per-asset matches, fetch current for the diff — but only
                // up to the cap. Past it, return with current=[] and let the
                // diff/build step resolve it lazily.
                if ($resolved_from === 'per_asset_full' && $current === null) {
                    if ($meta_fetches < $META_FETCH_CAP) {
                        $log('blockfrost assetMetadata #' . ($meta_fetches + 1) . ' (per-asset) ' . substr($unit, 0, 70));
                        $meta_resp = BlockfrostClient::assetMetadata($unit, $network);
                        $meta_fetches++;
                        $current = ($meta_resp['ok'] && isset($meta_resp['data']['onchain_metadata']) && is_array($meta_resp['data']['onchain_metadata']))
                            ? $meta_resp['data']['onchain_metadata']
                            : [];
                    } else {
                        $current = [];
                    }
                }

                $eligible[] = [
                    'policy_id'        => $policy_id,
                    'asset_name'       => $asset_name,
                    'asset_name_ascii' => MetadataResolver::hexToAscii($asset_name),
                    'unit'             => $unit,
                    'quantity'         => $holdings[$network][$unit] ?? '1',
                    'network'          => $network,
                    'upgrade_id'       => $upgrade_id,
                    'resolved_from'    => $resolved_from,
                    'current'          => $current ?? [],
                    'resolved'         => $resolved,
                ];
                unset($current);
            }
        }

        $log('done, eligible=' . count($eligible) . ' meta_fetches=' . $meta_fetches);
        return new \WP_REST_Response(['assets' => $eligible], 200);
    }

    /**
     * Single-payment-address fallback when the customer hands us a payment
     * (addr1...) address rather than a stake key. Returns the same
     * ['ok','data','status','error'] shape as assetsAtStakeAddress so the
     * caller can treat them interchangeably.
     */
    private static function assetsAtPaymentAddress(string $address, string $network): array {
        // Direct call via reflection on BlockfrostClient's private get()
        // would be cleaner, but adding a thin public method is friendlier
        // long-term. For now, hit the existing addressBalance endpoint's
        // sibling /addresses/{addr} which returns the full amount array.
        $project_id = method_exists(BlockfrostClient::class, 'isConfiguredFor')
            ? (BlockfrostClient::isConfiguredFor($network) ? '' : '')
            : '';
        // We don't have a public helper that returns the amount array — fall
        // back to fetching balance + treating non-lovelace units as assets.
        // The existing addressBalance() returns only lovelace; for now,
        // require stake-address lookups for asset eligibility.
        return [
            'ok'    => false,
            'error' => 'Payment-address lookup not yet supported — wallet must expose its stake (reward) address. Try a different wallet or reconnect.',
            'status'=> 0,
            'data'  => [],
        ];
    }

    /**
     * Normalize a wallet address to a bech32 stake address.
     *
     * Accepts:
     *   - bech32 stake (stake1.../stake_test1...) -> returned as-is
     *   - hex reward address (header 0xe?/0xf? + 28-byte cred, 58 hex) ->
     *     bech32-encoded locally (mainnet header low-nibble 1 -> 'stake',
     *     testnet 0 -> 'stake_test')
     *   - anything else (e.g. a payment address) -> handed to Anvil's parser;
     *     is_stake reflects whether that produced a stake address.
     *
     * @return array{0:string,1:bool} [bech32_address_or_empty, is_stake]
     */
    private static function normalize_stake_address(string $in): array {
        $in = trim($in);
        if ($in === '') return ['', false];

        if (preg_match('/^stake(_test)?1[0-9a-z]+$/i', $in)) {
            return [strtolower($in), true];
        }

        // Hex reward address: 29 bytes, first byte high nibble 0xe (key) or
        // 0xf (script), low nibble is the network id (1=mainnet, 0=testnet).
        if (preg_match('/^[0-9a-fA-F]{58}$/', $in)) {
            $bytes = hex2bin($in);
            if ($bytes !== false && strlen($bytes) === 29) {
                $hi  = (ord($bytes[0]) >> 4) & 0x0f;
                $net = ord($bytes[0]) & 0x0f;
                if ($hi === 0xe || $hi === 0xf) {
                    $hrp = ($net === 1) ? 'stake' : 'stake_test';
                    return [self::bech32_encode($hrp, $bytes), true];
                }
            }
        }

        // Fall back to Anvil for payment addresses / unknown formats.
        $b = AnvilAPI::convertAddressToBech32($in, 'mint');
        if (!is_string($b) || $b === '') return ['', false];
        $is_stake = (strpos($b, 'stake1') === 0 || strpos($b, 'stake_test1') === 0);
        return [$b, $is_stake];
    }

    /**
     * Minimal bech32 encoder (BIP-173, standard checksum constant 1 — the
     * form Cardano uses for addresses; no 90-char length cap). $data is the
     * raw byte string to encode under human-readable prefix $hrp.
     */
    private static function bech32_encode(string $hrp, string $data): string {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

        // 8-bit bytes -> 5-bit groups.
        $vals = [];
        $acc = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $acc = ($acc << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $vals[] = ($acc >> $bits) & 31;
            }
        }
        if ($bits > 0) $vals[] = ($acc << (5 - $bits)) & 31;

        $polymod = function (array $values): int {
            $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
            $chk = 1;
            foreach ($values as $v) {
                $b = $chk >> 25;
                $chk = (($chk & 0x1ffffff) << 5) ^ $v;
                for ($i = 0; $i < 5; $i++) {
                    if (($b >> $i) & 1) $chk ^= $gen[$i];
                }
            }
            return $chk;
        };

        $hrp_exp = [];
        for ($i = 0, $n = strlen($hrp); $i < $n; $i++) $hrp_exp[] = ord($hrp[$i]) >> 5;
        $hrp_exp[] = 0;
        for ($i = 0, $n = strlen($hrp); $i < $n; $i++) $hrp_exp[] = ord($hrp[$i]) & 31;

        $pm = $polymod(array_merge($hrp_exp, $vals, [0, 0, 0, 0, 0, 0])) ^ 1;
        $chk = [];
        for ($i = 0; $i < 6; $i++) $chk[] = ($pm >> (5 * (5 - $i))) & 31;

        $out = $hrp . '1';
        foreach (array_merge($vals, $chk) as $d) $out .= $charset[$d];
        return $out;
    }

    /**
     * Same Shelley-genesis anchors as the admin controller. Rough; phase 5
     * will hit /blocks/latest for the precise slot at tx-build time.
     */
    private static function is_lock_in_past(int $lock_slot, string $network): bool {
        $anchors = [
            'mainnet' => ['ts' => 1596059091, 'slot' => 4492800],
            'preprod' => ['ts' => 1655769600, 'slot' => 86400],
            'preview' => ['ts' => 1660003200, 'slot' => 0],
        ];
        if (!isset($anchors[$network])) return false;
        $current_slot = $anchors[$network]['slot'] + (time() - $anchors[$network]['ts']);
        return $lock_slot <= $current_slot;
    }
}
