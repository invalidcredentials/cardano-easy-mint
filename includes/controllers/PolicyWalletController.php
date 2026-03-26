<?php
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Helpers\EncryptionHelper;
use CardanoMintPay\Helpers\CardanoCLI;
use CardanoMintPay\Helpers\PolicyImport;

class PolicyWalletController {

    public static function register() {
        // Handle form submissions
        add_action('admin_init', [self::class, 'handleFormSubmissions']);

        // AJAX handlers for wallet archive
        add_action('wp_ajax_cardano_archive_policy_wallet', [self::class, 'ajaxArchivePolicyWallet']);
        add_action('wp_ajax_cardano_unarchive_policy_wallet', [self::class, 'ajaxUnarchivePolicyWallet']);

        // AJAX handlers for advanced import
        add_action('wp_ajax_cardano_mint_import_skey', [self::class, 'ajaxImportSkey']);
        add_action('wp_ajax_cardano_mint_import_mnemonic', [self::class, 'ajaxImportMnemonic']);
        add_action('wp_ajax_cardano_mint_import_manual', [self::class, 'ajaxImportManual']);
    }

    /**
     * Handle form submissions (generate/delete wallet)
     */
    public static function handleFormSubmissions() {
        if (!isset($_POST['action'])) {
            return;
        }

        // Generate wallet
        if ($_POST['action'] === 'generate_policy_wallet') {
            check_admin_referer('cardano_generate_policy_wallet', 'generate_wallet_nonce');

            if (!current_user_can('manage_options')) {
                wp_die('Insufficient permissions');
            }

            self::generatePolicyWallet();
            return;
        }

        // Delete wallet
        if ($_POST['action'] === 'delete_policy_wallet') {
            check_admin_referer('cardano_delete_policy_wallet', 'delete_wallet_nonce');

            if (!current_user_can('manage_options')) {
                wp_die('Insufficient permissions');
            }

            self::deletePolicyWallet();
            return;
        }
    }

    /**
     * Generate a new policy wallet using Cardano Wallet CLI
     */
    private static function generatePolicyWallet() {
        $wallet_name = sanitize_text_field($_POST['wallet_name'] ?? 'Default Policy Wallet');
        $network = get_option('cardano-mint-networkenvironment', 'preprod');

        error_log("PolicyWalletController: Generating wallet for network: " . $network);

        // Generate wallet using cross-platform CLI helper
        $result = CardanoCLI::generateWallet($network, true);

        if (!$result || !isset($result['success']) || !$result['success']) {
            $error_msg = isset($result['error']) ? $result['error'] : 'Unknown wallet generation error';
            error_log("PolicyWalletController: Wallet generation failed: " . $error_msg);
            add_settings_error(
                'cardano_policy_wallet',
                'generation_failed',
                'Failed to generate wallet: ' . $error_msg,
                'error'
            );
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_redirect(admin_url('admin.php?page=cardano-policy-wallet'));
            exit;
        }

        error_log("PolicyWalletController: Wallet generated successfully");
        error_log("PolicyWalletController: Result keys: " . implode(', ', array_keys($result)));

        if (!isset($result['mnemonic'])) {
            error_log("PolicyWalletController: FAIL - Missing mnemonic field");
            add_settings_error(
                'cardano_policy_wallet',
                'generation_failed',
                'Invalid wallet data - missing mnemonic.',
                'error'
            );
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_redirect(admin_url('admin.php?page=cardano-policy-wallet'));
            exit;
        }

        error_log("PolicyWalletController: Mnemonic field exists");
        error_log("PolicyWalletController: Has payment_skey_extended? " . (isset($result['payment_skey_extended']) ? 'YES' : 'NO'));

        // Encrypt sensitive data
        $mnemonic_encrypted = EncryptionHelper::encrypt($result['mnemonic']);
        $skey_encrypted = EncryptionHelper::encrypt($result['payment_skey_extended']);

        error_log("PolicyWalletController: Mnemonic encrypted length: " . strlen($mnemonic_encrypted));
        error_log("PolicyWalletController: Skey encrypted length: " . strlen($skey_encrypted));

        if (empty($mnemonic_encrypted) || empty($skey_encrypted)) {
            error_log("PolicyWalletController: FAIL - Encryption failed (empty result)");
            add_settings_error(
                'cardano_policy_wallet',
                'encryption_failed',
                'Failed to encrypt wallet data. Please check WordPress configuration.',
                'error'
            );
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_redirect(admin_url('admin.php?page=cardano-policy-wallet'));
            exit;
        }

        error_log("PolicyWalletController: Encryption successful");

        // New wallet API returns addresses in nested array
        $payment_address = $result['addresses']['payment_address'] ?? null;
        $stake_address = $result['addresses']['stake_address'] ?? null;
        $payment_keyhash = $result['payment_keyhash'] ?? null;

        error_log("PolicyWalletController: Payment address: " . ($payment_address ?? 'NULL'));
        error_log("PolicyWalletController: Stake address: " . ($stake_address ?? 'NULL'));
        error_log("PolicyWalletController: Key hash: " . ($payment_keyhash ?? 'NULL'));

        // Store in database
        error_log("PolicyWalletController: Attempting database insertion...");
        $wallet_id = MintModel::insertPolicyWallet([
            'wallet_name' => $wallet_name,
            'mnemonic_encrypted' => $mnemonic_encrypted,
            'skey_encrypted' => $skey_encrypted,
            'payment_address' => $payment_address,
            'payment_keyhash' => $payment_keyhash,
            'stake_address' => $stake_address,
            'network' => $network
        ]);

        error_log("PolicyWalletController: Database insertion result: " . ($wallet_id ? "SUCCESS (ID: {$wallet_id})" : "FAILED"));

        if ($wallet_id) {
            // Store mnemonic in transient for ONE-TIME display (5 minutes)
            set_transient('cardano_policy_wallet_mnemonic_' . get_current_user_id(), $result['mnemonic'], 300);

            error_log("PolicyWalletController: Wallet creation complete - redirecting with success");
            add_settings_error(
                'cardano_policy_wallet',
                'wallet_created',
                '✅ Policy wallet created successfully! KeyHash: ' . $payment_keyhash,
                'success'
            );
        } else {
            error_log("PolicyWalletController: FAIL - Database insertion returned false/0");
            add_settings_error(
                'cardano_policy_wallet',
                'db_insert_failed',
                'Failed to save wallet to database.',
                'error'
            );
        }

        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=cardano-policy-wallet&created=1'));
        exit;
    }

