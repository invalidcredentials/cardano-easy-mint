<?php
namespace CardanoMintPay\Onramp;

use CardanoMintPay\Helpers\EncryptionHelper;

if (!defined('ABSPATH')) exit;

/**
 * GuardarianClient
 *
 * Thin REST wrapper around the Guardarian API. Stays free of business logic
 * — GuardarianService is what knows about sessions, mint context, etc.
 *
 * Auth model:
 *   - `x-api-key` is the public partner key, used for everything customer-
 *     facing (estimate, create-transaction, get-transaction, currencies).
 *   - `x-secret-key` is required only for the partner-wide list endpoint
 *     (`GET /v1/transactions`). Optional in settings; only used when set.
 *
 * Two stored options:
 *   cardano_mint_onramp_guardarian_api_key      encrypted
 *   cardano_mint_onramp_guardarian_secret_key   encrypted (optional)
 *   cardano_mint_onramp_guardarian_env          'production' | 'staging'
 *
 * Critical operational notes (per Guardarian docs):
 *   - POST /v1/transaction is rate-limited to 1 per minute per source IP.
 *     Always pass X-Forwarded-For with the customer's real IP so the
 *     limit is per-customer rather than per-VPS.
 *   - `payout_info.payout_address` is silently ignored unless the partner
 *     account has the `allow_preset_payout_address` permission. Without it,
 *     the customer is asked to enter their own ADA address in the iframe,
 *     which breaks our flow. Get this enabled by emailing business@guardarian.com.
 *
 * All public methods return either a decoded array on success, or a WP_Error
 * with a 'guardarian_*' code on failure. Never throws.
 */
class GuardarianClient {

    public const OPT_API_KEY     = 'cardano_mint_onramp_guardarian_api_key';
    public const OPT_SECRET_KEY  = 'cardano_mint_onramp_guardarian_secret_key';
    public const OPT_ENV         = 'cardano_mint_onramp_guardarian_env';

    public const ENV_PRODUCTION  = 'production';
    public const ENV_STAGING     = 'staging';

    private const PROD_BASE      = 'https://api-payments.guardarian.com/v1';
    private const STAGING_BASE   = 'https://api-payments-staging.guardarian.com/v1';

    public static function isConfigured(): bool {
        return self::apiKey() !== '';
    }

    public static function env(): string {
        $v = (string) get_option(self::OPT_ENV, self::ENV_PRODUCTION);
        return $v === self::ENV_STAGING ? self::ENV_STAGING : self::ENV_PRODUCTION;
    }

    public static function baseUrl(): string {
        return self::env() === self::ENV_STAGING ? self::STAGING_BASE : self::PROD_BASE;
    }

    public static function apiKey(): string {
        $enc = (string) get_option(self::OPT_API_KEY, '');
        if ($enc === '') return '';
        $dec = EncryptionHelper::decrypt($enc);
        return is_string($dec) ? $dec : '';
    }

    public static function secretKey(): string {
        $enc = (string) get_option(self::OPT_SECRET_KEY, '');
        if ($enc === '') return '';
        $dec = EncryptionHelper::decrypt($enc);
        return is_string($dec) ? $dec : '';
    }

    /**
     * GET /v1/estimate
     *
     * Indicative quote — Guardarian explicitly does not lock the rate. The
     * actual to_amount at execution may differ; size your fee buffer
     * accordingly when displaying to the customer.
     *
     * @return array|\WP_Error
     */
    public static function estimate(string $fromCurrency, string $toCurrency, $fromAmount, string $toNetwork = 'ADA') {
        $params = [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
            'from_amount'   => (string) $fromAmount,
            'to_network'    => $toNetwork,
        ];
        return self::request('GET', '/estimate?' . http_build_query($params));
    }

    /**
     * GET /v1/market-info/min-max-range/{from}_{to}
     *
     * Returns {min, max} per-transaction. Cached for an hour because limits
     * rarely move, and the customer-facing copy ("min $X to buy ADA") lives
     * downstream of this.
     *
     * @return array|\WP_Error
     */
    public static function minMaxRange(string $fromCurrency, string $toCurrency) {
        $key = strtolower($fromCurrency . '_' . $toCurrency);
        $cacheKey = 'cm_onramp_minmax_' . $key;
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;

        $resp = self::request('GET', '/market-info/min-max-range/' . rawurlencode($key));
        if (is_wp_error($resp)) return $resp;

        set_transient($cacheKey, $resp, HOUR_IN_SECONDS);
        return $resp;
    }

    /**
     * POST /v1/transaction
     *
     * Creates a fiat→crypto buy on Guardarian and returns the transaction
     * record (including `redirect_url` for the hosted checkout iframe).
     *
     * @param array $payload Must include from_amount, from_currency, to_currency.
     *                       Should include external_partner_link_id (our session
     *                       correlation id), payout_info.payout_address (the
     *                       customer's ADA wallet), and the skip_choose_*
     *                       flags so the iframe is locked to just the card form.
     * @param string $customerIp Real customer IP for X-Forwarded-For; bypasses
     *                           the per-IP rate limit being our VPS's IP.
     * @return array|\WP_Error
     */
    public static function createTransaction(array $payload, string $customerIp = '') {
        $headers = [];
        if ($customerIp !== '') $headers['X-Forwarded-For'] = $customerIp;
        return self::request('POST', '/transaction', $payload, $headers);
    }

