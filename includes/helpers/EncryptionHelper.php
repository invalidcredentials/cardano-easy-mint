<?php
namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

/**
 * Encryption Helper for Policy Wallet Storage
 * Uses WordPress salts for encryption key derivation
 */
class EncryptionHelper {

    /**
     * Encrypt sensitive data (mnemonic, skey)
     *
     * @param string $plaintext Data to encrypt
     * @return string Base64-encoded encrypted data with IV prepended
     */
    public static function encrypt($plaintext) {
        if (empty($plaintext)) {
            cardanomint_log('EncryptionHelper: encrypt() called with empty plaintext');
            return '';
        }

        cardanomint_log('EncryptionHelper: Encrypting data (length: ' . strlen($plaintext) . ')');

        // Derive encryption key from WordPress salts
        $key = self::deriveKey();

        cardanomint_log('EncryptionHelper: Encryption key derived (length: ' . strlen($key) . ')');

        // Generate random IV (16 bytes for AES-256-CBC)
        $iv = openssl_random_pseudo_bytes(16);

        cardanomint_log('EncryptionHelper: IV generated (length: ' . strlen($iv) . ')');

        // Encrypt using AES-256-CBC
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            cardanomint_log('EncryptionHelper: openssl_encrypt() returned FALSE');
            cardanomint_log('EncryptionHelper: OpenSSL error: ' . openssl_error_string(), 'error');
            return '';
        }

        cardanomint_log('EncryptionHelper: Encryption successful (encrypted length: ' . strlen($encrypted) . ')');

        // Prepend IV to encrypted data and encode as base64
        $result = base64_encode($iv . $encrypted);
        cardanomint_log('EncryptionHelper: Base64 encoded result length: ' . strlen($result));

        return $result;
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $ciphertext Base64-encoded encrypted data with IV
     * @return string|false Decrypted plaintext or false on failure
     */
    public static function decrypt($ciphertext) {
        if (empty($ciphertext)) {
            return false;
        }

        // Derive encryption key from WordPress salts
        $key = self::deriveKey();

        // Decode from base64
        $data = base64_decode($ciphertext, true);

        if ($data === false || strlen($data) < 17) {
            cardanomint_log('EncryptionHelper: Invalid ciphertext format', 'error');
            return false;
        }

        // Extract IV (first 16 bytes) and encrypted data
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        // Decrypt using AES-256-CBC
        $plaintext = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            cardanomint_log('EncryptionHelper: Decryption failed', 'error');
            return false;
        }

        return $plaintext;
    }

    /**
     * Derive encryption key from WordPress salts
     *
     * @return string 32-byte encryption key
     */
    private static function deriveKey() {
        // Combine WordPress security salts
        // These are unique per installation and defined in wp-config.php

        // Check if WordPress salts are defined
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY') ||
            !defined('LOGGED_IN_KEY') || !defined('NONCE_KEY')) {
            cardanomint_log('EncryptionHelper: WordPress salts not defined!');
            cardanomint_log('AUTH_KEY defined: ' . (defined('AUTH_KEY') ? 'YES' : 'NO'));
            cardanomint_log('SECURE_AUTH_KEY defined: ' . (defined('SECURE_AUTH_KEY') ? 'YES' : 'NO'));
            cardanomint_log('LOGGED_IN_KEY defined: ' . (defined('LOGGED_IN_KEY') ? 'YES' : 'NO'));
            cardanomint_log('NONCE_KEY defined: ' . (defined('NONCE_KEY') ? 'YES' : 'NO'));
        }

        $salt_data = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;

        // Log salt data length (not the actual salts for security)
        cardanomint_log('EncryptionHelper: Salt data length: ' . strlen($salt_data));

        // Derive a 32-byte key using SHA-256
        return hash('sha256', $salt_data, true);
    }

    /**
     * Test encryption/decryption (for debugging)
     *
     * @return bool True if encryption is working
     */
    public static function test() {
        $test_data = 'test_encryption_' . time();
        $encrypted = self::encrypt($test_data);
        $decrypted = self::decrypt($encrypted);

        $success = ($decrypted === $test_data);

        if (!$success) {
            cardanomint_log('EncryptionHelper test failed!', 'error');
            cardanomint_log('Original: ' . $test_data);
            cardanomint_log('Encrypted: ' . $encrypted);
            cardanomint_log('Decrypted: ' . ($decrypted ?: 'FALSE'));
        }

        return $success;
    }
}
