<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Secp256k1
 *
 * Minimum GMP-backed curve math for BIP32 derivation and ECDSA signing.
 * Affine coordinates throughout (Phase 5 may move to Jacobian for speed).
 *
 * This is NOT a general-purpose elliptic-curve library: only the operations
 * required by BIP32 (G*k, P+Q, compressed serialization) and ECDSA signing
 * (RFC 6979 + r,s emission) are implemented.
 */
class Secp256k1 {

    // secp256k1 domain parameters (RFC 5639 / SEC2).
    public const P_HEX  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    public const N_HEX  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    public const GX_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    public const GY_HEX = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    private static $P = null;
    private static $N = null;
    private static $G = null;

    public static function init(): void {
        if (!extension_loaded('gmp')) {
            throw new \RuntimeException('GMP extension required for secp256k1 math');
        }
        if (self::$P === null) {
            self::$P = gmp_init('0x' . self::P_HEX);
            self::$N = gmp_init('0x' . self::N_HEX);
            self::$G = ['x' => gmp_init('0x' . self::GX_HEX), 'y' => gmp_init('0x' . self::GY_HEX)];
        }
    }

    public static function getN(): \GMP { self::init(); return self::$N; }
    public static function getP(): \GMP { self::init(); return self::$P; }
    public static function getG(): array { self::init(); return self::$G; }

    /** Scalar multiplication of the generator: returns G * k as ['x'=>GMP,'y'=>GMP] or null at infinity. */
    public static function mulG(\GMP $k): ?array {
        self::init();
        return self::pointMul(self::$G, $k);
    }

    /** Point addition. Returns null when at infinity. */
    public static function pointAdd(?array $p, ?array $q): ?array {
        self::init();
        if ($p === null) return $q;
        if ($q === null) return $p;
        $px = $p['x']; $py = $p['y']; $qx = $q['x']; $qy = $q['y'];
        if (gmp_cmp($px, $qx) === 0) {
            if (gmp_cmp(gmp_mod(gmp_add($py, $qy), self::$P), 0) === 0) return null; // P + (-P)
            return self::pointDouble($p);
        }
        $num = gmp_mod(gmp_sub($qy, $py), self::$P);
        $den = gmp_mod(gmp_sub($qx, $px), self::$P);
        $lam = gmp_mod(gmp_mul($num, gmp_invert($den, self::$P)), self::$P);
        $rx = gmp_mod(gmp_sub(gmp_sub(gmp_mul($lam, $lam), $px), $qx), self::$P);
        $ry = gmp_mod(gmp_sub(gmp_mul($lam, gmp_sub($px, $rx)), $py), self::$P);
        return ['x' => $rx, 'y' => $ry];
    }

    public static function pointDouble(array $p): ?array {
        self::init();
        if (gmp_cmp($p['y'], 0) === 0) return null;
        $three = gmp_init(3);
        $two   = gmp_init(2);
        $num = gmp_mod(gmp_mul($three, gmp_mul($p['x'], $p['x'])), self::$P);
        $den = gmp_mod(gmp_mul($two, $p['y']), self::$P);
        $lam = gmp_mod(gmp_mul($num, gmp_invert($den, self::$P)), self::$P);
        $rx = gmp_mod(gmp_sub(gmp_mul($lam, $lam), gmp_mul($two, $p['x'])), self::$P);
        $ry = gmp_mod(gmp_sub(gmp_mul($lam, gmp_sub($p['x'], $rx)), $p['y']), self::$P);
        return ['x' => $rx, 'y' => $ry];
    }

    /** Double-and-add scalar multiplication. */
    public static function pointMul(array $p, \GMP $k): ?array {
        self::init();
        $k = gmp_mod($k, self::$N);
        if (gmp_cmp($k, 0) === 0) return null;
        $result = null;
        $addend = $p;
        $bits = gmp_strval($k, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointDouble($addend);
        }
        return $result;
    }

    /**
     * Serialize a point in compressed SEC1 form: 0x02|0x03 prefix + 32-byte X.
     */
    public static function compress(array $p): string {
        $x = self::gmpToBin32($p['x']);
        $prefix = (gmp_mod($p['y'], gmp_init(2)) == 0) ? "\x02" : "\x03";
        return $prefix . $x;
    }

    /** Decompress a 33-byte SEC1 point back to ['x'=>GMP,'y'=>GMP]. */
    public static function decompress(string $bin): array {
        self::init();
        if (strlen($bin) !== 33) throw new \InvalidArgumentException('expected 33 bytes');
        $prefix = ord($bin[0]);
        if ($prefix !== 2 && $prefix !== 3) throw new \InvalidArgumentException('not a compressed point');
        $x = gmp_init('0x' . bin2hex(substr($bin, 1)));
        $alpha = gmp_mod(gmp_add(gmp_powm($x, 3, self::$P), 7), self::$P);
        // y = alpha^((p+1)/4) mod p
        $exp = gmp_div_q(gmp_add(self::$P, 1), 4);
        $y = gmp_powm($alpha, $exp, self::$P);
        $isOdd = (int) gmp_mod($y, 2);
        if (($prefix === 3 && $isOdd === 0) || ($prefix === 2 && $isOdd === 1)) {
            $y = gmp_sub(self::$P, $y);
        }
        return ['x' => $x, 'y' => $y];
    }

    /** Big-endian 32-byte representation of a non-negative GMP integer. */
    public static function gmpToBin32(\GMP $n): string {
        $hex = gmp_strval($n, 16);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = hex2bin($hex);
        if (strlen($bin) > 32) throw new \RangeException('value > 256 bits');
        return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
    }

    public static function binToGmp(string $bin): \GMP {
        return gmp_init('0x' . bin2hex($bin));
    }
}
