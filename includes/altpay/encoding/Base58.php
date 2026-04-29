<?php
namespace CardanoMintPay\AltPay\Encoding;

if (!defined('ABSPATH')) exit;

/**
 * Base58 + Base58Check. Used for Solana addresses (raw Base58) and BTC
 * legacy address forms (Base58Check). Implementation uses GMP for fast
 * arbitrary-precision math.
 */
class Base58 {

    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $bin): string {
        if ($bin === '') return '';
        $hex = bin2hex($bin);
        if ($hex === '') return '';
        $num = gmp_init('0x' . $hex);
        $out = '';
        $alpha = self::ALPHABET;
        while (gmp_cmp($num, 0) > 0) {
            $rem = gmp_intval(gmp_mod($num, 58));
            $num = gmp_div_q($num, 58);
            $out = $alpha[$rem] . $out;
        }
        // Preserve leading zero bytes as '1' chars.
        for ($i = 0; $i < strlen($bin) && ord($bin[$i]) === 0; $i++) {
            $out = '1' . $out;
        }
        return $out;
    }

    public static function decode(string $str): string {
        $alpha = self::ALPHABET;
        $num = gmp_init(0);
        for ($i = 0; $i < strlen($str); $i++) {
            $idx = strpos($alpha, $str[$i]);
            if ($idx === false) throw new \InvalidArgumentException('invalid base58 char');
            $num = gmp_add(gmp_mul($num, 58), $idx);
        }
        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = $hex === '0' ? '' : hex2bin($hex);
        // Re-add leading zero bytes from leading '1' chars.
        for ($i = 0; $i < strlen($str) && $str[$i] === '1'; $i++) {
            $bin = "\x00" . $bin;
        }
        return $bin;
    }

    public static function encodeCheck(string $bin): string {
        $checksum = substr(hash('sha256', hash('sha256', $bin, true), true), 0, 4);
        return self::encode($bin . $checksum);
    }

    public static function decodeCheck(string $str): string {
        $bin = self::decode($str);
        if (strlen($bin) < 4) throw new \InvalidArgumentException('base58check too short');
        $payload = substr($bin, 0, -4);
        $check   = substr($bin, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $check)) throw new \InvalidArgumentException('checksum mismatch');
        return $payload;
    }
}
