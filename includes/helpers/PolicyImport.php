<?php
/**
 * PolicyImport — Import external minting policies (skey + script, mnemonic, or full manual).
 * Ported from cardano-auctions CA_Policy_Import.
 */
namespace CardanoMintPay\Helpers;

use CardanoMintPay\Models\MintModel;

if ( ! defined( 'ABSPATH' ) ) exit;

class PolicyImport {

    /**
     * Parse a signing key from Cardano CLI JSON format or raw hex.
     *
     * Accepts:
     *  - Cardano CLI JSON: {"type":"...","cborHex":"5820..."}
     *  - Raw hex string (64 or 128 chars)
     *
     * @param string $input  Raw hex or JSON string.
     * @return string|\WP_Error  Raw key hex (64 or 128 chars).
     */
    public static function parse_cli_skey( string $input ) {
        $input = trim( $input );

        // Try JSON first.
        if ( strpos( $input, '{' ) === 0 ) {
            $json = json_decode( $input, true );
            if ( ! $json || empty( $json['cborHex'] ) ) {
                return new \WP_Error( 'skey_parse', 'Invalid skey JSON — missing cborHex field.' );
            }
            $cbor_hex = $json['cborHex'];

            // Strip CBOR byte string prefix: 5820 (32 bytes) or 5840 (64 bytes).
            if ( strpos( $cbor_hex, '5820' ) === 0 ) {
                return substr( $cbor_hex, 4 ); // 64 hex chars (standard 32-byte key)
            }
            if ( strpos( $cbor_hex, '5840' ) === 0 ) {
                return substr( $cbor_hex, 4 ); // 128 hex chars (extended 64-byte key)
            }

            return new \WP_Error( 'skey_parse', 'Unexpected CBOR prefix in skey. Expected 5820 or 5840.' );
        }

        // Raw hex.
        if ( preg_match( '/^[0-9a-fA-F]+$/', $input ) && in_array( strlen( $input ), array( 64, 128 ), true ) ) {
            return strtolower( $input );
        }

        return new \WP_Error( 'skey_parse', 'Invalid signing key format. Provide Cardano CLI JSON or raw hex (64 or 128 chars).' );
    }

    /**
     * Derive keyhash from a signing key hex string.
     *
     * @param string $skey_hex  64 or 128 hex chars.
     * @return string|\WP_Error  56-char hex keyhash (blake2b-224 of pubkey).
     */
    public static function derive_keyhash( string $skey_hex ) {
        if ( strlen( $skey_hex ) === 128 ) {
            $kL  = hex2bin( substr( $skey_hex, 0, 64 ) );
            $pub = \Ed25519Compat::ge_scalarmult_base_noclamp( $kL );
        } elseif ( strlen( $skey_hex ) === 64 ) {
            $seed = hex2bin( $skey_hex );
            $kp   = sodium_crypto_sign_seed_keypair( $seed );
            $pub  = sodium_crypto_sign_publickey( $kp );
        } else {
            return new \WP_Error( 'key_invalid', 'Signing key must be 64 or 128 hex chars.' );
        }

        return bin2hex( sodium_crypto_generichash( $pub, '', 28 ) );
    }

