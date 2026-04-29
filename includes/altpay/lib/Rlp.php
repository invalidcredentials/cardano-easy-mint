<?php
namespace CardanoMintPay\AltPay\Lib;

if (!defined('ABSPATH')) exit;

/**
 * Minimal RLP encoder. Handles strings (raw bytes) and nested lists. That
 * is enough to serialize an EIP-1559 transaction: an outer list whose
 * elements are byte strings and one inner list (accessList).
 *
 * Decoder is intentionally omitted - we only need to write txs, not parse
 * incoming ones.
 */
class Rlp {

    /**
     * Encode either a binary string or an array of encodable items.
     * Encoded ints should be passed in as their canonical big-endian byte
     * representation with no leading zeros (zero -> empty string).
     */
    public static function encode($input): string {
        if (is_array($input)) {
            $body = '';
            foreach ($input as $item) $body .= self::encode($item);
            return self::encodeLength(strlen($body), 0xc0) . $body;
        }
        $bin = (string) $input;
        if (strlen($bin) === 1 && ord($bin) < 0x80) return $bin;
        return self::encodeLength(strlen($bin), 0x80) . $bin;
    }

    /** Encode an unsigned integer as canonical big-endian bytes (no leading zeros). */
    public static function uint($value): string {
        $bin = is_string($value) ? $value : (string) $value;
        // Caller can pass either a decimal-string Bn value or raw bytes.
        // Detect raw-byte vs decimal: decimals are ASCII digits.
        if (preg_match('/^[0-9]+$/', $bin)) {
            $bin = Bn::toHex(Bn::fromDec($bin));
            if (strlen($bin) % 2) $bin = '0' . $bin;
            $bin = hex2bin($bin);
        }
        return ltrim($bin, "\x00");
    }

    private static function encodeLength(int $len, int $offset): string {
        if ($len < 56) return chr($offset + $len);
        $hex = dechex($len);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = hex2bin($hex);
        return chr($offset + 55 + strlen($bin)) . $bin;
    }
}
