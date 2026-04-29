<?php
/**
 * RestApiController — REST API for the minting widget.
 * Provides collection data, transaction proxy, and CORS support for cross-origin embedding.
 * Ported from cardano-auctions CA_REST_API.
 */
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Helpers\AnvilAPI;
use CardanoMintPay\Helpers\ApiKeys;

if ( ! defined( 'ABSPATH' ) ) exit;

class RestApiController {

    const API_NAMESPACE = 'cardano-mint/v1';
    const API_KEY_HEADER = 'X-CM-Api-Key';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );
        add_action( 'rest_api_init', array( __CLASS__, 'handle_preflight' ), 5 );
    }

    /**
     * CORS: add headers when an API key is present and origin is allowed.
     */
    public static function add_cors_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( strpos( $route, '/' . self::API_NAMESPACE ) !== 0 ) return $served;

        $api_key_raw = $request->get_header( self::API_KEY_HEADER );
        if ( empty( $api_key_raw ) ) return $served;

        $key_record = ApiKeys::validate( $api_key_raw );
        if ( ! $key_record ) return $served;

        $origin = $request->get_header( 'Origin' );
        if ( empty( $origin ) ) return $served;

        if ( ! ApiKeys::is_origin_allowed( $key_record, $origin ) ) return $served;

        header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, ' . self::API_KEY_HEADER );
        header( 'Access-Control-Max-Age: 86400' );

        return $served;
    }

    /**
     * Handle OPTIONS preflight requests.
     */
    public static function handle_preflight() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'OPTIONS' ) return;

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $uri, 'cardano-mint/v1' ) === false ) return;

        $origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
        $api_key = isset( $_SERVER['HTTP_X_CM_API_KEY'] ) ? $_SERVER['HTTP_X_CM_API_KEY'] : '';

        if ( ! empty( $api_key ) && ! empty( $origin ) ) {
            $key_record = ApiKeys::validate( $api_key );
            if ( $key_record && ApiKeys::is_origin_allowed( $key_record, $origin ) ) {
                header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
                header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
                header( 'Access-Control-Allow-Headers: Content-Type, ' . self::API_KEY_HEADER );
                header( 'Access-Control-Max-Age: 86400' );
                header( 'Content-Length: 0' );
                header( 'Content-Type: text/plain' );
                status_header( 200 );
                exit;
            }
        }
    }

    /**
     * Dual auth: WP nonce OR valid API key.
     */
    public static function verify_auth( \WP_REST_Request $request ) {
        if ( wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
            return true;
        }

        $api_key_raw = $request->get_header( self::API_KEY_HEADER );
        if ( ! empty( $api_key_raw ) ) {
            $key_record = ApiKeys::validate( $api_key_raw );
            if ( $key_record ) {
                ApiKeys::touch( $key_record['id'] );
                return true;
            }
        }

        return new \WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 403 ) );
    }

    public static function register() {
        // Public endpoints.
        register_rest_route( self::API_NAMESPACE, '/collections', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'list_collections' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/collections/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_collection' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/config', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_config' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/price', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_price' ),
            'permission_callback' => '__return_true',
        ) );

        // Authenticated endpoints (API key required for external widget usage).
        register_rest_route( self::API_NAMESPACE, '/mint/build', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'mint_build' ),
            'permission_callback' => array( __CLASS__, 'verify_auth' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/mint/submit', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'mint_submit' ),
            'permission_callback' => array( __CLASS__, 'verify_auth' ),
        ) );
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    public static function list_collections( \WP_REST_Request $request ): \WP_REST_Response {
        $policies = MintModel::getAllPolicies();
        $ada_price = AnvilAPI::getAdaPrice();

        $collections = array();
        foreach ( $policies as $policy ) {
            $variants = MintModel::getAssetsByCollectionId( $policy['collection_id'] ?? $policy['id'] );
            $collections[] = self::format_collection( $policy, $variants, $ada_price );
        }

        return new \WP_REST_Response( array( 'collections' => $collections ) );
    }

    public static function get_collection( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];
        $assets = MintModel::getAssetsByCollectionId( $id );

        if ( empty( $assets ) ) {
            return new \WP_REST_Response( array( 'error' => 'Collection not found.' ), 404 );
        }

        $ada_price = AnvilAPI::getAdaPrice();
        $primary = $assets[0];

        return new \WP_REST_Response( self::format_collection( $primary, $assets, $ada_price ) );
    }

    public static function get_config(): \WP_REST_Response {
        $network = get_option( 'cardano-mint-networkenvironment', 'preprod' );
        return new \WP_REST_Response( array(
            'network'   => $network,
            'site_name' => get_bloginfo( 'name' ),
        ) );
    }

    public static function get_price(): \WP_REST_Response {
        return new \WP_REST_Response( array(
            'ada_usd' => AnvilAPI::getAdaPrice(),
        ) );
    }

    /**
     * Build a minting transaction via Anvil (proxy).
     * Keeps Anvil API key server-side.
     */
    public static function mint_build( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();

        $collection_id    = (int) ( $params['collection_id'] ?? 0 );
        $variant          = sanitize_text_field( $params['variant'] ?? '' );
        $customer_address = sanitize_text_field( $params['customer_address'] ?? '' );

        if ( ! $collection_id || ! $customer_address ) {
            return new \WP_REST_Response( array( 'error' => 'collection_id and customer_address are required.' ), 400 );
        }

        // Look up the mint data.
        $asset = null;
        if ( $variant ) {
            $asset = MintModel::getAssetByCollectionAndVariant( $collection_id, $variant );
        } else {
            // Random weighted selection.
            $asset = MintModel::selectWeightedRandomAsset( $collection_id );
        }

        if ( ! $asset ) {
            return new \WP_REST_Response( array( 'error' => 'Mint not found or sold out.' ), 404 );
        }

        // Check quantity remaining.
        $remaining = ( (int) $asset['quantity_total'] ) - ( (int) $asset['quantity_minted'] );
        if ( $remaining <= 0 ) {
            return new \WP_REST_Response( array( 'error' => 'This mint is sold out.' ), 400 );
        }

        $merchant_address = get_option( 'cardano_mint_merchant_address', '' );
        if ( empty( $merchant_address ) ) {
            return new \WP_REST_Response( array( 'error' => 'Merchant address not configured.' ), 500 );
        }

        $usd_price = floatval( $asset['price'] ?? 0 );
        $policy_id = $asset['policyid'] ?? '';

        if ( ! $policy_id ) {
            return new \WP_REST_Response( array( 'error' => 'No policy ID for this mint.' ), 500 );
        }

        // Build mint transaction via Anvil.
        $result = AnvilAPI::buildMintTransaction(
            $merchant_address,
            $customer_address,
            $usd_price,
            $policy_id,
            'mint',
            $asset
        );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 502 );
        }

        // Echo back identifiers so the client can pass them to /mint/submit
        // for post-mint accounting (decrement quantity, record per-wallet mint).
        if ( is_array( $result ) ) {
            $result['asset_id']     = (int) $asset['id'];
            $result['collection_id'] = (int) ( $asset['collection_id'] ?? $collection_id );
            $result['policy_id']    = $policy_id;
        }

        return new \WP_REST_Response( $result );
    }

    /**
     * Submit a signed minting transaction via Anvil (proxy).
     * Adds policy wallet signature server-side.
     */
    public static function mint_submit( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();

        $transaction     = $params['transaction'] ?? '';
        $witnesses       = $params['witnesses'] ?? array();
        $policy_id       = sanitize_text_field( $params['policy_id'] ?? '' );
        $asset_id        = (int) ( $params['asset_id'] ?? 0 );
        $wallet_address  = sanitize_text_field( $params['wallet_address'] ?? '' );

        if ( empty( $transaction ) ) {
            return new \WP_REST_Response( array( 'error' => 'Transaction data is required.' ), 400 );
        }

        if ( ! is_array( $witnesses ) ) {
            $witnesses = array( $witnesses );
        }

        // Submit via Anvil — this adds the policy wallet signature server-side.
        // Pass policy_id for imported policy skey override.
        $result = AnvilAPI::submitTransaction( $transaction, $witnesses, 'mint', $policy_id );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 502 );
        }

        // Post-mint accounting: only run when Anvil confirms a txHash.
        $tx_hash = is_array( $result ) ? ( $result['txHash'] ?? $result['tx_hash'] ?? $result['hash'] ?? '' ) : '';
        if ( $tx_hash && $asset_id > 0 ) {
            $decremented = MintModel::decrementQuantity( $asset_id );
            if ( $decremented ) {
                error_log( '[CardanoMint] REST: decremented quantity for asset ID ' . $asset_id );
            } else {
                error_log( '[CardanoMint] REST: WARNING failed to decrement quantity for asset ID ' . $asset_id );
            }

            if ( $policy_id ) {
                MintModel::markRoyaltyTokenMinted( $policy_id );
            }

            if ( $wallet_address && $policy_id ) {
                $mint_data     = MintModel::getMintById( $asset_id );
                $mints_allowed = intval( $mint_data['mintsallowedperwallet'] ?? 0 );
                MintModel::recordMint( $policy_id, $wallet_address, null, $mints_allowed );
                MintModel::incrementMintCount( $policy_id, $wallet_address );
            }
        }

        return new \WP_REST_Response( $result );
    }

    /* ── Formatting ───────────────────────────────────────────────── */

    private static function format_collection( array $primary, array $variants, float $ada_price ): array {
        $usd_price = floatval( $primary['price'] ?? 0 );
        $ada_amount = $ada_price > 0 ? $usd_price / $ada_price : 0;

        // Resolve image URL.
        $image_url = '';
        if ( ! empty( $primary['ipfs_cid_manual'] ) ) {
            $image_url = 'ipfs://' . $primary['ipfs_cid_manual'];
        } elseif ( ! empty( $primary['ipfs_cid'] ) ) {
            $image_url = 'ipfs://' . $primary['ipfs_cid'];
        } elseif ( ! empty( $primary['image_id'] ) ) {
            $image_url = wp_get_attachment_url( $primary['image_id'] ) ?: '';
        }

        // Collection image (mystery box / hero).
        $collection_image = '';
        if ( ! empty( $primary['collection_image_id'] ) ) {
            $collection_image = wp_get_attachment_url( $primary['collection_image_id'] ) ?: '';
        }

        // Format variants.
        $formatted_variants = array();
        foreach ( $variants as $v ) {
            $v_image = '';
            if ( ! empty( $v['image_id'] ) ) {
                $v_image = wp_get_attachment_url( $v['image_id'] ) ?: '';
            }

            $formatted_variants[] = array(
                'variant'          => $v['variant'] ?? '',
                'title'            => $v['title'] ?? '',
                'price_usd'        => floatval( $v['price'] ?? 0 ),
                'price_ada'        => $ada_price > 0 ? round( floatval( $v['price'] ?? 0 ) / $ada_price, 2 ) : 0,
                'image_url'        => $v_image,
                'rarity_weight'    => floatval( $v['rarity_weight'] ?? 0 ),
                'quantity_total'   => (int) ( $v['quantity_total'] ?? 0 ),
                'quantity_minted'  => (int) ( $v['quantity_minted'] ?? 0 ),
                'quantity_remaining' => max( 0, (int) ( $v['quantity_total'] ?? 0 ) - (int) ( $v['quantity_minted'] ?? 0 ) ),
                'status'           => $v['status'] ?? 'Active',
            );
        }

        // Total supply across all variants.
        $total_supply = 0;
        $total_minted = 0;
        foreach ( $variants as $v ) {
            $total_supply += (int) ( $v['quantity_total'] ?? 0 );
            $total_minted += (int) ( $v['quantity_minted'] ?? 0 );
        }

        return array(
            'collection_id'      => (int) ( $primary['collection_id'] ?? $primary['id'] ),
            'title'              => $primary['title'] ?? '',
            'policy_id'          => $primary['policyid'] ?? '',
            'price_usd'          => $usd_price,
            'price_ada'          => round( $ada_amount, 2 ),
            'image_url'          => $image_url,
            'collection_image'   => $collection_image ?: $image_url,
            'expiration_date'    => $primary['expirationdate'] ?? '',
            'quantity_total'     => $total_supply,
            'quantity_minted'    => $total_minted,
            'quantity_remaining' => max( 0, $total_supply - $total_minted ),
            'royalty'            => floatval( $primary['royalty'] ?? 0 ),
            'royalty_address'    => $primary['royaltyaddress'] ?? '',
            'nft_metadata'       => $primary['nft_metadata'] ?? '',
            'variants'           => $formatted_variants,
            'status'             => $primary['status'] ?? 'Active',
        );
    }
}