    /**
     * Delete policy wallet
     */
    private static function deletePolicyWallet() {
        $wallet_id = intval($_POST['wallet_id'] ?? 0);

        if ($wallet_id > 0) {
            $deleted = MintModel::deletePolicyWallet($wallet_id);

            if ($deleted) {
                add_settings_error(
                    'cardano_policy_wallet',
                    'wallet_deleted',
                    'Policy wallet deleted successfully.',
                    'success'
                );
            } else {
                add_settings_error(
                    'cardano_policy_wallet',
                    'delete_failed',
                    'Failed to delete wallet.',
                    'error'
                );
            }
        }

        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=cardano-policy-wallet'));
        exit;
    }

    /**
     * AJAX handler: Archive policy wallet
     */
    public static function ajaxArchivePolicyWallet() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $wallet_id = intval($_POST['wallet_id'] ?? 0);

        if ($wallet_id <= 0) {
            wp_send_json_error(['message' => 'Invalid wallet ID']);
        }

        $result = MintModel::archivePolicyWallet($wallet_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result === false || $result === 0) {
            wp_send_json_error(['message' => 'Failed to archive wallet.']);
        }

        wp_send_json_success(['message' => 'Wallet archived successfully']);
    }

    /**
     * AJAX handler: Unarchive policy wallet
     */
    public static function ajaxUnarchivePolicyWallet() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $wallet_id = intval($_POST['wallet_id'] ?? 0);

        if ($wallet_id <= 0) {
            wp_send_json_error(['message' => 'Invalid wallet ID']);
        }

        $result = MintModel::unarchivePolicyWallet($wallet_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result === false || $result === 0) {
            wp_send_json_error(['message' => 'Failed to unarchive wallet.']);
        }

        wp_send_json_success(['message' => 'Wallet unarchived successfully']);
    }

    // ========================================
    // ADVANCED IMPORT HANDLERS
    // ========================================

    /**
     * AJAX handler: Import policy wallet from skey + native script
     */
    public static function ajaxImportSkey() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $name   = sanitize_text_field($_POST['name'] ?? '');
        $skey   = wp_unslash($_POST['skey'] ?? '');
        $script = wp_unslash($_POST['script'] ?? '');

        if (empty($name))   wp_send_json_error('Wallet name is required.');
        if (empty($skey))   wp_send_json_error('Signing key is required.');
        if (empty($script)) wp_send_json_error('Policy script is required.');

        $result = PolicyImport::import_skey_and_script($name, $skey, $script);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: Import policy wallet from mnemonic seed phrase
     */
    public static function ajaxImportMnemonic() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $name       = sanitize_text_field($_POST['name'] ?? '');
        $mnemonic   = sanitize_text_field($_POST['mnemonic'] ?? '');
        $expiration = sanitize_text_field($_POST['expiration_date'] ?? '');

        if (empty($name))     wp_send_json_error('Wallet name is required.');
        if (empty($mnemonic)) wp_send_json_error('Seed phrase is required.');

        $result = PolicyImport::import_mnemonic($name, $mnemonic, $expiration);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: Full manual import (policy ID + skey + script)
     */
    public static function ajaxImportManual() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $name      = sanitize_text_field($_POST['name'] ?? '');
        $policy_id = sanitize_text_field($_POST['policy_id'] ?? '');
        $skey      = wp_unslash($_POST['skey'] ?? '');
        $script    = wp_unslash($_POST['script'] ?? '');

        if (empty($name))      wp_send_json_error('Wallet name is required.');
        if (empty($policy_id)) wp_send_json_error('Policy ID is required.');
        if (empty($skey))      wp_send_json_error('Signing key is required.');
        if (empty($script))    wp_send_json_error('Policy script is required.');

        $result = PolicyImport::import_manual($name, $policy_id, $skey, $script);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }
}
