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

    const QUOTE_TTL_SECONDS = 15 * 60;          // 15-minute rate lock
    const INVOICE_TTL_SECONDS = 30 * 60;         // 30-minute payment window
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
    public static function quote(int $mintId, string $chain, string $customerCardanoAddress) {
        $chain = strtolower($chain);

        $provider = self::provider($chain);
        if (!$provider) return new \WP_Error('altpay_unknown_chain', 'Unknown chain: ' . $chain);

        if ($mintId <= 0) return new \WP_Error('altpay_bad_mint', 'Missing mint id');
        if ($customerCardanoAddress === '') return new \WP_Error('altpay_bad_address', 'Missing Cardano address');

        $mint = MintModel::getMintById($mintId);
        if (!$mint) return new \WP_Error('altpay_mint_not_found', 'Mint not found');

        $usd = (float) ($mint['price'] ?? 0);
        if ($usd <= 0) return new \WP_Error('altpay_no_price', 'Mint is not priced');

        $rate = PriceOracle::getRate($chain);
        if ($rate <= 0) return new \WP_Error('altpay_no_rate', 'Could not fetch ' . strtoupper($chain) . ' price');

        $network = self::network_for_chain($chain);
        $wallet  = self::pick_active_wallet($chain, $network);
        if (!$wallet) return new \WP_Error('altpay_no_wallet', 'No active ' . strtoupper($chain) . ' wallet configured');

        $index = ChainWalletModel::allocate_next_index((int) $wallet['id']);
        if ($index < 0) return new \WP_Error('altpay_alloc_failed', 'Could not allocate derivation index');

        $address = $provider->deriveChildAddress((int) $wallet['id'], $index);
        if ($address === '') return new \WP_Error('altpay_derive_failed', 'Could not derive child address');

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

        if ($cmp === 0)      ChainInvoiceModel::set_status((int) $inv['id'], 'funded',    $extra);
        elseif ($cmp === -1) ChainInvoiceModel::set_status((int) $inv['id'], 'underpaid', $extra);
        else                 ChainInvoiceModel::set_status((int) $inv['id'], 'overpaid',  $extra);
    }

    public static function cancel(int $invoiceId): bool {
        $inv = ChainInvoiceModel::get($invoiceId);
        if (!$inv || $inv['status'] !== 'pending') return false;
        return ChainInvoiceModel::set_status($invoiceId, 'cancelled');
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