    /**
     * GET /v1/transaction/{id}
     *
     * Fetch the current state of a transaction. Used both for the public
     * status endpoint (so the modal can poll while the customer is on the
     * Guardarian iframe) and as the source of truth when reconciling
     * webhook payloads we don't fully trust.
     *
     * @return array|\WP_Error
     */
    public static function getTransaction(string $providerTxId) {
        if ($providerTxId === '') return new \WP_Error('guardarian_bad_id', 'transaction id required');
        return self::request('GET', '/transaction/' . rawurlencode($providerTxId));
    }

    /**
     * GET /v1/currencies/crypto?available=true
     *
     * Operator-side sanity check that ADA is currently buyable. Cached for
     * a day; supported-currencies don't churn.
     *
     * @return array|\WP_Error
     */
    public static function availableCryptoCurrencies() {
        $cached = get_transient('cm_onramp_currencies_crypto');
        if (is_array($cached)) return $cached;
        $resp = self::request('GET', '/currencies/crypto?available=true');
        if (is_wp_error($resp)) return $resp;
        set_transient('cm_onramp_currencies_crypto', $resp, DAY_IN_SECONDS);
        return $resp;
    }

    /**
     * Settings-page connectivity probe. Hits a cheap endpoint with the
     * configured key and surfaces a clean ok/error to the admin UI.
     *
     * @return array {ok: bool, message: string, env: string}
     */
    public static function testConnection(): array {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => 'API key not set', 'env' => self::env()];
        }
        $resp = self::availableCryptoCurrencies();
        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => $resp->get_error_message(), 'env' => self::env()];
        }
        // Sanity check: ADA must be in the list, otherwise the on-ramp is
        // useless for this site even if auth is fine.
        $hasAda = false;
        foreach ($resp as $c) {
            if (is_array($c) && strtoupper((string) ($c['ticker'] ?? '')) === 'ADA') { $hasAda = true; break; }
        }
        if (!$hasAda) return ['ok' => false, 'message' => 'Auth OK but ADA is not currently available from this account', 'env' => self::env()];
        return ['ok' => true, 'message' => 'Connection OK; ADA is buyable', 'env' => self::env()];
    }

    /**
     * Internal HTTP layer. Always returns array on 2xx, WP_Error otherwise.
     * Surfaces Guardarian's own error envelope ({statusCode, code, message})
     * so callers can show the partner-support a useful message.
     */
    private static function request(string $method, string $path, $body = null, array $extraHeaders = []) {
        $apiKey = self::apiKey();
        if ($apiKey === '') return new \WP_Error('guardarian_no_key', 'Guardarian API key is not configured');

        $url = self::baseUrl() . $path;
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => array_merge([
                'x-api-key'    => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                // Optional secret key included only when the caller hits an
                // endpoint that accepts/requires it (currently none on the
                // customer path; left here so future ops endpoints work).
            ], $extraHeaders),
        ];
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true) && $body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return new \WP_Error('guardarian_http', $resp->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }

        // Guardarian errors come in two shapes:
        //   simple: {statusCode, code, message: "..."}
        //   validation: {statusCode, code, message: ["...","..."]}
        //                or {statusCode, errors: [{message,...},...]}
        // Flatten to one string so the customer sees the real reason
        // instead of PHP's "Array" cast warning.
        $msg = self::flattenErrorMessage($data, $code);
        $code_str = is_array($data) && isset($data['code']) ? (string) $data['code'] : ('http_' . $code);

        // Always log the full response server-side so the operator can
        // diagnose validation failures from PHP error log instead of
        // having to reproduce them.
        error_log('[CardanoMint Onramp] Guardarian ' . $method . ' ' . $path . ' -> ' . $code . ' : ' . $raw);

        return new \WP_Error('guardarian_' . $code_str, $msg, ['status' => $code, 'body' => $raw]);
    }

    /**
     * Walk the Guardarian error envelope and produce a single human-readable
     * string. Handles: scalar message, array-of-strings message, errors[]
     * with per-field messages, and total fallback to "http <code>".
     */
    private static function flattenErrorMessage($data, int $httpCode): string {
        if (!is_array($data)) return 'http ' . $httpCode;

        if (isset($data['message'])) {
            $m = $data['message'];
            if (is_string($m) && $m !== '') return $m;
            if (is_array($m)) {
                $parts = [];
                foreach ($m as $entry) {
                    if (is_string($entry) && $entry !== '') $parts[] = $entry;
                    elseif (is_array($entry) && isset($entry['message']) && is_string($entry['message'])) $parts[] = $entry['message'];
                }
                if (!empty($parts)) return implode('; ', $parts);
            }
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            $parts = [];
            foreach ($data['errors'] as $e) {
                if (is_string($e)) $parts[] = $e;
                elseif (is_array($e) && isset($e['message'])) $parts[] = (string) $e['message'];
            }
            if (!empty($parts)) return implode('; ', $parts);
        }

        // Fall back to error code (e.g. "BAD_REQUEST") if no usable message.
        if (isset($data['code'])) return (string) $data['code'] . ' (HTTP ' . $httpCode . ')';
        return 'http ' . $httpCode;
    }
}
