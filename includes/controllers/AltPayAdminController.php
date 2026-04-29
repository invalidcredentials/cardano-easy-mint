<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\AltPay\AltPayService;
use CardanoMintPay\AltPay\PriceOracle;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\Models\ChainTxLogModel;

if (!defined('ABSPATH')) exit;

/**
 * AltPayAdminController
 *
 * Single page under Cardano Mint -> Payment Wallets, with internal tabs for
 * BTC / ETH / SOL / Invoices / Settings. Tabs are switched via ?tab=... in
 * the URL so the operator can deep-link.
 *
 * Form submissions come back to the same page with a hidden _kg_action
 * field; AJAX-driven actions (rescan / generate-via-fetch / refund) use
 * the cardanocheckoutnonce nonce so they integrate with the existing
 * AJAX permission infrastructure.
 */
class AltPayAdminController {

    const PAGE_SLUG = 'cardano-payment-wallets';
    const NONCE     = 'cardanocheckoutnonce';

    public static function register(): void {
        add_action('wp_ajax_cardano_altpay_generate_wallet',  [self::class, 'ajaxGenerateWallet']);
        add_action('wp_ajax_cardano_altpay_import_wallet',    [self::class, 'ajaxImportWallet']);
        add_action('wp_ajax_cardano_altpay_archive_wallet',   [self::class, 'ajaxArchiveWallet']);
        add_action('wp_ajax_cardano_altpay_set_wallet_network',[self::class, 'ajaxSetWalletNetwork']);
        add_action('wp_ajax_cardano_altpay_rescan_invoice',   [self::class, 'ajaxRescanInvoice']);
        add_action('wp_ajax_cardano_altpay_refund_invoice',   [self::class, 'ajaxRefundInvoice']);
        add_action('wp_ajax_cardano_altpay_send_from_wallet', [self::class, 'ajaxSendFromWallet']);
        add_action('wp_ajax_cardano_altpay_wallet_balances',  [self::class, 'ajaxWalletBalances']);
        add_action('wp_ajax_cardano_altpay_save_settings',    [self::class, 'ajaxSaveSettings']);
        add_action('wp_ajax_cardano_altpay_reveal_mnemonic',  [self::class, 'ajaxRevealMnemonic']);
        add_action('admin_enqueue_scripts',                   [self::class, 'enqueueAdminAssets']);
    }