    /**
     * Import an external policy using skey + native script JSON.
     *
     * @param string $name         Wallet name.
     * @param string $skey_input   Cardano CLI JSON or raw hex signing key.
     * @param string $script_input Native script JSON string.
     * @return array|\WP_Error     { id, policy_id, name, source }
     */
    public static function import_skey_and_script( string $name, string $skey_input, string $script_input ) {
        // Parse the signing key.
        $skey_hex = self::parse_cli_skey( $skey_input );
        if ( is_wp_error( $skey_hex ) ) return $skey_hex;

        // Parse the script.
        $script = json_decode( $script_input, true );
        if ( ! $script || empty( $script['type'] ) ) {
            return new \WP_Error( 'script_parse', 'Invalid native script JSON.' );
        }

        // Derive keyhash from skey and validate it matches the script.
        $derived_keyhash = self::derive_keyhash( $skey_hex );
        if ( is_wp_error( $derived_keyhash ) ) return $derived_keyhash;

        $script_keyhash = self::extract_keyhash_from_script( $script );
        if ( $script_keyhash && $script_keyhash !== $derived_keyhash ) {
            return new \WP_Error( 'key_script_mismatch', sprintf(
                'Signing key keyhash (%s) does not match the script keyHash (%s).',
                substr( $derived_keyhash, 0, 16 ) . '...',
                substr( $script_keyhash, 0, 16 ) . '...'
            ) );
        }

        // Serialize the script via Anvil to get the policy ID.
        $serialize_result = AnvilAPI::call( 'utils/native-scripts/serialize', $script, 'mint' );
        if ( is_wp_error( $serialize_result ) ) {
            return new \WP_Error( 'serialize_failed', 'Failed to serialize policy script: ' . $serialize_result->get_error_message() );
        }

        $policy_id = $serialize_result['policyId'] ?? '';
        if ( empty( $policy_id ) ) {
            return new \WP_Error( 'serialize_failed', 'Anvil returned no policy ID.' );
        }

        $script_cbor = $serialize_result['script'] ?? '';

        // Check for duplicate policy.
        $existing = MintModel::getMintPolicyByPolicyId( $policy_id );
        if ( $existing ) {
            return new \WP_Error( 'policy_exists', 'A policy with this ID already exists: ' . $existing['name'] );
        }

        // Encrypt the skey.
        $skey_encrypted = EncryptionHelper::encrypt( $skey_hex );
        if ( empty( $skey_encrypted ) ) {
            return new \WP_Error( 'encrypt_failed', 'Failed to encrypt signing key.' );
        }

        // Determine key type.
        $key_type = strlen( $skey_hex ) === 128 ? 'extended' : 'standard';

        $network = get_option( 'cardano-mint-networkenvironment', 'preprod' );

        // Extract expiration from script if present.
        $expiration_date = null;
        $expiration_slot = 0;
        $slot = self::extract_slot_from_script( $script );
        if ( $slot ) {
            $expiration_slot = (int) $slot;
            $time_result = AnvilAPI::call( 'utils/network/slot-to-time', array( 'slot' => (int) $slot ), 'mint' );
            if ( ! is_wp_error( $time_result ) && ! empty( $time_result['time'] ) ) {
                $expiration_date = gmdate( 'Y-m-d H:i:s', (int) ( $time_result['time'] / 1000 ) );
            }
        }

        // Store in mint policies table.
        $db_id = MintModel::insertMintPolicy( array(
            'name'               => sanitize_text_field( $name ),
            'policy_id'          => $policy_id,
            'policy_schema'      => wp_json_encode( $script ),
            'policy_script_cbor' => $script_cbor,
            'skey_encrypted'     => $skey_encrypted,
            'key_type'           => $key_type,
            'source'             => 'imported_skey',
            'expiration_date'    => $expiration_date,
            'expiration_slot'    => $expiration_slot,
            'network'            => $network,
        ) );

        if ( ! $db_id ) {
            return new \WP_Error( 'insert_failed', 'Failed to store imported policy in database.' );
        }

        return array(
            'id'        => $db_id,
            'policy_id' => $policy_id,
            'keyhash'   => $derived_keyhash,
            'name'      => $name,
            'source'    => 'imported_skey',
        );
    }

    /**
     * Import from a mnemonic seed phrase.
     * Derives the wallet, builds a native script, serializes via Anvil.
     * The mnemonic is NOT stored — only the derived signing key.
     *
     * @param string $name            Wallet name.
     * @param string $mnemonic        BIP-39 mnemonic (12, 15, or 24 words).
     * @param string $expiration_date Optional expiration (Y-m-d\TH:i).
     * @return array|\WP_Error        { id, policy_id, name, source }
     */
    public static function import_mnemonic( string $name, string $mnemonic, string $expiration_date = '' ) {
        $mnemonic = trim( $mnemonic );
        $word_count = count( explode( ' ', $mnemonic ) );
        if ( ! in_array( $word_count, array( 12, 15, 24 ), true ) ) {
            return new \WP_Error( 'mnemonic_invalid', 'Mnemonic must be 12, 15, or 24 words.' );
        }

        $network = get_option( 'cardano-mint-networkenvironment', 'preprod' );

        // Derive wallet from mnemonic.
        try {
            $wallet = CardanoWalletPHP::fromMnemonic( $mnemonic, '', $network, 0 );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'mnemonic_derive', 'Failed to derive wallet from mnemonic: ' . $e->getMessage() );
        }

        $skey_hex = $wallet['payment_skey_extended'] ?? '';
        $keyhash  = $wallet['payment_keyhash'] ?? '';
        $payment_address = $wallet['addresses']['payment_address'] ?? '';
        $stake_address   = $wallet['addresses']['stake_address'] ?? '';

