<?php
namespace CardanoMintPay\AltPay\Encoding;

if (!defined('ABSPATH')) exit;

/**
 * BIP-173 Bech32 + BIP-350 Bech32m. Used for BTC SegWit v0 P2WPKH (Bech32)
 * and SegWit v1 Taproot (Bech32m). We only need encode + decode for v0.
 */
class Bech32 {

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST  = 1;
    private const BECH32M_CONST = 0x2bc830a3;

    public static function encode(string $hrp, array $data, bool $bech32m = false): string {
        $checksum = self::createChecksum($hrp, $data, $bech32m);
        $combined = array_merge($data, $checksum);
        $out = $hrp . '1';
        foreach ($combined as $d) $out .= self::CHARSET[$d];
        return $out;
    }

    /** Encode a SegWit v0 P2WPKH from a 20-byte program. */
    public static function encodeSegwitV0(string $hrp, string $program20): string {
        if (strlen($program20) !== 20) throw new \InvalidArgumentException('expected 20-byte program');
        $words = self::convertBits(array_values(unpack('C*', $program20)), 8, 5, true);
        array_unshift($words, 0); // witness version 0
        return self::encode($hrp, $words, false);
    }

    public static function decode(string $bech): ?array {
        $bech = strtolower($bech);
        $pos = strrpos($bech, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($bech)) return null;
        $hrp = substr($bech, 0, $pos);
        $data = [];
        for ($i = $pos + 1; $i < strlen($bech); $i++) {
            $idx = strpos(self::CHARSET, $bech[$i]);
            if ($idx === false) return null;
            $data[] = $idx;
        }
        $bech32m = false;
        if (!self::verifyChecksum($hrp, $data, false)) {
            if (!self::verifyChecksum($hrp, $data, true)) return null;
            $bech32m = true;
        }
        return ['hrp' => $hrp, 'data' => array_slice($data, 0, -6), 'bech32m' => $bech32m];
    }

    public static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): array {
        $acc = 0;
        $bits = 0;
        $out = [];
        $maxv = (1 << $toBits) - 1;
        foreach ($data as $val) {
            $val = $val & 0xff;
            $acc = ($acc << $fromBits) | $val;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $out[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) {
            $out[] = ($acc << ($toBits - $bits)) & $maxv;
        }
        return $out;
    }

    private static function polymod(array $values): int {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) $chk ^= $gen[$i];
            }
        }
        return $chk;
    }

    private static function hrpExpand(string $hrp): array {
        $hi = []; $lo = [];
        for ($i = 0; $i < strlen($hrp); $i++) {
            $hi[] = ord($hrp[$i]) >> 5;
            $lo[] = ord($hrp[$i]) & 31;
        }
        return array_merge($hi, [0], $lo);
    }

    private static function verifyChecksum(string $hrp, array $data, bool $bech32m): bool {
        $expected = $bech32m ? self::BECH32M_CONST : self::BECH32_CONST;
        return self::polymod(array_merge(self::hrpExpand($hrp), $data)) === $expected;
    }

    private static function createChecksum(string $hrp, array $data, bool $bech32m): array {
        $values = array_merge(self::hrpExpand($hrp), $data, [0,0,0,0,0,0]);
        $constant = $bech32m ? self::BECH32M_CONST : self::BECH32_CONST;
        $mod = self::polymod($values) ^ $constant;
        $out = [];
        for ($i = 0; $i < 6; $i++) $out[] = ($mod >> (5 * (5 - $i))) & 31;
        return $out;
    }
}
