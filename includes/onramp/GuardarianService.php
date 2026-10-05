<?php
namespace CardanoMintPay\Onramp;

use CardanoMintPay\Models\OnrampSessionModel;

if (!defined('ABSPATH')) exit;

/**
 * GuardarianService
 *
 * Orchestration layer between the REST controllers and the GuardarianClient.
 * Owns the rules around session creation, status mapping, and webhook
 * reconciliation. Controllers never call GuardarianClient directly.
 *
 * Design notes:
 *   - The customer's connected Cardano wallet IS the payout address. There
 *     is never an operator-wallet payout in this flow, so the site never
 *     custodies on-ramp funds.
 *   - The mint trigger is NOT here. Once Guardarian reports `finished`, the
 *     customer's wallet will receive ADA, and the existing client-side mint
 *     flow takes over (the modal polls Blockfrost until the wallet shows
 *     the new balance, then the customer signs a normal mint).
 *   - external_partner_link_id is generated locally as `kgo_{uniqid}` so
 *     it's both unique and distinguishable from Guardarian's numeric tx ids.
 */
class GuardarianService {

    public const FEE_BUFFER_OPTION = 'cardano_mint_onramp_fee_buffer_ada';
    public const DEFAULT_FEE_BUFFER_ADA = 7; // ~tx fee + min UTXO + headroom

    /**
     * Off by default. Presetting payout_address requires the Guardarian
     * partner permission `allow_preset_payout_address`, which has to be
     * enabled by Guardarian's business team. Without it, the customer is
     * asked to enter their address inside the iframe — which is fine, since
     * we already show the connected wallet's address with a copy button on
     * our pre-flight UI. Operators who later have the permission enabled
     * can flip this on from the on-ramp settings tab to skip that step.
     */
    public const PRESET_PAYOUT_OPTION = 'cardano_mint_onramp_preset_payout_enabled';

    /**
     * Pre-flight quote shown in our own KG-styled card BEFORE the iframe
     * opens. Returns the indicative ADA amount the customer should expect,
     * plus the configured min/max from Guardarian for the pair so the UI
     * can validate before round-tripping.
     *
     * @return array|\WP_Error
     */
    public static function quote(string $fromCurrency, $fromAmount, string $toCurrency = 'ADA') {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency   = strtoupper($toCurrency);
        $fromAmount   = (float) $fromAmount;

        if ($fromAmount <= 0) {
            return new \WP_Error('onramp_bad_amount', 'Amount must be greater than zero');
        }

        $range = GuardarianClient::minMaxRange($fromCurrency, $toCurrency);
        if (is_wp_error($range)) return $range;

        $min = isset($range['min']) ? (float) $range['min'] : 0;
        $max = isset($range['max']) ? (float) $range['max'] : 0;
        if ($min > 0 && $fromAmount < $min) {
            return new \WP_Error('onramp_below_min', sprintf('Minimum is %s %s', $min, $fromCurrency), [
                'min' => $min, 'max' => $max, 'currency' => $fromCurrency,
            ]);
        }
        if ($max > 0 && $fromAmount > $max) {
            return new \WP_Error('onramp_above_max', sprintf('Maximum is %s %s', $max, $fromCurrency), [
                'min' => $min, 'max' => $max, 'currency' => $fromCurrency,
            ]);
        }

        $est = GuardarianClient::estimate($fromCurrency, $toCurrency, $fromAmount);
        if (is_wp_error($est)) return $est;

        return [
            'from_currency'        => $fromCurrency,
            'from_amount'          => $fromAmount,
            'to_currency'          => $toCurrency,
            'to_amount_estimated'  => isset($est['value']) ? (string) $est['value'] : null,
            'estimated_rate'       => isset($est['estimated_exchange_rate']) ? (float) $est['estimated_exchange_rate'] : null,
            'min'                  => $min,
            'max'                  => $max,
            'fee_buffer_ada'       => self::feeBufferAda(),
            'env'                  => GuardarianClient::env(),
        ];
    }

