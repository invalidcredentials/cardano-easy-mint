<?php
namespace CardanoMintPay\AssetUpgrade;

use CardanoMintPay\Helpers\AnvilAPI;
use CardanoMintPay\Helpers\BlockfrostClient;
use CardanoMintPay\Helpers\CardanoCLI;
use CardanoMintPay\Helpers\EncryptionHelper;
use CardanoMintPay\Models\MintModel;

if (!defined('ABSPATH')) exit;

/**
 * AssetUpgradeService
 *
 * Orchestrates the burn-and-re-mint tx flow:
 *
 *   build($policy_id, $asset_name, $customer_address)
 *       — fetch current chain metadata, resolve target via MetadataResolver,
 *         look up policy script, compose Anvil multi-mint payload
 *         (mint -1 + mint +1), call Anvil /transactions/build, log a 'built'
 *         event, return { unsigned_tx_hex, fee_lovelace, log_id, ... }.
 *
 *   submit($log_id, $signed_tx_hex)
 *       — load the build context from the audit log, decrypt the policy
 *         skey, sign $signed_tx_hex with it via CardanoCLI, call Anvil
 *         /transactions/submit, log either a 'submitted' or 'failed' event,
 *         return { ok, tx_hash, error? }.
 *
 * The audit log (wp_cardano_asset_upgrade_log) is append-only: every event
 * (built / submitted / confirmed / failed) is its own row; rows are never
 * mutated. See docs/BUILD_PLAN.md (decision AU-D8).
 *
 * IMPORTANT — Anvil payload shape: the mint array with both a negative-
 * quantity (burn) and positive-quantity (re-mint) entry under the same
 * policy is the load-bearing pattern. This matches CIP-25 atomic update
 * semantics natively, but the exact Anvil field name for the negative
 * quantity ('quantity' here) needs to be confirmed against a preprod
 * sandbox before considering this production-ready. See open question #1
 * in BUILD_PLAN.md.
 */
class AssetUpgradeService {

    public static function table_specs(): string {
        return AssetUpgradeInstaller::table_specs();
    }

    public static function table_log(): string {
        return AssetUpgradeInstaller::table_log();
    }

    /**
     * Build a burn-and-re-mint transaction. Returns either:
     *   [ 'ok' => true,
     *     'unsigned_tx_hex' => '...',  // CBOR hex from Anvil
     *     'fee_lovelace'    => '170000',
     *     'log_id'          => 42,
     *     'asset_name'      => '...',
     *     'policy_id'       => '...' ]
     * or:
     *   [ 'ok' => false, 'error' => '<message>', 'stage' => '<step>' ]
     *
     * The log_id is the audit-row id; the submit endpoint accepts it as
     * the way to find the build context (so we don't have to ship policy
     * keys / metadata through the client).
     */
    public static function build(string $policy_id, string $asset_name, string $customer_address): array {
        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            return ['ok' => false, 'error' => 'Invalid policy_id', 'stage' => 'validate'];
        }
        if (!preg_match('/^[a-f0-9]+$/i', $asset_name) || strlen($asset_name) > 128) {
            return ['ok' => false, 'error' => 'Invalid asset_name (must be hex, ≤128 chars)', 'stage' => 'validate'];
        }
        if ($customer_address === '') {
            return ['ok' => false, 'error' => 'customer_address required', 'stage' => 'validate'];
        }

