<?php
namespace CardanoMintPay\AltPay;

use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\Models\ChainTxLogModel;
use CardanoMintPay\Models\MintModel;

if (!defined('ABSPATH')) exit;

/**
 * AltPayService
 *
 * Single orchestration surface used by the REST and admin controllers.
 * Resolves a ChainPaymentProvider by chain code, drives quote / status /
 * cancel / reconcile / refund / sweep. Controllers never touch providers
 * directly.
 *
 * Tolerance for amount comparison is +/- 1% by default, configurable via
 * cardano_mint_altpay_amount_tolerance_bps (basis points; 100 = 1%).
 */
class AltPayService {

    const QUOTE_TTL_SECONDS = 15 * 60;            // 15-minute rate lock (only for fresh quotes)
    const INVOICE_TTL_SECONDS = 24 * 60 * 60;     // 24-hour payment window (BTC confirmations are slow)
    const DEFAULT_TOLERANCE_BPS = 100;            // 1.00%

    /** @var array<string, ChainPaymentProvider> */
    private static $providers = [];

    public static function register(ChainPaymentProvider $provider): void {
        self::$providers[$provider->chainCode()] = $provider;
    }

    public static function provider(string $chain): ?ChainPaymentProvider {
        $chain = strtolower($chain);
        return self::$providers[$chain] ?? null;
    }

    public static function chains(): array {
        return array_keys(self::$providers);
    }

