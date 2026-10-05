<?php
/**
 * RestApiController — REST API for the minting widget.
 * Provides collection data, transaction proxy, and CORS support for cross-origin embedding.
 * Ported from cardano-auctions CA_REST_API.
 */
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\Helpers\AnvilAPI;
use CardanoMintPay\Helpers\ApiKeys;
use CardanoMintPay\Helpers\MintBuildRegistry;
use CardanoMintPay\AltPay\AltPayService;

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

        // Alt-chain payment endpoints. Public reads (status/quote) so the
        // unauthenticated mint widget can poll without a nonce; cancel and
        // quote both validate the mint exists, so abuse is bounded.
        register_rest_route( self::API_NAMESPACE, '/altpay/quote', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'altpay_quote' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::API_NAMESPACE, '/altpay/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'altpay_status' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::API_NAMESPACE, '/altpay/cancel', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'altpay_cancel' ),
            'permission_callback' => '__return_true',
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
        $invoice_id       = (int) ( $params['invoice_id'] ?? 0 );

        if ( ! $collection_id || ! $customer_address ) {
            return new \WP_REST_Response( array( 'error' => 'collection_id and customer_address are required.' ), 400 );
        }

        // Network gate: see ajaxBuildMintTransaction for rationale. Mismatched
        // networks orphan alt-pay invoices and produce txs the wallet cannot sign.
        $site_anvil_url = strtolower( (string) get_option( 'cardano_mint_anvil_api_url', '' ) );
        $site_is_mainnet = ( strpos( $site_anvil_url, 'preprod' ) === false && strpos( $site_anvil_url, 'preview' ) === false && strpos( $site_anvil_url, 'sancho' ) === false );
        $addr_is_testnet = ( stripos( $customer_address, 'addr_test1' ) === 0 );
        if ( $site_is_mainnet === $addr_is_testnet ) {
            return new \WP_REST_Response( array(
                'error' => 'Wallet network mismatch: your wallet is on '
                    . ( $addr_is_testnet ? 'Testnet' : 'Mainnet' )
                    . ' but this site is on '
                    . ( $site_is_mainnet ? 'Mainnet' : 'Pre-production' )
                    . '. Switch your wallet network and reconnect.'
            ), 400 );
        }

        // Alt-paid path: validate the invoice is funded and bound to this
        // customer / mint before we spend the policy wallet's signature on
        // the cheaper service-fee transaction.
        $altpay_invoice = null;
        if ( $invoice_id > 0 ) {
            $altpay_invoice = ChainInvoiceModel::get( $invoice_id );
            if ( ! $altpay_invoice ) {
                return new \WP_REST_Response( array( 'error' => 'Alt-pay invoice not found.' ), 404 );
            }
            if ( $altpay_invoice['status'] !== 'funded' ) {
                return new \WP_REST_Response( array( 'error' => 'Alt-pay invoice is not funded yet (status: ' . $altpay_invoice['status'] . ').' ), 400 );
            }
            if ( $altpay_invoice['customer_cardano_address'] !== $customer_address ) {
                return new \WP_REST_Response( array( 'error' => 'Alt-pay invoice does not belong to this Cardano address.' ), 403 );
            }
        }

        // Look up the mint data. An alt-pay invoice was issued for one specific
        // asset, so it decides the asset; it must sit in the requested collection.
        $asset = null;
        if ( $altpay_invoice ) {
            $asset = MintModel::getMintById( (int) $altpay_invoice['mint_id'] );
            if ( $asset && (int) ( $asset['collection_id'] ?? 0 ) !== $collection_id ) {
                return new \WP_REST_Response( array( 'error' => 'Alt-pay invoice was issued for a different mint.' ), 400 );
            }
        } elseif ( $variant ) {
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

        // For an alt-paid mint, override the merchant lovelace output with a
        // flat service fee. The customer's wallet still has to fund this and
        // sign, but the bulk of the payment already happened on the alt
        // chain. AnvilAPI honors `_altpay_service_fee_ada_override` on the
        // mint_data payload.
        if ( $altpay_invoice ) {
            $service_fee_ada = (float) get_option( 'cardano_mint_service_fee_ada', 5 );
            if ( $service_fee_ada < 2 )  $service_fee_ada = 2;
            if ( $service_fee_ada > 20 ) $service_fee_ada = 20;
            $asset['_altpay_service_fee_ada_override'] = $service_fee_ada;
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

        // Remember exactly what we built; /mint/submit only co-signs this tx
        // and takes its accounting from this record.
        $remembered = MintBuildRegistry::rememberBuild( $result, array(
            'kind'       => 'mint',
            'policy_id'  => $policy_id,
            'asset_id'   => (int) $asset['id'],
            'quantity'   => 1,
            'wallet'     => $customer_address,
            'invoice_id' => $altpay_invoice ? (int) $altpay_invoice['id'] : 0,
        ) );
        if ( ! $remembered ) {
            return new \WP_REST_Response( array( 'error' => 'Could not register the built transaction. Please try again.' ), 502 );
        }

        // Echo back identifiers for the client's own display/bookkeeping.
        // /mint/submit ignores them in favour of the build record.
        if ( is_array( $result ) ) {
            $result['asset_id']     = (int) $asset['id'];
            $result['collection_id'] = (int) ( $asset['collection_id'] ?? $collection_id );
            $result['policy_id']    = $policy_id;
            if ( $altpay_invoice ) {
                $result['invoice_id']   = (int) $altpay_invoice['id'];
                $result['payment_mode'] = 'altpay';
            }
        }

        return new \WP_REST_Response( $result );
    }

    /**
     * Submit a signed minting transaction via Anvil (proxy).
     * Adds policy wallet signature server-side.
     */
    public static function mint_submit( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();

        $transaction     = is_string( $params['transaction'] ?? null ) ? $params['transaction'] : '';
        $witnesses       = $params['witnesses'] ?? array();

        if ( empty( $transaction ) ) {
            return new \WP_REST_Response( array( 'error' => 'Transaction data is required.' ), 400 );
        }

        if ( ! is_array( $witnesses ) ) {
            $witnesses = array( $witnesses );
        }

        // Only a tx /mint/build produced gets the policy signature, and only
        // once. Accounting comes from the build record, not from the request.
        $build = MintBuildRegistry::claim( $transaction );
        if ( $build && ( $build['kind'] ?? '' ) !== 'mint' ) {
            MintBuildRegistry::release( $build );
            $build = null;
        }
        if ( ! $build ) {
            return new \WP_REST_Response( array( 'error' => 'This mint session expired or was already submitted. Please start again.' ), 409 );
        }
        $policy_id      = (string) $build['policy_id'];
        $asset_id       = (int) $build['asset_id'];
        $wallet_address = (string) $build['wallet'];
        $invoice_id     = (int) ( $build['invoice_id'] ?? 0 );

        // Spend the alt-pay invoice before submitting; restore it on failure.
        if ( $invoice_id > 0 && ! ChainInvoiceModel::consume_if_funded( $invoice_id ) ) {
            MintBuildRegistry::release( $build );
            return new \WP_REST_Response( array( 'error' => 'This alt-pay invoice has already been used.' ), 409 );
        }

        // Submit via Anvil — this adds the policy wallet signature server-side.
        $result = AnvilAPI::submitTransaction( $transaction, $witnesses, 'mint', $policy_id, $build );

        if ( is_wp_error( $result ) ) {
            MintBuildRegistry::release( $build );
            if ( $invoice_id > 0 ) {
                ChainInvoiceModel::set_status( $invoice_id, 'funded' );
            }
            return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 502 );
        }

        // Post-mint accounting: only run when Anvil confirms a txHash.
        $tx_hash = is_array( $result ) ? ( $result['txHash'] ?? $result['tx_hash'] ?? $result['hash'] ?? '' ) : '';
        if ( $tx_hash && $asset_id > 0 ) {
            $decremented = MintModel::decrementQuantity( $asset_id );
            if ( $decremented ) {
                cardanomint_log( '[CardanoMint] REST: decremented quantity for asset ID ' . $asset_id );
            } else {
                cardanomint_log( '[CardanoMint] REST: WARNING failed to decrement quantity for asset ID ' . $asset_id , 'error');
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

            if ( $invoice_id > 0 ) {
                cardanomint_log( '[CardanoMint AltPay] invoice ' . $invoice_id . ' consumed (tx ' . $tx_hash . ')' );
            }
        }

        return new \WP_REST_Response( $result );
    }

    /* ── Alt-chain payment endpoints ─────────────────────────────── */

    /**
     * Issue a new payment intent for a mint on a non-Cardano chain.
     * Accepts {mint_id, payment_method, customer_cardano_address}.
     */
    public static function altpay_quote( \WP_REST_Request $request ): \WP_REST_Response {
        if ( get_option( 'cardano_mint_altpay_enabled', '0' ) !== '1' ) {
            return new \WP_REST_Response( array( 'error' => 'Alt-chain payments are disabled.' ), 403 );
        }

        $params  = $request->get_json_params() ?: array();
        $mint_id = (int) ( $params['mint_id'] ?? 0 );
        $chain   = sanitize_key( $params['payment_method'] ?? '' );
        $caddr   = sanitize_text_field( $params['customer_cardano_address'] ?? '' );

        if ( ! $mint_id || ! $chain || ! $caddr ) {
            return new \WP_REST_Response( array( 'error' => 'mint_id, payment_method, and customer_cardano_address are required.' ), 400 );
        }

        // Soft per-IP rate limit. Each quote burns an HD index, so this also
        // bounds address-graph enumeration cost.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
        $rl_key = 'cm_altpay_quote_rl_' . md5( $ip );
        $rl_ct  = (int) get_transient( $rl_key );
        if ( $rl_ct >= 5 ) {
            return new \WP_REST_Response( array( 'error' => 'Too many quote requests, slow down.' ), 429 );
        }
        set_transient( $rl_key, $rl_ct + 1, 60 );

        $discount_code = strtoupper( trim( (string) ( $params['discount_code'] ?? '' ) ) );
        $res = AltPayService::quote( $mint_id, $chain, $caddr, $discount_code );
        if ( is_wp_error( $res ) ) {
            return new \WP_REST_Response( array( 'error' => $res->get_error_message() ), 400 );
        }
        return new \WP_REST_Response( $res );
    }

    public static function altpay_status( \WP_REST_Request $request ): \WP_REST_Response {
        $invoice_id = (int) $request->get_param( 'invoice_id' );
        if ( $invoice_id <= 0 ) {
            return new \WP_REST_Response( array( 'error' => 'invoice_id required.' ), 400 );
        }
        return new \WP_REST_Response( AltPayService::status( $invoice_id ) );
    }

    public static function altpay_cancel( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params() ?: array();
        $invoice_id = (int) ( $params['invoice_id'] ?? 0 );
        if ( $invoice_id <= 0 ) {
            return new \WP_REST_Response( array( 'error' => 'invoice_id required.' ), 400 );
        }
        $ok = AltPayService::cancel( $invoice_id );
        return new \WP_REST_Response( array( 'cancelled' => (bool) $ok ) );
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