    /**
     * Create a Guardarian transaction whose payout is the customer's own
     * Cardano wallet, and persist a corresponding session row.
     *
     * Locks the iframe to just card entry by setting:
     *   deposit.skip_choose_payment_category   true (+ payment_category VISA_MC)
     *   payout_info.skip_choose_payout_address true (+ preset payout_address)
     *
     * @param array  $params {
     *     @type string $from_currency           e.g. 'USD'
     *     @type float  $from_amount             e.g. 100
     *     @type string $customer_cardano_address bech32 addr1... that will receive ADA
     *     @type ?int   $mint_id                 optional link to a mint
     *     @type ?string $customer_email         prefills the iframe
     *     @type ?string $customer_locale        e.g. 'en'
     *     @type ?string $payment_category       defaults to 'VISA_MC'
     *     @type string $return_url              KG-side URL for Guardarian to redirect back to
     *     @type string $cancel_url              KG-side URL for cancel
     *     @type string $fail_url                KG-side URL for failure
     * }
     * @param string $customerIp Real customer IP for X-Forwarded-For
     * @return array|\WP_Error  {session_id, redirect_url, provider_tx_id, ...}
     */
    public static function createSession(array $params, string $customerIp = '') {
        $fromCurrency = strtoupper((string) ($params['from_currency'] ?? 'USD'));
        $fromAmount   = (float) ($params['from_amount'] ?? 0);
        $cardanoAddr  = trim((string) ($params['customer_cardano_address'] ?? ''));

        if ($fromAmount <= 0)   return new \WP_Error('onramp_bad_amount', 'Amount must be greater than zero');
        if ($cardanoAddr === '') return new \WP_Error('onramp_no_address', 'Customer Cardano address is required');
        if (strpos($cardanoAddr, 'addr1') !== 0 && strpos($cardanoAddr, 'addr_test1') !== 0) {
            return new \WP_Error('onramp_bad_address', 'Address must be a Cardano bech32 address (addr1... or addr_test1...)');
        }

        // Idempotency: if the customer has a session created in the last 5
        // minutes for the same {address, amount, currency} that's still
        // pending, return that one instead of POSTing a fresh transaction.
        // Guardarian rate-limits transaction creation to 1/min/IP, so this
        // saves the customer from hitting that wall on a double-click.
        $existing = self::findRecentDuplicate($cardanoAddr, $fromCurrency, $fromAmount);
        if ($existing) {
            return [
                'session_id'          => (int) $existing['id'],
                'partner_link_id'     => (string) $existing['external_partner_link_id'],
                'provider_tx_id'      => (string) ($existing['provider_tx_id'] ?? ''),
                'redirect_url'        => (string) ($existing['redirect_url'] ?? ''),
                'status'              => (string) $existing['status'],
                'to_amount_estimated' => $existing['to_amount_estimated'] ?? null,
                'from_amount'         => (float) $existing['from_amount'],
                'from_currency'       => (string) $existing['from_currency'],
                'reused'              => true,
            ];
        }

        $partnerLinkId = self::generatePartnerLinkId();

        $payload = [
            'from_amount'   => $fromAmount,
            'from_currency' => $fromCurrency,
            'to_currency'   => 'ADA',
            'to_network'    => 'ADA',
            'external_partner_link_id' => $partnerLinkId,
            'deposit'       => [
                'payment_category'             => (string) ($params['payment_category'] ?? 'VISA_MC'),
                'skip_choose_payment_category' => true,
            ],
        ];

        // Optional payout-address presetting. Off by default because it
        // requires the `allow_preset_payout_address` partner permission;
        // when off, the customer enters their address in the iframe and our
        // pre-flight UI already gives them a one-click copy of it.
        if (self::isPresetPayoutEnabled()) {
            $payload['payout_info'] = [
                'payout_address'             => $cardanoAddr,
                'skip_choose_payout_address' => true,
            ];
        }

        $redirects = [];
        if (!empty($params['return_url'])) $redirects['successful'] = (string) $params['return_url'];
        if (!empty($params['cancel_url'])) $redirects['cancelled']  = (string) $params['cancel_url'];
        if (!empty($params['fail_url']))   $redirects['failed']     = (string) $params['fail_url'];
        if (!empty($redirects)) $payload['redirects'] = $redirects;

        // Locale is a top-level field, independent of `customer`. Setting
        // it on its own is fine.
        if (!empty($params['customer_locale'])) {
            $payload['locale'] = sanitize_text_field((string) $params['customer_locale']);
        }

        // Customer must be a non-empty object when present. PHP encodes an
        // empty associative array as JSON `[]` (not `{}`), which Guardarian
        // rejects with "customer is not of a type(s) object". Only attach
        // it when we actually have content to put in it.
        $customer = [];
        if (!empty($params['customer_email'])) {
            $customer['contact_info'] = ['email' => sanitize_email((string) $params['customer_email'])];
        }
        if (!empty($customer)) {
            $payload['customer'] = $customer;
        }

        // Insert session FIRST so we have a stable id to log against, even
        // if the Guardarian POST fails. We set provider_tx_id once we have
        // the response.
        $sessionId = OnrampSessionModel::insert([
            'provider'                 => 'guardarian',
            'external_partner_link_id' => $partnerLinkId,
            'mint_id'                  => isset($params['mint_id']) ? (int) $params['mint_id'] : null,
            'customer_cardano_address' => $cardanoAddr,
            'payout_address'           => $cardanoAddr,
            'from_currency'            => $fromCurrency,
            'from_amount'              => $fromAmount,
            'to_currency'              => 'ADA',
            'status'                   => 'new',
            'meta'                     => ['payload_sent' => $payload, 'env' => GuardarianClient::env()],
        ]);
        if ($sessionId <= 0) return new \WP_Error('onramp_persist_failed', 'Could not create on-ramp session');

        $resp = GuardarianClient::createTransaction($payload, $customerIp);
        if (is_wp_error($resp)) {
            // Don't leave the session in 'new' forever; mark as failed so
            // the admin list isn't full of dead rows.
            OnrampSessionModel::set_status($sessionId, 'failed', [
                'meta' => [
                    'payload_sent' => $payload,
                    'error'        => $resp->get_error_message(),
                    'error_code'   => $resp->get_error_code(),
                    'error_data'   => $resp->get_error_data(),
                ],
            ]);
            return $resp;
        }

        $providerTxId = isset($resp['id']) ? (string) $resp['id'] : '';
        $redirectUrl  = isset($resp['redirect_url']) ? (string) $resp['redirect_url'] : '';
        $toAmountEst  = isset($resp['estimate_breakdown']['toAmount']) ? (string) $resp['estimate_breakdown']['toAmount']
                       : (isset($resp['to_amount']) ? (string) $resp['to_amount'] : null);
        $providerStatus = isset($resp['status']) ? self::normalizeStatus((string) $resp['status']) : 'new';

        OnrampSessionModel::update($sessionId, [
            'provider_tx_id'      => $providerTxId,
            'redirect_url'        => $redirectUrl,
            'to_amount_estimated' => $toAmountEst,
            'status'              => $providerStatus,
            'meta'                => ['payload_sent' => $payload, 'response' => $resp],
        ]);

        return [
            'session_id'              => $sessionId,
            'partner_link_id'         => $partnerLinkId,
            'provider_tx_id'          => $providerTxId,
            'redirect_url'            => $redirectUrl,
            'status'                  => $providerStatus,
            'to_amount_estimated'     => $toAmountEst,
            'from_amount'             => $fromAmount,
            'from_currency'           => $fromCurrency,
            'reused'                  => false,
        ];
    }

