<?php
/**
 * CardanoTransactionSignerPHP.php
 *
 * Pure-PHP Cardano transaction signer with CBOR codec.
 * Adds support for signing with extended keys (kL||kR) using Ed25519Compat::sign_extended.
 */

namespace CardanoMintPay\Helpers;

require_once __DIR__ . '/Ed25519Compat.php';

class CardanoTransactionSignerPHP
{
    // --- Public API ----------------------------------------------------------

    /**
     * Sign a CBOR-hex transaction body with a private key.
     * $skey_hex may be:
     *  - 64 hex chars (32 bytes): legacy seed path (libsodium keypair) [kept for back-compat]
     *  - 128 hex chars (64 bytes): extended key kL||kR (preferred, CIP-1852 correct)
     */
    public static function signTransaction(string $tx_hex, string $skey_hex, bool $debug=false): array
    {
        $debug_log = [];
        $debug_log[] = "Starting transaction signing...";
        $debug_log[] = "TX hex length: " . strlen($tx_hex) . " chars";
        $debug_log[] = "Private key length: " . strlen($skey_hex) . " chars";

        try {
            if ($tx_hex === '') return ['success'=>false,'error'=>'Missing tx hex','debug'=>$debug_log];
            $tx_bytes = hex2bin($tx_hex);
            if ($tx_bytes === false) return ['success'=>false,'error'=>'Invalid hex','debug'=>$debug_log];
            $debug_log[] = "✓ Hex decoded to " . strlen($tx_bytes) . " bytes";

            // CRITICAL: Extract ORIGINAL body bytes WITHOUT re-encoding
            // Re-encoding changes the CBOR structure and produces a different hash!
            $debug_log[] = "Extracting original transaction body bytes...";
            $body_bytes = self::extractBodyBytes($tx_bytes);
            $body_hash  = sodium_crypto_generichash($body_bytes, '', 32);
            $debug_log[] = "✓ Body hash (from ORIGINAL bytes): " . bin2hex($body_hash);
            $debug_log[] = "✓ Body length: " . strlen($body_bytes) . " bytes";

            // Now decode for witness set manipulation
            $debug_log[] = "Decoding CBOR structure for witness handling...";
            $tx = self::decodeCBOR($tx_bytes);
            if (!is_array($tx) || count($tx) < 2) return ['success'=>false,'error'=>'Invalid tx structure','debug'=>$debug_log];
            $body = $tx[0];
            $wset = isset($tx[1]) && is_array($tx[1]) ? $tx[1] : [];
            $debug_log[] = "✓ CBOR decoded, body type: " . gettype($body);

            // Prepare key and sign
            $sig = null; $pub = null;
            if (strlen($skey_hex) === 128) {
                $debug_log[] = "Using extended key (128 chars) - CIP-1852 mode";
                // Extended key: kL||kR
                // CRITICAL: Cardano uses NO-CLAMP Ed25519 signing!
                // We MUST use Ed25519Compat for both public key derivation AND signing
                $kL = hex2bin(substr($skey_hex, 0, 64));
                $kR = hex2bin(substr($skey_hex, 64, 64));
                if ($kL === false || $kR === false) return ['success'=>false,'error'=>'Invalid extended key hex','debug'=>$debug_log];

                $debug_log[] = "Using Ed25519Compat::sign_extended (no-clamp signing)...";
                // Derive public key WITHOUT clamping (must match wallet generation!)
                $pub = \Ed25519Compat::ge_scalarmult_base_noclamp($kL);
                $debug_log[] = "✓ Public key (no-clamp): " . bin2hex($pub);

                // Sign using extended key signing (no-clamp)
                $sig = \Ed25519Compat::sign_extended($body_hash, $kL, $kR);
                $debug_log[] = "✓ Signature created: " . strlen($sig) . " bytes";

                // Calculate key hash to help verify we're using the right key
                $pub_hash = sodium_crypto_generichash($pub, '', 28);
                $debug_log[] = "✓ Public key hash: " . bin2hex($pub_hash);
            } elseif (strlen($skey_hex) === 64) {
                $debug_log[] = "Using legacy seed (64 chars)";
                // Legacy seed path - uses standard clamped Ed25519
                $seed = hex2bin($skey_hex);
                if ($seed === false) return ['success'=>false,'error'=>'Invalid key hex','debug'=>$debug_log];
                $kp = sodium_crypto_sign_seed_keypair($seed);
                $sk = sodium_crypto_sign_secretkey($kp);
                $pub = sodium_crypto_sign_publickey($kp);
                $sig = sodium_crypto_sign_detached($body_hash, $sk);
                $debug_log[] = "✓ Legacy signature created";
            } else {
                return ['success'=>false,'error'=>'Private key must be 64 or 128 hex chars','debug'=>$debug_log];
            }

            // Build witness (vkey, signature)
            $debug_log[] = "Building witness set...";
            $vkey_witness = [$pub, $sig];

            // Create a new witness for this signature
            $new_witness = [$vkey_witness];

            // Build complete witness set as CBOR map
            // For submission to Anvil, we need JUST our witness as a map
            // IMPORTANT: vkey_witnesses must be a CBOR SET (tag 258), not a plain array!
            $vkey_witnesses_set = ['__cbor_tag__' => 258, '__value__' => $new_witness];
            $our_witness_set_map = [0 => $vkey_witnesses_set];  // Map with key 0 -> SET of vkey witnesses

            // For the complete signed transaction, merge with existing witnesses
            if (!isset($wset[0])) $wset[0] = [];
            $wset[0][] = $vkey_witness;
            $debug_log[] = "✓ Witness added to set";

            $debug_log[] = "Encoding signed transaction...";
            $tx_signed = [$body, $wset];
            $tx_signed_bytes = self::encodeCBOR($tx_signed);
            $debug_log[] = "✓ Signed TX: " . strlen($tx_signed_bytes) . " bytes";

            // Encode just OUR witness set separately (for Anvil submission format)
            // This MUST be a CBOR map {0: [[vkey, sig]]}
            // Force it to encode as a map by using encodeMap directly
            $debug_log[] = "Encoding our witness set as CBOR map...";
            $witness_set_bytes = self::encodeMap($our_witness_set_map);
            $debug_log[] = "✓ Witness set map: " . strlen($witness_set_bytes) . " bytes (forced CBOR map with key 0)";

            return [
                'success' => true,
                'signedTx' => bin2hex($tx_signed_bytes),  // Full signed transaction
                'witnessSetHex' => bin2hex($witness_set_bytes),  // Just the witness set for submission
                'vkey_hex' => bin2hex($pub),
                'sig_hex'  => bin2hex($sig),
                'debug' => $debug_log
            ];

        } catch (\Throwable $e) {
            return ['success'=>false,'error'=>$e->getMessage(),'debug'=>$debug_log];
        }
    }

