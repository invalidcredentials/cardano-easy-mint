<?php
namespace CardanoMintPay\AltPay\Encoding;

use CardanoMintPay\AltPay\Lib\Bn;

if (!defined('ABSPATH')) exit;

/**
 * Base58 + Base58Check. Used for Solana addresses (raw Base58) and BTC
 * legacy address forms (Base58Check). Big-int math via the Bn helper so
 * we run on bcmath when GMP is missing.
 */
class Base58 {

    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $bin): string {
        if ($bin === '') return '';
        $hex = bin2hex($bin);
        if ($hex === '') return '';
        $num = Bn::fromHex($hex);
        $out = '';
        $alpha = self::ALPHABET;
        $fiftyEight = Bn::fromDec('58');
        while (Bn::cmp($num, Bn::zero()) > 0) {
            $rem = Bn::toInt(Bn::mod($num, $fiftyEight));
            $num = Bn::divQ($num, $fiftyEight);
            $out = $alpha[$rem] . $out;
        }
        for ($i = 0; $i < strlen($bin) && ord($bin[$i]) === 0; $i++) {
            $out = '1' . $out;
        }
        return $out;
    }

    public static function decode(string $str): string {
        $alpha = self::ALPHABET;
        $num = Bn::zero();
        $fiftyEight = Bn::fromDec('58');
        for ($i = 0; $i < strlen($str); $i++) {
            $idx = strpos($alpha, $str[$i]);
            if ($idx === false) throw new \InvalidArgumentException('invalid base58 char');
            $num = Bn::add(Bn::mul($num, $fiftyEight), Bn::fromDec((string) $idx));
        }
        $hex = Bn::toHex($num);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = $hex === '0' ? '' : hex2bin($hex);
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
