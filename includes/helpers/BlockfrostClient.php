<?php
/**
 * Blockfrost client.
 *
 * Used for ADA payment-wallet read operations only (address balance lookup
 * for the dashboard). Transaction build + submit go through the Anvil API
 * helper since we already authenticate there.
 *
 * Project IDs are network-specific and configured in the plugin settings.
 * The free Blockfrost tier (50K req/day, 10/sec) is plenty for this use.
 */

namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class BlockfrostClient {

    /**
     * Look up the lovelace balance at a single payment address.
     *
     * Returns ['lovelace' => '<minor units string>', 'address' => $address]
     * on success, or ['lovelace' => '0', ...] when the address has no UTxOs
     * (Blockfrost returns 404 in that case, which we treat as zero balance).
     *
     * Returns ['error' => '...'] on misconfiguration / network failure so
     * the dashboard can show the failure inline rather than blowing up.
     */
    public static function addressBalance(string $address, string $network): array {
        if ($address === '') return ['lovelace' => '0', 'address' => $address, 'error' => 'no address'];

        $project_id = self::projectIdFor($network);
        if ($project_id === '') {
            return [
                'lovelace' => '0',
                'address'  => $address,
                'error'    => "Blockfrost project ID for {$network} is not set in Settings.",
            ];
        }

        $base = self::baseUrlFor($network);
        if ($base === '') return ['lovelace' => '0', 'address' => $address, 'error' => "unknown network: {$network}"];

        $url = $base . '/addresses/' . rawurlencode($address);
        $response = wp_remote_get($url, [
            'headers' => ['project_id' => $project_id],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return ['lovelace' => '0', 'address' => $address, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 404) {
            // Address has never received funds. Treat as zero, not an error.
            return ['lovelace' => '0', 'address' => $address];
        }
        if ($code !== 200) {
            $decoded = json_decode($body, true);
            $msg = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : ('http ' . $code);
            return ['lovelace' => '0', 'address' => $address, 'error' => $msg];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['amount']) || !is_array($data['amount'])) {
            return ['lovelace' => '0', 'address' => $address, 'error' => 'unexpected response shape'];
        }

        $lovelace = '0';
        foreach ($data['amount'] as $entry) {
            if (is_array($entry) && ($entry['unit'] ?? '') === 'lovelace') {
                $lovelace = (string) ($entry['quantity'] ?? '0');
                break;
            }
        }
        return ['lovelace' => $lovelace, 'address' => $address];
    }

    public static function isConfiguredFor(string $network): bool {
        return self::projectIdFor($network) !== '';
    }

    private static function projectIdFor(string $network): string {
        switch ($network) {
            case 'mainnet': return (string) get_option('cardano_mint_altpay_blockfrost_mainnet', '');
            case 'preprod': return (string) get_option('cardano_mint_altpay_blockfrost_preprod', '');
            case 'preview': return (string) get_option('cardano_mint_altpay_blockfrost_preview', '');
        }
        return '';
    }

    private static function baseUrlFor(string $network): string {
        switch ($network) {
            case 'mainnet': return 'https://cardano-mainnet.blockfrost.io/api/v0';
            case 'preprod': return 'https://cardano-preprod.blockfrost.io/api/v0';
            case 'preview': return 'https://cardano-preview.blockfrost.io/api/v0';
        }
        return '';
    }
}
