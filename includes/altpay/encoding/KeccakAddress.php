<?php
namespace CardanoMintPay\AltPay\Encoding;

use CardanoMintPay\AltPay\Lib\Keccak;
use CardanoMintPay\AltPay\Lib\Secp256k1;

if (!defined('ABSPATH')) exit;

/**
 * Ethereum address derivation: address = "0x" + last 20 bytes of
 * keccak256(uncompressed_pubkey_minus_first_byte).
 *
 * EIP-55 checksum casing applied to the hex digits after derivation.
 */
class KeccakAddress {

    /** Compute the EIP-55 checksummed 0x-prefixed address from a 33-byte compressed pubkey. */
    public static function fromCompressedPubkey(string $compressedPubkey): string {
        $point = Secp256k1::decompress($compressedPubkey);
        $x = Secp256k1::gmpToBin32($point['x']);
        $y = Secp256k1::gmpToBin32($point['y']);
        $hash = Keccak::hash256($x . $y);
        $addr = substr($hash, -20);
        return self::eip55(bin2hex($addr));
    }

    /** Apply EIP-55 mixed-case checksum to a lowercase hex address (no 0x). */
    public static function eip55(string $hexAddrLower): string {
        $hexAddrLower = strtolower($hexAddrLower);
        $hash = Keccak::hash256Hex($hexAddrLower);
        $out = '0x';
        for ($i = 0; $i < strlen($hexAddrLower); $i++) {
            $c = $hexAddrLower[$i];
            if (ctype_alpha($c)) {
                $out .= hexdec($hash[$i]) >= 8 ? strtoupper($c) : $c;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }
}
