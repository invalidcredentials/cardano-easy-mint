<?php
/**
 * TOTP (Time-based One-Time Password) helper.
 *
 * Pure-PHP RFC 6238 implementation, no Composer dependency. Used to gate
 * access to the Payment Wallets admin page with a second factor on top of
 * WordPress admin login.
 *
 * Compatible with Google Authenticator, Authy, 1Password, Bitwarden, and
 * any other RFC 6238 TOTP authenticator app. Defaults: SHA-1, 30-second
 * period, 6-digit codes.
 */

namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class TOTPHelper {

    const ISSUER    = 'Knights Guild Mint';
    const PERIOD    = 30;
    const DIGITS    = 6;
    const ALGORITHM = 'sha1';
    const SKEW_WINDOW = 1; // accept codes from t-30s, t, and t+30s

    /**
     * Generate a fresh base32-encoded secret. 20 bytes = 160 bits, the
     * RFC-6238-recommended size for SHA-1.
     */
    public static function generateSecret(int $bytes = 20): string {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Verify a 6-digit code against the secret. Allows ±1 30-second window
     * for clock skew between server and authenticator app.
     */
    public static function verify(string $secret, string $code, ?int $time = null): bool {
        $code = preg_replace('/[^0-9]/', '', $code);
        if (strlen($code) !== self::DIGITS) return false;

        $time = $time ?? time();
        $counter = (int) floor($time / self::PERIOD);

        for ($offset = -self::SKEW_WINDOW; $offset <= self::SKEW_WINDOW; $offset++) {
            $expected = self::codeAtCounter($secret, $counter + $offset);
            if (hash_equals($expected, $code)) return true;
        }
        return false;
    }

    /**
     * Build the otpauth:// URI that authenticator apps consume (either by
     * QR scan or manual paste).
     *
     * Format per Google's keyuri spec:
     *   otpauth://totp/Issuer:account?secret=BASE32&issuer=Issuer&...
     */
    public static function buildOtpAuthUri(string $secret, string $accountLabel = 'admin'): string {
        $issuer  = rawurlencode(self::ISSUER);
        $label   = rawurlencode($accountLabel);
        $algo    = strtoupper(self::ALGORITHM);
        $period  = self::PERIOD;
        $digits  = self::DIGITS;
        return "otpauth://totp/{$issuer}:{$label}"
             . "?secret={$secret}"
             . "&issuer={$issuer}"
             . "&algorithm={$algo}"
             . "&digits={$digits}"
             . "&period={$period}";
    }

    /**
     * Generate a single recovery code in XXXX-XXXX format using a Crockford-ish
     * alphabet that omits visually ambiguous characters (0/O, 1/I).
     */
    public static function generateRecoveryCode(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars, no 0/O/I/1
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    /**
     * Normalize user-entered recovery code to the canonical XXXX-XXXX form
     * for comparison. Accepts case-insensitive input with or without the dash
     * and with surrounding whitespace.
     */
    public static function normalizeRecoveryCode(string $input): string {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $input));
        if (strlen($clean) !== 8) return '';
        return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
    }

    /* ─── Internals ─────────────────────────────────────────────────── */

    private static function codeAtCounter(string $secret, int $counter): string {
        $key = self::base32Decode($secret);
        if ($key === '') return '';

        // 8-byte big-endian counter. PHP ints are 64-bit on modern hosts;
        // pack('J', ...) is 64-bit big-endian unsigned but only on PHP 7+.
        $bin = pack('J', $counter);

        $hash = hash_hmac(self::ALGORITHM, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string {
        if ($data === '') return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= $alphabet[bindec($chunk)];
        }
        // No '=' padding. Authenticator apps accept unpadded base32 fine.
        return $output;
    }

    private static function base32Decode(string $data): string {
        $data = strtoupper(preg_replace('/[^A-Z2-7]/', '', $data));
        if ($data === '') return '';

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $idx = strpos($alphabet, $data[$i]);
            if ($idx === false) continue;
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $output .= chr(bindec($chunk));
        }
        return $output;
    }
}
