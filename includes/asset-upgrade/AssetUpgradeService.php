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
 * Anvil payload shape: the mint array with both a negative-quantity (burn)
 * and positive-quantity (re-mint) entry under the same policy is the
 * load-bearing pattern. Confirmed 2026-05-27: same shape as the existing
 * mint entry, just `quantity: -1` on the burn entry; the rest of the
 * fields are identical.
 */
class AssetUpgradeService {

    public static function table_specs(): string {
        return AssetUpgradeInstaller::table_specs();
    }

    public static function table_log(): string {
        return AssetUpgradeInstaller::table_log();
    }

    /**
     * Build ONE leg of the burn-and-re-mint upgrade.
     *
     * CIP-25 metadata can only be refreshed on a fixed-supply 1/1 across TWO
     * transactions: a net-zero mint (burn -1 AND mint +1 of the same asset in
     * one tx) is rejected by the ledger ("MintAssets cannot be created with 0
     * value"). So:
     *
     *   step 'burn'   — mint -1, no metadata, no asset output. Resolves and
     *                   stashes the target metadata (transient keyed by the
     *                   burn log id) so the re-mint still has it after the
     *                   on-chain metadata is gone.
     *   step 'remint' — mint +1 with the new CIP-25 metadata, output the fresh
     *                   asset back to the customer. Reads the stashed metadata
     *                   via $burn_log_id (falls back to re-resolving for
     *                   full-mode specs).
     *
     * Both legs are funded + signed by the customer (Anvil auto-selects their
     * UTxOs from changeAddress) and co-signed by the policy wallet at submit.
     *
     * Returns ['ok'=>true,'step'=>..,'unsigned_tx_hex'=>..,'log_id'=>..,
     *          'policy_id'=>..,'asset_name'=>..] or ['ok'=>false,'error','stage'].
     */
    public static function build(string $policy_id, string $asset_name, string $customer_address, string $step = 'burn', int $burn_log_id = 0): array {
        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) {
            return ['ok' => false, 'error' => 'Invalid policy_id', 'stage' => 'validate'];
        }
        if (!preg_match('/^[a-f0-9]+$/i', $asset_name) || strlen($asset_name) > 128) {
            return ['ok' => false, 'error' => 'Invalid asset_name (must be hex, ≤128 chars)', 'stage' => 'validate'];
        }
        if ($customer_address === '') {
            return ['ok' => false, 'error' => 'customer_address required', 'stage' => 'validate'];
        }
        if (!in_array($step, ['burn', 'remint'], true)) {
            return ['ok' => false, 'error' => 'invalid step (expected burn|remint)', 'stage' => 'validate'];
        }

        // Load the spec for this asset. Per-asset row wins; falls back to the
        // policy-wide patch row.
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
        $network    = (string) $policy_row['network'];
        $upgrade_id = $per_asset_row ? (int) $per_asset_row['id'] : (int) $policy_row['id'];

        // Re-check time-lock at build time (decision D4).
        if (!empty($policy_row['policy_locks_at_slot']) && self::is_lock_in_past((int) $policy_row['policy_locks_at_slot'], $network)) {
            return ['ok' => false, 'error' => 'Policy is permanently locked on-chain. Assets cannot be upgraded.', 'stage' => 'lock'];
        }

        // Policy native script for preloadedScripts (imported policy_schema,
        // else active-mints fallback).
        $policy_script = self::load_policy_script_json($policy_id);
        if (empty($policy_script)) {
            return ['ok' => false, 'error' => 'Policy script JSON not available locally. Import it via the Policy Wallet admin page first.', 'stage' => 'policy_script'];
        }

        // Normalize the customer's change/funding address.
        $customer_address_bech = AnvilAPI::convertAddressToBech32($customer_address);
        if (!is_string($customer_address_bech) || $customer_address_bech === '') {
            return ['ok' => false, 'error' => 'Could not normalize customer address.', 'stage' => 'address_parse'];
        }

        $asset_name_field = ['name' => $asset_name, 'format' => 'hex'];
        $unit = $policy_id . $asset_name;

