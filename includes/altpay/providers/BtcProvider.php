<?php
namespace CardanoMintPay\AltPay\Providers;

use CardanoMintPay\AltPay\ChainPaymentProvider;
use CardanoMintPay\AltPay\Bip39;
use CardanoMintPay\AltPay\Bip32Secp;
use CardanoMintPay\AltPay\Encoding\Bech32;
use CardanoMintPay\AltPay\Rpc\MempoolClient;
use CardanoMintPay\Models\ChainWalletModel;

if (!defined('ABSPATH')) exit;

/**
 * BtcProvider
 *
 * BIP39 -> BIP32-secp256k1 -> SegWit v0 P2WPKH (bc1q... / tb1q...).
 * Address derivation path: m/84'/0'/0'/0/i (BIP-84 mainnet)
 *                          m/84'/1'/0'/0/i (BIP-84 testnet & signet).
 *
 * Phase 2 implements: generateParentWallet / importParentWallet /
 * deriveChildAddress / checkAddressBalance. Refund signing is Phase 5.
 */
class BtcProvider implements ChainPaymentProvider {

    public function chainCode(): string { return 'btc'; }

    public function generateParentWallet(string $network): array {
        $mnemonic = Bip39::generate(24);
        $seed = Bip39::mnemonicToSeed($mnemonic);
        $master = Bip32Secp::masterFromSeed($seed);
        $accountPath = $this->accountPath($network);
        $account = Bip32Secp::derivePath($master, $accountPath);
        $address0 = $this->deriveAddressFromAccount($account, 0, $network);
        return [
            'xprv'     => $this->serializeXprv($master, $network),
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
            $account = Bip32Secp::derivePath($master, $this->accountPath($network));
            return [
                'xprv'     => $this->serializeXprv($master, $network),
                'xpub'     => '',
                'address0' => $this->deriveAddressFromAccount($account, 0, $network),
                'mnemonic' => $trimmed,
            ];
        }
        throw new \RuntimeException('xprv-form import not yet supported; use a mnemonic for now');
    }

    public function deriveChildAddress(int $walletId, int $index): string {
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) throw new \RuntimeException('parent wallet not found');
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');

        // Stored xprv format: serialized with masterFromSeed shape; re-derive
        // master from the embedded seed-equivalent k+c. We stored {k,c} as
        // two concatenated 32-byte halves base64-encoded for portability.
        $master = $this->parseXprv($xprv);
        $account = Bip32Secp::derivePath($master, $this->accountPath($wallet['network']));
        return $this->deriveAddressFromAccount($account, $index, $wallet['network']);
    }

    public function expectedAmountMinor(float $usd, float $rate): string {
        if ($rate <= 0) return '0';
        $btc = $usd / $rate;
        $sats = (int) round($btc * 100000000);
        return (string) max(0, $sats);
    }

    public function checkAddressBalance(string $address, string $network): array {
        return (new MempoolClient())->checkAddressBalance($address, $network);
    }

    public function buildAndBroadcastRefund(int $walletId, int $childIndex, string $toAddr, string $amountMinor): array {
        throw new \RuntimeException('BTC refund signing is Phase 5');
    }

    public function explorerTxUrl(string $hash, string $network): string {
        switch ($network) {
            case 'signet':  return 'https://mempool.space/signet/tx/' . $hash;
            case 'testnet': return 'https://mempool.space/testnet/tx/' . $hash;
            default:        return 'https://mempool.space/tx/' . $hash;
        }
    }

    /** BIP-84 account path. Coin type 0 = mainnet, 1 = testnet/signet (per SLIP-44). */
    private function accountPath(string $network): string {
        $coinType = ($network === 'mainnet') ? 0 : 1;
        return "m/84'/" . $coinType . "'/0'/0";
    }

    private function deriveAddressFromAccount(array $account, int $index, string $network): string {
        $child = Bip32Secp::ckdPriv($account, $index);
        $pub   = Bip32Secp::pubFromPriv($child['k']);
        $hash160 = hash('ripemd160', hash('sha256', $pub, true), true);
        $hrp = ($network === 'mainnet') ? 'bc' : 'tb';
        return Bech32::encodeSegwitV0($hrp, $hash160);
    }

    /** Internal serialization: base64(k|c). Phase 5 will switch to canonical xprv string. */
    private function serializeXprv(array $master, string $network): string {
        return 'bip32-internal:' . base64_encode($master['k'] . $master['c']);
    }

    private function parseXprv(string $serialized): array {
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
