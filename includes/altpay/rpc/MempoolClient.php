<?php
namespace CardanoMintPay\AltPay\Rpc;

if (!defined('ABSPATH')) exit;

/**
 * Mempool.space-compatible REST client. Supports mainnet, testnet, and signet
 * via the public mempool.space API. The base URL is overridable via the
 * cardano_mint_altpay_btc_rpc option so operators can swap in a self-hosted
 * Esplora instance.
 *
 * Returns normalized {balance_minor, last_tx, confirmations} from
 * checkAddressBalance(). Sums confirmed AND mempool funded outputs minus the
 * spent versions, so a single payment is detected immediately.
 */
class MempoolClient {

    private function baseUrl(string $network): string {
        $custom = get_option('cardano_mint_altpay_btc_rpc', '');
        if ($custom !== '') return rtrim($custom, '/');
        switch ($network) {
            case 'signet':  return 'https://mempool.space/signet/api';
            case 'testnet': return 'https://mempool.space/testnet/api';
            default:        return 'https://mempool.space/api';
        }
    }

    public function checkAddressBalance(string $address, string $network): array {
        $base = $this->baseUrl($network);
        $resp = wp_remote_get($base . '/address/' . rawurlencode($address), ['timeout' => 10]);
        if (is_wp_error($resp)) {
            error_log('[CardanoMint AltPay] mempool BTC fetch failed: ' . $resp->get_error_message());
            return ['balance_minor' => '0', 'last_tx' => null, 'confirmations' => null];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return ['balance_minor' => '0', 'last_tx' => null, 'confirmations' => null];

        $confirmed = (int) (($body['chain_stats']['funded_txo_sum'] ?? 0) - ($body['chain_stats']['spent_txo_sum'] ?? 0));
        $mempool   = (int) (($body['mempool_stats']['funded_txo_sum'] ?? 0) - ($body['mempool_stats']['spent_txo_sum'] ?? 0));
        $balance   = max(0, $confirmed + $mempool);

        $lastTx = null;
        $confs  = null;
        $tipResp = wp_remote_get($base . '/address/' . rawurlencode($address) . '/txs', ['timeout' => 10]);
        if (!is_wp_error($tipResp)) {
            $txs = json_decode(wp_remote_retrieve_body($tipResp), true);
            if (is_array($txs) && !empty($txs[0]['txid'])) {
                $lastTx = $txs[0]['txid'];
                if (!empty($txs[0]['status']['confirmed'])) {
                    $blockHeight = (int) $txs[0]['status']['block_height'];
                    $tipResp2 = wp_remote_get($base . '/blocks/tip/height', ['timeout' => 10]);
                    if (!is_wp_error($tipResp2)) {
                        $tip = (int) trim(wp_remote_retrieve_body($tipResp2));
                        $confs = max(0, $tip - $blockHeight + 1);
                    }
                } else {
                    $confs = 0;
                }
            }
        }

        return [
            'balance_minor' => (string) $balance,
            'last_tx'       => $lastTx,
            'confirmations' => $confs,
        ];
    }
}
