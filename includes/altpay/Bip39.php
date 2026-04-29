<?php
namespace CardanoMintPay\AltPay;

if (!defined('ABSPATH')) exit;

/**
 * BIP39 mnemonic <-> seed conversion. Reuses the already-shipped wordlist
 * at includes/helpers/bip39-wordlist.php. 12-word (128-bit) and 24-word
 * (256-bit) entropy supported.
 */
class Bip39 {

    private static $wordlist = null;
    private static $word_to_index = null;

    private static function loadWordlist(): void {
        if (self::$wordlist !== null) return;
        $path = plugin_dir_path(dirname(__DIR__)) . 'includes/helpers/bip39-wordlist.php';
        $list = include $path;
        if (!is_array($list) || count($list) !== 2048) {
            throw new \RuntimeException('BIP39 wordlist invalid');
        }
        self::$wordlist = $list;
        self::$word_to_index = array_flip($list);
    }

    /** Generate a fresh mnemonic of N words (12 or 24). */
    public static function generate(int $words = 24): string {
        if ($words !== 12 && $words !== 24) throw new \InvalidArgumentException('words must be 12 or 24');
        $entropyBytes = $words === 12 ? 16 : 32;
        $entropy = random_bytes($entropyBytes);
        return self::entropyToMnemonic($entropy);
    }

    public static function entropyToMnemonic(string $entropy): string {
        self::loadWordlist();
        $entropyBits = strlen($entropy) * 8;
        if (!in_array($entropyBits, [128, 160, 192, 224, 256], true)) {
            throw new \InvalidArgumentException('entropy length invalid');
        }
        $checksumBits = $entropyBits / 32;
        $hash = hash('sha256', $entropy, true);
        $bits = self::bytesToBitString($entropy) . substr(self::bytesToBitString($hash), 0, $checksumBits);
        $words = [];
        for ($i = 0; $i < strlen($bits); $i += 11) {
            $idx = bindec(substr($bits, $i, 11));
            $words[] = self::$wordlist[$idx];
        }
        return implode(' ', $words);
    }

    public static function mnemonicToEntropy(string $mnemonic): string {
        self::loadWordlist();
        $words = preg_split('/\s+/', trim(strtolower($mnemonic)));
        $count = count($words);
        if (!in_array($count, [12, 15, 18, 21, 24], true)) {
            throw new \InvalidArgumentException('mnemonic word count invalid');
        }
        $bits = '';
        foreach ($words as $w) {
            if (!isset(self::$word_to_index[$w])) {
                throw new \InvalidArgumentException('unknown word: ' . $w);
            }
            $bits .= str_pad(decbin(self::$word_to_index[$w]), 11, '0', STR_PAD_LEFT);
        }
        $entropyBits = ($count * 11) - ($count * 11 / 33);
        $entropyBits = (int) $entropyBits;
        $entropy = self::bitStringToBytes(substr($bits, 0, $entropyBits));
        $checksum = substr($bits, $entropyBits);
        $hash = hash('sha256', $entropy, true);
        $expected = substr(self::bytesToBitString($hash), 0, strlen($checksum));
        if (!hash_equals($expected, $checksum)) {
            throw new \InvalidArgumentException('mnemonic checksum mismatch');
        }
        return $entropy;
    }

    /** BIP39 seed = PBKDF2-HMAC-SHA512(mnemonic, "mnemonic" + passphrase, 2048, 64). */
    public static function mnemonicToSeed(string $mnemonic, string $passphrase = ''): string {
        return hash_pbkdf2('sha512', $mnemonic, 'mnemonic' . $passphrase, 2048, 64, true);
    }

    private static function bytesToBitString(string $bytes): string {
        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private static function bitStringToBytes(string $bits): string {
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
