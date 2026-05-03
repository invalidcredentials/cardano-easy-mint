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

    public function getNonce(string $network, string $address): int {
        $url = $this->rpcUrl($network);
        $hex = $this->call($url, 'eth_getTransactionCount', [strtolower($address), 'pending']);
        if (!is_string($hex)) return 0;
        return (int) hexdec(ltrim(str_replace('0x', '', $hex), '0') ?: '0');
    }

    public function getChainId(string $network): int {
        $url = $this->rpcUrl($network);
        $hex = $this->call($url, 'eth_chainId', []);
        if (is_string($hex)) {
            $clean = ltrim(str_replace('0x', '', $hex), '0');
            return $clean === '' ? 0 : (int) hexdec($clean);
        }
        // Fall back to known mainnets if RPC didn't answer.
        switch ($network) {
            case 'sepolia': return 11155111;
            case 'goerli':  return 5;
            default:        return 1;
        }
    }

    /**
     * Estimate maxPriorityFeePerGas + maxFeePerGas in wei (decimal strings)
     * using eth_feeHistory if available, with conservative fallbacks.
     */
    public function getEip1559Fees(string $network): array {
        $url = $this->rpcUrl($network);
        $r = $this->call($url, 'eth_feeHistory', ['0x4', 'pending', [25, 50, 75]]);
        $tip   = '1500000000';   // 1.5 gwei default
        $base  = '20000000000';  // 20 gwei default
        if (is_array($r) && !empty($r['baseFeePerGas'])) {
            $bfg = end($r['baseFeePerGas']);
            $base = \CardanoMintPay\AltPay\Lib\Bn::toDec(\CardanoMintPay\AltPay\Lib\Bn::fromHex(str_replace('0x', '', (string) $bfg)));
            if (!empty($r['reward'])) {
                // Use the median tip across the last block.
                $row = end($r['reward']);
                if (is_array($row) && isset($row[1])) {
                    $tip = \CardanoMintPay\AltPay\Lib\Bn::toDec(\CardanoMintPay\AltPay\Lib\Bn::fromHex(str_replace('0x', '', (string) $row[1])));
                }
            }
        }
        // maxFee = 2 * base + tip (cushion for block fluctuation).
        $maxFee = bcadd(bcmul($base, '2'), $tip);
        return ['tip' => $tip, 'maxFee' => $maxFee, 'baseFee' => $base];
    }

    /**
     * Returns the tx hash on success, or ['__error' => msg] on RPC error.
     * The provider unpacks __error and rethrows a useful message.
     */
    public function sendRawTransaction(string $network, string $rawHex) {
        $url = $this->rpcUrl($network);
        if (strpos($rawHex, '0x') !== 0) $rawHex = '0x' . $rawHex;
        return $this->call($url, 'eth_sendRawTransaction', [$rawHex]);
    }

    public function checkAddressBalance(string $address, string $network): array {
        $url = $this->rpcUrl($network);
        $balanceHex = $this->call($url, 'eth_getBalance', [strtolower($address), 'latest']);
        if (!is_string($balanceHex) || $balanceHex === '') {
            return ['balance_minor' => '0', 'last_tx' => null, 'confirmations' => null];
        }

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

    /**
     * Returns the JSON-RPC `result` field on success or
     * ['__error' => msg] on RPC error. Read methods (chainId, nonce,
     * feeHistory) keep their existing call sites because they treat
     * '__error' arrays as null via array semantics; the write paths
     * (sendRawTransaction) check for '__error' explicitly.
     */
    public function call(string $url, string $method, array $params) {
        $payload = wp_json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => 1,
        ]);
        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $payload,
        ]);
        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            error_log('[CardanoMint AltPay] ETH RPC transport failed: ' . $msg);
            return ['__error' => 'transport: ' . $msg];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return ['__error' => 'non-JSON response'];
        if (isset($body['error'])) {
            $err = $body['error'];
            $msg = is_array($err) ? ($err['message'] ?? wp_json_encode($err)) : (string) $err;
            if (is_array($err) && isset($err['data'])) {
                $msg .= ' — ' . (is_string($err['data']) ? $err['data'] : wp_json_encode($err['data']));
            }
            error_log('[CardanoMint AltPay] ETH RPC returned error: ' . $msg);
            return ['__error' => $msg];
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
