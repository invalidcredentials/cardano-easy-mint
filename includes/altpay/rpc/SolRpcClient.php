<?php
namespace CardanoMintPay\AltPay\Rpc;

if (!defined('ABSPATH')) exit;

/**
 * Solana JSON-RPC client. Read-only methods needed for Phase 2:
 *   - getBalance (in lamports)
 *   - getSignaturesForAddress (last N inbound)
 *
 * Operator can override per network via
 * cardano_mint_altpay_sol_rpc_<network>.
 */
class SolRpcClient {

    private function rpcUrl(string $network): string {
        $custom = get_option('cardano_mint_altpay_sol_rpc_' . $network, '');
        if ($custom !== '') return $custom;
        switch ($network) {
            case 'devnet':  return 'https://api.devnet.solana.com';
            case 'testnet': return 'https://api.testnet.solana.com';
            default:        return 'https://api.mainnet-beta.solana.com';
        }
    }

    public function checkAddressBalance(string $address, string $network): array {
        $url = $this->rpcUrl($network);
        $balRes = $this->call($url, 'getBalance', [$address, ['commitment' => 'confirmed']]);
        $lamports = (int) ($balRes['value'] ?? 0);

        $lastTx = null;
        $confs  = null;
        $sigs = $this->call($url, 'getSignaturesForAddress', [$address, ['limit' => 1]]);
        if (is_array($sigs) && !empty($sigs[0]['signature'])) {
            $lastTx = $sigs[0]['signature'];
            // Commitment "confirmed" => ~32+ confs. Solana doesn't expose
            // confirmation count via RPC; map confirmation status to a
            // synthetic count so the UI has something to show.
            $confs = !empty($sigs[0]['confirmationStatus']) && $sigs[0]['confirmationStatus'] === 'finalized' ? 32 : 1;
        }

        return [
            'balance_minor' => (string) max(0, $lamports),
            'last_tx'       => $lastTx,
            'confirmations' => $confs,
        ];
    }

    public function getLatestBlockhash(string $network): ?string {
        $url = $this->rpcUrl($network);
        $r = $this->call($url, 'getLatestBlockhash', [['commitment' => 'confirmed']]);
        return is_array($r) ? ($r['value']['blockhash'] ?? null) : null;
    }

    /** Broadcast a base64-encoded versioned/legacy transaction. */
    public function sendRawTransaction(string $network, string $base64Tx): ?string {
        $url = $this->rpcUrl($network);
        return $this->call($url, 'sendTransaction', [$base64Tx, ['encoding' => 'base64', 'preflightCommitment' => 'confirmed']]);
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
            error_log('[CardanoMint AltPay] SOL RPC failed: ' . $resp->get_error_message());
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || isset($body['error'])) {
            if (isset($body['error']['message'])) {
                error_log('[CardanoMint AltPay] SOL RPC returned error: ' . $body['error']['message']);
            }
            return null;
        }
        return $body['result'] ?? null;
    }
}
