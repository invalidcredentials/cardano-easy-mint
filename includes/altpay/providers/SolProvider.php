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
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) throw new \RuntimeException('parent wallet not found');
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');

        try {
            $master = $this->parse($xprv);
            $node   = Slip10Ed25519::derivePath($master, "m/44'/501'/" . $childIndex . "'/0'");
            $seed32 = $node['k'];
            if (!function_exists('sodium_crypto_sign_seed_keypair')) {
                throw new \RuntimeException('sodium extension required');
            }
            $kp     = sodium_crypto_sign_seed_keypair($seed32);
            $pub    = sodium_crypto_sign_publickey($kp);
            $sk     = sodium_crypto_sign_secretkey($kp); // 64-byte ed25519 secret (seed || pub)

            $rpc = new SolRpcClient();
            $network = ($wallet['network'] ?? 'mainnet');
            $blockhashB58 = $rpc->getLatestBlockhash($network);
            if (!$blockhashB58) throw new \RuntimeException('could not fetch recent blockhash');
            $blockhash = Base58::decode($blockhashB58);
            if (strlen($blockhash) !== 32) throw new \RuntimeException('blockhash length unexpected');

            $toBytes = Base58::decode($toAddr);
            if (strlen($toBytes) !== 32) throw new \RuntimeException('destination address length unexpected');

            // Solana legacy message:
            //   header (3 bytes) || compactArray(accountKeys) || recentBlockhash || compactArray(instructions)
            //   header = [numRequiredSignatures=1, numReadonlySigned=0, numReadonlyUnsigned=1]
            //   accountKeys = [payerPub (signer, writable), recipient (writable, NOT signer), systemProgram (read-only, NOT signer)]
            $systemProgram = str_repeat("\x00", 32);
            $accountKeys = [$pub, $toBytes, $systemProgram];

            // SystemProgram::Transfer instruction data: u32(2) || u64-LE(lamports)
            $instructionData = pack('V', 2) . self::packU64LE($amountMinor);

            // Instruction layout: programIdIndex(u8) || compactArray(accountIndices u8) || compactArray(data)
            $programIdIndex = chr(2);
            $accountIdxBytes = chr(0) . chr(1); // payer, recipient
            $instr = $programIdIndex
                . self::compactU16(strlen($accountIdxBytes)) . $accountIdxBytes
                . self::compactU16(strlen($instructionData)) . $instructionData;

            $message = "\x01\x00\x01" // header
                . self::compactU16(count($accountKeys)) . implode('', $accountKeys)
                . $blockhash
                . self::compactU16(1) . $instr;

            $signature = sodium_crypto_sign_detached($message, $sk);
            if (strlen($signature) !== 64) throw new \RuntimeException('signature length unexpected');

            // Wire format: compactArray(signatures) || message
            $tx = self::compactU16(1) . $signature . $message;

            // Best-effort key zeroing
            try { sodium_memzero($sk); } catch (\Throwable $e) {}
            try { sodium_memzero($seed32); } catch (\Throwable $e) {}

            $base64 = base64_encode($tx);
            $hash = $rpc->sendRawTransaction($network, $base64);
            if (!is_string($hash) || $hash === '') throw new \RuntimeException('broadcast did not return a signature');
            return ['tx_hash' => $hash, 'raw_tx' => bin2hex($tx)];
        } finally {
            $xprv = null;
            unset($xprv);
        }
    }

    /** Solana compact-u16 (also called shortvec) encoding. */
    private static function compactU16(int $n): string {
        $out = '';
        do {
            $byte = $n & 0x7f;
            $n >>= 7;
            if ($n > 0) $byte |= 0x80;
            $out .= chr($byte);
        } while ($n > 0);
        return $out;
    }

    /** Pack a decimal-string lamport amount as 8-byte little-endian. */
    private static function packU64LE(string $decStr): string {
        $bin = \CardanoMintPay\AltPay\Lib\Bn::toBin(\CardanoMintPay\AltPay\Lib\Bn::fromDec($decStr), 8);
        // Reverse to little-endian.
        return strrev($bin);
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
