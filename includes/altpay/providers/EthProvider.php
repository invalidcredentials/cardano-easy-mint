<?php
namespace CardanoMintPay\AltPay\Providers;

use CardanoMintPay\AltPay\ChainPaymentProvider;
use CardanoMintPay\AltPay\Bip39;
use CardanoMintPay\AltPay\Bip32Secp;
use CardanoMintPay\AltPay\Encoding\KeccakAddress;
use CardanoMintPay\AltPay\Rpc\EthRpcClient;
use CardanoMintPay\Models\ChainWalletModel;

if (!defined('ABSPATH')) exit;

/**
 * EthProvider
 *
 * BIP39 -> BIP32-secp256k1 -> keccak256 last-20-bytes address.
 * Standard Ethereum derivation path: m/44'/60'/0'/0/i.
 *
 * Same network value across all EVM chains because the path is identical;
 * we still record the operator's selected network so the watcher hits the
 * right RPC.
 */
class EthProvider implements ChainPaymentProvider {

    public function chainCode(): string { return 'eth'; }

    public function generateParentWallet(string $network): array {
        $mnemonic = Bip39::generate(24);
        $seed = Bip39::mnemonicToSeed($mnemonic);
        $master = Bip32Secp::masterFromSeed($seed);
        $account = Bip32Secp::derivePath($master, "m/44'/60'/0'/0");
        $address0 = $this->deriveAddressFromAccount($account, 0);
        return [
            'xprv'     => $this->serialize($master),
            'xpub'     => '',
            'address0' => $address0,
            'mnemonic' => $mnemonic,
        ];
    }

    public function importParentWallet(string $xprvOrMnemonic, string $network): array {
        $trimmed = trim($xprvOrMnemonic);
        if (preg_match('/^[a-z\s]+$/', strtolower($trimmed))) {
            $seed = Bip39::mnemonicToSeed($trimmed);
            $master = Bip32Secp::masterFromSeed($seed);
            $account = Bip32Secp::derivePath($master, "m/44'/60'/0'/0");
            return [
                'xprv'     => $this->serialize($master),
                'xpub'     => '',
                'address0' => $this->deriveAddressFromAccount($account, 0),
                'mnemonic' => $trimmed,
            ];
        }
        throw new \RuntimeException('xprv-form import not yet supported; use a mnemonic for now');
    }

    public function deriveChildAddress(int $walletId, int $index): string {
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');
        $master = $this->parse($xprv);
        $account = Bip32Secp::derivePath($master, "m/44'/60'/0'/0");
        return $this->deriveAddressFromAccount($account, $index);
    }

    public function expectedAmountMinor(float $usd, float $rate): string {
        if ($rate <= 0) return '0';
        $eth_ratio = $usd / $rate;
        if (function_exists('bcmul')) {
            // PHP can lose precision converting tiny doubles to string. Use sprintf with high precision.
            $eth_str = rtrim(rtrim(sprintf('%.18F', $eth_ratio), '0'), '.');
            if ($eth_str === '' || $eth_str === '-') $eth_str = '0';
            $weiStr = bcmul($eth_str, '1000000000000000000', 0);
            return $weiStr === '' ? '0' : $weiStr;
        }
        $wei = (int) round($eth_ratio * 1e18);
        return (string) max(0, $wei);
    }

    public function checkAddressBalance(string $address, string $network): array {
        return (new EthRpcClient())->checkAddressBalance($address, $network);
    }

    public function buildAndBroadcastRefund(int $walletId, int $childIndex, string $toAddr, string $amountMinor): array {
        throw new \RuntimeException('ETH refund signing is Phase 5');
    }

    public function explorerTxUrl(string $hash, string $network): string {
        switch ($network) {
            case 'sepolia': return 'https://sepolia.etherscan.io/tx/' . $hash;
            case 'goerli':  return 'https://goerli.etherscan.io/tx/' . $hash;
            default:        return 'https://etherscan.io/tx/' . $hash;
        }
    }

    private function deriveAddressFromAccount(array $account, int $index): string {
        $child = Bip32Secp::ckdPriv($account, $index);
        $pub   = Bip32Secp::pubFromPriv($child['k']);
        return KeccakAddress::fromCompressedPubkey($pub);
    }

    private function serialize(array $master): string {
        return 'bip32-internal:' . base64_encode($master['k'] . $master['c']);
    }

    private function parse(string $serialized): array {
        if (strpos($serialized, 'bip32-internal:') !== 0) {
            throw new \RuntimeException('unknown xprv format');
        }
        $bin = base64_decode(substr($serialized, strlen('bip32-internal:')), true);
        if ($bin === false || strlen($bin) !== 64) {
            throw new \RuntimeException('xprv payload malformed');
        }
        return [
            'k' => substr($bin, 0, 32),
            'c' => substr($bin, 32, 32),
        ];
    }
}
