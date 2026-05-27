<?php
namespace CardanoMintPay\AssetUpgrade;

if (!defined('ABSPATH')) exit;

/**
 * MetadataResolver
 *
 * Pure functions for the metadata side of the Asset Upgrade flow:
 *
 *   - applyPatch(current, patch) — shallow-merge a JSON patch onto an
 *     existing CIP-25 metadata object. Top-level keys in patch overwrite
 *     matching keys in current; other keys preserved.
 *
 *   - parseCip25Bundle(json, policy_id) — split a pasted CIP-25 metadata
 *     bundle (the canonical {"721": {<policy>: {<asset_ascii>: {...}}}}
 *     shape) into an array of [{asset_name_hex, metadata}, ...] for the
 *     given policy.
 *
 *   - asciiToHex / hexToAscii — asset name encoding helpers. CIP-25
 *     bundles key by ASCII name; on-chain + DB store the hex-encoded form.
 *
 * No DB or network calls live here. The controller composes these against
 * BlockfrostClient + the spec row to produce a preview diff or a build
 * payload. Keeping it pure makes phase 4+5 (customer-side eligibility
 * + tx build) straightforward to compose without re-doing the work.
 */
class MetadataResolver {

    /**
     * Shallow merge: keys present in $patch replace matching keys in
     * $current; other keys preserved. Nested arrays in $patch fully
     * replace the corresponding nested arrays in $current — we don't
     * deep-merge. That matches the typical NFT-refresh case (swap
     * `image`, swap `files`) and avoids ambiguity around CIP-25's array
     * semantics for `files`.
     */
    public static function applyPatch(array $current, array $patch): array {
        return array_merge($current, $patch);
    }

    /**
     * Parse a pasted CIP-25 metadata bundle and pull out the entries for
     * one policy. Returns an array of ['asset_name' => <hex>, 'metadata' => <array>].
     *
     * Throws \InvalidArgumentException with a human-readable reason if
     * the bundle shape is wrong — the controller catches and surfaces
     * the message to the admin.
     */
    public static function parseCip25Bundle(string $json, string $expected_policy_id): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Pasted bundle is not valid JSON.');
        }
        if (!isset($decoded['721']) || !is_array($decoded['721'])) {
            throw new \InvalidArgumentException('Bundle must have a top-level "721" object.');
        }
        $policies = $decoded['721'];
        $assets   = $policies[$expected_policy_id] ?? null;
        if (!is_array($assets) || empty($assets)) {
            $found = array_filter(array_keys($policies), function ($k) { return $k !== 'version'; });
            $hint  = empty($found)
                ? 'no policies found in bundle'
                : 'found policies: ' . implode(', ', array_map([self::class, 'shortHash'], $found));
            throw new \InvalidArgumentException(
                'Bundle has no entries for policy ' . self::shortHash($expected_policy_id) . ' — ' . $hint . '.'
            );
        }

        $out = [];
        foreach ($assets as $asset_name_ascii => $metadata) {
            if (!is_array($metadata)) continue;
            $hex = self::asciiToHex((string) $asset_name_ascii);
            if ($hex === '') continue;
            $out[] = ['asset_name' => $hex, 'metadata' => $metadata];
        }
        if (empty($out)) {
            throw new \InvalidArgumentException('Bundle had a policy entry but no parseable asset rows under it.');
        }
        return $out;
    }

    public static function asciiToHex(string $ascii): string {
        if ($ascii === '') return '';
        return bin2hex($ascii);
    }

    /**
     * Decode a hex asset name back to ASCII when printable; returns '' if
     * the bytes aren't printable ASCII (caller should fall back to showing
     * the raw hex). Keeps the UI consistent with the JS-side decoder.
     */
    public static function hexToAscii(string $hex): string {
        if ($hex === '' || strlen($hex) % 2 !== 0) return '';
        $bin = @hex2bin($hex);
        if ($bin === false) return '';
        for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
            $c = ord($bin[$i]);
            if ($c < 0x20 || $c > 0x7e) return '';
        }
        return $bin;
    }

    private static function shortHash(string $h): string {
        return strlen($h) > 14 ? substr($h, 0, 10) . '…' : $h;
    }
}
