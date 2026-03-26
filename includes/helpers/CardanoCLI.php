<?php
/**
 * Cardano CLI Helper
 * Cross-platform wrapper for Deno-compiled Cardano binaries
 *
 * Automatically detects OS and uses the correct binary (Windows .exe or Linux)
 */

namespace CardanoMintPay\Helpers;

class CardanoCLI {

    /**
     * Get the correct binary path for the current operating system
     *
     * @param string $binary_name 'sign-tx' or 'cardano-wallet'
     * @return string Full path to the binary
     * @throws \Exception if binary not found
     */
    private static function getBinaryPath($binary_name) {
        $bin_dir = plugin_dir_path(__FILE__) . '../../bin/';

        // Detect operating system
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        if ($is_windows) {
            $binary_path = $bin_dir . $binary_name . '.exe';
        } else {
            // Linux/Mac
            $binary_path = $bin_dir . $binary_name . '-linux';
        }

        // Verify binary exists
        if (!file_exists($binary_path)) {
            throw new \Exception(
                "Cardano CLI binary not found: {$binary_path}\n" .
                "Please compile the binaries for your platform.\n" .
                "Windows: Run build-windows.bat\n" .
                "Linux: Run build-linux.sh"
            );
        }

        // On Linux, verify it's executable
        if (!$is_windows && !is_executable($binary_path)) {
            throw new \Exception(
                "Binary is not executable: {$binary_path}\n" .
                "Fix with: chmod +x {$binary_path}"
            );
        }

        return $binary_path;
    }

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
            error_log("CardanoCLI: Using pure PHP transaction signer");

            require_once plugin_dir_path(__FILE__) . 'CardanoTransactionSignerPHP.php';

            $result = CardanoTransactionSignerPHP::signTransaction($tx_hex, $skey_hex);

            if ($result['success']) {
                error_log("CardanoCLI: Pure PHP signing successful!");
            } else {
                error_log("CardanoCLI: Pure PHP signing failed: " . ($result['error'] ?? 'Unknown error'));
            }