    /**
     * Issue a payment intent for a mint.
     *
     * @return array|\WP_Error On success: invoice row + display fields.
     */
    public static function quote(int $mintId, string $chain, string $customerCardanoAddress, string $discountCode = '') {
        $chain = strtolower($chain);

        $provider = self::provider($chain);
        if (!$provider) return new \WP_Error('altpay_unknown_chain', 'Unknown chain: ' . $chain);

        if ($mintId <= 0) return new \WP_Error('altpay_bad_mint', 'Missing mint id');
        if ($customerCardanoAddress === '') return new \WP_Error('altpay_bad_address', 'Missing Cardano address');

        // Network gate: refuse to create an invoice bound to a Cardano address
        // that's on the wrong network. Otherwise the customer's off-chain
        // payment lands fine but the eventual mint tx can never be signed by
        // a wallet on the right network, and the funds are stranded.
        $site_anvil_url = strtolower((string) get_option('cardano_mint_anvil_api_url', ''));
        $site_is_mainnet = (strpos($site_anvil_url, 'preprod') === false && strpos($site_anvil_url, 'preview') === false && strpos($site_anvil_url, 'sancho') === false);
        $addr_is_testnet = (stripos($customerCardanoAddress, 'addr_test1') === 0);
        if ($site_is_mainnet === $addr_is_testnet) {
            $expected = $site_is_mainnet ? 'Mainnet' : 'Pre-production';
            $actual   = $addr_is_testnet ? 'Testnet'  : 'Mainnet';
            return new \WP_Error(
                'altpay_network_mismatch',
                'Wallet network mismatch: your wallet is on ' . $actual
                . ' but this site is on ' . $expected
                . '. Switch your wallet network and reconnect before paying.'
            );
        }

        $mint = MintModel::getMintById($mintId);
        if (!$mint) return new \WP_Error('altpay_mint_not_found', 'Mint not found');

        $usd = (float) ($mint['price'] ?? 0);
        if ($usd <= 0) return new \WP_Error('altpay_no_price', 'Mint is not priced');

        // Idempotency: if the same customer already has a pending invoice
        // for this mint and chain, return that one. Lets the modal survive
        // page reloads without burning fresh HD indices.
        $existing = ChainInvoiceModel::find_active_for($mintId, $chain, $customerCardanoAddress);
        if ($existing) {
            return [
                'invoice_id'             => (int) $existing['id'],
                'chain'                  => $chain,
                'address'                => $existing['address'],
                'currency'               => $existing['currency'],
                'expected_amount_minor'  => $existing['expected_amount_minor'],
                'rate_locked'            => (float) $existing['rate_locked'],
                'expires_at'             => gmdate('c', strtotime($existing['expires_at'])),
                'reused'                 => true,
            ];
        }

        $rate = PriceOracle::getRate($chain);
        if ($rate <= 0) return new \WP_Error('altpay_no_rate', 'Could not fetch ' . strtoupper($chain) . ' price');

        $network = self::network_for_chain($chain);
        $wallet  = self::pick_active_wallet($chain, $network);
        if (!$wallet) return new \WP_Error('altpay_no_wallet', 'No active ' . strtoupper($chain) . ' wallet configured');

        $index = ChainWalletModel::allocate_next_index((int) $wallet['id']);
        if ($index < 0) return new \WP_Error('altpay_alloc_failed', 'Could not allocate derivation index');

        $address = $provider->deriveChildAddress((int) $wallet['id'], $index);
        if ($address === '') return new \WP_Error('altpay_derive_failed', 'Could not derive child address');

        // Discount code: reserve + discount the USD the customer actually pays
        // BEFORE we lock the crypto amount, so the deposit address the watcher
        // waits on already reflects the discount. Alt-pay is always qty 1. The
        // reservation is linked to the invoice below and held for the invoice's
        // life (not the short ADA TTL).
        $reservationId = 0;
        if ($discountCode !== '' && class_exists('CardanoMintPay\\Discounts\\DiscountService')) {
            $res = \CardanoMintPay\Discounts\DiscountService::reserve(
                $discountCode, (string) ($mint['policyid'] ?? ''), $usd, 1, $chain, $customerCardanoAddress
            );
            if (empty($res['ok'])) {
                return new \WP_Error('altpay_discount_invalid', $res['error'] ?? 'That code isn\'t valid.');
            }
            $usd = (float) $res['pricing']['final_per_asset_usd'];
            $reservationId = (int) $res['redemption_id'];
        }

        $expectedMinor = $provider->expectedAmountMinor($usd, $rate);

        $now = time();
        $invoiceId = ChainInvoiceModel::insert([
            'parent_wallet_id'         => (int) $wallet['id'],
            'chain'                    => $chain,
            'derivation_index'         => $index,
            'address'                  => $address,
            'currency'                 => strtoupper($chain),
            'expected_amount_minor'    => $expectedMinor,
            'rate_locked'              => $rate,
            'rate_expires_at'          => gmdate('Y-m-d H:i:s', $now + self::QUOTE_TTL_SECONDS),
            'mint_id'                  => $mintId,
            'customer_cardano_address' => $customerCardanoAddress,
            'status'                   => 'pending',
            'expires_at'               => gmdate('Y-m-d H:i:s', $now + self::INVOICE_TTL_SECONDS),
        ]);

        if ($invoiceId <= 0) return new \WP_Error('altpay_insert_failed', 'Could not persist invoice');

        // Bind the discount reservation to this invoice so it's held for the
        // 24h payment window and committed when the mint lands.
        if ($reservationId > 0 && class_exists('CardanoMintPay\\Discounts\\DiscountService')) {
            \CardanoMintPay\Discounts\DiscountService::link_invoice($reservationId, $invoiceId);
        }

        return [
            'invoice_id'             => $invoiceId,
            'chain'                  => $chain,
            'address'                => $address,
            'currency'               => strtoupper($chain),
            'expected_amount_minor'  => $expectedMinor,
            'rate_locked'            => $rate,
            'expires_at'             => gmdate('c', $now + self::INVOICE_TTL_SECONDS),
        ];
    }

