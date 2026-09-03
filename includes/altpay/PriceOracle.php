<?php
namespace CardanoMintPay\AltPay;

if (!defined('ABSPATH')) exit;

/**
 * PriceOracle
 *
 * USD-per-unit rates for BTC / ETH / SOL / ADA. Single CoinGecko request,
 * 5-minute transient. Mirrors the AnvilAPI::getAdaPrice() shape so the
 * caching characteristics match the existing ADA price path.
 *
 * If CoinGecko fails AND no last-known-good is cached, we return 0.0 and
 * AltPayService refuses to issue a quote. We never silently fall back to
 * $1.0 here because that would let a customer pay 1 sat for an NFT.
 */
class PriceOracle {

    const TRANSIENT_PREFIX = 'cm_altpay_price_';
    const LAST_GOOD_PREFIX = 'cm_altpay_price_lkg_';
    const TTL_SECONDS = 300; // 5 minutes
    const COINGECKO_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,solana,cardano&vs_currencies=usd';

    private const CHAIN_TO_COINGECKO = [
        'btc' => 'bitcoin',
        'eth' => 'ethereum',
        'sol' => 'solana',
        'ada' => 'cardano',
    ];

    /** Returns USD-per-unit for the given chain code, or 0.0 if no rate is available. */
    public static function getRate(string $chain): float {
        $chain = strtolower($chain);
        if (!isset(self::CHAIN_TO_COINGECKO[$chain])) return 0.0;

        $cached = get_transient(self::TRANSIENT_PREFIX . $chain);
        if (is_numeric($cached) && $cached > 0) return (float) $cached;

        $rates = self::refresh();
        if (isset($rates[$chain]) && $rates[$chain] > 0) return (float) $rates[$chain];

        $lkg = get_option(self::LAST_GOOD_PREFIX . $chain);
        return is_numeric($lkg) ? (float) $lkg : 0.0;
    }

    /** Force a CoinGecko refetch. Returns array<chain, float>. */
    public static function refresh(): array {
        $resp = wp_remote_get(self::COINGECKO_URL, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/CardanoMint-AltPay',
        ]);
        if (is_wp_error($resp)) {
            cardanomint_log('[CardanoMint AltPay] PriceOracle fetch failed: ' . $resp->get_error_message(), 'error');
            return [];
        }
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (!is_array($data)) return [];

        $out = [];
        foreach (self::CHAIN_TO_COINGECKO as $chain => $cg) {
            if (isset($data[$cg]['usd']) && is_numeric($data[$cg]['usd']) && $data[$cg]['usd'] > 0) {
                $rate = (float) $data[$cg]['usd'];
                set_transient(self::TRANSIENT_PREFIX . $chain, $rate, self::TTL_SECONDS);
                update_option(self::LAST_GOOD_PREFIX . $chain, $rate, false);
                $out[$chain] = $rate;
            }
        }
        return $out;
    }
}
