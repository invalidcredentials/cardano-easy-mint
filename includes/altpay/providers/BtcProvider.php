<?php
namespace CardanoMintPay\AltPay\Providers;

use CardanoMintPay\AltPay\ChainPaymentProvider;
use CardanoMintPay\AltPay\Bip39;
use CardanoMintPay\AltPay\Bip32Secp;
use CardanoMintPay\AltPay\Encoding\Bech32;
use CardanoMintPay\AltPay\Rpc\MempoolClient;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\AltPay\Lib\Secp256k1;

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
        $wallet = ChainWalletModel::get($walletId);
        if (!$wallet) throw new \RuntimeException('parent wallet not found');
        $xprv = ChainWalletModel::get_xprv($walletId);
        if ($xprv === '') throw new \RuntimeException('xprv decrypt failed');

        try {
            $network = $wallet['network'] ?? 'mainnet';
            $master  = $this->parseXprv($xprv);
            $account = \CardanoMintPay\AltPay\Bip32Secp::derivePath($master, $this->accountPath($network));
            $child   = \CardanoMintPay\AltPay\Bip32Secp::ckdPriv($account, $childIndex);
            $priv32  = $child['k'];
            $pub     = \CardanoMintPay\AltPay\Bip32Secp::pubFromPriv($priv32);
            $hash160 = hash('ripemd160', hash('sha256', $pub, true), true);

            $hrp = ($network === 'mainnet') ? 'bc' : 'tb';
            $fromAddr = \CardanoMintPay\AltPay\Encoding\Bech32::encodeSegwitV0($hrp, $hash160);

            $rpc = new MempoolClient();
            $utxos = $rpc->getUtxos($fromAddr, $network);
            if (empty($utxos)) throw new \RuntimeException('no UTXOs available at the source address');

            // Pick UTXOs greedily until we cover amount + fee allowance.
            $amountSats = (int) $amountMinor;
            $feeRate = $rpc->getRecommendedFeeRate($network);
            $selected = [];
            $total = 0;
            // Initial fee estimate: 10 base + 68 per input + 31 per output. Two outputs (recipient + change).
            usort($utxos, function ($a, $b) { return (int) $b['value'] <=> (int) $a['value']; });
            foreach ($utxos as $u) {
                $selected[] = $u;
                $total += (int) $u['value'];
                $vbytes = 10 + 68 * count($selected) + 31 * 2;
                $fee = $vbytes * $feeRate;
                if ($total >= $amountSats + $fee) break;
            }
            $vbytes = 10 + 68 * count($selected) + 31 * 2;
            $fee = $vbytes * $feeRate;
            if ($total < $amountSats + $fee) {
                throw new \RuntimeException('insufficient balance: have ' . $total . ' sats, need ' . ($amountSats + $fee));
            }
            $change = $total - $amountSats - $fee;

            // Decode destination Bech32.
            $toDecoded = \CardanoMintPay\AltPay\Encoding\Bech32::decode($toAddr);
            if (!$toDecoded || empty($toDecoded['data']) || (int) $toDecoded['data'][0] !== 0) {
                throw new \InvalidArgumentException('destination must be a SegWit v0 P2WPKH bech32 address');
            }
            $witnessVersion = (int) $toDecoded['data'][0];
            $programWords = array_slice($toDecoded['data'], 1);
            $programBytes = \CardanoMintPay\AltPay\Encoding\Bech32::convertBits($programWords, 5, 8, false);
            if (count($programBytes) !== 20) throw new \InvalidArgumentException('destination program must be 20 bytes');
            $toScriptPubKey = "\x00" . chr(20) . pack('C*', ...$programBytes);

            // Source's own scriptPubKey (for change output).
            $fromScriptPubKey = "\x00" . chr(20) . $hash160;

            // Build tx skeleton
            $version = pack('V', 2);
            $locktime = pack('V', 0);
            $sequence = pack('V', 0xfffffffd); // RBF-enabled

            $outputs = [
                ['value' => $amountSats, 'script' => $toScriptPubKey],
            ];
            if ($change > 546) { // dust threshold
                $outputs[] = ['value' => $change, 'script' => $fromScriptPubKey];
            }

            // BIP143 prevouts/sequence/outputs hashes (precomputed once per tx).
            $prevoutsBin = '';
            $sequencesBin = '';
            foreach ($selected as $u) {
                $prevoutsBin  .= self::reverseBytes(hex2bin($u['txid'])) . pack('V', (int) $u['vout']);
                $sequencesBin .= $sequence;
            }
            $hashPrevouts = self::hash256($prevoutsBin);
            $hashSequence = self::hash256($sequencesBin);

            $outputsBin = '';
            foreach ($outputs as $o) {
                $outputsBin .= self::packU64LE($o['value']) . self::varint(strlen($o['script'])) . $o['script'];
            }
            $hashOutputs = self::hash256($outputsBin);

            // Per-input sighash (BIP143) + witnesses
            $witnesses = [];
            foreach ($selected as $i => $u) {
                $scriptCode = "\x19\x76\xa9\x14" . $hash160 . "\x88\xac"; // P2WPKH "script code" with len varint 0x19
                $preimage =
                    $version
                    . $hashPrevouts
                    . $hashSequence
                    . self::reverseBytes(hex2bin($u['txid'])) . pack('V', (int) $u['vout'])
                    . $scriptCode
                    . self::packU64LE((int) $u['value'])
                    . $sequence
                    . $hashOutputs
                    . $locktime
                    . pack('V', 1); // SIGHASH_ALL
                $sighash = self::hash256($preimage);
                $sig = Secp256k1::sign($priv32, $sighash);
                $der = Secp256k1::derEncodeSig($sig['r'], $sig['s']) . "\x01"; // append SIGHASH_ALL byte
                $witnesses[$i] = [
                    $der,
                    $pub, // 33-byte compressed pubkey
                ];
            }

            // Serialize the full tx (with marker + flag for SegWit).
            $raw = $version . "\x00\x01"; // marker + flag
            $raw .= self::varint(count($selected));
            foreach ($selected as $u) {
                $raw .= self::reverseBytes(hex2bin($u['txid']))
                      . pack('V', (int) $u['vout'])
                      . self::varint(0) // empty scriptSig for P2WPKH
                      . $sequence;
            }
            $raw .= self::varint(count($outputs));
            foreach ($outputs as $o) {
                $raw .= self::packU64LE($o['value']) . self::varint(strlen($o['script'])) . $o['script'];
            }
            // Witnesses
            foreach ($selected as $i => $_) {
                $w = $witnesses[$i];
                $raw .= self::varint(count($w));
                foreach ($w as $item) {
                    $raw .= self::varint(strlen($item)) . $item;
                }
            }
            $raw .= $locktime;

            $rawHex = bin2hex($raw);
            $hash = $rpc->broadcastRaw($rawHex, $network);
            if (is_array($hash) && isset($hash['__error'])) {
                throw new \RuntimeException('Bitcoin RPC: ' . $hash['__error']);
            }
            if (!is_string($hash) || $hash === '') {
                throw new \RuntimeException('mempool.space broadcast returned no txid');
            }
            return ['tx_hash' => $hash, 'raw_tx' => $rawHex];
        } finally {
            if (isset($priv32)) { $priv32 = null; unset($priv32); }
            $xprv = null; unset($xprv);
        }
    }

    /* ── Bitcoin serialization helpers ─────────────────────────── */

    private static function varint(int $n): string {
        if ($n < 0xfd)        return chr($n);
        if ($n <= 0xffff)     return "\xfd" . pack('v', $n);
        if ($n <= 0xffffffff) return "\xfe" . pack('V', $n);
        // 64-bit case: not expected for our small tx counts.
        $low = $n & 0xffffffff;
        $high = ($n >> 32) & 0xffffffff;
        return "\xff" . pack('V', $low) . pack('V', $high);
    }

    private static function packU64LE(int $n): string {
        $low  = $n & 0xffffffff;
        $high = (int) (($n - $low) / 4294967296);
        return pack('V', $low) . pack('V', $high & 0xffffffff);
    }

    private static function reverseBytes(string $bin): string { return strrev($bin); }

    private static function hash256(string $bin): string {
        return hash('sha256', hash('sha256', $bin, true), true);
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
