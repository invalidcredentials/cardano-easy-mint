<?php
namespace CardanoMintPay\Discounts;

use CardanoMintPay\Models\DiscountModel;

if (!defined('ABSPATH')) exit;

/**
 * DiscountService
 *
 * All discount business logic: code generation, validation, the DC-D9 pricing
 * math, and the reserve -> commit -> release lifecycle (DC-D4).
 *
 * Pricing is server-authoritative (DC-D1): callers pass the *authoritative*
 * per-asset USD price (read from the mint record, never the client) and the
 * service returns the discounted price. The discount only ever reduces the
 * MSRP / merchant-payment component (DC-D2); fees + receipt are out of scope
 * here and handled unchanged downstream in AnvilAPI::buildMintTransaction.
 */
class DiscountService {

    /** How long a reservation holds a use before the sweeper releases it. */
    const RESERVATION_TTL_MIN = 15;

    /** Unambiguous alphabet for generated codes (no 0/O, 1/I/L). */
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    const CODE_LENGTH   = 8;

    /* --------------------------------------------------------- code generation */

    public static function generate_unique_code(): string {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            if (!DiscountModel::code_exists($code)) {
                return $code;
            }
        }
        // Astronomically unlikely; widen with a time-seeded suffix as a fallback.
        return substr($code, 0, 4) . strtoupper(substr(dechex(random_int(0, 0xFFFFFF)), 0, 4));
    }

    /**
     * Generate + persist a batch of unique codes for a campaign. Returns the
     * code strings created.
     */
    public static function generate_codes(int $campaignId, int $count, int $usesAllowed): array {
        $count = max(1, min(5000, $count));
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $code = self::generate_unique_code();
            if (DiscountModel::insert_code($campaignId, $code, $usesAllowed)) {
                $created[] = $code;
            }
        }
        return $created;
    }

    /* ------------------------------------------------------------- pricing math */

    /**
     * Apply a campaign's discount to an order. DC-D9: percent is per-asset
     * (== per-order), fixed is taken off the order subtotal, and any result is
     * clamped to a $0 floor. Returns per-order totals plus the per-asset price
     * that gets handed to Anvil (which multiplies it back up by quantity).
     */
    public static function compute_pricing(array $campaign, float $perAssetUsd, int $quantity): array {
        $quantity = max(1, $quantity);
        $subtotal = round($perAssetUsd * $quantity, 2);
        $type     = (string) $campaign['discount_type'];
        $discount = 0.0;
        $label    = '';

        if ($type === 'percent') {
            $pct = (float) $campaign['percent_off'];
            $discount = round($subtotal * ($pct / 100), 2);
            $label = self::trim_num($pct) . '% off';
        } elseif ($type === 'fixed') {
            $fixed = (float) $campaign['fixed_off_usd'];
            $discount = min($fixed, $subtotal);
            $label = '$' . self::trim_num($fixed) . ' off';
        }

        $final          = max(0.0, round($subtotal - $discount, 2));
        $finalPerAsset  = $quantity > 0 ? round($final / $quantity, 6) : 0.0;

        return [
            'original_usd'        => $subtotal,
            'discount_usd'        => round($subtotal - $final, 2),
            'final_usd'           => $final,
            'final_per_asset_usd' => $finalPerAsset,
            'label'               => $label,
        ];
    }

    /* ----------------------------------------------------------------- validate */

    /**
     * Read-only validation + price preview. Reserves nothing. Returns:
     *   ['ok'=>true, 'campaign'=>[], 'code'=>[], 'pricing'=>[]]
     *   ['ok'=>false, 'error'=>'message']
     */
    public static function validate(string $code, string $policyId, float $perAssetUsd, int $quantity): array {
        $code = strtoupper(trim($code));
        if ($code === '') return ['ok' => false, 'error' => 'Enter a code.'];

        $codeRow = DiscountModel::get_code_by_string($code);
        if (!$codeRow)                       return ['ok' => false, 'error' => 'That code isn\'t valid.'];
        if ($codeRow['status'] !== 'active') return ['ok' => false, 'error' => 'This code has already been used or is no longer active.'];

        $campaign = DiscountModel::get_campaign((int) $codeRow['campaign_id']);
        if (!$campaign)                       return ['ok' => false, 'error' => 'That code isn\'t valid.'];
        if ($campaign['status'] !== 'active') return ['ok' => false, 'error' => 'This promotion is not currently active.'];

        if (!hash_equals(strtolower((string) $campaign['policy_id']), strtolower($policyId))) {
            return ['ok' => false, 'error' => 'This code isn\'t valid for this collection.'];
        }

        $now = current_time('timestamp');
        if (!empty($campaign['starts_at']) && $now < strtotime((string) $campaign['starts_at'])) {
            return ['ok' => false, 'error' => 'This promotion hasn\'t started yet.'];
        }
        if (!empty($campaign['expires_at']) && $now > strtotime((string) $campaign['expires_at'])) {
            return ['ok' => false, 'error' => 'This code has expired.'];
        }

        // Committed-use ceiling (the live reservation gate is enforced in reserve()).
        $allowed = (int) $codeRow['uses_allowed'];
        if ($allowed > 0 && (int) $codeRow['uses_count'] >= $allowed) {
            return ['ok' => false, 'error' => 'This code has already been used.'];
        }

        $type = (string) $campaign['discount_type'];
        if (!in_array($type, ['percent', 'fixed'], true)) {
            return ['ok' => false, 'error' => 'This discount type is not available yet.'];
        }

        return [
            'ok'       => true,
            'campaign' => $campaign,
            'code'     => $codeRow,
            'pricing'  => self::compute_pricing($campaign, $perAssetUsd, $quantity),
        ];
    }

    /* ----------------------------------------------------- reserve / commit / release */

    /**
     * Validate, then atomically hold a use (DC-D4). Returns the redemption id +
     * pricing on success. The live-use gate (committed + non-expired
     * reservations) is what stops a single-use code being claimed twice or
     * deposited against twice (alt-pay).
     */
    public static function reserve(string $code, string $policyId, float $perAssetUsd, int $quantity, string $paymentMethod, ?string $wallet, ?int $invoiceId = null): array {
        $res = self::validate($code, $policyId, $perAssetUsd, $quantity);
        if (!$res['ok']) return $res;

        $codeRow = $res['code'];
        $allowed = (int) $codeRow['uses_allowed'];
        if ($allowed > 0) {
            $live = DiscountModel::count_live_uses((int) $codeRow['id'], self::RESERVATION_TTL_MIN);
            if ($live >= $allowed) {
                return ['ok' => false, 'error' => 'This code is being used right now or is fully redeemed. Try again shortly.'];
            }
        }

        $pricing = $res['pricing'];
        $redemptionId = DiscountModel::insert_reservation([
            'code_id'        => (int) $codeRow['id'],
            'campaign_id'    => (int) $res['campaign']['id'],
            'policy_id'      => $policyId,
            'wallet_address' => $wallet,
            'payment_method' => $paymentMethod,
            'quantity'       => $quantity,
            'original_usd'   => $pricing['original_usd'],
            'discount_usd'   => $pricing['discount_usd'],
            'final_usd'      => $pricing['final_usd'],
            'invoice_id'     => $invoiceId,
        ]);
        if (!$redemptionId) return ['ok' => false, 'error' => 'Could not reserve the code. Try again.'];

        return [
            'ok'            => true,
            'redemption_id' => $redemptionId,
            'campaign'      => $res['campaign'],
            'code'          => $codeRow,
            'pricing'       => $pricing,
        ];
    }

    /**
     * Commit a reservation when the mint actually goes through. Verifies the
     * reservation is still held and (when known) belongs to this wallet, then
     * bumps the code's committed-use counter atomically.
     */
    public static function commit(int $redemptionId, ?string $wallet = null, ?string $txHash = null): bool {
        if ($redemptionId <= 0) return false;
        $red = DiscountModel::get_redemption($redemptionId);
        if (!$red || $red['status'] !== 'reserved') return false;
        if ($wallet && !empty($red['wallet_address']) && !hash_equals((string) $red['wallet_address'], (string) $wallet)) {
            return false;
        }

        // Atomic guard on the code; if we lost the race the use is already gone.
        if (!DiscountModel::bump_code_use((int) $red['code_id'])) {
            DiscountModel::mark_redemption($redemptionId, 'released');
            return false;
        }
        return DiscountModel::mark_redemption($redemptionId, 'redeemed', ['tx_hash' => $txHash]);
    }

    public static function release(int $redemptionId): bool {
        if ($redemptionId <= 0) return false;
        $red = DiscountModel::get_redemption($redemptionId);
        if (!$red || $red['status'] !== 'reserved') return false;
        return DiscountModel::mark_redemption($redemptionId, 'released');
    }

    /**
     * Link a (just-created) reservation to its alt-pay invoice. Keeps the row
     * 'reserved' but stamps invoice_id, which exempts it from the TTL sweep and
     * lets commit_for_invoice find it when the invoice is consumed.
     */
    public static function link_invoice(int $redemptionId, int $invoiceId): bool {
        if ($redemptionId <= 0 || $invoiceId <= 0) return false;
        return DiscountModel::mark_redemption($redemptionId, 'reserved', ['invoice_id' => $invoiceId]);
    }

    /** Commit the reservation tied to an alt-pay invoice once the mint lands. */
    public static function commit_for_invoice(int $invoiceId, ?string $txHash = null): bool {
        if ($invoiceId <= 0) return false;
        $red = DiscountModel::get_reserved_by_invoice($invoiceId);
        if (!$red) return false;
        if (!DiscountModel::bump_code_use((int) $red['code_id'])) {
            DiscountModel::mark_redemption((int) $red['id'], 'released');
            return false;
        }
        return DiscountModel::mark_redemption((int) $red['id'], 'redeemed', ['tx_hash' => $txHash]);
    }

    /** Cron: release stale ADA holds + alt-pay holds whose invoice has died. */
    public static function sweep(): int {
        $n  = DiscountModel::release_stale(self::RESERVATION_TTL_MIN);
        $n += DiscountModel::release_for_dead_invoices();
        return $n;
    }

    /* ------------------------------------------------------------------- helpers */

    private static function trim_num($n): string {
        $n = (float) $n;
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
