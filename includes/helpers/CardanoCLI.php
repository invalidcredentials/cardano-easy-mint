<?php
/**
 * CardanoCLI
 *
 * Historical name. Originally a wrapper around Deno-compiled Cardano binaries;
 * since 3.x everything runs through the pure-PHP signer and wallet generator
 * (CardanoTransactionSignerPHP / CardanoWalletPHP). Kept as a thin facade so
 * the call sites (PolicyWalletController, AltPayAdminController, AnvilAPI) do
 * not need to change. No binaries, no shell_exec, no Python.
 */

namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class CardanoCLI {

    /**
     * Sign a transaction with the policy wallet
     *
     * @param string $tx_hex Transaction CBOR hex
     * @param string $skey_hex Private key hex
     * @return array Result with 'success', 'signedTx', 'witnessSetHex' or 'error'
     */
    public static function signTransaction($tx_hex, $skey_hex) {
        try {
            // Use pure PHP signer (no external binaries needed!)
            cardanomint_log("CardanoCLI: Using pure PHP transaction signer");

            require_once plugin_dir_path(__FILE__) . 'CardanoTransactionSignerPHP.php';

            $result = CardanoTransactionSignerPHP::signTransaction($tx_hex, $skey_hex);

            if ($result['success']) {
                cardanomint_log("CardanoCLI: Pure PHP signing successful!");
            } else {
                cardanomint_log("CardanoCLI: Pure PHP signing failed: " . ($result['error'] ?? 'Unknown error'), 'error');
            }

            return $result;

        } catch (\Exception $e) {
            cardanomint_log("CardanoCLI: Exception in signTransaction: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a new policy wallet (pure PHP, CIP-1852).
     *
     * @param string      $network       "mainnet" or "preprod"
     * @param bool        $with_mnemonic Kept for signature compatibility; a mnemonic is always produced.
     * @param string|null $restore_seed  Not supported here; use the Policy Wallet import flow.
     * @return array Wallet data with success => true, or [success => false, error => ...]
     */
    public static function generateWallet($network = 'preprod', $with_mnemonic = true, $restore_seed = null) {
        if ($restore_seed !== null && $restore_seed !== '') {
            return ['success' => false, 'error' => 'Restoring from a seed is handled by the Policy Wallet import flow.'];
        }
        try {
            require_once plugin_dir_path(__FILE__) . 'CardanoWalletPHP.php';
            $result = CardanoWalletPHP::generateWallet($network);
            if (!empty($result['success'])) {
                cardanomint_log('CardanoCLI: pure PHP wallet generation successful');
                return $result;
            }
            cardanomint_log('CardanoCLI: pure PHP wallet generation failed: ' . ($result['error'] ?? 'Unknown error'), 'error');
            return ['success' => false, 'error' => $result['error'] ?? 'Wallet generation failed'];
        } catch (\Exception $e) {
            cardanomint_log('CardanoCLI: wallet generation exception: ' . $e->getMessage(), 'error');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

}
