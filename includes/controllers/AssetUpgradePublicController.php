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
                'policy_id'        => ['required' => true, 'type' => 'string'],
                'asset_name'       => ['required' => true, 'type' => 'string'],
                'customer_address' => ['required' => true, 'type' => 'string'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/upgrade/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'route_submit'],
            'permission_callback' => '__return_true',
            'args'                => [
                'log_id'         => ['required' => true, 'type' => 'integer'],
                'signed_tx_hex'  => ['required' => true, 'type' => 'string'],
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

    public static function route_build(\WP_REST_Request $req) {
        return self::guard(function () use ($req) {
            $policy_id  = (string) $req->get_param('policy_id');
            $asset_name = (string) $req->get_param('asset_name');
            $customer   = (string) $req->get_param('customer_address');
            $result = AssetUpgradeService::build($policy_id, $asset_name, $customer);
            $status = !empty($result['ok']) ? 200 : 400;
            return new \WP_REST_Response($result, $status);
        });
    }

    public static function route_submit(\WP_REST_Request $req) {
        return self::guard(function () use ($req) {
            $log_id        = (int) $req->get_param('log_id');
            $signed_tx_hex = (string) $req->get_param('signed_tx_hex');
            $result = AssetUpgradeService::submit($log_id, $signed_tx_hex);
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
        $address_in = (string) $req->get_param('address');
        if ($address_in === '') {
            return new \WP_REST_Response(['error' => 'address is required'], 400);
        }

        // Normalize. AnvilAPI handles both payment + stake hex; if it's
        // already bech32 it short-circuits and returns as-is.
        $address = AnvilAPI::convertAddressToBech32($address_in);
        if (!is_string($address) || $address === '') {
            return new \WP_REST_Response(['error' => 'address could not be parsed'], 400);
        }
        $is_stake = str_starts_with($address, 'stake1') || str_starts_with($address, 'stake_test1');

        // Pull all active policy-wide rows + their per-asset rows in one shot.
        global $wpdb;
        $table = AssetUpgradeInstaller::table_specs();
        $rows = $wpdb->get_results(
            "SELECT id, policy_id, asset_name, network, new_metadata, mode, status, policy_locks_at_slot
             FROM $table
             WHERE status = 'active'",
            ARRAY_A
        );
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
        }

        // Match holdings to active specs.
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

                if (isset($cfg['per_asset'][$asset_name])) {
                    $resolved = $cfg['per_asset'][$asset_name]['metadata'];
                    $upgrade_id = $cfg['per_asset'][$asset_name]['upgrade_id'];
                    $resolved_from = 'per_asset_full';
                } elseif (is_array($cfg['patch']) && !empty($cfg['patch'])) {
                    // We need current chain metadata to apply the patch.
                    $meta_resp = BlockfrostClient::assetMetadata($unit, $network);
                    if (!$meta_resp['ok']) continue; // skip silently; customer doesn't care which one couldn't load
                    $current = isset($meta_resp['data']['onchain_metadata']) && is_array($meta_resp['data']['onchain_metadata'])
                        ? $meta_resp['data']['onchain_metadata']
                        : [];
                    $resolved = MetadataResolver::applyPatch($current, $cfg['patch']);
                    $resolved_from = 'policy_wide_patch';
                } else {
                    continue; // active row but no usable metadata
                }

                // Always fetch current — front-end needs it for the diff.
                if ($resolved_from === 'per_asset_full' && !isset($current)) {
                    $meta_resp = BlockfrostClient::assetMetadata($unit, $network);
                    $current = ($meta_resp['ok'] && isset($meta_resp['data']['onchain_metadata']) && is_array($meta_resp['data']['onchain_metadata']))
                        ? $meta_resp['data']['onchain_metadata']
                        : [];
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
