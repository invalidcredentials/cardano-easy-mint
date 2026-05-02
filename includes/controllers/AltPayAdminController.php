<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\AltPay\AltPayService;
use CardanoMintPay\AltPay\PriceOracle;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\Models\ChainTxLogModel;
use CardanoMintPay\Helpers\EncryptionHelper;
use CardanoMintPay\Helpers\TOTPHelper;

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

    /* TOTP / 2FA state lives in WP options + per-user transients. */
    const TOTP_OPT_ENABLED       = 'cardano_mint_altpay_totp_enabled';
    const TOTP_OPT_SECRET        = 'cardano_mint_altpay_totp_secret_encrypted';
    const TOTP_OPT_RECOVERY      = 'cardano_mint_altpay_totp_recovery_codes';
    const TOTP_PENDING_PREFIX    = 'cardano_altpay_totp_pending_';   // setup secret before verify
    const TOTP_UNLOCKED_PREFIX   = 'cardano_altpay_totp_unlocked_';  // session unlock per user
    const TOTP_FAILS_PREFIX      = 'cardano_altpay_totp_fails_';     // rate-limit counter
    const TOTP_UNLOCK_TTL        = 1800; // 30 minutes
    const TOTP_FAILS_TTL         = 300;  // 5 minutes
    const TOTP_FAILS_LIMIT       = 5;
    const TOTP_SETUP_TTL         = 300;  // pending-setup secret lives 5 min

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
        add_action('wp_ajax_cardano_altpay_totp_begin_setup', [self::class, 'ajaxTotpBeginSetup']);
        add_action('wp_ajax_cardano_altpay_totp_verify_setup',[self::class, 'ajaxTotpVerifySetup']);
        add_action('wp_ajax_cardano_altpay_totp_disable',     [self::class, 'ajaxTotpDisable']);
        // admin_init fires before admin-header.php so wp_safe_redirect works.
        // renderPage runs AFTER headers, so the POST handler must live here.
        add_action('admin_init',                              [self::class, 'maybeHandleTotpUnlockPost']);
        add_action('admin_enqueue_scripts',                   [self::class, 'enqueueAdminAssets']);
    }

    /**
     * Hooked on admin_init. Only runs on our page when a TOTP unlock POST
     * is present; otherwise it's a no-op.
     */
    public static function maybeHandleTotpUnlockPost(): void {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== self::PAGE_SLUG) return;
        if (empty($_POST['kg_totp_unlock'])) return;
        self::handleTotpUnlockPost();
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
                'ada' => (string) get_option('cardano_mint_altpay_ada_sweep_address', ''),
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
        // 2FA gate: if TOTP is enabled and the current user hasn't unlocked
        // recently, render the prompt instead of any tab. The prompt POSTs
        // back to this same URL; the POST is handled in maybeHandleTotpUnlockPost
        // (hooked on admin_init so wp_safe_redirect works).
        if (self::isTotpEnabled() && !self::isTotpUnlocked()) {
            self::renderTotpPrompt();
            return;
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $allowed = ['dashboard', 'btc', 'eth', 'sol', 'ada', 'invoices', 'settings'];
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

        // ADA payment wallets do not fit the BTC/ETH/SOL HD-provider model
        // (no derivation, no per-mint child addresses). Branch into a
        // dedicated path that uses CardanoCLI::generateWallet, but still
        // persists into the polymorphic wp_cm_chain_wallets table so the
        // wallet lives next to the other payment wallets in the UI.
        if ($chain === 'ada') {
            self::generateAdaWallet($name, $network);
            return;
        }

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

        // ADA payment wallets are single-address custodial — no derivation,
        // no per-mint child invoices. Just look up the one address.
        if ($wallet['chain'] === 'ada') {
            $address = (string) ($wallet['xpub'] ?? '');
            $bal = \CardanoMintPay\Helpers\BlockfrostClient::addressBalance($address, $wallet['network']);
            if (!empty($bal['error'])) wp_send_json_error(['message' => $bal['error']]);
            $minor = (string) ($bal['lovelace'] ?? '0');
            wp_send_json_success([
                'wallet_id'   => $walletId,
                'chain'       => 'ada',
                'network'     => $wallet['network'],
                'total_minor' => $minor,
                'children'    => [
                    ['index' => 0, 'address' => $address, 'balance_minor' => $minor],
                ],
            ]);
        }

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

        // ADA send goes through Anvil's transactions/build + transactions/submit
        // pair, signed locally with the wallet's stored payment_skey_extended.
        // BTC/ETH/SOL stay on the existing AltPayService path.
        $wallet = ChainWalletModel::get($walletId);
        if ($wallet && $wallet['chain'] === 'ada') {
            self::sendFromAdaWallet($walletId, $toAddr, $amount);
            return;
        }

        $res = AltPayService::send_from_wallet($walletId, $toAddr, $amount);
        if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()]);

        wp_send_json_success($res);
    }

    /**
     * Send ADA from a custodial payment wallet via Anvil (build + submit) +
     * local signing. Uses the same Anvil API key/URL as minting (plugin_type
     * = 'mint') but bypasses AnvilAPI::submitTransaction() so we don't trigger
     * the policy-wallet co-signing logic — this isn't a mint, just a transfer.
     *
     * Min UTxO on Cardano outputs is ~1 ADA (mainnet, varies by era). The
     * caller is responsible for ensuring $lovelace is at least that, plus
     * leaving ~1 ADA in the change output. We surface Anvil's error if it
     * fails so the operator sees the real reason.
     */
    private static function sendFromAdaWallet(int $walletId, string $toAddr, string $amountLovelace): void {
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet || $wallet['chain'] !== 'ada') {
            wp_send_json_error(['message' => 'not an ada wallet']);
        }

        $source_address = (string) ($wallet['xpub'] ?? '');
        $skey_hex       = ChainWalletModel::get_xprv($walletId); // encrypted column actually holds the skey for ADA

        if ($source_address === '' || $skey_hex === '') {
            wp_send_json_error(['message' => 'wallet missing address or signing key']);
        }

        $lovelace = (int) $amountLovelace;
        if ($lovelace <= 0) wp_send_json_error(['message' => 'amount must be positive lovelace']);

        // Step 1: build via Anvil. Auto UTxO selection from the source
        // address (which is also the change address — any unspent UTxO
        // beyond the output + fee comes back to the source).
        $build_request = [
            'changeAddress' => $source_address,
            'outputs'       => [
                ['address' => $toAddr, 'lovelace' => $lovelace],
            ],
        ];

        $build = \CardanoMintPay\Helpers\AnvilAPI::call('transactions/build', $build_request, 'mint');
        if (is_wp_error($build)) {
            wp_send_json_error(['message' => 'Build failed: ' . $build->get_error_message()]);
        }

        // Anvil's response shape uses 'complete' for the unsigned tx hex on
        // the standard build endpoint. Fall through to other common keys
        // (some endpoints return 'transaction' or 'stripped' instead) so a
        // future API tweak doesn't silently break this.
        $tx_hex = (string) ($build['complete'] ?? $build['transaction'] ?? $build['stripped'] ?? '');
        if ($tx_hex === '') {
            wp_send_json_error([
                'message' => 'Build returned no transaction hex.',
                'response' => $build,
            ]);
        }

        // Step 2: sign locally with the wallet's payment_skey_extended.
        $signed = \CardanoMintPay\Helpers\CardanoCLI::signTransaction($tx_hex, $skey_hex);
        if (!is_array($signed) || empty($signed['success'])) {
            wp_send_json_error(['message' => 'Sign failed: ' . (is_array($signed) ? ($signed['error'] ?? 'unknown') : 'unknown')]);
        }
        $witness = (string) ($signed['witnessSetHex'] ?? '');
        if ($witness === '') wp_send_json_error(['message' => 'Sign returned no witness set']);

        // Step 3: submit the unsigned tx + our witness via Anvil. We call
        // AnvilAPI::call directly (not submitTransaction) so the policy-
        // wallet co-signing step is not triggered.
        $submit = \CardanoMintPay\Helpers\AnvilAPI::call('transactions/submit', [
            'transaction' => $tx_hex,
            'signatures'  => [$witness],
        ], 'mint');

        if (is_wp_error($submit)) {
            wp_send_json_error(['message' => 'Submit failed: ' . $submit->get_error_message()]);
        }

        $tx_hash = (string) ($submit['hash'] ?? $submit['txHash'] ?? $submit['tx_hash'] ?? '');

        wp_send_json_success([
            'tx_hash'        => $tx_hash,
            'source_index'   => 0, // ADA wallet is single-address; surface 0 so existing JS UX works
            'source_address' => $source_address,
            'dest_address'   => $toAddr,
            'amount_minor'   => (string) $lovelace,
        ]);
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
            'cardano_mint_altpay_ada_sweep_address'       => sanitize_text_field($_POST['ada_sweep_address'] ?? ''),
            'cardano_mint_altpay_blockfrost_mainnet'      => sanitize_text_field($_POST['blockfrost_mainnet'] ?? ''),
            'cardano_mint_altpay_blockfrost_preprod'      => sanitize_text_field($_POST['blockfrost_preprod'] ?? ''),
            'cardano_mint_altpay_blockfrost_preview'      => sanitize_text_field($_POST['blockfrost_preview'] ?? ''),
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
            case 'ada': return ['mainnet', 'preprod', 'preview'];
        }
        return ['mainnet'];
    }

    /**
     * Generate an ADA custodial payment wallet.
     *
     * This is a SEPARATE concern from policy wallets (wp_cm_policy_wallets)
     * which sign mints. ADA payment wallets receive funds — the operator
     * typically copies the resulting address into the merchant address
     * setting so minting change/payments flow into a wallet they control
     * inside the plugin instead of an external one.
     *
     * Storage column reuse for ADA in wp_cm_chain_wallets:
     *   xprv_encrypted -> encrypted payment_skey_extended
     *   xpub           -> the payment_address (NOT a Cardano public key)
     *   next_index     -> unused, stays 0 (no HD derivation for this wallet)
     */
    private static function generateAdaWallet(string $name, string $network): void {
        if ($name === '') $name = 'ADA payment wallet';
        if (!in_array($network, ['mainnet', 'preprod', 'preview'], true)) {
            wp_send_json_error(['message' => 'invalid ada network']);
        }

        $result = \CardanoMintPay\Helpers\CardanoCLI::generateWallet($network, true);
        if (!$result || empty($result['success'])) {
            $err = isset($result['error']) ? (string) $result['error'] : 'wallet generation failed';
            wp_send_json_error(['message' => $err]);
        }

        $skey            = (string) ($result['payment_skey_extended'] ?? '');
        $payment_address = (string) ($result['addresses']['payment_address'] ?? '');
        $mnemonic        = (string) ($result['mnemonic'] ?? '');

        if ($skey === '' || $payment_address === '') {
            wp_send_json_error(['message' => 'wallet generated but missing skey or address']);
        }

        $id = ChainWalletModel::insert([
            'chain'   => 'ada',
            'name'    => $name,
            'network' => $network,
            'xprv'    => $skey,
            'xpub'    => $payment_address,
            'source'  => 'generated',
        ]);
        if ($id <= 0) wp_send_json_error(['message' => 'persist failed']);

        if ($mnemonic !== '') {
            set_transient('cardano_altpay_mnemonic_' . $id, $mnemonic, 5 * MINUTE_IN_SECONDS);
        }

        wp_send_json_success([
            'id'       => $id,
            'address0' => $payment_address,
            'mnemonic' => $mnemonic,
        ]);
    }

    /* ─── TOTP / 2FA gate ───────────────────────────────────────────── */

    public static function isTotpEnabled(): bool {
        return (string) get_option(self::TOTP_OPT_ENABLED, '0') === '1';
    }

    public static function isTotpUnlocked(): bool {
        $uid = get_current_user_id();
        if ($uid <= 0) return false;
        return (bool) get_transient(self::TOTP_UNLOCKED_PREFIX . $uid);
    }

    public static function totpRecoveryRemaining(): int {
        $hashed = json_decode((string) get_option(self::TOTP_OPT_RECOVERY, '[]'), true);
        return is_array($hashed) ? count($hashed) : 0;
    }

    private static function setTotpUnlocked(): void {
        $uid = get_current_user_id();
        if ($uid > 0) set_transient(self::TOTP_UNLOCKED_PREFIX . $uid, time(), self::TOTP_UNLOCK_TTL);
    }

    private static function getTotpFailCount(): int {
        $uid = get_current_user_id();
        if ($uid <= 0) return 0;
        return (int) (get_transient(self::TOTP_FAILS_PREFIX . $uid) ?: 0);
    }

    private static function bumpTotpFailCount(): int {
        $uid = get_current_user_id();
        if ($uid <= 0) return 0;
        $count = self::getTotpFailCount() + 1;
        set_transient(self::TOTP_FAILS_PREFIX . $uid, $count, self::TOTP_FAILS_TTL);
        return $count;
    }

    private static function clearTotpFailCount(): void {
        $uid = get_current_user_id();
        if ($uid > 0) delete_transient(self::TOTP_FAILS_PREFIX . $uid);
    }

    /**
     * Render the lockscreen page that gates Payment Wallets when 2FA is on.
     * Must NOT call any tab views — this is a full replacement for the page.
     */
    private static function renderTotpPrompt(): void {
        $base_url     = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $rate_limited = self::getTotpFailCount() >= self::TOTP_FAILS_LIMIT;
        $just_failed  = isset($_POST['kg_totp_unlock']) && !$rate_limited;
        $remaining    = max(0, self::TOTP_FAILS_LIMIT - self::getTotpFailCount());
        $nonce        = wp_create_nonce('kg_totp_unlock');
        include plugin_dir_path(dirname(__DIR__)) . 'includes/views/altpay/totp-prompt.php';
    }

    /**
     * Process a TOTP unlock POST. Validates either a 6-digit TOTP code or a
     * single-use recovery code, sets the unlock transient on success, and
     * redirects back to the page so the gate passes on the next request.
     */
    private static function handleTotpUnlockPost(): void {
        if (empty($_POST['kg_totp_unlock'])) return;
        if (!current_user_can('manage_options')) return;
        if (!self::isTotpEnabled()) return;
        if (!isset($_POST['_kg_totp_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field((string) $_POST['_kg_totp_nonce']), 'kg_totp_unlock')) return;

        if (self::getTotpFailCount() >= self::TOTP_FAILS_LIMIT) return; // prompt page shows cooldown

        $input = sanitize_text_field((string) ($_POST['kg_totp_code'] ?? ''));
        $valid = false;

        if ($input !== '') {
            // Try 6-digit TOTP code first
            $digits = preg_replace('/[^0-9]/', '', $input);
            if (strlen($digits) === 6) {
                $secret = (string) EncryptionHelper::decrypt((string) get_option(self::TOTP_OPT_SECRET, ''));
                if ($secret !== '' && TOTPHelper::verify($secret, $digits)) {
                    $valid = true;
                }
            }

            // Else try as recovery code (XXXX-XXXX, 8 alphanumerics)
            if (!$valid) {
                $rc = TOTPHelper::normalizeRecoveryCode($input);
                if ($rc !== '' && self::tryConsumeRecoveryCode($rc)) {
                    $valid = true;
                }
            }
        }

        if ($valid) {
            self::clearTotpFailCount();
            self::setTotpUnlocked();
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
            exit;
        }
        self::bumpTotpFailCount();
        // Fall through; renderTotpPrompt will display the failure message.
    }

    /**
     * Step 1 of enrollment. Generates a fresh secret, stores it as a 5-min
     * transient (NOT yet the active secret), and returns it for QR/manual
     * scan. Verifying a code in step 2 is what promotes it to active.
     */
    public static function ajaxTotpBeginSetup(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        if (self::isTotpEnabled()) wp_send_json_error(['message' => '2FA is already enabled. Disable it first to re-enroll.']);

        $secret = TOTPHelper::generateSecret();
        $uid    = get_current_user_id();
        set_transient(self::TOTP_PENDING_PREFIX . $uid, $secret, self::TOTP_SETUP_TTL);

        $user  = wp_get_current_user();
        $label = $user && $user->user_login ? $user->user_login : 'admin';
        $uri   = TOTPHelper::buildOtpAuthUri($secret, $label);

        wp_send_json_success([
            'secret'             => $secret,
            'uri'                => $uri,
            'expires_in_seconds' => self::TOTP_SETUP_TTL,
        ]);
    }

    /**
     * Step 2 of enrollment. Verifies the user's TOTP code against the pending
     * secret; on success, promotes the pending secret to the active option,
     * generates 5 single-use recovery codes (returned plaintext ONCE,
     * persisted as password_hash), and unlocks the current session.
     */
    public static function ajaxTotpVerifySetup(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);
        if (self::isTotpEnabled()) wp_send_json_error(['message' => '2FA already enabled.']);

        $code = sanitize_text_field((string) ($_POST['code'] ?? ''));
        $code = preg_replace('/[^0-9]/', '', $code);
        if (strlen($code) !== 6) wp_send_json_error(['message' => 'Code must be 6 digits.']);

        $uid    = get_current_user_id();
        $secret = (string) get_transient(self::TOTP_PENDING_PREFIX . $uid);
        if ($secret === '') wp_send_json_error(['message' => 'Setup expired. Click Enable 2FA again to start over.']);

        if (!TOTPHelper::verify($secret, $code)) {
            wp_send_json_error(['message' => 'Code did not match. Check your authenticator app and try again.']);
        }

        // Generate recovery codes: keep plaintext for one-time UI display,
        // persist password_hash so even DB compromise doesn't reveal them.
        $recovery_plain  = [];
        $recovery_hashed = [];
        for ($i = 0; $i < 5; $i++) {
            $rc = TOTPHelper::generateRecoveryCode();
            $recovery_plain[]  = $rc;
            $recovery_hashed[] = password_hash($rc, PASSWORD_DEFAULT);
        }

        update_option(self::TOTP_OPT_SECRET,   EncryptionHelper::encrypt($secret), false);
        update_option(self::TOTP_OPT_RECOVERY, wp_json_encode($recovery_hashed),    false);
        update_option(self::TOTP_OPT_ENABLED,  '1',                                  false);

        delete_transient(self::TOTP_PENDING_PREFIX . $uid);
        self::setTotpUnlocked();

        wp_send_json_success([
            'enabled'        => true,
            'recovery_codes' => $recovery_plain,
        ]);
    }

    /**
     * Disable 2FA. Requires a valid current TOTP code OR a valid recovery
     * code, so a stolen WP admin session can't simply turn off 2FA.
     */
    public static function ajaxTotpDisable(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden']);

        if (!self::isTotpEnabled()) {
            wp_send_json_success(['enabled' => false]);
            return;
        }

        $input = sanitize_text_field((string) ($_POST['code'] ?? ''));
        $valid = false;

        $digits = preg_replace('/[^0-9]/', '', $input);
        if (strlen($digits) === 6) {
            $secret = (string) EncryptionHelper::decrypt((string) get_option(self::TOTP_OPT_SECRET, ''));
            if ($secret !== '' && TOTPHelper::verify($secret, $digits)) $valid = true;
        }
        if (!$valid) {
            $rc = TOTPHelper::normalizeRecoveryCode($input);
            if ($rc !== '' && self::tryConsumeRecoveryCode($rc)) $valid = true;
        }

        if (!$valid) wp_send_json_error(['message' => 'Code did not match. Cannot disable 2FA without a valid current code or recovery code.']);

        delete_option(self::TOTP_OPT_SECRET);
        delete_option(self::TOTP_OPT_RECOVERY);
        update_option(self::TOTP_OPT_ENABLED, '0', false);

        wp_send_json_success(['enabled' => false]);
    }

    /**
     * Try to verify a recovery code against the stored hash list. On match,
     * removes that hash from the list (single-use) and persists.
     */
    private static function tryConsumeRecoveryCode(string $code): bool {
        if ($code === '') return false;
        $hashed = json_decode((string) get_option(self::TOTP_OPT_RECOVERY, '[]'), true);
        if (!is_array($hashed) || empty($hashed)) return false;

        foreach ($hashed as $i => $hash) {
            if (!is_string($hash) || $hash === '') continue;
            if (password_verify($code, $hash)) {
                array_splice($hashed, $i, 1);
                update_option(self::TOTP_OPT_RECOVERY, wp_json_encode($hashed), false);
                return true;
            }
        }
        return false;
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
