<?php
namespace CardanoMintPay\AltPay\Providers;

use CardanoMintPay\AltPay\ChainPaymentProvider;
use CardanoMintPay\AltPay\Bip39;
use CardanoMintPay\AltPay\Slip10Ed25519;
use CardanoMintPay\AltPay\Encoding\Base58;
use CardanoMintPay\AltPay\Rpc\SolRpcClient;
use CardanoMintPay\Models\ChainWalletModel;

if (!defined('ABSPATH')) exit;

/**
 * SolProvider
 *
 * BIP39 -> SLIP-0010 ed25519 -> Base58 address. Solana derivation path
 * convention: m/44'/501'/N'/0' (all hardened). The public key bytes ARE
 * the address (Base58 encoded), no hash step.
 */
class SolProvider implements ChainPaymentProvider {

    public function chainCode(): string { return 'sol'; }

    public function generateParentWallet(string $network): array {
        $mnemonic = Bip39::generate(24);
        $seed = Bip39::mnemonicToSeed($mnemonic);
        $master = Slip10Ed25519::masterFromSeed($seed);
        $address0 = $this->deriveAddressFromMaster($master, 0);
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
            $master = Slip10Ed25519::masterFromSeed($seed);
            return [
                'xprv'     => $this->serialize($master),
                'xpub'     => '',
                'address0' => $this->deriveAddressFromMaster($master, 0),
                'mnemonic' => $trimmed,
            ];
        }
        throw new \RuntimeException('xprv-form import not yet supported; use a mnemonic for now');
    }

    public function deriveChildAddress(int $walletId, int $index): string {
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');
        $master = $this->parse($xprv);
        return $this->deriveAddressFromMaster($master, $index);
    }

    public function expectedAmountMinor(float $usd, float $rate): string {
        if ($rate <= 0) return '0';
        $sol = $usd / $rate;
        $lamports = (int) round($sol * 1000000000);
        return (string) max(0, $lamports);
    }

    public function checkAddressBalance(string $address, string $network): array {
        return (new SolRpcClient())->checkAddressBalance($address, $network);
    }

    public function buildAndBroadcastRefund(int $walletId, int $childIndex, string $toAddr, string $amountMinor): array {
        throw new \RuntimeException('SOL refund signing is Phase 5');
    }

    public function explorerTxUrl(string $hash, string $network): string {
        $cluster = ($network === 'devnet' || $network === 'testnet') ? '?cluster=' . $network : '';
        return 'https://explorer.solana.com/tx/' . $hash . $cluster;
    }

    private function deriveAddressFromMaster(array $master, int $index): string {
        $node = Slip10Ed25519::derivePath($master, "m/44'/501'/" . $index . "'/0'");
        $pub  = Slip10Ed25519::publicKeyFromSeed($node['k']);
        return Base58::encode($pub);
    }

    private function serialize(array $master): string {
        return 'slip10-internal:' . base64_encode($master['k'] . $master['c']);
    }

    private function parse(string $serialized): array {
        if (strpos($serialized, 'slip10-internal:') !== 0) {
            throw new \RuntimeException('unknown xprv format');
        }
        $bin = base64_decode(substr($serialized, strlen('slip10-internal:')), true);
        if ($bin === false || strlen($bin) !== 64) {
            throw new \RuntimeException('xprv payload malformed');
        }
        return [
            'k' => substr($bin, 0, 32),
            'c' => substr($bin, 32, 32),
        ];
    }
}