    /**
     * Public status lookup keyed by the unguessable partner_link_id rather
     * than the auto-increment session_id, so customer-facing endpoints
     * don't leak enumerable handles.
     *
     * @return array|\WP_Error
     */
    public static function statusByPartnerLink(string $partnerLinkId, bool $forceRefresh = false) {
        $row = OnrampSessionModel::get_by_partner_link($partnerLinkId);
        if (!$row) return new \WP_Error('onramp_not_found', 'Session not found');
        return self::status((int) $row['id'], $forceRefresh);
    }

    /**
     * Read-mostly status check. Returns the persisted row, optionally after
     * refreshing from Guardarian if forceRefresh is true (or if the row is
     * older than the cache window). Public-facing — used by the modal to
     * advance through status while the iframe is open.
     *
     * @return array|\WP_Error
     */
    public static function status(int $sessionId, bool $forceRefresh = false) {
        $row = OnrampSessionModel::get($sessionId);
        if (!$row) return new \WP_Error('onramp_not_found', 'Session not found');

        // Terminal: never re-poll Guardarian for these.
        if (in_array($row['status'], OnrampSessionModel::TERMINAL_STATUSES, true)) {
            return self::shapePublicStatus($row);
        }

        $providerTxId = (string) ($row['provider_tx_id'] ?? '');
        if ($providerTxId === '') return self::shapePublicStatus($row);

        // Throttle: at most one refresh every 5s per session.
        $cacheKey = 'cm_onramp_statuscache_' . $sessionId;
        $cached = get_transient($cacheKey);
        if (!$forceRefresh && $cached) return self::shapePublicStatus($row);

        $resp = GuardarianClient::getTransaction($providerTxId);
        if (is_wp_error($resp)) return self::shapePublicStatus($row); // soft-fail to last-known

        set_transient($cacheKey, 1, 5);

        $providerStatus = isset($resp['status']) ? self::normalizeStatus((string) $resp['status']) : $row['status'];
        $toAmountActual = isset($resp['to_amount']) ? (string) $resp['to_amount'] : ($row['to_amount_actual'] ?? null);

        if ($providerStatus !== $row['status'] || $toAmountActual !== $row['to_amount_actual']) {
            OnrampSessionModel::update($sessionId, [
                'status'           => $providerStatus,
                'to_amount_actual' => $toAmountActual,
            ]);
            $row['status'] = $providerStatus;
            $row['to_amount_actual'] = $toAmountActual;
        }

        return self::shapePublicStatus($row);
    }

