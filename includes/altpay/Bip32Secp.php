<?php
namespace CardanoMintPay\AltPay;

use CardanoMintPay\AltPay\Lib\Secp256k1;
use CardanoMintPay\AltPay\Lib\Bn;

if (!defined('ABSPATH')) exit;

/**
 * BIP32 over secp256k1. Implements the subset BTC + ETH need:
 *   - master key from seed
 *   - CKDpriv (hardened + non-hardened)
 *   - public-key derivation
 *
 * Indices are accepted as int|string|GMP because PHP on 32-bit Windows
 * builds (Local's bundled PHP) overflows int at 2^31. We normalize via
 * Bn internally so the same code works on GMP and bcmath backends.
 */
class Bip32Secp {

    private static $HARDENED = null;

    private static function consts(): void {
        if (self::$HARDENED === null) self::$HARDENED = Bn::fromHex('80000000');
    }

    public static function masterFromSeed(string $seed64): array {
        $i = hash_hmac('sha512', $seed64, 'Bitcoin seed', true);
        $k = substr($i, 0, 32);
        $c = substr($i, 32, 32);
        $kInt = Secp256k1::binToBn($k);
        if (Bn::isZero($kInt) || Bn::cmp($kInt, Secp256k1::getN()) >= 0) {
            throw new \RuntimeException('master key out of range; reroll seed');
        }
        return ['k' => $k, 'c' => $c];
    }

    /**
     * Derive a child private key. $index >= 0x80000000 is hardened.
     * Accepts int, numeric string, GMP, or bcmath-string.
     */
    public static function ckdPriv(array $parent, $index): array {
        self::consts();
        $idxBn = self::toBnIndex($index);

        $kPar = Secp256k1::binToBn($parent['k']);
        $cPar = $parent['c'];

        if (Bn::cmp($idxBn, self::$HARDENED) >= 0) {
            $data = "\x00" . $parent['k'] . self::packUint32BE($idxBn);
        } else {
            $pub = self::pubFromPriv($parent['k']);
            $data = $pub . self::packUint32BE($idxBn);
        }

        $i = hash_hmac('sha512', $data, $cPar, true);
        $iL = substr($i, 0, 32);
        $iR = substr($i, 32, 32);
        $iLInt = Secp256k1::binToBn($iL);
        if (Bn::cmp($iLInt, Secp256k1::getN()) >= 0) {
            throw new \RuntimeException('iL >= n; caller should retry with index+1');
        }
        $childK = Bn::mod(Bn::add($iLInt, $kPar), Secp256k1::getN());
        if (Bn::isZero($childK)) {
            throw new \RuntimeException('child key zero; caller should retry with index+1');
        }
        return ['k' => Secp256k1::bnToBin32($childK), 'c' => $iR];
    }

    public static function derivePath(array $master, string $path): array {
        self::consts();
        $node = $master;
        $parts = explode('/', $path);
        if (count($parts) === 0 || $parts[0] !== 'm') {
            throw new \InvalidArgumentException('path must start with m/');
        }
        for ($i = 1; $i < count($parts); $i++) {
            $seg = $parts[$i];
            if ($seg === '') continue;
            $hardened = false;
            if (substr($seg, -1) === "'" || substr($seg, -1) === 'h' || substr($seg, -1) === 'H') {
                $hardened = true;
                $seg = substr($seg, 0, -1);
            }
            $idx = Bn::fromDec((string) (int) $seg);
            if ($hardened) $idx = Bn::add($idx, self::$HARDENED);
            $node = self::ckdPriv($node, $idx);
        }
        return $node;
    }

    /** Compressed (33-byte) public key from a 32-byte private key. */
    public static function pubFromPriv(string $priv32): string {
        $kInt = Secp256k1::binToBn($priv32);
        $point = Secp256k1::mulG($kInt);
        if ($point === null) throw new \RuntimeException('zero priv key');
        return Secp256k1::compress($point);
    }

    private static function toBnIndex($index) {
        self::consts();
        // GMP value passes through (Bn comparisons accept it on the GMP backend).
        if (is_object($index)) return $index;
        if (is_string($index)) {
            // Hex-or-dec ambiguity is a non-issue here: BIP32 indices are decimal.
            // Accept both decimal strings and bcmath strings.
            return Bn::fromDec($index);
        }
        if (is_int($index)) {
            // 32-bit PHP wraps hardened indices to negative. Reinterpret as
            // unsigned 32-bit via a positive offset.
            if ($index < 0) {
                return Bn::add(self::$HARDENED, Bn::fromDec((string) ($index + 2147483648)));
            }
            return Bn::fromDec((string) $index);
        }
        if (is_float($index)) return Bn::fromDec(sprintf('%.0F', $index));
        throw new \InvalidArgumentException('index must be int, string, GMP, or float');
    }

    /** Big-endian 4-byte serialization of an unsigned 32-bit value. */
    private static function packUint32BE($idxBn): string {
        return Bn::toBin($idxBn, 4);
    }
}
