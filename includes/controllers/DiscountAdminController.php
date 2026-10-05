<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Discounts\DiscountInstaller;
use CardanoMintPay\Discounts\DiscountService;
use CardanoMintPay\Models\DiscountModel;
use CardanoMintPay\Models\MintModel;

if (!defined('ABSPATH')) exit;

/**
 * DiscountAdminController
 *
 * Top-level "Discounts" admin page (submenu under Cardano Mint, same level as
 * Policy Wallet / Payment Wallets). Create a campaign of codes for a policy,
 * list campaigns + results, export CSV.
 *
 * DC-D6: gated by `manage_options` + nonces only — no TOTP gate (unlike Payment
 * Wallets / Asset Upgrades), to keep batch generation friction-free.
 */
class DiscountAdminController {

    const PAGE_SLUG = 'cardano-mint-discounts';
    const NONCE     = 'cardano_discount_admin';
    const CAP       = 'manage_options';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);

        add_action('wp_ajax_cardano_discount_create_campaign', [self::class, 'ajax_create_campaign']);
        add_action('wp_ajax_cardano_discount_list_campaigns',  [self::class, 'ajax_list_campaigns']);
        add_action('wp_ajax_cardano_discount_view_campaign',   [self::class, 'ajax_view_campaign']);
        add_action('wp_ajax_cardano_discount_set_status',      [self::class, 'ajax_set_status']);
        add_action('wp_ajax_cardano_discount_disable_code',    [self::class, 'ajax_disable_code']);
        add_action('wp_ajax_cardano_discount_export_csv',      [self::class, 'ajax_export_csv']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cardano-mint-plugin-setup',
            'Discounts',
            'Discounts',
            self::CAP,
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function enqueue($hook): void {
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) return;
        // __DIR__ = includes/controllers; the plugin root (where the main file +
        // assets/ live) is two levels up.
        $dir  = dirname(__DIR__, 2);
        $base = plugin_dir_url($dir . '/cardano-nft-checkout.php');
        wp_enqueue_style(
            'cardano-discount-admin',
            $base . 'assets/discounts/discount-admin.css',
            [],
            (string) @filemtime($dir . '/assets/discounts/discount-admin.css')
        );
        wp_enqueue_script(
            'cardano-discount-admin',
            $base . 'assets/discounts/discount-admin.js',
            [],
            (string) @filemtime($dir . '/assets/discounts/discount-admin.js'),
            true
        );
        wp_localize_script('cardano-discount-admin', 'CM_DISCOUNT_ADMIN', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE),
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAP)) wp_die('Insufficient permissions');
        DiscountInstaller::maybe_install();
        $policies = MintModel::getAllPolicies();
        include dirname(__DIR__) . '/views/discounts/admin-page.php';
    }

    /* ----------------------------------------------------------------- helpers */

    private static function guard(): void {
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => 'Forbidden']);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private static function site_network(): string {
        $url = strtolower((string) get_option('cardano_mint_anvil_api_url', ''));
        $is_test = (strpos($url, 'preprod') !== false || strpos($url, 'preview') !== false || strpos($url, 'sancho') !== false);
        return $is_test ? 'preprod' : 'mainnet';
    }

    /* -------------------------------------------------------------------- ajax */

    public static function ajax_create_campaign(): void {
        self::guard();

        $title     = sanitize_text_field((string) ($_POST['title'] ?? ''));
        $policy_id = sanitize_text_field((string) ($_POST['policy_id'] ?? ''));
        $type      = sanitize_text_field((string) ($_POST['discount_type'] ?? ''));
        $code_mode = sanitize_text_field((string) ($_POST['code_mode'] ?? 'batch'));

        if ($title === '')                      wp_send_json_error(['message' => 'Give the campaign a title.']);
        if (!preg_match('/^[a-f0-9]{56}$/i', $policy_id)) wp_send_json_error(['message' => 'Pick a valid policy.']);
        if (!in_array($type, ['percent', 'fixed'], true)) wp_send_json_error(['message' => 'Pick a discount type.']);

        // Discount value sanity.
        $percent_off = null; $fixed_off = null;
        if ($type === 'percent') {
            $percent_off = (float) ($_POST['percent_off'] ?? 0);
            if ($percent_off <= 0 || $percent_off > 100) wp_send_json_error(['message' => 'Percent off must be between 0 and 100.']);
        } else {
            $fixed_off = (float) ($_POST['fixed_off_usd'] ?? 0);
            if ($fixed_off <= 0) wp_send_json_error(['message' => 'Fixed amount off must be greater than 0.']);
        }

        $uses_per_code = max(0, intval($_POST['uses_per_code'] ?? 1));
        $expires_at = trim((string) ($_POST['expires_at'] ?? ''));
        $expires_at = $expires_at !== '' ? gmdate('Y-m-d H:i:s', strtotime($expires_at)) : null;

        $campaign_id = DiscountModel::create_campaign([
            'title'         => $title,
            'policy_id'     => $policy_id,
            'network'       => self::site_network(),
            'discount_type' => $type,
            'percent_off'   => $percent_off,
            'fixed_off_usd' => $fixed_off,
            'code_mode'     => $code_mode,
            'uses_per_code' => $uses_per_code,
            'expires_at'    => $expires_at,
            'status'        => 'active',
        ]);
        if (!$campaign_id) wp_send_json_error(['message' => 'Could not create the campaign.']);

        $codes = [];
        if ($code_mode === 'shared') {
            $shared = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['shared_code'] ?? '')));
            if (strlen($shared) < 3 || strlen($shared) > 32) {
                wp_send_json_error(['message' => 'Shared code must be 3–32 letters/numbers.']);
            }
            if (DiscountModel::code_exists($shared)) {
                wp_send_json_error(['message' => 'That code already exists. Pick another.']);
            }
            // uses_allowed: campaign uses_per_code (0 = unlimited until expiry).
            DiscountModel::insert_code($campaign_id, $shared, $uses_per_code);
            $codes = [$shared];
        } else {
            $count = max(1, min(5000, intval($_POST['batch_count'] ?? 1)));
            // Batch codes are single-use by nature; honor uses_per_code if the
            // operator overrode it, else default 1.
            $per = $uses_per_code > 0 ? $uses_per_code : 1;
            $codes = DiscountService::generate_codes($campaign_id, $count, $per);
        }

        wp_send_json_success([
            'campaign_id' => $campaign_id,
            'codes'       => $codes,
            'count'       => count($codes),
        ]);
    }

    public static function ajax_list_campaigns(): void {
        self::guard();
        wp_send_json_success(['campaigns' => DiscountModel::list_campaigns(200, 0)]);
    }

    public static function ajax_view_campaign(): void {
        self::guard();
        $id = intval($_POST['campaign_id'] ?? 0);
        $campaign = DiscountModel::get_campaign($id);
        if (!$campaign) wp_send_json_error(['message' => 'Campaign not found.']);
        wp_send_json_success([
            'campaign'    => $campaign,
            'codes'       => DiscountModel::list_codes($id, 2000, 0),
            'redemptions' => DiscountModel::list_redemptions($id, 1000, 0),
        ]);
    }

    public static function ajax_set_status(): void {
        self::guard();
        $id = intval($_POST['campaign_id'] ?? 0);
        $status = sanitize_text_field((string) ($_POST['status'] ?? ''));
        if (!in_array($status, ['active', 'paused'], true)) wp_send_json_error(['message' => 'Bad status.']);
        DiscountModel::set_campaign_status($id, $status);
        wp_send_json_success(['status' => $status]);
    }

    public static function ajax_disable_code(): void {
        self::guard();
        $id = intval($_POST['code_id'] ?? 0);
        DiscountModel::set_code_status($id, 'disabled');
        wp_send_json_success(['code_id' => $id]);
    }

    /**
     * Stream a CSV of a campaign's codes + their redemption status. GET with the
     * admin nonce; exits after streaming.
     */
    public static function ajax_export_csv(): void {
        if (!current_user_can(self::CAP)) wp_die('Forbidden');
        check_ajax_referer(self::NONCE, 'nonce');

        $id = intval($_GET['campaign_id'] ?? 0);
        $campaign = DiscountModel::get_campaign($id);
        if (!$campaign) wp_die('Campaign not found');

        $codes = DiscountModel::list_codes($id, 100000, 0);
        $reds  = DiscountModel::list_redemptions($id, 100000, 0);

        // Index redemptions by code_id (latest committed wins for the summary).
        $by_code = [];
        foreach ($reds as $r) {
            $cid = (int) $r['code_id'];
            if (!isset($by_code[$cid]) || $r['status'] === 'redeemed') $by_code[$cid] = $r;
        }

        $slug = sanitize_title($campaign['title']) ?: ('campaign-' . $id);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="discount-' . $slug . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['code', 'status', 'uses_allowed', 'uses_count', 'redeemed_wallet', 'payment_method', 'final_usd', 'tx_hash', 'redeemed_at']);
        foreach ($codes as $c) {
            $r = $by_code[(int) $c['id']] ?? null;
            fputcsv($out, [
                $c['code'],
                $c['status'],
                $c['uses_allowed'],
                $c['uses_count'],
                $r['wallet_address'] ?? '',
                $r['payment_method'] ?? '',
                isset($r['final_usd']) ? $r['final_usd'] : '',
                $r['tx_hash'] ?? '',
                $r['redeemed_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}