    public static function enqueueAdminAssets($hook): void {
        if (strpos((string) $hook, self::PAGE_SLUG) === false) return;
        $base = plugin_dir_url(dirname(__DIR__));
        wp_enqueue_script(
            'cardano-altpay-admin',
            $base . 'assets/altpay/altpay-admin.js',
            ['jquery'],
            '0.1.0',
            true
        );
        wp_localize_script('cardano-altpay-admin', 'cardanoAltPay', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'pageUrl' => admin_url('admin.php?page=' . self::PAGE_SLUG),
            'sweepTargets' => [
                'btc' => (string) get_option('cardano_mint_altpay_btc_sweep_address', ''),
                'eth' => (string) get_option('cardano_mint_altpay_eth_sweep_address', ''),
                'sol' => (string) get_option('cardano_mint_altpay_sol_sweep_address', ''),
            ],
        ]);
        wp_enqueue_style(
            'cardano-altpay-admin',
            $base . 'assets/altpay/altpay-admin.css',
            [],
            '0.1.0'
        );
    }

    public static function renderPage(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $allowed = ['dashboard', 'btc', 'eth', 'sol', 'invoices', 'settings'];
        if (!in_array($tab, $allowed, true)) $tab = 'dashboard';
        $cap = function_exists('cardanomint_altpay_capability_check')
            ? cardanomint_altpay_capability_check()
            : ['ok' => true, 'missing' => []];
        $base_url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        include plugin_dir_path(dirname(__DIR__)) . 'includes/views/altpay/page-payment-wallets.php';
    }

    /* ─── AJAX handlers ─────────────────────────────────────────────── */

    public static function ajaxGenerateWallet(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $chain   = sanitize_key($_POST['chain'] ?? '');
        $name    = sanitize_text_field($_POST['name'] ?? '');
        $network = sanitize_text_field($_POST['network'] ?? 'mainnet');

        $provider = AltPayService::provider($chain);
        if (!$provider) wp_send_json_error(['message' => 'unknown chain']);
        if ($name === '') $name = strtoupper($chain) . ' wallet';

        try {
            $wallet = $provider->generateParentWallet($network);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $id = ChainWalletModel::insert([
            'chain'   => $chain,
            'name'    => $name,
            'network' => $network,
            'xprv'    => $wallet['xprv'],
            'xpub'    => $wallet['xpub'] ?? '',
            'source'  => 'generated',
        ]);

        if ($id <= 0) wp_send_json_error(['message' => 'persist failed']);

        // One-shot reveal of the mnemonic via 5-min transient, mirroring the
        // policy-wallet UX. The transient is the only place the mnemonic
        // exists in cleartext after this AJAX response returns.
        if (!empty($wallet['mnemonic'])) {
            set_transient('cardano_altpay_mnemonic_' . $id, $wallet['mnemonic'], 5 * MINUTE_IN_SECONDS);
        }

        wp_send_json_success([
            'id'        => $id,
            'address0'  => $wallet['address0'] ?? '',
            'mnemonic'  => $wallet['mnemonic'] ?? '',
        ]);
    }

    public static function ajaxImportWallet(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $chain   = sanitize_key($_POST['chain'] ?? '');
        $name    = sanitize_text_field($_POST['name'] ?? '');
        $network = sanitize_text_field($_POST['network'] ?? 'mainnet');
        $secret  = isset($_POST['secret']) ? trim((string) $_POST['secret']) : '';

        if ($secret === '') wp_send_json_error(['message' => 'secret required']);

        $provider = AltPayService::provider($chain);
        if (!$provider) wp_send_json_error(['message' => 'unknown chain']);
        if ($name === '') $name = strtoupper($chain) . ' import';

        try {
            $wallet = $provider->importParentWallet($secret, $network);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $id = ChainWalletModel::insert([
            'chain'   => $chain,
            'name'    => $name,
            'network' => $network,
            'xprv'    => $wallet['xprv'],
            'xpub'    => $wallet['xpub'] ?? '',
            'source'  => 'imported',
        ]);

        if ($id <= 0) wp_send_json_error(['message' => 'persist failed']);

        wp_send_json_success([
            'id'       => $id,
            'address0' => $wallet['address0'] ?? '',
        ]);
    }

    public static function ajaxArchiveWallet(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        $id = (int) ($_POST['wallet_id'] ?? 0);
        $on = !empty($_POST['archived']);
        $ok = ChainWalletModel::set_archived($id, $on);
        if (!$ok) wp_send_json_error(['message' => 'update failed']);
        wp_send_json_success(['id' => $id, 'archived' => $on]);
    }

    public static function ajaxSetWalletNetwork(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        $id = (int) ($_POST['wallet_id'] ?? 0);
        $network = sanitize_text_field($_POST['network'] ?? '');
        $wallet = ChainWalletModel::get($id);
        if (!$wallet) wp_send_json_error(['message' => 'wallet not found']);
        $allowed = self::chainNetworks($wallet['chain']);
        if (!in_array($network, $allowed, true)) wp_send_json_error(['message' => 'unsupported network for this chain']);
        if (!ChainWalletModel::set_network($id, $network)) wp_send_json_error(['message' => 'update failed']);
        wp_send_json_success(['id' => $id, 'network' => $network]);
    }

    public static function ajaxRescanInvoice(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        $id = (int) ($_POST['invoice_id'] ?? 0);
        $inv = ChainInvoiceModel::get($id);
        if (!$inv) wp_send_json_error(['message' => 'invoice not found']);
        AltPayService::reconcile($inv);
        $fresh = ChainInvoiceModel::get($id);
        wp_send_json_success(['invoice' => $fresh]);
    }

    /**
     * Aggregate balances for a parent wallet across all known child
     * invoices. Used by the "Send funds" panel to show how much is
     * currently sittable on this wallet before the operator picks an
     * amount.
     */
    public static function ajaxWalletBalances(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        $walletId = (int) ($_POST['wallet_id'] ?? 0);
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) wp_send_json_error(['message' => 'wallet not found']);
        $provider = AltPayService::provider($wallet['chain']);
        if (!$provider) wp_send_json_error(['message' => 'unknown chain']);

        global $wpdb;
        $tbl = \CardanoMintPay\Models\ChainInvoiceModel::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT derivation_index, address FROM `$tbl` WHERE parent_wallet_id = %d ORDER BY derivation_index ASC",
            $walletId
        ), ARRAY_A);

        $children = [];
        $total = '0';
        foreach ($rows as $r) {
            $bal = $provider->checkAddressBalance($r['address'], $wallet['network']);
            $minor = (string) ($bal['balance_minor'] ?? '0');
            $children[] = [
                'index'         => (int) $r['derivation_index'],
                'address'       => $r['address'],
                'balance_minor' => $minor,
            ];
            if (function_exists('bcadd')) $total = bcadd($total, $minor, 0);
            else $total = (string) ((int) $total + (int) $minor);
        }
        usort($children, function ($a, $b) {
            if (function_exists('bccomp')) return bccomp($b['balance_minor'], $a['balance_minor'], 0);
            return ((int) $b['balance_minor']) - ((int) $a['balance_minor']);
        });
        wp_send_json_success([
            'wallet_id'   => $walletId,
            'chain'       => $wallet['chain'],
            'network'     => $wallet['network'],
            'total_minor' => $total,
            'children'    => $children,
        ]);
    }

    public static function ajaxSendFromWallet(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $walletId = (int) ($_POST['wallet_id'] ?? 0);
        $toAddr   = sanitize_text_field($_POST['to_address'] ?? '');
        $amount   = preg_replace('/[^0-9]/', '', (string) ($_POST['amount_minor'] ?? ''));

        if ($walletId <= 0)   wp_send_json_error(['message' => 'wallet_id required']);
        if ($toAddr === '')   wp_send_json_error(['message' => 'destination required']);
        if ($amount === '' || $amount === '0') wp_send_json_error(['message' => 'amount_minor required']);

        $res = AltPayService::send_from_wallet($walletId, $toAddr, $amount);
        if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()]);

        wp_send_json_success($res);
    }

    public static function ajaxRefundInvoice(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $id = (int) ($_POST['invoice_id'] ?? 0);
        $toAddr = sanitize_text_field($_POST['to_address'] ?? '');
        $amount = preg_replace('/[^0-9]/', '', (string) ($_POST['amount_minor'] ?? ''));

        if ($id <= 0)         wp_send_json_error(['message' => 'invoice_id required']);
        if ($toAddr === '')   wp_send_json_error(['message' => 'destination required']);
        if ($amount === '' || $amount === '0') wp_send_json_error(['message' => 'amount_minor required']);

        $res = AltPayService::refund($id, $toAddr, $amount);
        if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()]);

        wp_send_json_success([
            'invoice_id' => $id,
            'tx_hash'    => $res['tx_hash'] ?? '',
            'raw_tx'     => $res['raw_tx'] ?? '',
        ]);
    }

    public static function ajaxSaveSettings(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        $opts = [
            'cardano_mint_altpay_enabled'                 => isset($_POST['enabled']) ? '1' : '0',
            'cardano_mint_service_fee_ada'                => max(2, min(20, (int) ($_POST['service_fee_ada'] ?? 5))),
            'cardano_mint_altpay_amount_tolerance_bps'    => max(0, min(2000, (int) ($_POST['tolerance_bps'] ?? 100))),
            'cardano_mint_altpay_btc_network'             => sanitize_text_field($_POST['btc_network'] ?? 'mainnet'),
            'cardano_mint_altpay_eth_network'             => sanitize_text_field($_POST['eth_network'] ?? 'mainnet'),
            'cardano_mint_altpay_sol_network'             => sanitize_text_field($_POST['sol_network'] ?? 'mainnet'),
            'cardano_mint_altpay_btc_rpc'                 => esc_url_raw($_POST['btc_rpc'] ?? ''),
            'cardano_mint_altpay_eth_rpc_mainnet'         => esc_url_raw($_POST['eth_rpc_mainnet'] ?? ''),
            'cardano_mint_altpay_eth_rpc_sepolia'         => esc_url_raw($_POST['eth_rpc_sepolia'] ?? ''),
            'cardano_mint_altpay_sol_rpc_mainnet'         => esc_url_raw($_POST['sol_rpc_mainnet'] ?? ''),
            'cardano_mint_altpay_sol_rpc_devnet'          => esc_url_raw($_POST['sol_rpc_devnet'] ?? ''),
            'cardano_mint_altpay_btc_sweep_address'       => sanitize_text_field($_POST['btc_sweep_address'] ?? ''),
            'cardano_mint_altpay_eth_sweep_address'       => sanitize_text_field($_POST['eth_sweep_address'] ?? ''),
            'cardano_mint_altpay_sol_sweep_address'       => sanitize_text_field($_POST['sol_sweep_address'] ?? ''),
        ];
        foreach ($opts as $k => $v) update_option($k, $v);

        wp_send_json_success(['saved' => array_keys($opts)]);
    }

    public static function ajaxRevealMnemonic(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        $id = (int) ($_POST['wallet_id'] ?? 0);
        $mn = get_transient('cardano_altpay_mnemonic_' . $id);
        if (!$mn) wp_send_json_error(['message' => 'mnemonic no longer available — generate a fresh wallet to reveal again']);
        delete_transient('cardano_altpay_mnemonic_' . $id);
        wp_send_json_success(['mnemonic' => $mn]);
    }

    /* ─── Helpers used by views ─────────────────────────────────────── */

    public static function pageUrl(array $args = []): string {
        $args = array_merge(['page' => self::PAGE_SLUG], $args);
        return admin_url('admin.php?' . http_build_query($args));
    }

    public static function chainLabel(string $chain): string {
        return strtoupper($chain);
    }

    public static function chainNetworks(string $chain): array {
        switch ($chain) {
            case 'btc': return ['mainnet', 'testnet', 'signet'];
            case 'eth': return ['mainnet', 'sepolia'];
            case 'sol': return ['mainnet', 'devnet', 'testnet'];
        }
        return ['mainnet'];
    }

    public static function liveBalance(string $chain, string $address, string $network): ?array {
        $provider = AltPayService::provider($chain);
        if (!$provider) return null;
        $cacheKey = 'cm_altpay_addrcache_' . $chain . '_' . md5($address);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;
        $bal = $provider->checkAddressBalance($address, $network);
        set_transient($cacheKey, $bal, 30);
        return $bal;
    }
}
