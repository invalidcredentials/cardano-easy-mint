<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Onramp\GuardarianClient;
use CardanoMintPay\Onramp\GuardarianService;
use CardanoMintPay\Helpers\EncryptionHelper;
use CardanoMintPay\Models\OnrampSessionModel;

if (!defined('ABSPATH')) exit;

/**
 * OnrampAdminController
 *
 * Admin AJAX surface for the on-ramp tab inside Payment Wallets. Owns its
 * own nonce ('cardanoonrampnonce') so it can be enabled/disabled
 * independently of the AltPay AJAX surface.
 *
 * Tab rendering still lives under the AltPay page (Payment Wallets), but
 * the AJAX handlers and the tab view template are owned here.
 */
class OnrampAdminController {

    public const NONCE = 'cardanoonrampnonce';

    public static function register(): void {
        add_action('wp_ajax_cardano_onramp_save_settings',   [self::class, 'ajaxSaveSettings']);
        add_action('wp_ajax_cardano_onramp_test_connection', [self::class, 'ajaxTestConnection']);
        add_action('wp_ajax_cardano_onramp_refresh_session', [self::class, 'ajaxRefreshSession']);
        add_action('admin_enqueue_scripts',                  [self::class, 'enqueueAdminAssets']);
    }

    public static function enqueueAdminAssets($hook): void {
        if (strpos((string) $hook, AltPayAdminController::PAGE_SLUG) === false) return;
        $base = plugin_dir_url(dirname(__DIR__));
        wp_enqueue_script(
            'cardano-onramp-admin',
            $base . 'assets/onramp/onramp-admin.js',
            ['jquery'],
            '0.1.0',
            true
        );
        wp_localize_script('cardano-onramp-admin', 'cardanoOnramp', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
        ]);
    }

    public static function renderTab(): void {
        $views_dir = plugin_dir_path(dirname(__DIR__)) . 'includes/views/altpay/';
        include $views_dir . 'tab-onramp.php';
    }

    public static function ajaxSaveSettings(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        // Encrypt the API + secret keys before storing. Empty input = leave
        // existing key alone (so the form doesn't show '••••' in plaintext
        // and an empty re-save doesn't blow it away).
        $api_key_input    = isset($_POST['guardarian_api_key'])    ? trim((string) $_POST['guardarian_api_key'])    : '';
        $secret_key_input = isset($_POST['guardarian_secret_key']) ? trim((string) $_POST['guardarian_secret_key']) : '';

        if ($api_key_input !== '') {
            update_option(GuardarianClient::OPT_API_KEY, EncryptionHelper::encrypt($api_key_input), false);
        }
        if ($secret_key_input !== '') {
            update_option(GuardarianClient::OPT_SECRET_KEY, EncryptionHelper::encrypt($secret_key_input), false);
        }

        $env = sanitize_text_field((string) ($_POST['guardarian_env'] ?? GuardarianClient::ENV_PRODUCTION));
        if (!in_array($env, [GuardarianClient::ENV_PRODUCTION, GuardarianClient::ENV_STAGING], true)) {
            $env = GuardarianClient::ENV_PRODUCTION;
        }
        update_option(GuardarianClient::OPT_ENV, $env, false);

        $fee_buffer = (int) ($_POST['fee_buffer_ada'] ?? GuardarianService::DEFAULT_FEE_BUFFER_ADA);
        if ($fee_buffer < 1 || $fee_buffer > 100) $fee_buffer = GuardarianService::DEFAULT_FEE_BUFFER_ADA;
        update_option(GuardarianService::FEE_BUFFER_OPTION, $fee_buffer, false);

        $allowlist_raw = (string) ($_POST['webhook_ip_allowlist'] ?? '');
        update_option(OnrampWebhookController::OPT_ALLOWLIST, $allowlist_raw, false);

        $enabled = isset($_POST['onramp_enabled']) ? '1' : '0';
        update_option('cardano_mint_onramp_enabled', $enabled, false);

        // Off by default — requires Guardarian's `allow_preset_payout_address`
        // partner permission. When off, customer enters address in the iframe
        // and our pre-flight UI hands them a one-click copy of it.
        $preset = isset($_POST['preset_payout_enabled']) ? '1' : '0';
        update_option(GuardarianService::PRESET_PAYOUT_OPTION, $preset, false);

        wp_send_json_success(['saved' => true]);
    }

    public static function ajaxTestConnection(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $resp = GuardarianClient::testConnection();
        wp_send_json_success($resp);
    }

    public static function ajaxRefreshSession(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $partnerLink = sanitize_text_field((string) ($_POST['partner_link_id'] ?? ''));
        if ($partnerLink === '') wp_send_json_error(['message' => 'partner_link_id required']);

        $resp = GuardarianService::statusByPartnerLink($partnerLink, true);
        if (is_wp_error($resp)) wp_send_json_error(['message' => $resp->get_error_message()]);
        wp_send_json_success($resp);
    }
}