    /**
     * Read-mostly status check. Hits the chain RPC at most once per
     * configurable cache window and writes promotion to the invoice row.
     */
    public static function status(int $invoiceId): array {
        $inv = ChainInvoiceModel::get($invoiceId);
        if (!$inv) return ['status' => 'not_found'];

        // No further RPC calls once the invoice is in a terminal state.
        if (!in_array($inv['status'], ['pending'], true)) {
            return self::shape_status($inv);
        }

        // Auto-expire if past due.
        if (strtotime($inv['expires_at']) < time()) {
            ChainInvoiceModel::set_status($invoiceId, 'expired');
            $inv['status'] = 'expired';
            return self::shape_status($inv);
        }

        self::reconcile($inv);
        $fresh = ChainInvoiceModel::get($invoiceId);
        return self::shape_status($fresh ?: $inv);
    }

    /** Re-check the chain RPC for this invoice and update its status. */
    public static function reconcile(array $inv): void {
        $provider = self::provider($inv['chain']);
        if (!$provider) return;

        $cacheKey = 'cm_altpay_addrcache_' . $inv['chain'] . '_' . md5($inv['address']);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            $balance = $cached;
        } else {
            $network = self::network_for_chain($inv['chain']);
            $balance = $provider->checkAddressBalance($inv['address'], $network);
            set_transient($cacheKey, $balance, 15);
        }

        $observedMinor = (string) ($balance['balance_minor'] ?? '0');
        if ($observedMinor === '0' || $observedMinor === '') return;

        $expected = (string) $inv['expected_amount_minor'];
        $cmp = self::compare_with_tolerance($observedMinor, $expected);

        $extra = [
            'observed_amount_minor' => $observedMinor,
            'observed_at'           => current_time('mysql'),
            'confirmations'         => (int) ($balance['confirmations'] ?? 0),
        ];
        if (!empty($balance['last_tx'])) {
            $extra['observed_tx'] = (string) $balance['last_tx'];
            ChainTxLogModel::append([
                'invoice_id'    => (int) $inv['id'],
                'tx_hash'       => (string) $balance['last_tx'],
                'direction'     => 'in',
                'amount_minor'  => $observedMinor,
                'confirmations' => (int) ($balance['confirmations'] ?? 0),
                'raw_payload'   => $balance,
            ]);
        }