        if ($step === 'burn') {
            // The asset must still exist as a 1/1 on chain to be burned.
            $asset_resp = BlockfrostClient::assetMetadata($unit, $network);
            if ($asset_resp['ok'] && isset($asset_resp['data']['quantity'])) {
                $qty = (string) $asset_resp['data']['quantity'];
                if ($qty !== '1') {
                    return ['ok' => false, 'error' => "Asset has on-chain quantity {$qty}, not 1. Burn-and-re-mint is only safe for 1-of-1 CIP-25 NFTs.", 'stage' => 'multi_quantity'];
                }
            }

            // Resolve the target metadata NOW — for policy-wide patch mode the
            // current chain metadata disappears after the burn, so we can't
            // defer this to the re-mint leg.
            $resolved = self::resolve_target_meta($policy_id, $asset_name, $policy_row, $per_asset_row, $network);
            if (!$resolved['ok']) return $resolved;
            $target_meta = $resolved['meta'];

            // Burn leg: mint -1, no metadata, no asset output. The NFT's UTxO
            // is consumed (auto-selected from changeAddress); its ADA returns
            // as change. `version: cip25` is REQUIRED on every mint entry by
            // Anvil's input schema even for a metadata-less burn — omitting it
            // returns "Input validation failed" (mint[0].version).
            $tx_request = [
                'changeAddress' => $customer_address_bech,
                'mint' => [
                    [
                        'version'   => 'cip25',
                        'policyId'  => $policy_id,
                        'quantity'  => -1,
                        'assetName' => $asset_name_field,
                    ],
                ],
                'preloadedScripts' => [
                    ['type' => 'simple', 'script' => $policy_script, 'hash' => $policy_id],
                ],
            ];

            $unsigned = self::anvil_build($tx_request, $upgrade_id, $policy_id, $asset_name, $customer_address_bech, 'burn');
            if (!$unsigned['ok']) return $unsigned;

            $log_id = self::log_event($upgrade_id, $policy_id, $asset_name, $customer_address_bech, null, 'built', 'burn');
            // Stash resolved metadata for the re-mint leg (2h TTL).
            set_transient('cem_upg_meta_' . $log_id, $target_meta, 2 * HOUR_IN_SECONDS);

            return [
                'ok'              => true,
                'step'            => 'burn',
                'unsigned_tx_hex' => $unsigned['tx'],
                'log_id'          => $log_id,
                'asset_name'      => $asset_name,
                'policy_id'       => $policy_id,
            ];
        }

        // step === 'remint'.
        // Prefer the metadata stashed at burn time; fall back to re-resolving
        // (works for full mode — patch mode needs the stash since the on-chain
        // metadata is already burned).
        $target_meta = $burn_log_id > 0 ? get_transient('cem_upg_meta_' . $burn_log_id) : false;
        if (!is_array($target_meta) || empty($target_meta)) {
            $resolved = self::resolve_target_meta($policy_id, $asset_name, $policy_row, $per_asset_row, $network);
            if (!$resolved['ok']) return $resolved;
            $target_meta = $resolved['meta'];
        }
        if (!is_array($target_meta) || empty($target_meta)) {
            return ['ok' => false, 'error' => 'Resolved metadata is empty; refusing to re-mint.', 'stage' => 'resolve'];
        }

        $tx_request = [
            'changeAddress' => $customer_address_bech,
            'outputs' => [
                [
                    'address'  => $customer_address_bech,
                    'lovelace' => 1500000, // min-utxo for an output carrying one native token; Anvil bumps if needed
                    'assets'   => [
                        ['policyId' => $policy_id, 'assetName' => $asset_name_field, 'quantity' => 1],
                    ],
                ],
            ],
            'mint' => [
                [
                    'version'   => 'cip25',
                    'policyId'  => $policy_id,
                    'quantity'  => 1,
                    'assetName' => $asset_name_field,
                    'metadata'  => $target_meta,
                ],
            ],
            'preloadedScripts' => [
                ['type' => 'simple', 'script' => $policy_script, 'hash' => $policy_id],
            ],
        ];

        $unsigned = self::anvil_build($tx_request, $upgrade_id, $policy_id, $asset_name, $customer_address_bech, 'remint');
        if (!$unsigned['ok']) return $unsigned;

        $log_id = self::log_event($upgrade_id, $policy_id, $asset_name, $customer_address_bech, null, 'built', 'remint');
        if ($burn_log_id > 0) delete_transient('cem_upg_meta_' . $burn_log_id);

