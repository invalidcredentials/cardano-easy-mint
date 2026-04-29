<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Secp256k1 curve math, bcmath/GMP backend via Bn.
 *
 * Phase 2 only needs G*k, P+Q, compress, decompress for BIP32 derivation
 * and address generation. Phase 5 (refunds) will add ECDSA signing.
 *
 * Affine coordinates throughout; double-and-add for scalar multiplication.
 * Pure-PHP performance is fine for our frequency (per-derivation, per-
 * refund — never on the watcher path).
 */
class Secp256k1 {

    public const P_HEX  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    public const N_HEX  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    public const GX_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    public const GY_HEX = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    private static $P = null;
    private static $N = null;
    private static $G = null;

    public static function init(): void {
        if (self::$P === null) {
            self::$P = Bn::fromHex(self::P_HEX);
            self::$N = Bn::fromHex(self::N_HEX);
            self::$G = ['x' => Bn::fromHex(self::GX_HEX), 'y' => Bn::fromHex(self::GY_HEX)];
        }
    }

    public static function getN()       { self::init(); return self::$N; }
    public static function getP()       { self::init(); return self::$P; }
    public static function getG(): array{ self::init(); return self::$G; }

    public static function mulG($k): ?array {
        self::init();
        return self::pointMul(self::$G, $k);
    }

    public static function pointAdd(?array $p, ?array $q): ?array {
        self::init();
        if ($p === null) return $q;
        if ($q === null) return $p;
        $px = $p['x']; $py = $p['y']; $qx = $q['x']; $qy = $q['y'];
        if (Bn::cmp($px, $qx) === 0) {
            if (Bn::isZero(Bn::mod(Bn::add($py, $qy), self::$P))) return null; // P + (-P)
            return self::pointDouble($p);
        }
        $num = Bn::mod(Bn::sub($qy, $py), self::$P);
        $den = Bn::mod(Bn::sub($qx, $px), self::$P);
        $lam = Bn::mod(Bn::mul($num, Bn::modInv($den, self::$P)), self::$P);
        $rx = Bn::mod(Bn::sub(Bn::sub(Bn::mul($lam, $lam), $px), $qx), self::$P);
        $ry = Bn::mod(Bn::sub(Bn::mul($lam, Bn::sub($px, $rx)), $py), self::$P);
        return ['x' => $rx, 'y' => $ry];
    }

    public static function pointDouble(array $p): ?array {
        self::init();
        if (Bn::isZero($p['y'])) return null;
        $three = Bn::fromDec('3');
        $two   = Bn::fromDec('2');
        $num = Bn::mod(Bn::mul($three, Bn::mul($p['x'], $p['x'])), self::$P);
        $den = Bn::mod(Bn::mul($two, $p['y']), self::$P);
        $lam = Bn::mod(Bn::mul($num, Bn::modInv($den, self::$P)), self::$P);
        $rx = Bn::mod(Bn::sub(Bn::mul($lam, $lam), Bn::mul($two, $p['x'])), self::$P);
        $ry = Bn::mod(Bn::sub(Bn::mul($lam, Bn::sub($p['x'], $rx)), $p['y']), self::$P);
        return ['x' => $rx, 'y' => $ry];
    }

    public static function pointMul(array $p, $k): ?array {
        self::init();
        $k = Bn::mod($k, self::$N);
        if (Bn::isZero($k)) return null;
        $result = null;
        $addend = $p;
        // Iterate over k's bits (MSB-to-LSB scan over the binary string of dec).
        $bits = self::toBinaryString($k);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointDouble($addend);
        }
        return $result;
    }

    /** SEC1 compressed: 0x02|0x03 prefix + 32-byte X. */
    public static function compress(array $p): string {
        $x = self::bnToBin32($p['x']);
        $prefix = Bn::isOdd($p['y']) ? "\x03" : "\x02";
        return $prefix . $x;
    }

    public static function decompress(string $bin): array {
        self::init();
        if (strlen($bin) !== 33) throw new \InvalidArgumentException('expected 33 bytes');
        $prefix = ord($bin[0]);
        if ($prefix !== 2 && $prefix !== 3) throw new \InvalidArgumentException('not a compressed point');
        $x = Bn::fromBin(substr($bin, 1));
        $alpha = Bn::mod(Bn::add(Bn::powMod($x, Bn::fromDec('3'), self::$P), Bn::fromDec('7')), self::$P);
        // y = alpha^((p+1)/4) mod p
        $exp = Bn::divQ(Bn::add(self::$P, Bn::fromDec('1')), Bn::fromDec('4'));
        $y = Bn::powMod($alpha, $exp, self::$P);
        $isOdd = Bn::isOdd($y);
        if (($prefix === 3 && !$isOdd) || ($prefix === 2 && $isOdd)) {
            $y = Bn::sub(self::$P, $y);
        }
        return ['x' => $x, 'y' => $y];
    }

    public static function bnToBin32($n): string { return Bn::toBin($n, 32); }
    public static function binToBn(string $bin)  { return Bn::fromBin($bin); }

    /** Backwards-compat shims for callers still using gmp-style names. */
    public static function gmpToBin32($n): string { return self::bnToBin32($n); }
    public static function binToGmp(string $bin)  { return self::binToBn($bin); }

    /**
     * MSB-to-LSB binary string of an arbitrary-precision integer. Uses
     * repeated divmod-by-2 so it works for both GMP and bcmath backends.
     */
    private static function toBinaryString($n): string {
        $two = Bn::fromDec('2');
        $bits = '';
        $cur = $n;
        while (!Bn::isZero($cur)) {
            $bits = (Bn::isOdd($cur) ? '1' : '0') . $bits;
            $cur = Bn::divQ($cur, $two);
        }
        return $bits === '' ? '0' : $bits;
    }
}
