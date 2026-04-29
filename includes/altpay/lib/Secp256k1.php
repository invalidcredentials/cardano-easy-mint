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

    /**
     * ECDSA sign a 32-byte message hash with the given 32-byte private key.
     * Uses RFC 6979 deterministic k (no entropy from system, reproducible),
     * low-s normalization (BIP-62 / EIP-2), and emits the v/yParity recovery
     * bit.
     *
     * @return array { @type string $r 32 raw bytes, @type string $s 32 raw bytes, @type int $v 0|1 }
     */
    public static function sign(string $priv32, string $hash32): array {
        self::init();
        if (strlen($priv32) !== 32) throw new \InvalidArgumentException('priv32 must be 32 bytes');
        if (strlen($hash32) !== 32) throw new \InvalidArgumentException('hash32 must be 32 bytes');

        $n  = self::$N;
        $z  = Bn::fromBin($hash32);
        $d  = Bn::fromBin($priv32);
        if (Bn::isZero($d) || Bn::cmp($d, $n) >= 0) {
            throw new \InvalidArgumentException('private key out of range');
        }

        // RFC 6979 deterministic k
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $k = self::deterministicK($priv32, $hash32, $attempt);
            $kBn = Bn::fromBin($k);
            if (Bn::isZero($kBn) || Bn::cmp($kBn, $n) >= 0) continue;

            $R = self::pointMul(self::$G, $kBn);
            if ($R === null) continue;
            $r = Bn::mod($R['x'], $n);
            if (Bn::isZero($r)) continue;

            $kInv = Bn::modInv($kBn, $n);
            $rd   = Bn::mod(Bn::mul($r, $d), $n);
            $sumZRD = Bn::mod(Bn::add($z, $rd), $n);
            $s = Bn::mod(Bn::mul($kInv, $sumZRD), $n);
            if (Bn::isZero($s)) continue;

            // Low-s normalization (BIP-62 / EIP-2): if s > n/2, s = n - s.
            // When we flip s, the recovery bit also flips.
            $halfN = Bn::divQ($n, Bn::fromDec('2'));
            $yParity = (int) Bn::isOdd($R['y']);
            // Whether r needed reduction mod n. For canonical secp256k1,
            // R.x < n almost always (~1 in 2^128 chance otherwise); we
            // ignore the high bit for now (would set v |= 2 in that case).
            if (Bn::cmp($s, $halfN) > 0) {
                $s = Bn::sub($n, $s);
                $yParity ^= 1;
            }

            return [
                'r' => Bn::toBin($r, 32),
                's' => Bn::toBin($s, 32),
                'v' => $yParity,
            ];
        }
        throw new \RuntimeException('ECDSA signing failed after 16 attempts');
    }

    /**
     * RFC 6979 deterministic-k generation. Returns 32 raw bytes.
     * On retry $attempt > 0 we extend the iteration loop to satisfy the
     * "k must be in [1, n-1]" constraint without reaching for entropy.
     */
    private static function deterministicK(string $priv32, string $hash32, int $attempt): string {
        // Step a: skipped (we feed in the hash directly)
        // Step b: V = 0x01 * 32
        $v = str_repeat("\x01", 32);
        // Step c: K = 0x00 * 32
        $k = str_repeat("\x00", 32);
        // Step d: K = HMAC(K, V || 0x00 || priv || hash)
        $k = hash_hmac('sha256', $v . "\x00" . $priv32 . $hash32, $k, true);
        // Step e: V = HMAC(K, V)
        $v = hash_hmac('sha256', $v, $k, true);
        // Step f: K = HMAC(K, V || 0x01 || priv || hash)
        $k = hash_hmac('sha256', $v . "\x01" . $priv32 . $hash32, $k, true);
        // Step g: V = HMAC(K, V)
        $v = hash_hmac('sha256', $v, $k, true);
        // Step h: loop until valid k. Each retry past attempt 0 reseeds.
        for ($i = 0; $i <= $attempt; $i++) {
            $v = hash_hmac('sha256', $v, $k, true);
        }
        return $v;
    }

    /**
     * DER-encode an (r, s) pair. Used by Bitcoin signature script formatting.
     */
    public static function derEncodeSig(string $rBin32, string $sBin32): string {
        $rEnc = self::derEncodeInt($rBin32);
        $sEnc = self::derEncodeInt($sBin32);
        $body = $rEnc . $sEnc;
        return "\x30" . chr(strlen($body)) . $body;
    }

    private static function derEncodeInt(string $bin32): string {
        // Strip leading zero bytes.
        $bin = ltrim($bin32, "\x00");
        if ($bin === '') $bin = "\x00";
        // If high bit set, prepend 0x00 to keep it a positive integer.
        if (ord($bin[0]) & 0x80) $bin = "\x00" . $bin;
        return "\x02" . chr(strlen($bin)) . $bin;
    }

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