        return [
            'ok'              => true,
            'step'            => 'remint',
            'unsigned_tx_hex' => $unsigned['tx'],
            'log_id'          => $log_id,
            'asset_name'      => $asset_name,
            'policy_id'       => $policy_id,
        ];
    }

    /**
     * Resolve the target CIP-25 metadata for an asset.
     * Returns ['ok'=>true,'meta'=>array] or ['ok'=>false,'error','stage'].
     */
    private static function resolve_target_meta(string $policy_id, string $asset_name, array $policy_row, $per_asset_row, string $network): array {
        if ($per_asset_row && (string) $per_asset_row['mode'] === 'full') {
            if ((string) $per_asset_row['status'] !== 'active') {
                return ['ok' => false, 'error' => 'Per-asset spec for this NFT is not active.', 'stage' => 'spec_inactive'];
            }
            $meta = json_decode((string) $per_asset_row['new_metadata'], true);
            if (!is_array($meta) || empty($meta)) {
                return ['ok' => false, 'error' => 'Per-asset metadata is empty.', 'stage' => 'resolve'];
            }
            return ['ok' => true, 'meta' => $meta];
        }

        if ((string) $policy_row['status'] !== 'active') {
            return ['ok' => false, 'error' => 'Policy-wide upgrade spec is not active.', 'stage' => 'spec_inactive'];
        }
        $patch = json_decode((string) $policy_row['new_metadata'], true);
        if (!is_array($patch) || empty($patch)) {
            return ['ok' => false, 'error' => 'Policy-wide patch is empty; nothing to upgrade.', 'stage' => 'spec_empty'];
        }
        $resp = BlockfrostClient::assetMetadata($policy_id . $asset_name, $network);
        if (!$resp['ok']) {
            return ['ok' => false, 'error' => 'Could not fetch current chain metadata: ' . ($resp['error'] ?? 'unknown'), 'stage' => 'chain_meta'];
        }
        $current = isset($resp['data']['onchain_metadata']) && is_array($resp['data']['onchain_metadata'])
            ? $resp['data']['onchain_metadata']
            : [];
        $meta = MetadataResolver::applyPatch($current, $patch);
        if (!is_array($meta) || empty($meta)) {
            return ['ok' => false, 'error' => 'Resolved metadata is empty; refusing.', 'stage' => 'resolve'];
        }
        return ['ok' => true, 'meta' => $meta];
    }

    /**
     * Call Anvil transactions/build and extract the unsigned tx hex.
     * Returns ['ok'=>true,'tx'=>hex] or ['ok'=>false,'error','stage'] and logs
     * a 'failed' audit row on error. $leg is 'burn'|'remint' for the log.
     */
    private static function anvil_build(array $tx_request, int $upgrade_id, string $policy_id, string $asset_name, string $wallet, string $leg): array {
        $resp = AnvilAPI::call('transactions/build', $tx_request, 'mint');
        if (is_wp_error($resp)) {
            self::log_event($upgrade_id, $policy_id, $asset_name, $wallet, null, 'failed', "anvil build ($leg): " . $resp->get_error_message());
            return ['ok' => false, 'error' => "Anvil build failed ($leg): " . $resp->get_error_message(), 'stage' => 'anvil_build'];
        }
        // 'complete' carries the metadata inline (fine here — the customer
        // already reviewed the diff). Fall back through Anvil's other shapes.
        $tx = $resp['complete'] ?? ($resp['transaction'] ?? ($resp['stripped'] ?? ($resp['cborHex'] ?? '')));
        if (!is_string($tx) || $tx === '') {
            self::log_event($upgrade_id, $policy_id, $asset_name, $wallet, null, 'failed', "anvil build ($leg) returned no tx hex: " . wp_json_encode($resp));
            return ['ok' => false, 'error' => "Anvil returned no transaction ($leg).", 'stage' => 'anvil_response'];
        }
        return ['ok' => true, 'tx' => $tx];
    }

    /**
     * Submit one leg. The customer's CIP-30 wallet returns a witness set from
     * signTx(unsignedTx, true); we hand the UNSIGNED tx + that witness to the
     * proven mint submit path, which adds the policy-wallet witness and
     * dispatches to Anvil.
     *
     * $signatures is the array of customer witness-set hexes from the client.
     *
     * Returns ['ok'=>true,'tx_hash'=>..] or ['ok'=>false,'error','stage'].
     */
    public static function submit(int $log_id, string $transaction, array $signatures): array {
        if ($log_id <= 0)       return ['ok' => false, 'error' => 'log_id required', 'stage' => 'validate'];
        if ($transaction === '') return ['ok' => false, 'error' => 'transaction required', 'stage' => 'validate'];

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT id, policy_id, asset_name, wallet_address, status FROM " . self::table_log() . " WHERE id = %d",
            $log_id
        ), ARRAY_A);
        if (!$log)                               return ['ok' => false, 'error' => 'Build log row not found', 'stage' => 'log_lookup'];
        if ((string) $log['status'] !== 'built') return ['ok' => false, 'error' => 'Build row is in status ' . $log['status'] . ', not built. Refresh and retry.', 'stage' => 'log_state'];

        // Reuse the standard mint submit path: it adds the policy-wallet
        // witness (imported skey wins, internal wallet fallback) to the
        // signatures array and submits {transaction, signatures} to Anvil.
        // This is the heaviest step (pure-PHP policy sign + Anvil submit
        // round-trip).
        $resp = AnvilAPI::submitTransaction($transaction, $signatures, 'mint', (string) $log['policy_id']);
        if (is_wp_error($resp)) {
            self::log_event(null, $log['policy_id'], $log['asset_name'], $log['wallet_address'], null, 'failed', 'anvil submit: ' . $resp->get_error_message());
            return ['ok' => false, 'error' => 'Anvil submit failed: ' . $resp->get_error_message(), 'stage' => 'anvil_submit'];
        }
        $tx_hash = is_array($resp) ? (string) ($resp['txHash'] ?? ($resp['tx_hash'] ?? ($resp['hash'] ?? ''))) : '';
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
        // Imported policies store the native script JSON in `policy_schema`
        // (the cardano_mint_policies table has no `policy_json` column —
        // that belongs to the active-mints table handled in the fallback).
        if ($imported) {
            $raw = $imported['policy_schema'] ?? ($imported['policy_json'] ?? '');
            if (!empty($raw)) {
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) return $decoded;
            }
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