        $target = ($cmp === 0) ? 'funded' : (($cmp === -1) ? 'underpaid' : 'overpaid');
        $ok = ChainInvoiceModel::set_status((int) $inv['id'], $target, $extra);
        if (!$ok) {
            // wpdb->update returned false, which means the row update was
            // refused (column too small, type mismatch, etc.). Without this
            // log line the watcher silently keeps re-running every minute
            // and the invoice never advances. Surface it so the operator
            // can see the underlying $wpdb->last_error in the PHP log.
            global $wpdb;
            error_log(
                '[CardanoMint AltPay] reconcile failed to set_status invoice='
                . (int) $inv['id'] . ' target=' . $target
                . ' wpdb_last_error=' . ($wpdb ? $wpdb->last_error : '?')
            );
        }
    }

    public static function cancel(int $invoiceId): bool {
        $inv = ChainInvoiceModel::get($invoiceId);
        if (!$inv || $inv['status'] !== 'pending') return false;
        return ChainInvoiceModel::set_status($invoiceId, 'cancelled');
    }

    /**
     * Issue a refund / sweep tx FROM the invoice's child address TO an
     * arbitrary destination. Only allowed on invoices that are funded,
     * underpaid, overpaid, or consumed; pending invoices haven't received
     * money and refunds expired/cancelled ones can be sent if the operator
     * insists (the on-chain check is the ultimate authority).
     *
     * @param int    $invoiceId   row id in wp_cm_chain_invoices
     * @param string $toAddress   chain-native destination
     * @param string $amountMinor decimal-string amount in smallest units
     * @return array|\WP_Error    {tx_hash, raw_tx} on success
     */
    public static function refund(int $invoiceId, string $toAddress, string $amountMinor) {
        $inv = ChainInvoiceModel::get($invoiceId);
        if (!$inv) return new \WP_Error('altpay_no_invoice', 'invoice not found');
        $provider = self::provider($inv['chain']);
        if (!$provider) return new \WP_Error('altpay_unknown_chain', 'unknown chain');

        if ($amountMinor === '' || preg_match('/[^0-9]/', $amountMinor)) {
            return new \WP_Error('altpay_bad_amount', 'amount_minor must be a non-negative integer string');
        }
        if ($toAddress === '') return new \WP_Error('altpay_bad_address', 'destination address required');

        try {
            $result = $provider->buildAndBroadcastRefund(
                (int) $inv['parent_wallet_id'],
                (int) $inv['derivation_index'],
                $toAddress,
                $amountMinor
            );
        } catch (\Throwable $e) {
            error_log('[CardanoMint AltPay] refund failed: ' . $e->getMessage());
            return new \WP_Error('altpay_refund_failed', $e->getMessage());
        }

        ChainTxLogModel::append([
            'invoice_id'   => $invoiceId,
            'tx_hash'      => (string) ($result['tx_hash'] ?? ''),
            'direction'    => 'refund',
            'amount_minor' => $amountMinor,
            'raw_payload'  => ['to' => $toAddress, 'raw_tx' => $result['raw_tx'] ?? ''],
        ]);
        ChainInvoiceModel::set_status($invoiceId, 'refunded');

        return $result;
    }

    /**
     * Operator-side withdrawal. Walks the parent wallet's known invoices,
     * checks live balances, and issues a single tx from whichever child
     * address holds funds — typically the most-funded one. ETH and SOL
     * cap out at the highest single child; BTC could aggregate UTXOs
     * across many children but for V1 we keep it single-source so the
     * operator can verify what moves before scaling.
     *
     * @return array|\WP_Error {tx_hash, raw_tx, source_index, source_balance_minor}
     */
    public static function send_from_wallet(int $walletId, string $toAddress, string $amountMinor) {
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) return new \WP_Error('altpay_no_wallet', 'wallet not found');
        $provider = self::provider($wallet['chain']);
        if (!$provider) return new \WP_Error('altpay_unknown_chain', 'unknown chain');
        if ($amountMinor === '' || preg_match('/[^0-9]/', $amountMinor) || $amountMinor === '0') {
            return new \WP_Error('altpay_bad_amount', 'amount_minor must be a positive integer string');
        }
        if ($toAddress === '') return new \WP_Error('altpay_bad_address', 'destination required');

        // Find the most-funded child address tied to this wallet. We only
        // walk indices that an invoice was issued for; orphan derivations
        // shouldn't have funds anyway.
        global $wpdb;
        $tbl = ChainInvoiceModel::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, derivation_index, address FROM `$tbl` WHERE parent_wallet_id = %d ORDER BY derivation_index ASC",
            $walletId
        ), ARRAY_A);
        if (empty($rows)) return new \WP_Error('altpay_no_children', 'no derived addresses on this wallet yet');

        $bestIndex = -1;
        $bestBalance = '0';
        $bestInvoiceId = 0;
        foreach ($rows as $r) {
            $bal = $provider->checkAddressBalance($r['address'], $wallet['network']);
            $minor = (string) ($bal['balance_minor'] ?? '0');
            if (function_exists('bccomp')) {
                if (bccomp($minor, $bestBalance, 0) > 0) {
                    $bestBalance = $minor;
                    $bestIndex = (int) $r['derivation_index'];
                    $bestInvoiceId = (int) $r['id'];
                }
            } else if ((int) $minor > (int) $bestBalance) {
                $bestBalance = $minor;
                $bestIndex = (int) $r['derivation_index'];
                $bestInvoiceId = (int) $r['id'];
            }
        }
        if ($bestIndex < 0 || $bestBalance === '0') {
            return new \WP_Error('altpay_no_funds', 'no child address holds funds yet');
        }
        if (function_exists('bccomp') && bccomp($amountMinor, $bestBalance, 0) > 0) {
            return new \WP_Error('altpay_insufficient', 'requested amount exceeds the most-funded child (' . $bestBalance . ')');
        }

        try {
            $result = $provider->buildAndBroadcastRefund($walletId, $bestIndex, $toAddress, $amountMinor);
        } catch (\Throwable $e) {
            error_log('[CardanoMint AltPay] send_from_wallet failed: ' . $e->getMessage());
            return new \WP_Error('altpay_send_failed', $e->getMessage());
        }

        // Audit log against whichever invoice the funds were drawn from.
        if ($bestInvoiceId > 0) {
            ChainTxLogModel::append([
                'invoice_id'   => $bestInvoiceId,
                'tx_hash'      => (string) ($result['tx_hash'] ?? ''),
                'direction'    => 'sweep',
                'amount_minor' => $amountMinor,
                'raw_payload'  => ['to' => $toAddress, 'raw_tx' => $result['raw_tx'] ?? '', 'source_index' => $bestIndex],
            ]);
        }

        return array_merge($result, [
            'source_index'         => $bestIndex,
            'source_balance_minor' => $bestBalance,
        ]);
    }

    /** Cron-driven sweep across pending invoices. */
    public static function watcher_tick(): void {
        ChainInvoiceModel::expire_past_due();
        $pending = ChainInvoiceModel::find_pending(50);
        foreach ($pending as $inv) {
            self::reconcile($inv);
        }
    }

    private static function pick_active_wallet(string $chain, string $network): ?array {
        $wallets = ChainWalletModel::list_for_chain($chain, false);
        foreach ($wallets as $w) {
            if (($w['network'] ?? '') === $network) return $w;
        }
        // Fallback: first non-archived wallet on this chain regardless of network.
        return $wallets[0] ?? null;
    }

    public static function network_for_chain(string $chain): string {
        $opt = 'cardano_mint_altpay_' . strtolower($chain) . '_network';
        $val = get_option($opt, '');
        if ($val === '') {
            // Default to mainnet for any chain unless operator selected otherwise.
            return 'mainnet';
        }
        return (string) $val;
    }

    /**
     * Returns 0 if observed is within +/- tolerance of expected,
     * -1 if under-tolerance, +1 if over-tolerance. String math throughout.
     */
    private static function compare_with_tolerance(string $observed, string $expected): int {
        $bps = (int) get_option('cardano_mint_altpay_amount_tolerance_bps', self::DEFAULT_TOLERANCE_BPS);
        if ($bps < 0) $bps = 0;

        // Use bcmath for portability; fall back to GMP if bcmath missing.
        if (function_exists('bccomp')) {
            $low  = bcmul($expected, (string) (10000 - $bps), 0);
            $high = bcmul($expected, (string) (10000 + $bps), 0);
            $obs  = bcmul($observed, '10000', 0);
            if (bccomp($obs, $low) < 0)  return -1;
            if (bccomp($obs, $high) > 0) return 1;
            return 0;
        }
        // Crude fallback. ETH wei will lose precision here; bcmath should be present.
        $obs = (float) $observed;
        $exp = (float) $expected;
        if ($exp <= 0) return 0;
        $delta = ($obs - $exp) / $exp * 10000;
        if ($delta < -$bps) return -1;
        if ($delta > $bps)  return 1;
        return 0;
    }

    private static function shape_status(array $inv): array {
        return [
            'status'                => $inv['status'],
            'chain'                 => $inv['chain'],
            'address'               => $inv['address'],
            'expected_amount_minor' => $inv['expected_amount_minor'],
            'observed_amount_minor' => $inv['observed_amount_minor'] ?? null,
            'confirmations'         => isset($inv['confirmations']) ? (int) $inv['confirmations'] : null,
            'expires_at'            => $inv['expires_at'],
        ];
    }
}
