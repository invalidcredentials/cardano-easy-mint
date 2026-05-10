<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Onramp\GuardarianService;

if (!defined('ABSPATH')) exit;

/**
 * OnrampWebhookController
 *
 * Receives status notifications from Guardarian. Audit-only:
 *   - Updates the matching session row's status + meta.
 *   - Does NOT trigger a mint. The customer-side modal polls Blockfrost for
 *     ADA arrival in the customer's wallet and prompts the user to mint.
 *
 * Authentication note: Guardarian webhooks are NOT signed (no HMAC). They
 * authenticate by source IP only, and Guardarian provides their outbound
 * IP set on request via business@guardarian.com. We allowlist those IPs
 * here. Cloudflare is in front of the site, so we read the real client IP
 * from CF-Connecting-IP first.
 *
 * If the IP allowlist is empty (e.g. before Guardarian ships us their
 * IPs), the endpoint refuses every request rather than open up. Operator
 * fills the allowlist in the on-ramp settings tab once they have it.
 */
class OnrampWebhookController {

    public const REST_NAMESPACE = 'cardano-mint/v1';
    public const OPT_ALLOWLIST  = 'cardano_mint_onramp_webhook_ip_allowlist';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void {
        register_rest_route(self::REST_NAMESPACE, '/onramp/webhooks/guardarian', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [self::class, 'permissionAllowlistedIp'],
        ]);
    }

    public static function permissionAllowlistedIp(\WP_REST_Request $req) {
        $ip = self::clientIp($req);
        $allowlist = self::allowlist();
        if (empty($allowlist)) {
            return new \WP_Error('webhook_disabled', 'Webhook IP allowlist is empty', ['status' => 503]);
        }
        if (!in_array($ip, $allowlist, true)) {
            error_log('[CardanoMint Onramp] Webhook from unauthorized IP: ' . $ip);
            return new \WP_Error('webhook_forbidden', 'IP not allowlisted', ['status' => 403]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];

        $resp = GuardarianService::applyWebhook($body);
        if (is_wp_error($resp)) {
            // Soft-200: don't make Guardarian retry a webhook we can't match,
            // but log loudly so the operator can investigate.
            error_log('[CardanoMint Onramp] Webhook reconcile error: ' . $resp->get_error_message());
            return rest_ensure_response(['ok' => false, 'message' => $resp->get_error_message()]);
        }
        return rest_ensure_response(['ok' => true, 'session_id' => $resp['session_id'] ?? null, 'status' => $resp['status'] ?? null]);
    }

    /**
     * Trim/dedupe the operator-managed IP list. Stored as a newline- or
     * comma-separated string in options for hand-editing convenience.
     */
    public static function allowlist(): array {
        $raw = (string) get_option(self::OPT_ALLOWLIST, '');
        if ($raw === '') return [];
        $parts = preg_split('/[\s,]+/', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            if (!filter_var($p, FILTER_VALIDATE_IP)) continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private static function clientIp(\WP_REST_Request $req): string {
        $cf = $req->get_header('CF-Connecting-IP');
        if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
        $xff = $req->get_header('X-Forwarded-For');
        if ($xff) {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }
}