        if ( empty( $skey_hex ) || empty( $keyhash ) ) {
            return new \WP_Error( 'mnemonic_derive', 'Wallet derivation returned incomplete data.' );
        }

        // Check for duplicate keyhash.
        $existing = MintModel::getPolicyWalletByKeyhash( $keyhash );
        if ( $existing ) {
            return new \WP_Error( 'wallet_exists', 'A wallet with this keyhash already exists: ' . $existing['wallet_name'] );
        }

        // Default expiration: 1 year from now.
        if ( empty( $expiration_date ) ) {
            $expiration_date = gmdate( 'Y-m-d\TH:i', strtotime( '+1 year' ) );
        }

        // Convert expiration to slot via Anvil.
        $exp_ms = strtotime( $expiration_date ) * 1000;
        $slot_result = AnvilAPI::call( 'utils/network/time-to-slot', array( 'time' => $exp_ms ), 'mint' );
        if ( is_wp_error( $slot_result ) ) {
            return new \WP_Error( 'slot_conversion_failed', 'Failed to convert time to slot: ' . $slot_result->get_error_message() );
        }

        $expiration_slot = $slot_result['slot'] ?? 0;
        if ( ! $expiration_slot ) {
            return new \WP_Error( 'slot_conversion_failed', 'Anvil returned no slot number.' );
        }

        // Build native script.
        $script = array(
            'type'    => 'all',
            'scripts' => array(
                array( 'type' => 'sig', 'keyHash' => $keyhash ),
                array( 'type' => 'before', 'slot' => (int) $expiration_slot ),
            ),
        );

        // Serialize via Anvil.
        $serialize_result = AnvilAPI::call( 'utils/native-scripts/serialize', $script, 'mint' );
        if ( is_wp_error( $serialize_result ) ) {
            return new \WP_Error( 'serialize_failed', 'Failed to serialize policy script: ' . $serialize_result->get_error_message() );
        }

        $policy_id = $serialize_result['policyId'] ?? '';
        if ( empty( $policy_id ) ) {
            return new \WP_Error( 'serialize_failed', 'Anvil returned no policy ID.' );
        }

        // Encrypt signing key (mnemonic is NOT stored).
        $skey_encrypted = EncryptionHelper::encrypt( $skey_hex );
        if ( empty( $skey_encrypted ) ) {
            return new \WP_Error( 'encrypt_failed', 'Failed to encrypt signing key.' );
        }

        $db_id = MintModel::insertPolicyWallet( array(
            'wallet_name'        => sanitize_text_field( $name ),
            'mnemonic_encrypted' => null,
            'skey_encrypted'     => $skey_encrypted,
            'payment_address'    => $payment_address,
            'payment_keyhash'    => $keyhash,
            'stake_address'      => $stake_address,
            'network'            => $network,
            'source'             => 'imported_seed',
        ) );

        if ( ! $db_id ) {
            return new \WP_Error( 'insert_failed', 'Failed to store imported wallet in database.' );
        }