            return $result;

        } catch (\Exception $e) {
            error_log("CardanoCLI: Exception in signTransaction: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a new Cardano wallet
     *
     * @param string $network 'mainnet' or 'preprod'
     * @param bool $with_mnemonic Generate with 24-word seed phrase
     * @param string|null $restore_seed Optional: restore from existing seed
     * @return array Wallet data or error
     */
    public static function generateWallet($network = 'preprod', $with_mnemonic = true, $restore_seed = null) {
        try {
            // Try pure PHP implementation first (works everywhere!)
            error_log("CardanoCLI: Attempting pure PHP wallet generation");

            require_once plugin_dir_path(__FILE__) . 'CardanoWalletPHP.php';

            $result = CardanoWalletPHP::generateWallet($network);

            if ($result['success']) {
                error_log("CardanoCLI: Pure PHP wallet generation successful!");
                return $result;
            }

            error_log("CardanoCLI: Pure PHP failed: " . $result['error'] . " - falling back to binary");

            // Fallback to binary if PHP method fails
            $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

            if (!$is_windows) {
                error_log("CardanoCLI: Linux detected - trying Python script");
                return self::generateWalletPython($network);
            }

            // Windows: Use Deno binary as last resort
            error_log("CardanoCLI: Windows detected - using Deno binary as fallback");
            $binary_path = self::getBinaryPath('cardano-wallet');

            // Generate unique temp filename
            $temp_name = 'wallet_' . uniqid();
            $temp_dir = sys_get_temp_dir();
            $old_cwd = getcwd();

            // Change to temp directory (binary saves files locally)
            chdir($temp_dir);

            // Build command
            if ($restore_seed) {
                // Restore from seed
                $command = sprintf(
                    '"%s" --name=%s --mnemonic --seed=%s 2>&1',
                    $binary_path,
                    escapeshellarg($temp_name),
                    escapeshellarg($restore_seed)
                );
            } elseif ($with_mnemonic) {
                // Generate with mnemonic
                $command = sprintf(
                    '"%s" --name=%s --mnemonic 2>&1',
                    $binary_path,
                    escapeshellarg($temp_name)
                );
            } else {
                // Generate enterprise wallet (no staking)
                $command = sprintf(
                    '"%s" --name=%s 2>&1',
                    $binary_path,
                    escapeshellarg($temp_name)
                );
            }

            // Execute
            error_log("CardanoCLI: Executing command in temp dir: {$temp_dir}");
            error_log("CardanoCLI: Command: {$command}");

            $output = shell_exec($command);

            error_log("CardanoCLI: Shell output: " . ($output ?? 'NULL'));

            // Read generated JSON file
            $json_file = $temp_dir . '/' . $temp_name . '.json';

            error_log("CardanoCLI: Looking for wallet file: {$json_file}");
            error_log("CardanoCLI: File exists? " . (file_exists($json_file) ? 'YES' : 'NO'));

            if (!file_exists($json_file)) {
                // List files in temp dir for debugging
                $temp_files = scandir($temp_dir);
                error_log("CardanoCLI: Files in temp dir: " . implode(', ', array_slice($temp_files, 0, 20)));

                chdir($old_cwd);
                return [
                    'success' => false,
                    'error' => 'Wallet file not created. Output: ' . $output
                ];
            }

            $wallet_json = file_get_contents($json_file);
            error_log("CardanoCLI: Wallet JSON length: " . strlen($wallet_json));
            error_log("CardanoCLI: Wallet JSON preview: " . substr($wallet_json, 0, 200));

            $wallet_data = json_decode($wallet_json, true);

            // Clean up temp file
            unlink($json_file);
            chdir($old_cwd);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CardanoCLI: JSON parse error: " . json_last_error_msg());
                return [
                    'success' => false,
                    'error' => 'Failed to parse wallet JSON: ' . json_last_error_msg()
                ];
            }

            error_log("CardanoCLI: Wallet data keys: " . implode(', ', array_keys($wallet_data)));

            // Add network info
            $wallet_data['network'] = $network;
            $wallet_data['success'] = true;

            error_log("CardanoCLI: Wallet generation SUCCESS");
            return $wallet_data;

        } catch (\Exception $e) {
            if (isset($old_cwd)) {
                chdir($old_cwd);
            }
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the payment address for a given network from wallet data
     *
     * @param array $wallet_data Wallet data from generateWallet()
     * @param string $network 'mainnet' or 'preprod'
     * @return string|null Payment address
     */
    public static function getPaymentAddress($wallet_data, $network = 'preprod') {
        if ($network === 'mainnet') {
            return $wallet_data['base_address_mainnet'] ?? $wallet_data['enterprise_address_mainnet'] ?? null;
        } else {
            return $wallet_data['base_address_preprod'] ?? $wallet_data['enterprise_address_preprod'] ?? null;
        }
    }

    /**
     * Get the stake address for a given network from wallet data
     *
     * @param array $wallet_data Wallet data from generateWallet()
     * @param string $network 'mainnet' or 'preprod'
     * @return string|null Stake address (null if enterprise wallet)
     */
    public static function getStakeAddress($wallet_data, $network = 'preprod') {
        if ($network === 'mainnet') {
            return $wallet_data['reward_address_mainnet'] ?? null;
        } else {
            return $wallet_data['reward_address_preprod'] ?? null;
        }
    }

    /**
     * Test if Cardano CLI binaries are available and working
     *
     * @return array Test results
     */
    public static function testBinaries() {
        $results = [
            'sign_tx' => ['available' => false, 'error' => null],
            'cardano_wallet' => ['available' => false, 'error' => null],
            'os' => PHP_OS,
            'is_windows' => (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
        ];

        // Test sign-tx
        try {
            $sign_tx_path = self::getBinaryPath('sign-tx');
            $results['sign_tx']['available'] = true;
            $results['sign_tx']['path'] = $sign_tx_path;
        } catch (\Exception $e) {
            $results['sign_tx']['error'] = $e->getMessage();
        }

        // Test cardano-wallet
        try {
            $wallet_path = self::getBinaryPath('cardano-wallet');
            $results['cardano_wallet']['available'] = true;
            $results['cardano_wallet']['path'] = $wallet_path;
        } catch (\Exception $e) {
            $results['cardano_wallet']['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Generate wallet using Python script (Linux fallback)
     * Used on Linux servers where Deno binary has memory issues
     *
     * @param string $network 'mainnet' or 'preprod'
     * @return array Wallet data or error
     */
    private static function generateWalletPython($network = 'preprod') {
        $script_path = plugin_dir_path(__FILE__) . '../../scripts/generate_wallet.py';

        if (!file_exists($script_path)) {
            return [
                'success' => false,
                'error' => 'Python wallet generator script not found: ' . $script_path
            ];
        }

        error_log("CardanoCLI: Using Python script at: {$script_path}");

        // Try python3 first, fall back to python
        $python_cmd = 'python3';
        exec('which python3 2>/dev/null', $output, $return_code);
        if ($return_code !== 0) {
            $python_cmd = 'python';
            error_log("CardanoCLI: python3 not found, trying python");
        }

        // Check Python version
        $version_check = shell_exec("{$python_cmd} --version 2>&1");
        error_log("CardanoCLI: Python version: {$version_check}");

        // Execute Python script
        $command = sprintf(
            '%s %s %s 2>&1',
            $python_cmd,
            escapeshellarg($script_path),
            escapeshellarg($network)
        );

        error_log("CardanoCLI: Executing Python command: {$command}");

        $output = shell_exec($command);

        error_log("CardanoCLI: Python output: " . ($output ?? 'NULL'));

        if (empty($output)) {
            return [
                'success' => false,
                'error' => 'No output from Python wallet generator. Python dependencies may be missing. Install with: pip3 install pycardano mnemonic bech32'
            ];
        }

        // Parse JSON output
        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Failed to parse Python output: ' . $output
            ];
        }

        // Python script returns different field names, map them to match Deno binary format
        if (isset($result['success']) && $result['success']) {
            error_log("CardanoCLI: Python wallet generated successfully");

            // Map Python fields to match expected format
            $mapped_result = [
                'success' => true,
                'mnemonic' => $result['mnemonic'],
                'skey_hex' => $result['skey_cbor_hex'] ?? $result['skey_bech32'],
                'key_hash' => $result['payment_keyhash'],
                'base_address_mainnet' => $result['payment_address'], // Python uses same address for all
                'base_address_preprod' => $result['payment_address'],
                'reward_address_mainnet' => $result['stake_address'],
                'reward_address_preprod' => $result['stake_address']
            ];

            error_log("CardanoCLI: Mapped Python result keys: " . implode(', ', array_keys($mapped_result)));

            return $mapped_result;
        }

        return $result; // Return error from Python
    }
}
