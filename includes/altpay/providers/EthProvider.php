<?php
namespace CardanoMintPay\AltPay\Providers;

use CardanoMintPay\AltPay\ChainPaymentProvider;
use CardanoMintPay\AltPay\Bip39;
use CardanoMintPay\AltPay\Bip32Secp;
use CardanoMintPay\AltPay\Encoding\KeccakAddress;
use CardanoMintPay\AltPay\Rpc\EthRpcClient;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\AltPay\Lib\Secp256k1;
use CardanoMintPay\AltPay\Lib\Keccak;
use CardanoMintPay\AltPay\Lib\Rlp;

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
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) throw new \RuntimeException('parent wallet not found');
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');

        try {
            $master  = $this->parse($xprv);
            $account = \CardanoMintPay\AltPay\Bip32Secp::derivePath($master, "m/44'/60'/0'/0");
            $child   = \CardanoMintPay\AltPay\Bip32Secp::ckdPriv($account, $childIndex);
            $priv32  = $child['k'];
            $pub     = \CardanoMintPay\AltPay\Bip32Secp::pubFromPriv($priv32);
            $fromAddr = \CardanoMintPay\AltPay\Encoding\KeccakAddress::fromCompressedPubkey($pub);

            $rpc      = new EthRpcClient();
            $network  = $wallet['network'] ?? 'mainnet';
            $chainId  = $rpc->getChainId($network);
            $nonce    = $rpc->getNonce($network, $fromAddr);
            $fees     = $rpc->getEip1559Fees($network);
            $gasLimit = '21000'; // simple ETH transfer

            // Validate the destination is hex 0x + 20 bytes.
            $toHex = strtolower(ltrim($toAddr, '0x'));
            if (!preg_match('/^[0-9a-f]{40}$/', $toHex)) {
                throw new \InvalidArgumentException('destination must be a 0x-prefixed 20-byte hex address');
            }
            $toBytes = hex2bin($toHex);

            // Type-2 (EIP-1559) tx fields:
            //   [chainId, nonce, maxPriorityFeePerGas, maxFeePerGas, gasLimit, to, value, data, accessList]
            $unsignedFields = [
                Rlp::uint((string) $chainId),
                Rlp::uint((string) $nonce),
                Rlp::uint($fees['tip']),
                Rlp::uint($fees['maxFee']),
                Rlp::uint($gasLimit),
                $toBytes,
                Rlp::uint($amountMinor),
                '',          // empty data
                [],          // empty access list
            ];
            $unsignedRlp = Rlp::encode($unsignedFields);
            $sigHash     = Keccak::hash256("\x02" . $unsignedRlp);
            $sig         = Secp256k1::sign($priv32, $sigHash);

            $signedFields = $unsignedFields;
            $signedFields[] = Rlp::uint((string) $sig['v']); // yParity (0 or 1)
            $signedFields[] = ltrim($sig['r'], "\x00");
            $signedFields[] = ltrim($sig['s'], "\x00");
            $rawTx = "\x02" . Rlp::encode($signedFields);

            $hash = $rpc->sendRawTransaction($network, '0x' . bin2hex($rawTx));
            if (is_array($hash) && isset($hash['__error'])) {
                throw new \RuntimeException('Ethereum RPC: ' . $hash['__error']);
            }
            if (!is_string($hash) || $hash === '') {
                throw new \RuntimeException('eth_sendRawTransaction returned no hash');
            }
            return ['tx_hash' => $hash, 'raw_tx' => '0x' . bin2hex($rawTx)];
        } finally {
            if (isset($priv32)) { $priv32 = null; unset($priv32); }
            $xprv = null; unset($xprv);
        }
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
