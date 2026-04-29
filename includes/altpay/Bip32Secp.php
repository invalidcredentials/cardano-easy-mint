<?php
namespace CardanoMintPay\AltPay;

use CardanoMintPay\AltPay\Lib\Secp256k1;

if (!defined('ABSPATH')) exit;

/**
 * BIP32 over secp256k1. Implements the subset BTC + ETH need:
 *   - master key from seed
 *   - CKDpriv (hardened + non-hardened)
 *   - public-key derivation
 *
 * Indices are accepted as int|string|GMP because PHP on 32-bit Windows
 * builds (Local's bundled PHP) overflows int at 2^31. We normalize to GMP
 * internally and emit the 4-byte big-endian index manually.
 */
class Bip32Secp {

    private static $HARDENED = null;
    private static $UINT32_MAX = null;

    private static function consts(): void {
        if (self::$HARDENED === null) {
            self::$HARDENED = gmp_init('0x80000000');
            self::$UINT32_MAX = gmp_init('0xFFFFFFFF');
        }
    }

    public static function masterFromSeed(string $seed64): array {
        $i = hash_hmac('sha512', $seed64, 'Bitcoin seed', true);
        $k = substr($i, 0, 32);
        $c = substr($i, 32, 32);
        $kInt = Secp256k1::binToGmp($k);
        if (gmp_cmp($kInt, 0) === 0 || gmp_cmp($kInt, Secp256k1::getN()) >= 0) {
            throw new \RuntimeException('master key out of range; reroll seed');
        }
        return ['k' => $k, 'c' => $c];
    }

    /**
     * Derive a child private key from a parent. $index >= 0x80000000 is hardened.
     * Accepts int, numeric string, or GMP for portability across 32-bit PHP.
     */
    public static function ckdPriv(array $parent, $index): array {
        self::consts();
        $idxGmp = self::toGmpIndex($index);

        $kPar = Secp256k1::binToGmp($parent['k']);
        $cPar = $parent['c'];

        if (gmp_cmp($idxGmp, self::$HARDENED) >= 0) {
            $data = "\x00" . $parent['k'] . self::packUint32BE($idxGmp);
        } else {
            $pub = self::pubFromPriv($parent['k']);
            $data = $pub . self::packUint32BE($idxGmp);
        }

        $i = hash_hmac('sha512', $data, $cPar, true);
        $iL = substr($i, 0, 32);
        $iR = substr($i, 32, 32);
        $iLInt = Secp256k1::binToGmp($iL);
        if (gmp_cmp($iLInt, Secp256k1::getN()) >= 0) {
            throw new \RuntimeException('iL >= n; caller should retry with index+1');
        }
        $childK = gmp_mod(gmp_add($iLInt, $kPar), Secp256k1::getN());
        if (gmp_cmp($childK, 0) === 0) {
            throw new \RuntimeException('child key zero; caller should retry with index+1');
        }
        return ['k' => Secp256k1::gmpToBin32($childK), 'c' => $iR];
    }


    /** Walk a derivation path like "m/44'/0'/0'/0/0" from a master node. */
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
            $idx = gmp_init((string) (int) $seg);
            if ($hardened) $idx = gmp_add($idx, self::$HARDENED);
            $node = self::ckdPriv($node, $idx);
        }
        return $node;
    }

    /** Compressed (33-byte) public key from a 32-byte private key. */
    public static function pubFromPriv(string $priv32): string {
        $kInt = Secp256k1::binToGmp($priv32);
        $point = Secp256k1::mulG($kInt);
        if ($point === null) throw new \RuntimeException('zero priv key');
        return Secp256k1::compress($point);
    }

    private static function toGmpIndex($index): \GMP {
        self::consts();
        if (is_object($index) && $index instanceof \GMP) return $index;
        if (is_string($index)) return gmp_init($index);
        if (is_int($index)) {
            // On 32-bit PHP, hardened indices arrive as wrapped negatives.
            if ($index < 0) {
                // Reinterpret as unsigned 32-bit.
                return gmp_add(self::$HARDENED, gmp_init((string) ($index + 2147483648)));
            }
            return gmp_init((string) $index);
        }
        if (is_float($index)) return gmp_init(sprintf('%.0F', $index));
        throw new \InvalidArgumentException('index must be int, string, or GMP');
    }

    private static function packUint32BE(\GMP $g): string {
        $bytes = '';
        for ($shift = 3; $shift >= 0; $shift--) {
            $byte = gmp_intval(gmp_mod(gmp_div_q($g, gmp_pow(2, $shift * 8)), 256));
            $bytes .= chr($byte & 0xff);
        }
        return $bytes;
    }
}