        // Step 1: load the spec for this asset. Per-asset row wins; falls
        // back to the policy-wide patch row.
        global $wpdb;
        $table = self::table_specs();
        $policy_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, network, new_metadata, mode, status, policy_locks_at_slot
             FROM $table WHERE policy_id = %s AND asset_name = ''",
            $policy_id
        ), ARRAY_A);
        if (!$policy_row) {
            return ['ok' => false, 'error' => 'Policy not registered for upgrades.', 'stage' => 'spec'];
        }
        $per_asset_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, new_metadata, mode, status FROM $table WHERE policy_id = %s AND asset_name = %s",
            $policy_id, $asset_name
        ), ARRAY_A);

        // Locked decision D4: re-check time-lock at build time.
        if (!empty($policy_row['policy_locks_at_slot']) && self::is_lock_in_past((int) $policy_row['policy_locks_at_slot'], (string) $policy_row['network'])) {
            return ['ok' => false, 'error' => 'Policy is permanently locked on-chain. Assets cannot be upgraded.', 'stage' => 'lock'];
        }

        // Determine which spec applies + its status. Must be 'active'.
        $upgrade_id = null;
        $target_meta = null;
        if ($per_asset_row && (string) $per_asset_row['mode'] === 'full') {
            if ((string) $per_asset_row['status'] !== 'active') {
                return ['ok' => false, 'error' => 'Per-asset spec for this NFT is not active.', 'stage' => 'spec_inactive'];
            }
            $target_meta = json_decode((string) $per_asset_row['new_metadata'], true);
            $upgrade_id  = (int) $per_asset_row['id'];
        } else {
            if ((string) $policy_row['status'] !== 'active') {
                return ['ok' => false, 'error' => 'Policy-wide upgrade spec is not active.', 'stage' => 'spec_inactive'];
            }
            $patch = json_decode((string) $policy_row['new_metadata'], true);
            if (!is_array($patch) || empty($patch)) {
                return ['ok' => false, 'error' => 'Policy-wide patch is empty; nothing to upgrade.', 'stage' => 'spec_empty'];
            }
            // Need current chain metadata for the merge.
            $unit = $policy_id . $asset_name;
            $resp = BlockfrostClient::assetMetadata($unit, (string) $policy_row['network']);
            if (!$resp['ok']) {
                return ['ok' => false, 'error' => 'Could not fetch current chain metadata: ' . ($resp['error'] ?? 'unknown'), 'stage' => 'chain_meta'];
            }
            $current = isset($resp['data']['onchain_metadata']) && is_array($resp['data']['onchain_metadata'])
                ? $resp['data']['onchain_metadata']
                : [];
            $target_meta = MetadataResolver::applyPatch($current, $patch);
            $upgrade_id = (int) $policy_row['id'];
        }
        if (!is_array($target_meta) || empty($target_meta)) {
            return ['ok' => false, 'error' => 'Resolved metadata is empty; refusing to mint.', 'stage' => 'resolve'];
        }

        // Step 2: policy script for preloadedScripts. We look it up in the
        // imported-policies table first (admins paste these for non-internal
        // collections), falling back to the policy_json on active mints.
        $policy_script = self::load_policy_script_json($policy_id);
        if (empty($policy_script)) {
            return ['ok' => false, 'error' => 'Policy script JSON not available locally. Import it via the Policy Wallet admin page first.', 'stage' => 'policy_script'];
        }

        // Step 3: bech32-normalize the customer address. Anvil's parse
        // endpoint accepts hex or bech32 and returns bech32.
        $customer_address_bech = AnvilAPI::convertAddressToBech32($customer_address);
        if (!is_string($customer_address_bech) || $customer_address_bech === '') {
            return ['ok' => false, 'error' => 'Could not normalize customer address.', 'stage' => 'address_parse'];
        }

        // Multi-quantity guard: CIP-25 NFTs are 1-of-1 by design. If the
        // on-chain quantity for this asset is > 1, the asset is fungible
        // and our burn(-1)+mint(+1) pattern would leave the customer with
        // n-1 old tokens + 1 new token — almost certainly not what they
        // want. Refuse rather than silently produce a confusing result.
        $unit = $policy_id . $asset_name;
        $asset_resp = BlockfrostClient::assetMetadata($unit, (string) $policy_row['network']);
        if ($asset_resp['ok'] && isset($asset_resp['data']['quantity'])) {
            $qty = (string) $asset_resp['data']['quantity'];
            if ($qty !== '1') {
                return ['ok' => false, 'error' => "Asset has on-chain quantity {$qty}, not 1. Burn-and-re-mint is only safe for 1-of-1 CIP-25 NFTs.", 'stage' => 'multi_quantity'];
            }
        }

        // Step 4: compose the Anvil request. Asset name is hex in our DB
        // and on-chain; we use format='hex' so we don't have to round-trip
        // through ASCII (which would fail for non-ASCII asset names).
        $asset_name_field = ['name' => $asset_name, 'format' => 'hex'];

        // Wrap the resolved metadata in the CIP-25 721 envelope per Anvil's
        // mint payload contract.
        $tx_request = [
            'changeAddress' => $customer_address_bech,
            'outputs' => [
                [
                    'address'  => $customer_address_bech,
                    'lovelace' => 1500000, // min-utxo for an output carrying one native token; Anvil bumps if needed
                    'assets'   => [
                        [
                            'policyId'  => $policy_id,
                            'assetName' => $asset_name_field,
                            'quantity'  => 1,
                        ],
                    ],
                ],
            ],
            'mint' => [
                // Burn the existing asset. Net effect at the protocol level:
                // the customer's input UTxO holding this token is consumed,
                // and the protocol accepts the burn because this tx says so.
                [
                    'version'   => 'cip25',
                    'policyId'  => $policy_id,
                    'quantity'  => -1,
                    'assetName' => $asset_name_field,
                ],
                // Mint a fresh copy at the same policy + asset_name with the
                // new CIP-25 metadata. Same fingerprint, different metadata.
                [
                    'version'   => 'cip25',
                    'policyId'  => $policy_id,
                    'quantity'  => 1,
                    'assetName' => $asset_name_field,
                    'metadata'  => $target_meta,
                ],
            ],
            'preloadedScripts' => [
                [
                    'type'   => 'simple',
                    'script' => $policy_script,
                    'hash'   => $policy_id,
                ],
            ],
        ];

        // Step 5: call Anvil.
        $resp = AnvilAPI::call('transactions/build', $tx_request, 'mint');
        if (is_wp_error($resp)) {
            self::log_event($upgrade_id, $policy_id, $asset_name, $customer_address_bech, null, 'failed', 'anvil build: ' . $resp->get_error_message());
            return ['ok' => false, 'error' => 'Anvil build failed: ' . $resp->get_error_message(), 'stage' => 'anvil_build'];
        }
        $unsigned_tx_hex = $resp['complete'] ?? ($resp['transaction'] ?? ($resp['cborHex'] ?? ''));
        $fee_lovelace    = $resp['stripped']['body']['fee'] ?? ($resp['fee'] ?? '');

        if ($unsigned_tx_hex === '') {
            self::log_event($upgrade_id, $policy_id, $asset_name, $customer_address_bech, null, 'failed', 'anvil build returned no tx hex: ' . wp_json_encode($resp));
            return ['ok' => false, 'error' => 'Anvil returned no transaction. Check the policy script + asset name encoding.', 'stage' => 'anvil_response'];
        }

        $log_id = self::log_event($upgrade_id, $policy_id, $asset_name, $customer_address_bech, null, 'built', null);

        return [
            'ok'              => true,
            'unsigned_tx_hex' => $unsigned_tx_hex,
            'fee_lovelace'    => (string) $fee_lovelace,
            'log_id'          => $log_id,
            'asset_name'      => $asset_name,
            'policy_id'       => $policy_id,
        ];
    }

    /**
     * Submit a customer-signed transaction. Adds the policy wallet's
     * signature via CardanoCLI and dispatches to Anvil.
     *
     * $signed_tx_hex is the FULL transaction the customer's CIP-30 wallet
     * returned from signTx() — it already contains the customer witness.
     * We sign it again with the policy skey to add the second signature
     * the policy script requires, then submit.
     *
     * Returns ['ok' => true, 'tx_hash' => '...'] or
     *         ['ok' => false, 'error' => '...', 'stage' => '...'].
     */
    public static function submit(int $log_id, string $signed_tx_hex): array {
        if ($log_id <= 0)        return ['ok' => false, 'error' => 'log_id required', 'stage' => 'validate'];
        if ($signed_tx_hex === '') return ['ok' => false, 'error' => 'signed_tx_hex required', 'stage' => 'validate'];

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT id, policy_id, asset_name, wallet_address, status FROM " . self::table_log() . " WHERE id = %d",
            $log_id
        ), ARRAY_A);
        if (!$log)                                 return ['ok' => false, 'error' => 'Build log row not found', 'stage' => 'log_lookup'];
        if ((string) $log['status'] !== 'built')   return ['ok' => false, 'error' => 'Build row is in status ' . $log['status'] . ', not built. Refresh and retry.', 'stage' => 'log_state'];

        // Add policy signature via CLI helper (same path as the standard
        // mint submit flow).
        $skey_hex = self::load_policy_skey($log['policy_id']);
        if ($skey_hex === '') {
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'policy skey not available');
            return ['ok' => false, 'error' => 'Policy signing key not available on the server.', 'stage' => 'policy_skey'];
        }

        $sign_result = CardanoCLI::signTransaction($signed_tx_hex, $skey_hex);
        if (!$sign_result || empty($sign_result['success'])) {
            $msg = is_array($sign_result) && isset($sign_result['error']) ? $sign_result['error'] : 'unknown CLI sign error';
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'policy sign: ' . $msg);
            return ['ok' => false, 'error' => 'Policy sign failed: ' . $msg, 'stage' => 'policy_sign'];
        }
        $fully_signed_hex = (string) ($sign_result['signed_tx'] ?? $sign_result['cborHex'] ?? '');
        if ($fully_signed_hex === '') {
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'policy sign returned no tx');
            return ['ok' => false, 'error' => 'Policy sign returned no transaction.', 'stage' => 'policy_sign'];
        }

        // Anvil submit takes the fully-signed tx. (Some Anvil deployments
        // also accept separate transaction + signatures; we pass the
        // already-merged tx for consistency with this plugin's existing
        // mint submit pattern.)
        $resp = AnvilAPI::call('transactions/submit', [
            'transaction' => $fully_signed_hex,
            'signatures'  => [],
        ], 'mint');
        if (is_wp_error($resp)) {
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'anvil submit: ' . $resp->get_error_message());
            return ['ok' => false, 'error' => 'Anvil submit failed: ' . $resp->get_error_message(), 'stage' => 'anvil_submit'];
        }
        $tx_hash = (string) ($resp['txHash'] ?? ($resp['tx_hash'] ?? ($resp['hash'] ?? '')));
        if ($tx_hash === '') {
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'anvil submit returned no txHash: ' . wp_json_encode($resp));
            return ['ok' => false, 'error' => 'Anvil accepted the submit but returned no txHash.', 'stage' => 'anvil_response'];
        }

        self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], $tx_hash, 'submitted', null);

        return ['ok' => true, 'tx_hash' => $tx_hash];
    }

    /**
     * Confirmation watcher tick. Walks every 'submitted' audit row that
     * doesn't already have a paired 'confirmed' row, queries Blockfrost
     * for the tx hash, and inserts a 'confirmed' event when the tx has
     * landed in a block.
     *
     * Registered to fire on the WP-Cron hook 'cardano_asset_upgrade_confirm_tick'
     * with a 5-minute interval (see cardano-nft-checkout.php for the
     * schedule wiring). Safe to call manually for testing.
     */
    public static function confirmation_tick(): void {
        global $wpdb;
        $log_table  = self::table_log();
        $spec_table = self::table_specs();

        // Pick up 'submitted' rows that don't yet have a matching
        // 'confirmed' or 'failed' row for the same tx_hash. Limit so a
        // single tick never burns the rate-limit budget on a giant backlog.
        $rows = $wpdb->get_results(
            "SELECT l.id, l.policy_id, l.asset_name, l.wallet_address, l.tx_hash
             FROM $log_table l
             WHERE l.status = 'submitted'
               AND l.tx_hash IS NOT NULL AND l.tx_hash != ''
               AND NOT EXISTS (
                   SELECT 1 FROM $log_table l2
                   WHERE l2.tx_hash = l.tx_hash
                     AND l2.status IN ('confirmed','failed')
               )
             ORDER BY l.id ASC
             LIMIT 25",
            ARRAY_A
        );
        if (empty($rows)) return;

        // Group by policy so we can resolve network once per policy.
        $networks = [];
        foreach ($rows as $r) {
            if (isset($networks[$r['policy_id']])) continue;
            $networks[$r['policy_id']] = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT network FROM $spec_table WHERE policy_id = %s AND asset_name = '' LIMIT 1",
                $r['policy_id']
            ));
        }

        foreach ($rows as $r) {
            $network = $networks[$r['policy_id']] ?? '';
            if ($network === '') continue;

            $resp = BlockfrostClient::getTransaction((string) $r['tx_hash'], $network);
            if (!$resp['ok']) {
                // 404 just means "not yet" — leave it for the next tick.
                if (($resp['status'] ?? 0) === 404) continue;
                // Any other failure: log once as 'failed' so we stop polling.
                self::log_event(null, (string) $r['policy_id'], (string) $r['asset_name'], (string) $r['wallet_address'], (string) $r['tx_hash'], 'failed', 'confirmation lookup: ' . ($resp['error'] ?? 'unknown'));
                continue;
            }
            // Got a tx record — it's on-chain.
            self::log_event(null, (string) $r['policy_id'], (string) $r['asset_name'], (string) $r['wallet_address'], (string) $r['tx_hash'], 'confirmed', null);
        }
    }

    /* ─── helpers ──────────────────────────────────────────────────────── */

    /**
     * Append-only audit log write. Returns the inserted row id. Per
     * decision D8, every event is its own row; rows never mutate.
     */
    private static function log_event(?int $upgrade_id, string $policy_id, string $asset_name, string $wallet_address, ?string $tx_hash, string $status, ?string $error_message): int {
        global $wpdb;
        $wpdb->insert(self::table_log(), [
            'upgrade_id'     => $upgrade_id,
            'policy_id'      => $policy_id,
            'asset_name'     => $asset_name,
            'wallet_address' => $wallet_address,
            'tx_hash'        => $tx_hash,
            'status'         => $status,
            'error_message'  => $error_message,
            'created_at'     => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Find the native script JSON for a policy. Imported policies (admins
     * paste these via the Policy Wallet admin page for collections that
     * weren't minted with this plugin) take precedence; falls back to the
     * `policy_json` column on the active mints table.
     */
    private static function load_policy_script_json(string $policy_id) {
        $imported = MintModel::getMintPolicyByPolicyId($policy_id);
        if ($imported && !empty($imported['policy_json'])) {
            $decoded = json_decode((string) $imported['policy_json'], true);
            if (is_array($decoded)) return $decoded;
        }
        // Active mints fallback.
        global $wpdb;
        $table = $wpdb->prefix . 'cardanonftactivemints';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT policy_json FROM $table WHERE policyid = %s LIMIT 1",
            $policy_id
        ), ARRAY_A);
        if ($row && !empty($row['policy_json'])) {
            $decoded = json_decode((string) $row['policy_json'], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }

    /**
     * Decrypt the policy signing key. Imported-policy skey wins (the admin
     * brought it in for an external collection); fallback to the internal
     * policy wallet for assets minted by this plugin.
     */
    private static function load_policy_skey(string $policy_id): string {
        $imported = MintModel::getMintPolicyByPolicyId($policy_id);
        if ($imported && !empty($imported['skey_encrypted'])) {
            $hex = EncryptionHelper::decrypt((string) $imported['skey_encrypted']);
            if (is_string($hex) && $hex !== '') return $hex;
        }
        $network = get_option('cardano-mint-networkenvironment', 'preprod');
        $wallet = MintModel::getPolicyWallet($network);
        if (!$wallet || empty($wallet['skey_encrypted'])) return '';
        $hex = EncryptionHelper::decrypt((string) $wallet['skey_encrypted']);
        return is_string($hex) ? $hex : '';
    }

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
