<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Keccak-256 (NOT FIPS 202 SHA-3-256). Differs from SHA-3 only in the
 * padding byte (0x01 vs 0x06). Used for Ethereum address derivation.
 *
 * Implementation strategy: each 64-bit lane is stored as an 8-byte
 * little-endian string. Bit ops are done with PHP's native string XOR
 * / AND / OR / NOT (which operate byte-wise on equal-length strings),
 * and lane rotations go through a temporary bit-string representation.
 *
 * No GMP or bcmath required. Slower than a 64-bit integer impl, but
 * correct on every PHP build (32-bit and 64-bit) and we only hash
 * once per address derivation.
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

    public static function hash256(string $input): string {
        $rateBytes = 136; // 1088-bit rate, 256-bit capacity
        $padLen = $rateBytes - (strlen($input) % $rateBytes);
        if ($padLen === 1) {
            $input .= "\x81";
        } else {
            $input .= "\x01" . str_repeat("\x00", $padLen - 2) . "\x80";
        }

        $zero = str_repeat("\x00", 8);
        $state = array_fill(0, 25, $zero); // each lane is an 8-byte LE string

        $blocks = strlen($input) / $rateBytes;
        for ($b = 0; $b < $blocks; $b++) {
            $offset = $b * $rateBytes;
            for ($i = 0; $i < $rateBytes / 8; $i++) {
                $lane = substr($input, $offset + $i * 8, 8);
                $state[$i] = $state[$i] ^ $lane;
            }
            self::keccakF($state);
        }

        // Squeeze 32 bytes from the first 4 lanes.
        $out = '';
        for ($i = 0; $i < 4; $i++) $out .= $state[$i];
        return $out;
    }

    public static function hash256Hex(string $input): string {
        return bin2hex(self::hash256($input));
    }

    private static function keccakF(array &$state): void {
        $allOnes = "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
        $rcLanes = [];
        foreach (self::ROUND_CONSTS_HEX as $hex) {
            // Round constants are written MSB-first; convert to LE bytes.
            $rcLanes[] = self::beHexToLeBytes($hex);
        }

        for ($round = 0; $round < self::ROUNDS; $round++) {
            // Theta
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = $state[$x] ^ $state[$x + 5] ^ $state[$x + 10] ^ $state[$x + 15] ^ $state[$x + 20];
            }
            $D = [];
            for ($x = 0; $x < 5; $x++) {
                $D[$x] = $C[($x + 4) % 5] ^ self::rotl64Le($C[($x + 1) % 5], 1);
            }
            for ($i = 0; $i < 25; $i++) {
                $state[$i] = $state[$i] ^ $D[$i % 5];
            }

            // Rho + Pi
            $B = array_fill(0, 25, str_repeat("\x00", 8));
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $idx    = $x + 5 * $y;
                    $newIdx = $y + 5 * (((2 * $x) + (3 * $y)) % 5);
                    $B[$newIdx] = self::rotl64Le($state[$idx], self::RHO_OFFSETS[$idx]);
                }
            }

            // Chi: state[x][y] = B[x][y] XOR ((NOT B[x+1][y]) AND B[x+2][y])
            for ($y = 0; $y < 5; $y++) {
                $row = [];
                for ($x = 0; $x < 5; $x++) $row[$x] = $B[$x + 5 * $y];
                for ($x = 0; $x < 5; $x++) {
                    $notNext = $row[($x + 1) % 5] ^ $allOnes;
                    $state[$x + 5 * $y] = $row[$x] ^ ($notNext & $row[($x + 2) % 5]);
                }
            }

            // Iota
            $state[0] = $state[0] ^ $rcLanes[$round];
        }
    }

    /** Rotate-left a 64-bit little-endian lane by n bits. */
    private static function rotl64Le(string $lane, int $n): string {
        $n = $n % 64;
        if ($n === 0) return $lane;
        // Build MSB-first bit string from the LE lane.
        $bits = '';
        for ($i = 7; $i >= 0; $i--) {
            $bits .= str_pad(decbin(ord($lane[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits = substr($bits, $n) . substr($bits, 0, $n);
        // Convert back to LE bytes.
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $byteBits = substr($bits, 64 - 8 * ($i + 1), 8);
            $out .= chr(bindec($byteBits));
        }
        return $out;
    }

    private static function beHexToLeBytes(string $hex): string {
        // Round constants are 16 hex chars = 8 bytes MSB-first. Convert to LE.
        $beBytes = hex2bin($hex);
        $le = '';
        for ($i = strlen($beBytes) - 1; $i >= 0; $i--) $le .= $beBytes[$i];
        return $le;
    }
}
