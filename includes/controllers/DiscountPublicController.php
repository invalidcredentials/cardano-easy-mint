<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Discounts\DiscountService;
use CardanoMintPay\Models\MintModel;

if (!defined('ABSPATH')) exit;

/**
 * DiscountPublicController
 *
 * Customer-facing "apply a code" preview for the mint modal. Runs over
 * admin-ajax (same transport + nonce the mint build/submit calls already use,
 * so the modal needs no new nonce), not REST — the widget/REST path can wrap
 * DiscountService directly when phase 2 needs it.
 *
 *   POST admin-ajax?action=cardano_discount_validate
 *     { nonce, code, asset_id, quantity, payment_method }
 *     -> success { valid, label, original_usd, discount_usd, final_usd, ... }
 *     -> error   { message }   (invalid / expired / wrong collection / etc.)
 *
 * Read-only: this reserves nothing and changes nothing. The code is actually
 * held at build time (DiscountService::reserve in the build endpoint), so the
 * preview can be hit freely. Server-authoritative pricing (DC-D1): the base
 * price comes from the mint record, never the client.
 */
class DiscountPublicController {

    public static function register(): void {
        add_action('wp_ajax_cardano_discount_validate',        [self::class, 'ajaxValidate']);
        add_action('wp_ajax_nopriv_cardano_discount_validate', [self::class, 'ajaxValidate']);
    }

    public static function ajaxValidate(): void {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        // Soft per-IP rate limit to blunt code enumeration (mirrors the alt-pay
        // quote limiter). Generous for a human typing one code; harsh for a bot.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : 'unknown';
        $rl_key = 'cm_discount_validate_rl_' . md5($ip);
        $rl_ct  = (int) get_transient($rl_key);
        if ($rl_ct >= 20) {
            wp_send_json_error(['message' => 'Too many attempts. Wait a minute and try again.']);
        }
        set_transient($rl_key, $rl_ct + 1, 60);

        $code     = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $quantity = max(1, min(5, intval($_POST['quantity'] ?? 1)));
        $method   = sanitize_text_field((string) ($_POST['payment_method'] ?? 'ada'));

        if ($code === '' || $asset_id <= 0) {
            wp_send_json_error(['message' => 'Enter a code.']);
        }

        $mint = MintModel::getMintById($asset_id);
        if (!$mint) {
            wp_send_json_error(['message' => 'Mint not found.']);
        }

        $policy_id    = (string) ($mint['policyid'] ?? '');
        $per_asset_usd = (float) ($mint['price'] ?? 0);
        if ($per_asset_usd <= 0) {
            wp_send_json_error(['message' => 'This mint is not priced, so a code can\'t be applied.']);
        }

        $res = DiscountService::validate($code, $policy_id, $per_asset_usd, $quantity);
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => $res['error'] ?? 'That code isn\'t valid.']);
        }

        $p    = $res['pricing'];
        $camp = $res['campaign'];
        wp_send_json_success([
            'valid'         => true,
            'code'          => $code,
            'label'         => $p['label'],
            // The rule itself, so the modal can re-preview at any quantity
            // without another round-trip (server still re-validates at build).
            'discount_type' => $camp['discount_type'],
            'percent_off'   => $camp['percent_off'],
            'fixed_off_usd' => $camp['fixed_off_usd'],
            'original_usd'  => $p['original_usd'],
            'discount_usd'  => $p['discount_usd'],
            'final_usd'     => $p['final_usd'],
            'quantity'      => $quantity,
        ]);
    }
}
