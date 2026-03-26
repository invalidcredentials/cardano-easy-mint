<?php
/**
 * WidgetAdminController — Admin page and AJAX handlers for the widget deployer.
 * Ported from cardano-auctions CA_Admin_Widget.
 */
namespace CardanoMintPay\Controllers;

use CardanoMintPay\Models\MintModel;
use CardanoMintPay\Helpers\ApiKeys;

if ( ! defined( 'ABSPATH' ) ) exit;

class WidgetAdminController {

    const CONFIG_OPTION = 'cardano_mint_widget_config';

    public static function init() {
        if ( ! is_admin() ) return;
        add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
        add_action( 'wp_ajax_cardano_mint_generate_api_key', array( __CLASS__, 'ajax_generate_key' ) );
        add_action( 'wp_ajax_cardano_mint_revoke_api_key', array( __CLASS__, 'ajax_revoke_key' ) );
    }

    public static function page_deployer() {
        $api_keys = ApiKeys::get_all();
        $config   = get_option( self::CONFIG_OPTION, array() );
        $network  = get_option( 'cardano-mint-networkenvironment', 'preprod' );
        $mints    = MintModel::getAllPolicies();
        $rest_url = esc_url_raw( rest_url( 'cardano-mint/v1' ) );

        $defaults = array(
            'theme'      => 'game-dark',
            'accent'     => '#00e5ff',
            'confirm'    => '#f0c040',
            'background' => '#0a0e1a',
            'text'       => '#ffffff',
        );
        $config = wp_parse_args( $config, $defaults );

        include plugin_dir_path( __DIR__ ) . '/views/widget-deployer.php';
    }

    public static function handle_save() {
        if ( ! isset( $_POST['cm_widget_config_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cm_widget_config_nonce'], 'cm_save_widget_config' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $config = array(
            'theme'      => sanitize_text_field( $_POST['cm_widget_theme'] ?? 'game-dark' ),
            'accent'     => sanitize_hex_color( $_POST['cm_widget_accent'] ?? '#00e5ff' ),
            'confirm'    => sanitize_hex_color( $_POST['cm_widget_confirm'] ?? '#f0c040' ),
            'background' => sanitize_hex_color( $_POST['cm_widget_background'] ?? '#0a0e1a' ),
            'text'       => sanitize_hex_color( $_POST['cm_widget_text'] ?? '#ffffff' ),
        );

        update_option( self::CONFIG_OPTION, $config, false );
        add_settings_error( 'cardano_mint_settings', 'cm_widget_saved', 'Widget appearance saved.', 'success' );
    }

    public static function ajax_generate_key() {
        check_ajax_referer( 'cardanocheckoutnonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $label   = sanitize_text_field( $_POST['label'] ?? '' );
        $origins = array_filter( array_map( 'trim', explode( "\n", $_POST['allowed_origins'] ?? '' ) ) );
        $mint_ids = array();
        if ( ! empty( $_POST['mint_ids'] ) && is_array( $_POST['mint_ids'] ) ) {
            $mint_ids = array_map( 'intval', $_POST['mint_ids'] );
        }

        if ( empty( $label ) ) wp_send_json_error( 'Label is required.' );

        $result = ApiKeys::generate( $label, $origins, $mint_ids );
        wp_send_json_success( $result );
    }

    public static function ajax_revoke_key() {
        check_ajax_referer( 'cardanocheckoutnonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $id = sanitize_text_field( $_POST['key_id'] ?? '' );
        if ( empty( $id ) ) wp_send_json_error( 'Key ID is required.' );

        $revoked = ApiKeys::revoke( $id );
        if ( $revoked ) {
            wp_send_json_success( array( 'revoked' => true ) );
        } else {
            wp_send_json_error( 'Key not found.' );
        }
    }
}