    /**
     * Extract original transaction body bytes WITHOUT decoding/re-encoding
     * This preserves the exact CBOR structure including tags
     */
    private static function extractBodyBytes(string $tx_bytes): string
    {
        $offset = 0;

        // Skip the transaction array header (should be 0x84 = array with 4 elements)
        $first_byte = ord($tx_bytes[$offset]);
        if (($first_byte & 0xE0) != 0x80) {
            throw new \RuntimeException('Transaction must be a CBOR array');
        }
        $offset++;

        // Now we're at the start of the body (first element of the array)
        $body_start = $offset;

        // Skip one complete CBOR element to find where the body ends
        self::skipCborElement($tx_bytes, $offset);
        $body_end = $offset;

        // Extract and return the original body bytes
        return substr($tx_bytes, $body_start, $body_end - $body_start);
    }

    /**
     * Skip one complete CBOR element by advancing the offset
     */
    private static function skipCborElement(string $bytes, int &$offset): void
    {
        $initial = ord($bytes[$offset]);
        $major = ($initial >> 5) & 0x07;
        $add = $initial & 0x1F;
        $offset++;

        // Read length based on additional info
        $length = 0;
        if ($add < 24) {
            $length = $add;
        } elseif ($add == 24) {
            $length = ord($bytes[$offset]);
            $offset++;
        } elseif ($add == 25) {
            $length = unpack('n', substr($bytes, $offset, 2))[1];
            $offset += 2;
        } elseif ($add == 26) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            $offset += 4;
        } elseif ($add == 27) {
            // 64-bit length
            $offset += 8;
            $length = 0; // We'll handle this case specially if needed
        }