        return array(
            'id'              => $db_id,
            'policy_id'       => $policy_id,
            'keyhash'         => $keyhash,
            'name'            => $name,
            'source'          => 'imported_seed',
            'expiration_date' => $expiration_date,
        );
    }

    /**
     * Full manual import: policy ID + script + skey all provided.
     *
     * @param string $name         Wallet name.
     * @param string $policy_id    56-char hex policy ID.
     * @param string $skey_input   Cardano CLI JSON or raw hex signing key.
     * @param string $script_input Native script JSON.
     * @return array|\WP_Error     { id, policy_id, name, source }
     */
    public static function import_manual( string $name, string $policy_id, string $skey_input, string $script_input ) {
        $policy_id = trim( strtolower( $policy_id ) );
        if ( ! preg_match( '/^[0-9a-f]{56}$/', $policy_id ) ) {
            return new \WP_Error( 'policy_id_invalid', 'Policy ID must be exactly 56 hex characters.' );
        }

        // Parse skey.
        $skey_hex = self::parse_cli_skey( $skey_input );
        if ( is_wp_error( $skey_hex ) ) return $skey_hex;

        // Parse script.
        $script = json_decode( $script_input, true );
        if ( ! $script || empty( $script['type'] ) ) {
            return new \WP_Error( 'script_parse', 'Invalid native script JSON.' );
        }

        // Verify policy ID matches by serializing the script.
        $serialize_result = AnvilAPI::call( 'utils/native-scripts/serialize', $script, 'mint' );
        if ( ! is_wp_error( $serialize_result ) ) {
            $derived_policy_id = $serialize_result['policyId'] ?? '';
            if ( $derived_policy_id && $derived_policy_id !== $policy_id ) {
                return new \WP_Error( 'policy_mismatch', sprintf(
                    'Provided policy ID (%s) does not match the script (derives %s).',
                    substr( $policy_id, 0, 16 ) . '...',
                    substr( $derived_policy_id, 0, 16 ) . '...'
                ) );
            }
        }

        $script_cbor = ( ! is_wp_error( $serialize_result ) ) ? ( $serialize_result['script'] ?? '' ) : '';

        // Check for duplicate policy.
        $existing = MintModel::getMintPolicyByPolicyId( $policy_id );
        if ( $existing ) {
            return new \WP_Error( 'policy_exists', 'A policy with this ID already exists: ' . $existing['name'] );
        }

        // Derive keyhash from skey.
        $derived_keyhash = self::derive_keyhash( $skey_hex );
        if ( is_wp_error( $derived_keyhash ) ) return $derived_keyhash;

        // Encrypt skey.
        $skey_encrypted = EncryptionHelper::encrypt( $skey_hex );
        if ( empty( $skey_encrypted ) ) {
            return new \WP_Error( 'encrypt_failed', 'Failed to encrypt signing key.' );
        }

        $key_type = strlen( $skey_hex ) === 128 ? 'extended' : 'standard';
        $network = get_option( 'cardano-mint-networkenvironment', 'preprod' );

        // Extract expiration from script.
        $expiration_date = null;
        $expiration_slot = 0;
        $slot = self::extract_slot_from_script( $script );
        if ( $slot ) {
            $expiration_slot = (int) $slot;
            $time_result = AnvilAPI::call( 'utils/network/slot-to-time', array( 'slot' => (int) $slot ), 'mint' );
            if ( ! is_wp_error( $time_result ) && ! empty( $time_result['time'] ) ) {
                $expiration_date = gmdate( 'Y-m-d H:i:s', (int) ( $time_result['time'] / 1000 ) );
            }
        }

        // Store in mint policies table.
        $db_id = MintModel::insertMintPolicy( array(
            'name'               => sanitize_text_field( $name ),
            'policy_id'          => $policy_id,
            'policy_schema'      => wp_json_encode( $script ),
            'policy_script_cbor' => $script_cbor,
            'skey_encrypted'     => $skey_encrypted,
            'key_type'           => $key_type,
            'source'             => 'imported_manual',
            'expiration_date'    => $expiration_date,
            'expiration_slot'    => $expiration_slot,
            'network'            => $network,
        ) );

        if ( ! $db_id ) {
            return new \WP_Error( 'insert_failed', 'Failed to store imported policy in database.' );
        }

        return array(
            'id'        => $db_id,
            'policy_id' => $policy_id,
            'keyhash'   => $derived_keyhash,
            'name'      => $name,
            'source'    => 'imported_manual',
        );
    }

    /**
     * Extract the keyHash from a native script's sig requirement.
     * Walks nested scripts to find the first sig entry.
     */
    private static function extract_keyhash_from_script( array $script ): ?string {
        if ( ( $script['type'] ?? '' ) === 'sig' && ! empty( $script['keyHash'] ) ) {
            return $script['keyHash'];
        }

        if ( ! empty( $script['scripts'] ) && is_array( $script['scripts'] ) ) {
            foreach ( $script['scripts'] as $sub ) {
                $found = self::extract_keyhash_from_script( $sub );
                if ( $found ) return $found;
            }
        }

        return null;
    }

    /**
     * Extract the slot number from a native script's "before" constraint.
     */
    private static function extract_slot_from_script( array $script ): ?int {
        if ( ( $script['type'] ?? '' ) === 'before' && isset( $script['slot'] ) ) {
            return (int) $script['slot'];
        }

        if ( ! empty( $script['scripts'] ) && is_array( $script['scripts'] ) ) {
            foreach ( $script['scripts'] as $sub ) {
                $found = self::extract_slot_from_script( $sub );
                if ( $found ) return $found;
            }
        }

        return null;
    }
}
