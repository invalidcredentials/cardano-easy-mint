<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Keccak-256 (NOT FIPS 202 SHA-3-256). Used for Ethereum address derivation
 * and EIP-712 / RLP hashing. Differs from SHA-3 only in the padding byte
 * (0x01 vs 0x06).
 *
 * Implemented over GMP so 64-bit lane math is correct on 32-bit and 64-bit
 * PHP without any bit-overflow worries. Performance is fine for the
 * frequency at which we hash (per-derivation, per-refund).
 */
class Keccak {

    private const ROUNDS = 24;
    private const RHO_OFFSETS = [
         0,  1, 62, 28, 27,
        36, 44,  6, 55, 20,
         3, 10, 43, 25, 39,
        41, 45, 15, 21,  8,
        18,  2, 61, 56, 14,
    ];
    private const ROUND_CONSTS_HEX = [
        '0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000',
        '000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009',
        '000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
        '000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003',
        '8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a',
        '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
    ];

    private static $MASK64 = null;
    private static $RC = null;

    private static function init(): void {
        if (self::$MASK64 !== null) return;
        if (!extension_loaded('gmp')) {
            throw new \RuntimeException('GMP extension required for Keccak');
        }
        self::$MASK64 = gmp_init('0xffffffffffffffff');
        self::$RC = [];
        foreach (self::ROUND_CONSTS_HEX as $hex) {
            self::$RC[] = gmp_init('0x' . $hex);
        }
    }

    public static function hash256(string $input): string {
        self::init();
        $rateBytes = 136; // 1088-bit rate, 256-bit capacity = 256-bit output

        // Keccak-style padding: 0x01 ... 0x80, possibly merged into 0x81.
        $padLen = $rateBytes - (strlen($input) % $rateBytes);
        if ($padLen === 1) {
            $input .= "\x81";
        } else {
            $input .= "\x01" . str_repeat("\x00", $padLen - 2) . "\x80";
        }

        // 5x5 lane state.
        $state = array_fill(0, 25, gmp_init(0));

        $blocks = strlen($input) / $rateBytes;
        for ($b = 0; $b < $blocks; $b++) {
            $offset = $b * $rateBytes;
            for ($i = 0; $i < $rateBytes / 8; $i++) {
                $word = substr($input, $offset + $i * 8, 8);
                // Keccak lanes are little-endian 64-bit.
                $lane = self::leToGmp($word);
                $state[$i] = gmp_xor($state[$i], $lane);
            }
            self::keccakF($state);
        }

        // Squeeze 32 bytes (4 lanes) of output.
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::gmpToLe($state[$i]);
        }
        return $out;
    }

    public static function hash256Hex(string $input): string {
        return bin2hex(self::hash256($input));
    }

    private static function keccakF(array &$state): void {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            // Theta
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = gmp_xor(
                    gmp_xor(gmp_xor($state[$x], $state[$x + 5]), $state[$x + 10]),
                    gmp_xor($state[$x + 15], $state[$x + 20])
                );
            }
            $D = [];
            for ($x = 0; $x < 5; $x++) {
                $D[$x] = gmp_xor($C[($x + 4) % 5], self::rotl64($C[($x + 1) % 5], 1));
            }
            for ($i = 0; $i < 25; $i++) {
                $state[$i] = gmp_xor($state[$i], $D[$i % 5]);
            }
            // Rho + Pi
            $B = array_fill(0, 25, gmp_init(0));
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $idx    = $x + 5 * $y;
                    $newIdx = $y + 5 * (((2 * $x) + (3 * $y)) % 5);
                    $B[$newIdx] = self::rotl64($state[$idx], self::RHO_OFFSETS[$idx]);
                }
            }
            // Chi
            for ($y = 0; $y < 5; $y++) {
                $row = [];
                for ($x = 0; $x < 5; $x++) $row[$x] = $B[$x + 5 * $y];
                for ($x = 0; $x < 5; $x++) {
                    $notNext = gmp_and(gmp_xor($row[($x + 1) % 5], self::$MASK64), self::$MASK64);
                    $state[$x + 5 * $y] = gmp_xor($row[$x], gmp_and($notNext, $row[($x + 2) % 5]));
                }
            }
            // Iota
            $state[0] = gmp_xor($state[0], self::$RC[$round]);
        }
    }

    private static function rotl64(\GMP $w, int $n): \GMP {
        $n = $n % 64;
        if ($n === 0) return $w;
        $left  = gmp_and(gmp_mul($w, gmp_pow(2, $n)), self::$MASK64);
        $right = gmp_div_q($w, gmp_pow(2, 64 - $n));
        return gmp_or($left, $right);
    }

    private static function leToGmp(string $word8): \GMP {
        // Little-endian: byte 0 is the least significant.
        $g = gmp_init(0);
        for ($i = 7; $i >= 0; $i--) {
            $g = gmp_mul($g, 256);
            $g = gmp_add($g, ord($word8[$i]));
        }
        return $g;
    }

    private static function gmpToLe(\GMP $g): string {
        $g = gmp_and($g, self::$MASK64);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= chr(gmp_intval(gmp_and($g, 0xff)));
            $g = gmp_div_q($g, 256);
        }
        return $out;
    }
}
