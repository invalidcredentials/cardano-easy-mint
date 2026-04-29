<?php
namespace CardanoMintPay\AltPay\Rpc;

if (!defined('ABSPATH')) exit;

/**
 * Minimal Ethereum JSON-RPC client. Only the read-only methods needed for
 * watcher / quote flows in Phase 2:
 *   - eth_blockNumber
 *   - eth_getBalance
 *   - eth_getBlockByNumber (to find a tx confirming the receive)
 *
 * Default endpoints picked because they require no API key. Operator can
 * override per network via cardano_mint_altpay_eth_rpc_<network> options.
 */
class EthRpcClient {

    private function rpcUrl(string $network): string {
        $custom = get_option('cardano_mint_altpay_eth_rpc_' . $network, '');
        if ($custom !== '') return $custom;
        switch ($network) {
            case 'sepolia': return 'https://ethereum-sepolia-rpc.publicnode.com';
            case 'goerli':  return 'https://ethereum-goerli-rpc.publicnode.com';
            default:        return 'https://eth.llamarpc.com';
        }
    }

    public function checkAddressBalance(string $address, string $network): array {
        $url = $this->rpcUrl($network);
        $balanceHex = $this->call($url, 'eth_getBalance', [strtolower($address), 'latest']);
        if ($balanceHex === null) return ['balance_minor' => '0', 'last_tx' => null, 'confirmations' => null];

        $balance = $this->hexToDecimalString($balanceHex);

        // Phase 2 only needs balance; tx hash + confirmations are best-effort.
        // Resolving these correctly without an indexer (Etherscan/Alchemy) is
        // expensive, so we leave them null and let the watcher promote on the
        // strength of balance >= expected, like the existing BTC path does
        // before block-height lookup runs.
        return [
            'balance_minor' => $balance,
            'last_tx'       => null,
            'confirmations' => null,
        ];
    }

    /** Returns the JSON-RPC `result` field as-is, or null on error. */
    public function call(string $url, string $method, array $params) {
        $payload = wp_json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => 1,
        ]);
        $resp = wp_remote_post($url, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $payload,
        ]);
        if (is_wp_error($resp)) {
            error_log('[CardanoMint AltPay] ETH RPC failed: ' . $resp->get_error_message());
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || isset($body['error'])) {
            if (isset($body['error']['message'])) {
                error_log('[CardanoMint AltPay] ETH RPC returned error: ' . $body['error']['message']);
            }
            return null;
        }
        return $body['result'] ?? null;
    }

    /** Convert "0x" hex to a decimal string. Uses GMP/bcmath for arbitrary precision. */
    private function hexToDecimalString(string $hex): string {
        $hex = ltrim($hex, 'x');
        if (strpos($hex, '0x') === 0) $hex = substr($hex, 2);
        $hex = ltrim($hex, '0');
        if ($hex === '') return '0';
        return \CardanoMintPay\AltPay\Lib\Bn::toDec(\CardanoMintPay\AltPay\Lib\Bn::fromHex($hex));
    }
}
