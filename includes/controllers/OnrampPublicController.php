<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Onramp\GuardarianService;
use CardanoMintPay\Onramp\GuardarianClient;
use CardanoMintPay\Helpers\BlockfrostClient;

if (!defined('ABSPATH')) exit;

/**
 * OnrampPublicController
 *
 * Customer-facing REST endpoints for the on-ramp modal:
 *
 *   POST /wp-json/cardano-mint/v1/onramp/quote
 *     body: { from_currency, from_amount }
 *     -> { from_amount, to_amount_estimated, min, max, fee_buffer_ada, ... }
 *
 *   POST /wp-json/cardano-mint/v1/onramp/sessions
 *     body: { from_currency, from_amount, customer_cardano_address,
 *             mint_id?, customer_email?, customer_locale? }
 *     -> { partner_link_id, redirect_url, status, to_amount_estimated }
 *
 *   GET  /wp-json/cardano-mint/v1/onramp/sessions/{partner_link_id}
 *     -> public status shape (no internal session_id)
 *
 *   GET  /wp-json/cardano-mint/v1/onramp/wallet-balance
 *     query: address, network
 *     -> { lovelace } via Blockfrost — used by the modal to detect ADA arrival
 *        in the customer's wallet after Guardarian's success redirect.
 *
 * All endpoints require the standard wp_rest nonce (X-WP-Nonce header) so a
 * random outside hit can't burn our Guardarian rate limit. The frontend
 * injects the nonce via wp_localize_script.
 */
class OnrampPublicController {

    public const REST_NAMESPACE = 'cardano-mint/v1';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void {
        register_rest_route(self::REST_NAMESPACE, '/onramp/quote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleQuote'],
            'permission_callback' => [self::class, 'permissionPublic'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/onramp/sessions', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleCreateSession'],
            'permission_callback' => [self::class, 'permissionPublic'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/onramp/sessions/(?P<partner_link_id>[A-Za-z0-9_]{8,64})', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleGetSession'],
            'permission_callback' => [self::class, 'permissionPublic'],
            'args'                => [
                'partner_link_id' => ['type' => 'string', 'required' => true],
                'refresh'         => ['type' => 'boolean', 'default' => false],
            ],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/onramp/wallet-balance', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleWalletBalance'],
            'permission_callback' => [self::class, 'permissionPublic'],
            'args'                => [
                'address' => ['type' => 'string', 'required' => true],
                'network' => ['type' => 'string', 'default' => 'mainnet'],
            ],
        ]);
    }

    /**
     * Standard WP REST nonce gate — same shape used by the rest of the
     * plugin's customer-facing endpoints. Anonymous requests are allowed
     * if the nonce is present and valid (the page that loaded the modal
     * has the nonce localized in).
     */
    public static function permissionPublic(\WP_REST_Request $req) {
        if (!GuardarianClient::isConfigured()) {
            return new \WP_Error('onramp_not_configured', 'On-ramp is not configured', ['status' => 503]);
        }
        $nonce = $req->get_header('X-WP-Nonce');
        if (!$nonce) $nonce = $req->get_param('_wpnonce');
        if (!wp_verify_nonce((string) $nonce, 'wp_rest')) {
            return new \WP_Error('rest_forbidden', 'Bad nonce', ['status' => 403]);
        }
        return true;
    }

    public static function handleQuote(\WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = $req->get_params();

        $fromCurrency = sanitize_text_field((string) ($body['from_currency'] ?? 'USD'));
        $fromAmount   = (float) ($body['from_amount'] ?? 0);

        $resp = GuardarianService::quote($fromCurrency, $fromAmount);
        if (is_wp_error($resp)) return $resp;
        return rest_ensure_response($resp);
    }

    public static function handleCreateSession(\WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = $req->get_params();

        $params = [
            'from_currency'            => sanitize_text_field((string) ($body['from_currency'] ?? 'USD')),
            'from_amount'              => (float) ($body['from_amount'] ?? 0),
            'customer_cardano_address' => trim((string) ($body['customer_cardano_address'] ?? '')),
            'mint_id'                  => isset($body['mint_id']) ? (int) $body['mint_id'] : null,
            'customer_email'           => isset($body['customer_email']) ? sanitize_email((string) $body['customer_email']) : null,
            'customer_locale'          => isset($body['customer_locale']) ? sanitize_text_field((string) $body['customer_locale']) : 'en',
            'return_url'               => isset($body['return_url']) ? esc_url_raw((string) $body['return_url']) : '',
            'cancel_url'               => isset($body['cancel_url']) ? esc_url_raw((string) $body['cancel_url']) : '',
            'fail_url'                 => isset($body['fail_url']) ? esc_url_raw((string) $body['fail_url']) : '',
        ];

        $resp = GuardarianService::createSession($params, self::clientIp($req));
        if (is_wp_error($resp)) return $resp;
        return rest_ensure_response($resp);
    }

    public static function handleGetSession(\WP_REST_Request $req) {
        $partnerLinkId = (string) $req->get_param('partner_link_id');
        $force = (bool) $req->get_param('refresh');

        $resp = GuardarianService::statusByPartnerLink($partnerLinkId, $force);
        if (is_wp_error($resp)) return $resp;
        return rest_ensure_response($resp);
    }

    /**
     * Blockfrost passthrough used by the modal to poll the customer's wallet
     * after Guardarian's success redirect. Returns just lovelace so the JS
     * can compare against the expected ADA amount and advance the UI.
     *
     * Cached server-side for 8s per address to keep our Blockfrost project
     * quota safe even if a customer leaves the modal open.
     */
    public static function handleWalletBalance(\WP_REST_Request $req) {
        $address = trim((string) $req->get_param('address'));
        $network = sanitize_text_field((string) ($req->get_param('network') ?: 'mainnet'));

        if ($address === '') return new \WP_Error('onramp_no_address', 'address required', ['status' => 400]);
        if (strpos($address, 'addr1') !== 0 && strpos($address, 'addr_test1') !== 0) {
            return new \WP_Error('onramp_bad_address', 'address must be a Cardano bech32 address', ['status' => 400]);
        }

        $cacheKey = 'cm_onramp_balcache_' . md5($network . '|' . $address);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return rest_ensure_response($cached);

        $bal = BlockfrostClient::addressBalance($address, $network);
        $payload = [
            'address'  => $address,
            'lovelace' => isset($bal['lovelace']) ? (string) $bal['lovelace'] : '0',
        ];
        if (!empty($bal['error'])) $payload['error'] = (string) $bal['error'];

        set_transient($cacheKey, $payload, 8);
        return rest_ensure_response($payload);
    }

    /** Best-effort real-client-IP extraction for X-Forwarded-For passthrough. */
    private static function clientIp(\WP_REST_Request $req): string {
        $candidates = [
            $req->get_header('CF-Connecting-IP'),
            $req->get_header('X-Forwarded-For'),
            $req->get_header('X-Real-IP'),
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ];
        foreach ($candidates as $c) {
            if (!$c) continue;
            // X-Forwarded-For can be a comma-separated list — take the first.
            $ip = trim(explode(',', (string) $c)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return '';
    }
}