    /**
     * Webhook reconciliation. Updates the session row from a Guardarian
     * webhook payload. Audit-only: does not trigger any mint, does not
     * affect customer wallet polling. The client-side modal already polls
     * Blockfrost directly for ADA arrival.
     *
     * @return array|\WP_Error
     */
    public static function applyWebhook(array $payload) {
        $tx = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $partnerLink = isset($tx['external_partner_link_id']) ? (string) $tx['external_partner_link_id'] : '';
        $providerTxId = isset($tx['id']) ? (string) $tx['id'] : '';

        $row = null;
        if ($partnerLink !== '')   $row = OnrampSessionModel::get_by_partner_link($partnerLink);
        if (!$row && $providerTxId !== '') $row = OnrampSessionModel::get_by_provider_tx('guardarian', $providerTxId);
        if (!$row) return new \WP_Error('onramp_webhook_unknown', 'Webhook references an unknown session');

        $providerStatus = isset($tx['status']) ? self::normalizeStatus((string) $tx['status']) : $row['status'];
        $toAmountActual = isset($tx['to_amount']) ? (string) $tx['to_amount'] : ($row['to_amount_actual'] ?? null);

        OnrampSessionModel::update((int) $row['id'], [
            'status'           => $providerStatus,
            'to_amount_actual' => $toAmountActual,
            'last_webhook_at'  => current_time('mysql'),
            'provider_tx_id'   => $providerTxId !== '' ? $providerTxId : ($row['provider_tx_id'] ?? null),
            'meta'             => self::mergeMeta($row, ['last_webhook' => $tx]),
        ]);

        return ['session_id' => (int) $row['id'], 'status' => $providerStatus];
    }

    public static function feeBufferAda(): int {
        $v = (int) get_option(self::FEE_BUFFER_OPTION, self::DEFAULT_FEE_BUFFER_ADA);
        return ($v > 0 && $v < 1000) ? $v : self::DEFAULT_FEE_BUFFER_ADA;
    }

    public static function isPresetPayoutEnabled(): bool {
        return (string) get_option(self::PRESET_PAYOUT_OPTION, '0') === '1';
    }

    /* ── internal helpers ──────────────────────────────────────────────── */

    /** Map Guardarian's exact status string to our enum (lower-case, _-separated). */
    private static function normalizeStatus(string $s): string {
        $s = strtolower(trim($s));
        $allowed = [
            'new', 'waiting_for_customer', 'waiting_for_deposit', 'exchanging',
            'on_hold', 'sending', 'finished', 'failed', 'expired', 'cancelled', 'refunded',
        ];
        return in_array($s, $allowed, true) ? $s : 'new';
    }

    private static function shapePublicStatus(array $row): array {
        return [
            'partner_link_id'     => (string) ($row['external_partner_link_id'] ?? ''),
            'status'              => (string) $row['status'],
            'is_terminal'         => in_array($row['status'], OnrampSessionModel::TERMINAL_STATUSES, true),
            'is_finished'         => $row['status'] === 'finished',
            'redirect_url'        => $row['redirect_url'] ?? null,
            'to_amount_estimated' => $row['to_amount_estimated'] ?? null,
            'to_amount_actual'    => $row['to_amount_actual'] ?? null,
            'payout_address'      => $row['payout_address'] ?? null,
            'from_amount'         => $row['from_amount'] ?? null,
            'from_currency'       => $row['from_currency'] ?? null,
        ];
    }

    private static function generatePartnerLinkId(): string {
        return 'kgo_' . bin2hex(random_bytes(8));
    }

    private static function findRecentDuplicate(string $cardanoAddr, string $fromCurrency, float $fromAmount): ?array {
        global $wpdb;
        $tbl = OnrampSessionModel::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$tbl`
              WHERE customer_cardano_address = %s
                AND from_currency = %s
                AND from_amount = %f
                AND status NOT IN ('finished','failed','expired','cancelled','refunded')
                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
              ORDER BY created_at DESC
              LIMIT 1",
            $cardanoAddr,
            $fromCurrency,
            $fromAmount
        ), ARRAY_A);
        return $row ?: null;
    }

    private static function mergeMeta(array $row, array $extra): array {
        $existing = isset($row['meta']) ? json_decode((string) $row['meta'], true) : [];
        if (!is_array($existing)) $existing = [];
        return array_merge($existing, $extra);
    }
}
