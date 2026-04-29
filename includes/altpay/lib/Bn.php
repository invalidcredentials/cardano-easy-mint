<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Bn — Big-number helper for the altpay crypto stack.
 *
 * Auto-detects GMP and uses it when available (10x+ faster on hot paths).
 * Falls back to bcmath, which is ubiquitous on shared PHP hosts. Mirrors
 * the patterns in Ed25519Pure.php so the existing Cardano signer and the
 * altpay providers share idioms.
 *
 * Internal representation: when GMP is loaded, all values are GMP. When
 * bcmath is loaded, values are decimal strings. Callers should treat
 * the returned value as opaque and only feed it back through Bn::*
 * methods.
 *
 * The minimum operation set is what BIP32, ECDSA, and the eth wei math
 * actually need: add, sub, mul, mod, div_q, cmp, pow, powmod, mod_inv,
 * to_int, to_dec_str, to_hex_str, from_dec, from_hex, from_bin.
 */
class Bn {

    private static $useGmp = null;

    public static function backend(): string {
        if (self::$useGmp === null) self::$useGmp = extension_loaded('gmp');
        if (self::$useGmp) return 'gmp';
        if (extension_loaded('bcmath')) return 'bcmath';
        throw new \RuntimeException('Bn requires gmp or bcmath; neither is loaded');
    }

    /* ── Constructors ──────────────────────────────────────────────── */

    /** From a decimal string. */
    public static function fromDec(string $dec) {
        if (self::backend() === 'gmp') return \gmp_init($dec, 10);
        return self::normalize_dec($dec);
    }

    /** From a hex string (with or without 0x prefix). */
    public static function fromHex(string $hex) {
        $hex = strpos($hex, '0x') === 0 ? substr($hex, 2) : $hex;
        if (self::backend() === 'gmp') return \gmp_init('0x' . ($hex === '' ? '0' : $hex));
        // bcmath path: hex -> dec via repeated multiply-by-16.
        $dec = '0';
        for ($i = 0; $i < strlen($hex); $i++) {
            $dec = bcmul($dec, '16', 0);
            $digit = strtolower($hex[$i]);
            $dec = bcadd($dec, (string) hexdec($digit), 0);
        }
        return $dec;
    }

    /** From raw big-endian binary (treated as unsigned). */
    public static function fromBin(string $bin) {
        return self::fromHex(bin2hex($bin));
    }

    public static function zero() { return self::fromDec('0'); }

    /* ── Conversion ────────────────────────────────────────────────── */

    public static function toDec($n): string {
        if (self::backend() === 'gmp') return \gmp_strval($n, 10);
        return $n;
    }

    public static function toHex($n): string {
        if (self::backend() === 'gmp') return \gmp_strval($n, 16);
        // bcmath: dec -> hex via repeated divmod.
        $dec = $n;
        if (self::cmp($dec, self::zero()) === 0) return '0';
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $rem = (int) bcmod($dec, '16');
            $hex = dechex($rem) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return $hex;
    }

    /** Big-endian binary representation, padded to $len bytes. */
    public static function toBin($n, int $len): string {
        $hex = self::toHex($n);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = hex2bin($hex);
        if (strlen($bin) > $len) throw new \RangeException('value > ' . ($len * 8) . ' bits');
        return str_pad($bin, $len, "\x00", STR_PAD_LEFT);
    }

    public static function toInt($n): int {
        if (self::backend() === 'gmp') return \gmp_intval($n);
        return (int) $n;
    }

    /* ── Arithmetic ────────────────────────────────────────────────── */

    public static function add($a, $b) {
        if (self::backend() === 'gmp') return \gmp_add($a, $b);
        return bcadd($a, $b, 0);
    }

    public static function sub($a, $b) {
        if (self::backend() === 'gmp') return \gmp_sub($a, $b);
        return bcsub($a, $b, 0);
    }

    public static function mul($a, $b) {
        if (self::backend() === 'gmp') return \gmp_mul($a, $b);
        return bcmul($a, $b, 0);
    }

    /** Floored division: a // b. */
    public static function divQ($a, $b) {
        if (self::backend() === 'gmp') return \gmp_div_q($a, $b);
        return bcdiv($a, $b, 0);
    }

    /** Signed modulo, normalized to [0, m). */
    public static function mod($a, $m) {
        if (self::backend() === 'gmp') {
            $r = \gmp_mod($a, $m);
            // gmp_mod follows the sign of the dividend in some PHP builds;
            // normalize to non-negative.
            if (\gmp_cmp($r, 0) < 0) $r = \gmp_add($r, $m);
            return $r;
        }
        $r = bcmod($a, $m);
        if (bccomp($r, '0') < 0) $r = bcadd($r, $m, 0);
        return $r;
    }

    /** -1 / 0 / 1 like spaceship. */
    public static function cmp($a, $b): int {
        if (self::backend() === 'gmp') return \gmp_cmp($a, $b);
        return bccomp($a, $b, 0);
    }

    /** Integer power (no modulus). */
    public static function pow($a, int $exp) {
        if (self::backend() === 'gmp') return \gmp_pow($a, $exp);
        return bcpow($a, (string) $exp, 0);
    }

    /** Modular exponentiation: a^e mod m. */
    public static function powMod($a, $e, $m) {
        if (self::backend() === 'gmp') return \gmp_powm($a, $e, $m);
        // Square-and-multiply over bcmath. $e is a Bn value.
        $result = '1';
        $base   = bcmod($a, $m);
        $exp    = $e;
        while (bccomp($exp, '0', 0) > 0) {
            if ((int) bcmod($exp, '2') === 1) {
                $result = bcmod(bcmul($result, $base, 0), $m, 0);
            }
            $base = bcmod(bcmul($base, $base, 0), $m, 0);
            $exp  = bcdiv($exp, '2', 0);
        }
        return $result;
    }

    /** Modular inverse via extended Euclidean algorithm. */
    public static function modInv($a, $m) {
        if (self::backend() === 'gmp') {
            $inv = \gmp_invert($a, $m);
            if ($inv === false) throw new \RuntimeException('modular inverse does not exist');
            return $inv;
        }
        $a = self::mod($a, $m);
        $t = '0'; $newt = '1';
        $r = $m;  $newr = $a;
        while (bccomp($newr, '0', 0) !== 0) {
            $q = bcdiv($r, $newr, 0);
            $tmp = $newt; $newt = bcsub($t, bcmul($q, $newt, 0), 0); $t = $tmp;
            $tmp = $newr; $newr = bcsub($r, bcmul($q, $newr, 0), 0); $r = $tmp;
        }
        if (bccomp($r, '1', 0) !== 0) {
            throw new \RuntimeException('modular inverse does not exist');
        }
        if (bccomp($t, '0', 0) < 0) $t = bcadd($t, $m, 0);
        return bcmod($t, $m);
    }

    /* ── Predicates ────────────────────────────────────────────────── */

    public static function isZero($n): bool { return self::cmp($n, self::zero()) === 0; }
    public static function isOdd($n): bool {
        if (self::backend() === 'gmp') return ((int) \gmp_mod($n, 2)) === 1;
        return ((int) bcmod($n, '2')) === 1;
    }

    /* ── Internal ──────────────────────────────────────────────────── */

    private static function normalize_dec(string $dec): string {
        $sign = '';
        if ($dec !== '' && $dec[0] === '-') { $sign = '-'; $dec = substr($dec, 1); }
        $dec = ltrim($dec, '0');
        if ($dec === '') $dec = '0';
        return $sign . $dec;
    }
}
