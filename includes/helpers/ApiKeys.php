<?php
/**
 * ApiKeys — Generate, validate, and revoke API keys for the minting widget.
 * Keys are SHA-256 hashed at rest and stored encrypted via EncryptionHelper.
 * Ported from cardano-auctions CA_Api_Keys.
 */
namespace CardanoMintPay\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class ApiKeys {

    const OPTION = 'cardano_mint_widget_api_keys';

    /**
     * Generate a new API key. Returns the full key (shown once).
     */
    public static function generate( string $label, array $allowed_origins = array(), array $mint_ids = array() ): array {
        $raw_key  = 'cmk_' . wp_generate_password( 40, false );
        $key_hash = hash( 'sha256', $raw_key );
        $id       = substr( $key_hash, 0, 8 );

        $record = array(
            'id'              => $id,
            'key_hash'        => $key_hash,
            'key_prefix'      => substr( $raw_key, 0, 12 ),
            'label'           => sanitize_text_field( $label ),
            'allowed_origins' => array_map( 'esc_url_raw', array_filter( $allowed_origins ) ),
            'mint_ids'        => array_map( 'intval', array_filter( $mint_ids ) ),
            'created_at'      => current_time( 'mysql' ),
            'last_used'       => null,
        );

        $keys = self::get_all();
        $keys[] = $record;
        self::save_all( $keys );

        return array(
            'id'      => $id,
            'key'     => $raw_key,
            'label'   => $record['label'],
            'prefix'  => $record['key_prefix'],
        );
    }

    /**
     * Revoke a key by its ID (first 8 chars of hash).
     */
    public static function revoke( string $id ): bool {
        $keys    = self::get_all();
        $updated = array();
        $found   = false;

        foreach ( $keys as $k ) {
            if ( $k['id'] === $id ) {
                $found = true;
                continue;
            }
            $updated[] = $k;
        }

        if ( $found ) {
            self::save_all( $updated );
        }
        return $found;
    }

    /**
     * Validate a raw API key. Returns the key record or false.
     */
    public static function validate( string $raw_key ) {
        if ( strpos( $raw_key, 'cmk_' ) !== 0 ) return false;

        $hash = hash( 'sha256', $raw_key );
        $keys = self::get_all();

        foreach ( $keys as $k ) {
            if ( hash_equals( $k['key_hash'], $hash ) ) {
                return $k;
            }
        }
        return false;
    }

    /**
     * Check if an origin is allowed for a given key record.
     */
    public static function is_origin_allowed( array $key_record, string $origin ): bool {
        if ( empty( $key_record['allowed_origins'] ) ) return true;
        return in_array( rtrim( $origin, '/' ), array_map( function( $o ) { return rtrim( $o, '/' ); }, $key_record['allowed_origins'] ), true );
    }

    /**
     * Check if a mint/collection ID is allowed for a given key record.
     */
    public static function is_mint_allowed( array $key_record, int $mint_id ): bool {
        if ( empty( $key_record['mint_ids'] ) ) return true;
        return in_array( $mint_id, $key_record['mint_ids'], true );
    }

    /**
     * Update last_used timestamp.
     */
    public static function touch( string $id ): void {
        $keys = self::get_all();
        foreach ( $keys as &$k ) {
            if ( $k['id'] === $id ) {
                $k['last_used'] = current_time( 'mysql' );
                break;
            }
        }
        unset( $k );
        self::save_all( $keys );
    }

    /**
     * Get all stored key records (decrypted).
     */
    public static function get_all(): array {
        $encrypted = get_option( self::OPTION, '' );
        if ( empty( $encrypted ) ) return array();

        $json = EncryptionHelper::decrypt( $encrypted );
        if ( empty( $json ) ) return array();

        $keys = json_decode( $json, true );
        return is_array( $keys ) ? $keys : array();
    }

    /**
     * Save all key records (encrypted).
     */
    private static function save_all( array $keys ): void {
        $json      = wp_json_encode( $keys );
        $encrypted = EncryptionHelper::encrypt( $json );
        update_option( self::OPTION, $encrypted, false );
    }
}