        // Handle different major types
        if ($major == 0 || $major == 1 || $major == 7) {
            // Unsigned int, negative int, simple values - no additional data to skip
            return;
        } elseif ($major == 2 || $major == 3) {
            // Byte string or text string - skip the data bytes
            $offset += $length;
            return;
        } elseif ($major == 4) {
            // Array - recursively skip N elements
            for ($i = 0; $i < $length; $i++) {
                self::skipCborElement($bytes, $offset);
            }
            return;
        } elseif ($major == 5) {
            // Map - recursively skip N key-value pairs
            for ($i = 0; $i < $length; $i++) {
                self::skipCborElement($bytes, $offset); // key
                self::skipCborElement($bytes, $offset); // value
            }
            return;
        } elseif ($major == 6) {
            // Tag - skip the tagged content
            self::skipCborElement($bytes, $offset);
            return;
        }
    }

    // --- CBOR codec (subset used for Cardano) --------------------------------
    // Minimal CBOR encode/decode; your existing implementation may be richer.
    // Here we provide enough to hash/sign standard transactions.

    private static function encodeCBOR($value): string
    {
        switch (gettype($value)) {
            case 'integer':
                if ($value >= 0) return self::encodeUnsigned($value);
                return self::encodeNegative($value);
            case 'string':
                return self::encodeBytes($value);
            case 'array':
                // Check for CBOR tag marker BEFORE checking isAssoc
                if (isset($value['__cbor_tag__']) && isset($value['__value__'])) {
                    return self::encodeTagged($value['__cbor_tag__'], $value['__value__']);
                }
                if (self::isAssoc($value)) return self::encodeMap($value);
                return self::encodeArray($value);
            case 'double':
                return self::encodeFloat($value);
            case 'NULL':
                return "\xF6";
            case 'boolean':
                return $value ? "\xF5" : "\xF4";
            default:
                throw new \RuntimeException('Unsupported CBOR type: ' . gettype($value));
        }
    }

    private static function isAssoc(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i++) return true;
        }
        return false;
    }

    private static function encodeUnsigned($value): string
    {
        if ($value < 24) return chr($value);
        if ($value < 256) return "\x18" . chr($value);
        if ($value < 65536) return "\x19" . pack('n', $value);
        if ($value < 4294967296) return "\x1A" . pack('N', $value);
        // 64-bit
        $hi = (int) bcdiv((string)$value, '4294967296', 0);
        $lo = (int) bcmod((string)$value, '4294967296');
        return "\x1B" . pack('N', $hi) . pack('N', $lo);
    }

    private static function encodeNegative($value): string
    {
        $n = -1 - $value;
        if ($n < 24) return chr(0x20 | $n);
        if ($n < 256) return "\x38" . chr($n);
        if ($n < 65536) return "\x39" . pack('n', $n);
        if ($n < 4294967296) return "\x3A" . pack('N', $n);
        $hi = (int) bcdiv((string)$n, '4294967296', 0);
        $lo = (int) bcmod((string)$n, '4294967296');
        return "\x3B" . pack('N', $hi) . pack('N', $lo);
    }

    private static function encodeBytes(string $s): string
    {
        $len = strlen($s);
        if ($len < 24) return chr(0x40 | $len) . $s;
        if ($len < 256) return "\x58" . chr($len) . $s;
        if ($len < 65536) return "\x59" . pack('n', $len) . $s;
        if ($len < 4294967296) return "\x5A" . pack('N', $len) . $s;
        $hi = (int) bcdiv((string)$len, '4294967296', 0);
        $lo = (int) bcmod((string)$len, '4294967296');
        return "\x5B" . pack('N', $hi) . pack('N', $lo) . $s;
    }

    private static function encodeArray(array $arr): string
    {
        $n = count($arr);
        $out = '';
        if ($n < 24) $out .= chr(0x80 | $n);
        elseif ($n < 256) $out .= "\x98" . chr($n);
        elseif ($n < 65536) $out .= "\x99" . pack('n', $n);
        else { $hi=(int)bcdiv((string)$n,'4294967296',0); $lo=(int)bcmod((string)$n,'4294967296'); $out.="\x9A".pack('N',$hi).pack('N',$lo); }
        foreach ($arr as $v) $out .= self::encodeCBOR($v);
        return $out;
    }

    private static function encodeMap(array $map): string
    {
        $n = count($map);
        $out = '';
        if ($n < 24) $out .= chr(0xA0 | $n);
        elseif ($n < 256) $out .= "\xB8" . chr($n);
        elseif ($n < 65516) $out .= "\xB9" . pack('n', $n);
        else { $hi=(int)bcdiv((string)$n,'4294967296',0); $lo=(int)bcmod((string)$n,'4294967296'); $out.="\xBA".pack('N',$hi).pack('N',$lo); }

        // Canonical CBOR: sort integer keys numerically
        $allInt = true;
        foreach ($map as $k => $_) {
            if (!is_int($k)) {
                $allInt = false;
                break;
            }
        }
        if ($allInt) {
            ksort($map, SORT_NUMERIC);
        }

        foreach ($map as $k=>$v) { $out .= self::encodeCBOR($k) . self::encodeCBOR($v); }
        return $out;
    }

    private static function encodeFloat($value): string
    {
        return "\xFB" . pack('E', $value); // 64-bit float (little-endian pack 'E')
    }

    private static function encodeTagged($tag, $value): string
    {
        // CBOR tag encoding (major type 6)
        // For tag 258 (CBOR set), we need: 0xD9 (major type 6, 2-byte uint16) + 0x0102 (258 in big-endian)
        $out = '';
        if ($tag < 24) {
            $out .= chr(0xC0 | $tag); // Major type 6, small tag
        } elseif ($tag < 256) {
            $out .= "\xD8" . chr($tag); // Major type 6, 1-byte tag
        } elseif ($tag < 65536) {
            $out .= "\xD9" . pack('n', $tag); // Major type 6, 2-byte tag (big-endian)
        } elseif ($tag < 4294967296) {
            $out .= "\xDA" . pack('N', $tag); // Major type 6, 4-byte tag
        } else {
            $hi = (int) bcdiv((string)$tag, '4294967296', 0);
            $lo = (int) bcmod((string)$tag, '4294967296');
            $out .= "\xDB" . pack('N', $hi) . pack('N', $lo); // Major type 6, 8-byte tag
        }
        // Encode the tagged content
        $out .= self::encodeCBOR($value);
        return $out;
    }

    // --- Decode (very small subset used for txs) -----------------------------

    public static function decodeCBOR(string $bytes) {
        $ofs = 0;
        return self::decodeOne($bytes, $ofs);
    }

    private static function decodeOne(string $b, int &$ofs) {
        $ai = ord($b[$ofs]); $ofs++;
        $maj = $ai >> 5; $add = $ai & 31;
        $getn = function(int $n) use ($b, &$ofs) {
            $s = substr($b, $ofs, $n); $ofs += $n; return $s;
        };
        $getu = function(int $n) use ($getn) {
            $d = $getn($n);
            if ($n===1) return ord($d);
            if ($n===2) { $v=unpack('n', $d)[1]; return $v; }
            if ($n===4) { $v=unpack('N', $d)[1]; return $v; }
            if ($n===8) { $hi=unpack('N', substr($d,0,4))[1]; $lo=unpack('N', substr($d,4,4))[1]; return bcadd(bcmul($hi,'4294967296'), $lo); }
            return 0;
        };
        $readLen = function($add) use (&$ofs, $getu) {
            if ($add < 24) return $add;
            if ($add === 24) return $getu(1);
            if ($add === 25) return $getu(2);
            if ($add === 26) return $getu(4);
            if ($add === 27) return $getu(8);
            throw new \RuntimeException('Indefinite lengths not supported');
        };
        if ($maj === 0) { // unsigned
            return $readLen($add);
        } elseif ($maj === 1) { // negative
            $n = $readLen($add);
            return -1 - $n;
        } elseif ($maj === 2) { // byte string
            $len = $readLen($add);
            $s = substr($b, $ofs, $len);
            $ofs += $len; // Security fix: Increment offset BEFORE return
            return $s;
        } elseif ($maj === 3) { // text string
            $len = $readLen($add);
            $s = substr($b, $ofs, $len);
            $ofs += $len; // Security fix: Increment offset BEFORE return
            return $s;
        } elseif ($maj === 4) { // array
            $len = $readLen($add);
            $arr = [];
            for ($i=0; $i<$len; $i++) $arr[] = self::decodeOne($b, $ofs);
            return $arr;
        } elseif ($maj === 5) { // map
            $len = $readLen($add);
            $map = [];
            for ($i=0; $i<$len; $i++) {
                $k = self::decodeOne($b, $ofs);
                $v = self::decodeOne($b, $ofs);
                $map[$k] = $v;
            }
            return $map;
        } elseif ($maj === 6) {
            // tags - pass through tagged content
            $tag = $readLen($add);
            $val = self::decodeOne($b, $ofs);
            return $val; // ignore tag for signing
        } elseif ($maj === 7) {
            if ($add === 20) return false;
            if ($add === 21) return true;
            if ($add === 22) return null;
            if ($add === 27) { $d = $getu(8); return (float)$d; }
            throw new \RuntimeException('Unsupported simple/float');
        }
        throw new \RuntimeException('Unsupported CBOR major type');
    }
}
