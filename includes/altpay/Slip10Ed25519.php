<?php
namespace CardanoMintPay\AltPay;

use CardanoMintPay\AltPay\Lib\Bn;

if (!defined('ABSPATH')) exit;

/**
 * SLIP-0010 ed25519 derivation, used by Solana.
 *
 * Hardened-only derivation. Indices are accepted as int|string|GMP|bcmath
 * because PHP on 32-bit Windows wraps int literals at 2^31.
 */
class Slip10Ed25519 {

    private static $HARDENED = null;

    private static function consts(): void {
        if (self::$HARDENED === null) self::$HARDENED = Bn::fromHex('80000000');
    }

    public static function masterFromSeed(string $seed64): array {
        $i = hash_hmac('sha512', $seed64, 'ed25519 seed', true);
        return ['k' => substr($i, 0, 32), 'c' => substr($i, 32, 32)];
    }

    public static function ckdPriv(array $parent, $index): array {
        self::consts();
        $idxBn = self::toBnIndex($index);
        if (Bn::cmp($idxBn, self::$HARDENED) < 0) {
            throw new \InvalidArgumentException('SLIP-0010 ed25519 only supports hardened derivation');
        }
        $data = "\x00" . $parent['k'] . Bn::toBin($idxBn, 4);
        $i = hash_hmac('sha512', $data, $parent['c'], true);
        return ['k' => substr($i, 0, 32), 'c' => substr($i, 32, 32)];
    }

    public static function derivePath(array $master, string $path): array {
        self::consts();
        $node = $master;
        $parts = explode('/', $path);
        if (count($parts) === 0 || $parts[0] !== 'm') {
            throw new \InvalidArgumentException('path must start with m/');
        }
        for ($i = 1; $i < count($parts); $i++) {
            $seg = $parts[$i];
            if ($seg === '') continue;
            $hardened = false;
            if (substr($seg, -1) === "'" || substr($seg, -1) === 'h' || substr($seg, -1) === 'H') {
                $hardened = true;
                $seg = substr($seg, 0, -1);
            }
            if (!$hardened) throw new \InvalidArgumentException('SLIP-0010 ed25519 path segments must be hardened');
            $idx = Bn::add(Bn::fromDec((string) (int) $seg), self::$HARDENED);
            $node = self::ckdPriv($node, $idx);
        }
        return $node;
    }

    public static function publicKeyFromSeed(string $seed32): string {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new \RuntimeException('sodium extension required for SOL ed25519');
        }
        $kp = sodium_crypto_sign_seed_keypair($seed32);
        return sodium_crypto_sign_publickey($kp);
    }

    private static function toBnIndex($index) {
        self::consts();
        if (is_object($index)) return $index;
        if (is_string($index)) return Bn::fromDec($index);
        if (is_int($index)) {
            if ($index < 0) return Bn::add(self::$HARDENED, Bn::fromDec((string) ($index + 2147483648)));
            return Bn::fromDec((string) $index);
        }
        if (is_float($index)) return Bn::fromDec(sprintf('%.0F', $index));
        throw new \InvalidArgumentException('index must be int, string, GMP, or float');
    }
}
