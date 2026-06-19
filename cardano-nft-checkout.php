<?php
/*
Plugin Name: Cardano Minting
Description: NFT minting for Cardano sites via the Anvil API. Alt-chain payments (BTC / ETH / SOL / ADA), batch quantity (1-5 per tx), wallet-network gate, optional 2FA gate on the Payment Wallets admin page, and dashboard send-funds via Anvil + Blockfrost balance lookups for ADA custodial wallets.
Version: 4.5.1
Author: Pb
Text Domain: cardano-minting
*/

if (!defined('ABSPATH')) {
    exit;
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/helpers/AnvilAPI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/PinataAPI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/EncryptionHelper.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/CardanoCLI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/CardanoWalletPHP.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/PolicyImport.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/ApiKeys.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/TOTPHelper.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/BlockfrostClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/MintModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/NFTCheckoutController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/PolicyWalletController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AJAXController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/RestApiController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/WidgetAdminController.php';

// Alt-chain payments (BTC/ETH/SOL) — Phase 2.
require_once plugin_dir_path(__FILE__) . 'includes/altpay/lib/Bn.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/lib/Secp256k1.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/lib/Keccak.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/lib/Rlp.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/encoding/Bech32.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/encoding/Base58.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/encoding/KeccakAddress.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/Bip39.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/Bip32Secp.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/Slip10Ed25519.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/rpc/MempoolClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/rpc/EthRpcClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/rpc/SolRpcClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/ChainPaymentProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/AltPayInstaller.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/PriceOracle.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/AltPayService.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/providers/BtcProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/providers/EthProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/altpay/providers/SolProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/ChainWalletModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/ChainInvoiceModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/ChainTxLogModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AltPayAdminController.php';

// Fiat-to-ADA on-ramp (Guardarian). Sibling subsystem to AltPay: customer
// buys ADA with a credit card; ADA lands directly in their own connected
// Cardano wallet; the existing mint flow takes over from there. No operator
// wallet involvement, no chargeback exposure on this side.
require_once plugin_dir_path(__FILE__) . 'includes/onramp/OnrampInstaller.php';
require_once plugin_dir_path(__FILE__) . 'includes/onramp/GuardarianClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/onramp/GuardarianService.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/OnrampSessionModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/OnrampPublicController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/OnrampWebhookController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/OnrampAdminController.php';

// Asset Upgrade (burn & re-mint). Adds a per-asset CIP-25 refresh flow:
// customer connects wallet, picks an eligible NFT under a configured policy,
// signs one tx that burns the old token and re-mints the same asset name
// with new metadata. See docs/BUILD_PLAN.md.
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/AssetUpgradeInstaller.php';
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/MetadataResolver.php';
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/AssetUpgradeService.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AssetUpgradeAdminController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AssetUpgradePublicController.php';
\CardanoMintPay\Controllers\AssetUpgradeAdminController::register();
\CardanoMintPay\Controllers\AssetUpgradePublicController::register();

// Discount codes (e-commerce coupons). Admin creates campaigns of codes per
// policy; a code reduces the MSRP component of the price server-side (fees +
// receipt untouched) at build time. See docs/DISCOUNT_CODES_BUILD_PLAN.md.
require_once plugin_dir_path(__FILE__) . 'includes/discounts/DiscountInstaller.php';
require_once plugin_dir_path(__FILE__) . 'includes/models/DiscountModel.php';
require_once plugin_dir_path(__FILE__) . 'includes/discounts/DiscountService.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/DiscountPublicController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/DiscountAdminController.php';

// Asset Upgrade confirmation watcher. 5-min cron flips 'submitted' audit
// rows to 'confirmed' once the burn-and-re-mint tx lands on-chain.
add_action('cardano_asset_upgrade_confirm_tick', ['\\CardanoMintPay\\AssetUpgrade\\AssetUpgradeService', 'confirmation_tick']);

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cardano_asset_upgrade_confirm_tick')) {
        wp_schedule_event(time() + 60, 'fiveminutes', 'cardano_asset_upgrade_confirm_tick');
    }
});
register_deactivation_hook(__FILE__, function () {
    $next = wp_next_scheduled('cardano_asset_upgrade_confirm_tick');
    if ($next) wp_unschedule_event($next, 'cardano_asset_upgrade_confirm_tick');
    $sweep = wp_next_scheduled('cardano_discount_sweep_tick');
    if ($sweep) wp_unschedule_event($sweep, 'cardano_discount_sweep_tick');
});

// Custom cron interval. WP only ships hourly/daily/twicedaily by default.
add_filter('cron_schedules', function ($schedules) {
    if (empty($schedules['fiveminutes'])) {
        $schedules['fiveminutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes'];
    }
    return $schedules;
});

// Belt-and-suspenders: if the cron event was missed (activation hook didn't
// fire because the plugin was deployed via git pull rather than re-activated),
// schedule it on admin_init. Mirrors the same JIT pattern as the schema
// installers.
add_action('admin_init', function () {
    if (!wp_next_scheduled('cardano_asset_upgrade_confirm_tick')) {
        wp_schedule_event(time() + 60, 'fiveminutes', 'cardano_asset_upgrade_confirm_tick');
    }
});

/**
 * [cardano-upgrade] shortcode. Renders the customer-facing upgrade button
 * + modal. See includes/views/asset-upgrade/shortcode.php for the markup
 * and assets/asset-upgrade/upgrade.js for the CIP-30 + diff flow.
 *
 * Supported attrs:
 *   policy-id="<56 hex>"   filter eligibility to one policy (optional)
 *   label="My text"        override the trigger button label
 */
add_shortcode('cardano-upgrade', function ($atts) {
    static $instance_counter = 0;
    $instance_counter++;

    $atts = shortcode_atts([
        'policy-id' => '',
        'label'     => 'Upgrade my NFTs',
    ], $atts, 'cardano-upgrade');

    $instance_id  = (string) $instance_counter;
    $policy_id    = preg_match('/^[a-f0-9]{56}$/i', (string) $atts['policy-id']) ? (string) $atts['policy-id'] : '';
    $button_label = (string) $atts['label'];

    $base_url = plugin_dir_url(__FILE__);
    wp_enqueue_style(
        'cardano-upgrade',
        $base_url . 'assets/asset-upgrade/upgrade.css',
        [],
        (string) @filemtime(plugin_dir_path(__FILE__) . 'assets/asset-upgrade/upgrade.css')
    );
    wp_enqueue_script(
        'cardano-upgrade',
        $base_url . 'assets/asset-upgrade/upgrade.js',
        [],
        (string) @filemtime(plugin_dir_path(__FILE__) . 'assets/asset-upgrade/upgrade.js'),
        true
    );
    wp_localize_script('cardano-upgrade', 'KG_CARDANO_UPGRADE', [
        'rest_url' => esc_url_raw(rest_url('cardano-mint/v1/')),
        'nonce'    => wp_create_nonce('wp_rest'),
    ]);

    ob_start();
    include plugin_dir_path(__FILE__) . 'includes/views/asset-upgrade/shortcode.php';
    return ob_get_clean();
});

// Register activation hook for database tables
register_activation_hook(__FILE__, 'cardanomint_activate');

function cardanomint_activate() {
    // Create database tables
    CardanoMintPay\Models\MintModel::install_active_mints_table();
    CardanoMintPay\Models\MintModel::install_mint_counts_table();
    CardanoMintPay\Models\MintModel::install_policy_wallets_table();
    CardanoMintPay\Models\MintModel::install_mint_wallets_table();
    CardanoMintPay\Models\MintModel::install_mint_policies_table();

    // Run migrations for existing tables (adds new columns if they don't exist)
    CardanoMintPay\Models\MintModel::add_metadata_columns();
    CardanoMintPay\Models\MintModel::add_multi_asset_columns();
    CardanoMintPay\Models\MintModel::add_preview_image_columns();

    // Alt-chain payments schema (BTC/ETH/SOL).
    CardanoMintPay\AltPay\AltPayInstaller::install();

    // On-ramp sessions schema (Guardarian fiat-to-ADA).
    CardanoMintPay\Onramp\OnrampInstaller::install();

    // Asset Upgrade schema (burn & re-mint specs + audit log).
    CardanoMintPay\AssetUpgrade\AssetUpgradeInstaller::install();

    // Discount codes schema (campaigns + codes + redemptions).
    CardanoMintPay\Discounts\DiscountInstaller::install();

    // Fix binary permissions on Linux
    cardanomint_fix_binary_permissions();
}

/**
 * Capability check for alt-chain payments. Returns ['ok' => bool, 'missing' => string[]].
 * Pure-PHP secp256k1 needs at minimum BCMath; GMP is preferred. SOL needs libsodium
 * (built into PHP 7.2+ but can be disabled at compile time on hardened hosts).
 */
function cardanomint_altpay_capability_check(): array {
    $missing = [];
    if (!extension_loaded('bcmath') && !extension_loaded('gmp')) {
        $missing[] = 'bcmath or gmp (required for BTC/ETH big-int math)';
    }
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {
        $missing[] = 'sodium (required for SOL ed25519 signing)';
    }
    if (!function_exists('hash_hmac')) {
        $missing[] = 'hash (required for BIP32 / SLIP-0010)';
    }
    return ['ok' => empty($missing), 'missing' => $missing];
}

// One-time migration for preview-image columns (for installs that were
// active before these columns existed).
add_action('admin_init', function() {
    if (get_option('cardano_mint_preview_image_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::add_preview_image_columns();
        update_option('cardano_mint_preview_image_migration_run', '1');
        error_log('Cardano Mint: Preview image columns migration completed');
    }
});

/**
 * Ensure Cardano CLI binaries have execute permissions on Linux
 */
function cardanomint_fix_binary_permissions() {
    // Only fix on Linux/Unix systems
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return; // Skip on Windows
    }

    $bin_dir = plugin_dir_path(__FILE__) . 'bin/';
    $binaries = ['cardano-wallet-linux', 'sign-tx-linux'];

    foreach ($binaries as $binary) {
        $path = $bin_dir . $binary;

        if (file_exists($path)) {
            // Make executable (755 = rwxr-xr-x)
            chmod($path, 0755);
            error_log("Cardano Mint: Set execute permissions for {$binary}");
        } else {
            error_log("Cardano Mint: Binary not found: {$binary}");
        }
    }
}

// One-time migration hook for archive system (can be removed after first run)
add_action('admin_init', function() {
    if (get_option('cardano_mint_archive_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_active_mints_table();
        update_option('cardano_mint_archive_migration_run', '1');
        error_log('Cardano Mint: Archive system migration completed');
    }
});

// One-time migration hook for policy wallet archive system
add_action('admin_init', function() {
    if (get_option('cardano_wallet_archive_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_policy_wallets_table();
        update_option('cardano_wallet_archive_migration_run', '1');
        error_log('Cardano Mint: Policy wallet archive migration completed');
    }
});

// One-time fix for binary permissions on Linux
add_action('admin_init', function() {
    if (get_option('cardano_binary_permissions_fixed') !== '1') {
        cardanomint_fix_binary_permissions();
        update_option('cardano_binary_permissions_fixed', '1');
        error_log('Cardano Mint: Binary permissions fix completed');
    }
});

// One-time migration for advanced import (source column + nullable mnemonic)
add_action('admin_init', function() {
    if (get_option('cardano_mint_import_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_policy_wallets_table();
        update_option('cardano_mint_import_migration_run', '1');
        error_log('Cardano Mint: Advanced import migration completed');
    }
});

// One-time migration for mint policies table (imported policy storage)
add_action('admin_init', function() {
    if (get_option('cardano_mint_policies_table_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_mint_policies_table();
        update_option('cardano_mint_policies_table_run', '1');
        error_log('Cardano Mint: Mint policies table created');
    }
});

// Idempotent altpay schema check on every admin_init. Cheap: returns early
// once the version flag is set. Mirrors the JIT-migration pattern.
add_action('admin_init', function() {
    if (class_exists('CardanoMintPay\\AltPay\\AltPayInstaller')) {
        CardanoMintPay\AltPay\AltPayInstaller::maybe_install();
    }
});

// Same JIT-migration pattern for the Asset Upgrade tables.
add_action('admin_init', function() {
    if (class_exists('CardanoMintPay\\AssetUpgrade\\AssetUpgradeInstaller')) {
        CardanoMintPay\AssetUpgrade\AssetUpgradeInstaller::maybe_install();
    }
});

// Surface a one-shot admin notice if the AltPay capability check fails.
// We do not block activation; we just tell the operator why the feature
// will refuse to issue quotes.
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (get_option('cardano_mint_altpay_enabled') !== '1') return;
    $cap = cardanomint_altpay_capability_check();
    if ($cap['ok']) return;
    echo '<div class="notice notice-error"><p><strong>Cardano Mint AltPay:</strong> the following PHP extensions are missing: '
       . esc_html(implode(', ', $cap['missing']))
       . '. Alt-chain payments will refuse to issue quotes until these are available.</p></div>';
});

// Register deactivation hook for cleanup
register_deactivation_hook(__FILE__, 'cardanomint_deactivate');

function cardanomint_deactivate() {
    $delete_data = get_option('cardano-mint-delete_data_on_deactivation', 0);
    
    if ($delete_data) {
        global $wpdb;
        
        // Drop plugin tables
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cardanonftactivemints");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cardanonftmintcounts");
        
        // Delete plugin options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cardano-mint-%'");
        
        // Clear any cached data
        wp_cache_flush();
    }
}

// Initialize controllers
add_action('init', function() {
    CardanoMintPay\Controllers\NFTCheckoutController::register();
    CardanoMintPay\Controllers\NFTCheckoutController::registerPolicyGeneration();
    CardanoMintPay\Controllers\PolicyWalletController::register();
    CardanoMintPay\Controllers\AJAXController::register();
    CardanoMintPay\Controllers\RestApiController::init();
    CardanoMintPay\Controllers\WidgetAdminController::init();

    // Alt-chain providers — register one instance per chain with the service.
    if (class_exists('CardanoMintPay\\AltPay\\AltPayService')) {
        CardanoMintPay\AltPay\AltPayService::register(new CardanoMintPay\AltPay\Providers\BtcProvider());
        CardanoMintPay\AltPay\AltPayService::register(new CardanoMintPay\AltPay\Providers\EthProvider());
        CardanoMintPay\AltPay\AltPayService::register(new CardanoMintPay\AltPay\Providers\SolProvider());
    }

    if (class_exists('CardanoMintPay\\Controllers\\AltPayAdminController')) {
        CardanoMintPay\Controllers\AltPayAdminController::register();
    }

    // On-ramp: just-in-time install + register the three controllers
    // (public REST, webhook receiver, admin AJAX).
    if (class_exists('CardanoMintPay\\Onramp\\OnrampInstaller')) {
        CardanoMintPay\Onramp\OnrampInstaller::maybe_install();
    }
    if (class_exists('CardanoMintPay\\Controllers\\OnrampPublicController')) {
        CardanoMintPay\Controllers\OnrampPublicController::register();
    }
    if (class_exists('CardanoMintPay\\Controllers\\OnrampWebhookController')) {
        CardanoMintPay\Controllers\OnrampWebhookController::register();
    }
    if (class_exists('CardanoMintPay\\Controllers\\OnrampAdminController')) {
        CardanoMintPay\Controllers\OnrampAdminController::register();
    }

    // Discounts: just-in-time install + register the public (validate) and
    // admin (campaigns/codes) controllers.
    if (class_exists('CardanoMintPay\\Discounts\\DiscountInstaller')) {
        CardanoMintPay\Discounts\DiscountInstaller::maybe_install();
    }
    if (class_exists('CardanoMintPay\\Controllers\\DiscountPublicController')) {
        CardanoMintPay\Controllers\DiscountPublicController::register();
    }
    if (class_exists('CardanoMintPay\\Controllers\\DiscountAdminController')) {
        CardanoMintPay\Controllers\DiscountAdminController::register();
    }
});

// Discount reservation sweeper: release holds left by abandoned checkouts so a
// single-use code isn't stuck. Reuses the 'fiveminutes' interval defined for the
// asset-upgrade tick.
add_action('cardano_discount_sweep_tick', ['\\CardanoMintPay\\Discounts\\DiscountService', 'sweep']);
add_action('init', function () {
    if (!wp_next_scheduled('cardano_discount_sweep_tick')) {
        wp_schedule_event(time() + 120, 'fiveminutes', 'cardano_discount_sweep_tick');
    }
});

// Watcher cron: hourly is the WP default minimum, so we register a custom
// every-minute schedule. The tick scans pending invoices and promotes them.
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['cardano_altpay_minute'])) {
        $schedules['cardano_altpay_minute'] = ['interval' => 60, 'display' => 'Every minute (Cardano AltPay)'];
    }
    return $schedules;
});

add_action('init', function() {
    if (!wp_next_scheduled('cardano_altpay_watcher_tick')) {
        wp_schedule_event(time() + 60, 'cardano_altpay_minute', 'cardano_altpay_watcher_tick');
    }
});

add_action('cardano_altpay_watcher_tick', function() {
    if (class_exists('CardanoMintPay\\AltPay\\AltPayService')) {
        CardanoMintPay\AltPay\AltPayService::watcher_tick();
    }
});

register_deactivation_hook(__FILE__, function() {
    $ts = wp_next_scheduled('cardano_altpay_watcher_tick');
    if ($ts) wp_unschedule_event($ts, 'cardano_altpay_watcher_tick');
});

// Enqueue scripts and styles for Cardano Mint
add_action('wp_enqueue_scripts', function() {
    global $post;
    
    // Load on all pages since shortcode might be in Bricks elements
    // The script will only initialize if the button exists
        
        // Minting JS (wallet connection is embedded natively — no external dependencies).
        // Version is the file mtime so any git pull / edit auto-busts the
        // browser cache without anyone remembering to bump a string.
        $mint_js_path  = plugin_dir_path(__FILE__) . 'assets/cardano-nft-mint.js';
        $mint_css_path = plugin_dir_path(__FILE__) . 'assets/cardano-checkout.css';
        wp_enqueue_script(
            'cardano-mint-js',
            plugin_dir_url(__FILE__) . 'assets/cardano-nft-mint.js',
            ['jquery'],
            file_exists($mint_js_path) ? filemtime($mint_js_path) : '3.0.0',
            true
        );

        wp_enqueue_style('cardano-checkout-css', plugin_dir_url(__FILE__) . 'assets/cardano-checkout.css', [], file_exists($mint_css_path) ? filemtime($mint_css_path) : '1.0.0');
        
        // Localize script for AJAX
        wp_localize_script('cardano-mint-js', 'cardanoMint', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cardanocheckoutnonce'),
            'network' => cardanomint_get_network_name(),
            'anvilApiUrl' => get_option('cardano_mint_anvil_api_url', 'https://preprod.api.ada-anvil.app/v2/services'),
            'anvilApiKey' => get_option('cardano_mint_anvil_api_key', '')
        ]);
});

// Get network name for explorer links
function cardanomint_get_network_name() {
    $api_url = get_option('cardano_mint_anvil_api_url', 'https://preprod.api.ada-anvil.app/v2/services');
    
    if (strpos($api_url, 'preprod') !== false) {
        return 'preprod';
    } elseif (strpos($api_url, 'preview') !== false) {
        return 'preview';
    } else {
        return 'mainnet';
    }
}

add_action('admin_menu', 'cardanomint_admin_menu');

function cardanomint_admin_menu() {
    add_menu_page(
        'Cardano Mint',
        'Cardano Mint',
        'manage_options',
        'cardano-mint-plugin-setup',
        'cardanomint_setup_page',
        'dashicons-admin-customizer',
        56
    );

    add_submenu_page(
        'cardano-mint-plugin-setup',
        'Mint Manager',
        'Mint Manager',
        'manage_options',
        'cardano-mint-page-1',
        'cardanomint_mint_manager_page'
    );
    add_submenu_page(
        'cardano-mint-plugin-setup',
        'Active Mints',
        'Active Mints',
        'manage_options',
        'cardano-mint-page-2',
        'cardanomint_active_mints_page'
    );

    add_submenu_page(
        'cardano-mint-plugin-setup',
        'Policy Wallet',
        'Policy Wallet',
        'manage_options',
        'cardano-policy-wallet',
        'cardanomint_policy_wallet_page'
    );

    add_submenu_page(
        'cardano-mint-plugin-setup',
        'Payment Wallets',
        'Payment Wallets',
        'manage_options',
        'cardano-payment-wallets',
        'cardanomint_payment_wallets_page'
    );

    add_submenu_page(
        'cardano-mint-plugin-setup',
        'Widget Deployer',
        'Widget Deployer',
        'manage_options',
        'cardano-mint-widget-deployer',
        array('CardanoMintPay\Controllers\WidgetAdminController', 'page_deployer')
    );

    add_submenu_page(
        'cardano-mint-plugin-setup',
        'How to Use',
        'How to Use',
        'manage_options',
        'cardano-mint-how-to-use',
        'cardanomint_how_to_use_page'
    );
}

function cardanomint_policy_wallet_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    include(plugin_dir_path(__FILE__) . 'includes/views/policy-wallet-manager.php');
}

function cardanomint_payment_wallets_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    \CardanoMintPay\Controllers\AltPayAdminController::renderPage();
}

function cardanomint_setup_page() {
    $prefix = 'cardano-mint';
    $settingsmessage = '';

    if (isset($_POST['pluginsetupsave'])) {
        check_admin_referer('pluginsetupoptions');
        
        // Determine API URL based on network selection
        $network = sanitize_text_field($_POST['networkenvironment']);
        $api_url = ($network === 'mainnet') ? 'https://prod.api.ada-anvil.app/v2/services' : 'https://preprod.api.ada-anvil.app/v2/services';
        
        // Use appropriate API key based on network
        $api_key = ($network === 'mainnet') ? sanitize_text_field($_POST['anvilapikeymainnet']) : sanitize_text_field($_POST['anvilapikeytestnet']);
        
        // Save standardized options for AnvilAPI helper
        update_option('cardano_mint_anvil_api_key', $api_key);
        update_option('cardano_mint_anvil_api_url', $api_url);
        update_option('cardano_mint_merchant_address', sanitize_text_field($_POST['merchantwalletaddress']));
        
        // Keep legacy options for backward compatibility
        update_option($prefix . '-anvilapikeymainnet', sanitize_text_field($_POST['anvilapikeymainnet']));
        update_option($prefix . '-anvilapikeytestnet', sanitize_text_field($_POST['anvilapikeytestnet']));
        update_option($prefix . '-merchantwalletaddress', sanitize_text_field($_POST['merchantwalletaddress']));
        update_option($prefix . '-networkenvironment', $network);
        update_option($prefix . '-delete_data_on_deactivation', isset($_POST['delete_data_on_deactivation']) ? 1 : 0);

        // Pinata settings
        update_option('cardano_mint_pinata_jwt', sanitize_text_field($_POST['pinata_jwt'] ?? ''));
        update_option('cardano_mint_pinata_api_key', sanitize_text_field($_POST['pinata_api_key'] ?? ''));
        update_option('cardano_mint_pinata_secret_key', sanitize_text_field($_POST['pinata_secret_key'] ?? ''));
        update_option('cardano_mint_pinata_enabled', isset($_POST['pinata_enabled']) ? 1 : 0);

        $settingsmessage = 'Settings saved!';
    }

    $anvilapikeymainnet = get_option($prefix . '-anvilapikeymainnet', '');
    $anvilapikeytestnet = get_option($prefix . '-anvilapikeytestnet', '');
    $merchantwalletaddress = get_option($prefix . '-merchantwalletaddress', '');
    $networkenvironment = get_option($prefix . '-networkenvironment', 'mainnet');
    $delete_data_on_deactivation = get_option($prefix . '-delete_data_on_deactivation', 0);
    $pinata_jwt = get_option('cardano_mint_pinata_jwt', '');
    $pinata_api_key = get_option('cardano_mint_pinata_api_key', '');
    $pinata_secret_key = get_option('cardano_mint_pinata_secret_key', '');
    $pinata_enabled = get_option('cardano_mint_pinata_enabled', 0);

    ?>
    <div class="wrap">
        <h1>Cardano Mint Plugin Setup</h1>
        <?php if ($settingsmessage): ?>
            <div class="notice notice-success">
                <p><?php echo esc_html($settingsmessage); ?></p>
            </div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('pluginsetupoptions'); ?>
            <table class="form-table">
                <tr>
                    <th>ADA Anvil API Key Mainnet</th>
                    <td>
                        <input type="text" name="anvilapikeymainnet" value="<?php echo esc_attr($anvilapikeymainnet); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>ADA Anvil API Key Testnet</th>
                    <td>
                        <input type="text" name="anvilapikeytestnet" value="<?php echo esc_attr($anvilapikeytestnet); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>Merchant Wallet Address</th>
                    <td>
                        <input type="text" name="merchantwalletaddress" value="<?php echo esc_attr($merchantwalletaddress); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>Network Environment</th>
                    <td>
                        <select name="networkenvironment">
                            <option value="mainnet" <?php selected($networkenvironment, 'mainnet', true); ?>>Mainnet</option>
                            <option value="preprod" <?php selected($networkenvironment, 'preprod', true); ?>>Preprod Testnet</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Data Cleanup</th>
                    <td>
                        <input type="checkbox" name="delete_data_on_deactivation" id="delete_data_on_deactivation" value="1" <?php checked($delete_data_on_deactivation, 1); ?> />
                        <label for="delete_data_on_deactivation">Delete all plugin data when deactivated</label>
                        <p class="description">This will remove all mints, mint counts, and settings when the plugin is deactivated.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top: 30px;">Pinata IPFS Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Enable Pinata IPFS</th>
                    <td>
                        <input type="checkbox" name="pinata_enabled" id="pinata_enabled" value="1" <?php checked($pinata_enabled, 1); ?> />
                        <label for="pinata_enabled">Use Pinata for NFT image storage</label>
                        <p class="description">When enabled, NFT images will be uploaded to IPFS via Pinata instead of using WordPress CDN URLs.</p>
                    </td>
                </tr>
                <tr>
                    <th>Pinata JWT Token</th>
                    <td>
                        <input type="password" name="pinata_jwt" value="<?php echo esc_attr($pinata_jwt); ?>" size="60" autocomplete="off" />
                        <p class="description">Get your JWT token from <a href="https://app.pinata.cloud/keys" target="_blank">Pinata Dashboard → API Keys</a> (Recommended)</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2" style="text-align: center; padding: 10px; background: #f0f0f0;">OR use API Key + Secret (Legacy)</th>
                </tr>
                <tr>
                    <th>Pinata API Key</th>
                    <td>
                        <input type="text" name="pinata_api_key" value="<?php echo esc_attr($pinata_api_key); ?>" size="60" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th>Pinata Secret API Key</th>
                    <td>
                        <input type="password" name="pinata_secret_key" value="<?php echo esc_attr($pinata_secret_key); ?>" size="60" autocomplete="off" />
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="pluginsetupsave" class="button-primary" value="Save Settings" />
                <button type="button" id="test-anvil-api" class="button" style="margin-left: 10px;">Test Anvil API Connection</button>
            </p>
            
            <div id="anvil-test-results" style="margin-top: 20px; padding: 15px; border-radius: 5px; display: none;">
                <h3>Anvil API Test Results</h3>
                <div id="test-results-content"></div>
            </div>
            
            <script>
            document.getElementById('test-anvil-api').addEventListener('click', function() {
                const button = this;
                const resultsDiv = document.getElementById('anvil-test-results');
                const contentDiv = document.getElementById('test-results-content');
                
                button.disabled = true;
                button.textContent = 'Testing...';
                resultsDiv.style.display = 'block';
                contentDiv.innerHTML = '<p>Testing Anvil API connection...</p>';
                
                const formData = new FormData();
                formData.append('action', 'cardano_test_anvil_api');
                formData.append('nonce', '<?php echo wp_create_nonce('cardanocheckoutnonce'); ?>');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const results = data.data;
                        let html = '<ul>';
                        html += '<li><strong>API Key:</strong> ' + (results.api_key_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>API URL:</strong> ' + (results.api_url_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>Merchant Address:</strong> ' + (results.merchant_address_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>API URL:</strong> ' + results.api_url + '</li>';
                        html += '<li><strong>Merchant Address:</strong> ' + results.merchant_address + '</li>';
                        
                        if (results.api_health_check === 'success') {
                            html += '<li><strong>API Health Check:</strong> ✅ Success (HTTP ' + results.api_response_code + ')</li>';
                        } else if (results.api_health_check === 'error') {
                            html += '<li><strong>API Health Check:</strong> ❌ Error - ' + results.api_error + '</li>';
                        } else {
                            html += '<li><strong>API Health Check:</strong> ⏭️ Skipped (missing configuration)</li>';
                        }
                        
                        html += '</ul>';
                        contentDiv.innerHTML = html;
                    } else {
                        contentDiv.innerHTML = '<p style="color: red;">Test failed: ' + (data.data?.message || 'Unknown error') + '</p>';
                    }
                })
                .catch(error => {
                    contentDiv.innerHTML = '<p style="color: red;">Test failed: ' + error.message + '</p>';
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = 'Test Anvil API Connection';
                });
            });
            </script>
        </form>
    </div>
    <?php
}

function cardanomint_mint_manager_page() {
    // Enqueue media library scripts
    wp_enqueue_media();

    $metaurl = '';
    $expiration = '';
    $unlimited = '';
    $royalty = '';
    $royaltyaddr = '';
    $mintsallowedperwallet = '';
    $message = '';
    $editMode = false;
    $editData = null;

    // Handle edit mode
    if (isset($_GET['edit']) && !empty($_GET['edit'])) {
        $editId = intval($_GET['edit']);
        $editData = CardanoMintPay\Models\MintModel::getMintById($editId);

        if ($editData) {
            $editMode = true;
            // Pre-populate variables
            $metaurl = '';
            $expiration = $editData['expirationdate'];
            $unlimited = $editData['unlimited'];
            $royalty = $editData['royalty'];
            $royaltyaddr = $editData['royaltyaddress'];
            $mintsallowedperwallet = $editData['mintsallowedperwallet'];
        }
    }

    // Handle form submission
    if (isset($_POST['mintmanagersave']) && wp_verify_nonce($_POST['_wpnonce'], 'mintmanagersavenonce')) {
        // Add metadata columns to existing table if needed
        CardanoMintPay\Models\MintModel::add_metadata_columns();
        // Add multi-asset columns to existing table if needed
        CardanoMintPay\Models\MintModel::add_multi_asset_columns();

        // Debug: Log what we're receiving from the form
        error_log("=== MINT MANAGER FORM SUBMISSION ===");
        error_log("Policy ID from form: " . ($_POST['cardanonftpolicyid'] ?? 'NOT SET'));
        error_log("Policy JSON present: " . (isset($_POST['cardanonftpolicyjson']) ? 'YES' : 'NO'));
        if (isset($_POST['cardanonftpolicyjson'])) {
            error_log("Policy JSON length: " . strlen($_POST['cardanonftpolicyjson']));
            error_log("Policy JSON preview: " . substr($_POST['cardanonftpolicyjson'], 0, 200));
        }

        // Determine if this is a new policy or adding to existing
        $policyMode = sanitize_text_field($_POST['policy_mode'] ?? 'new');
        $existingCollectionId = isset($_POST['existing_collection_id']) ? intval($_POST['existing_collection_id']) : null;

        // Validate and decode the policy JSON to ensure it's valid
        $policy_json_raw = isset($_POST['cardanonftpolicyjson']) ? stripslashes($_POST['cardanonftpolicyjson']) : null;
        $policy_json_validated = null;

        if ($policy_json_raw) {
            $decoded = json_decode($policy_json_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['policyId']) && isset($decoded['schema'])) {
                // Re-encode to ensure clean JSON
                $policy_json_validated = wp_json_encode($decoded);
                error_log("Policy JSON validated successfully");
            } else {
                error_log("Policy JSON validation FAILED: " . json_last_error_msg());
            }
        }

        // Build base mint data
        $mintData = [
            'title' => sanitize_text_field($_POST['cardanonfttitle'] ?? ''),
            'asset_name' => sanitize_text_field($_POST['cardanonftassetname'] ?? ''),
            'policyid' => sanitize_text_field($_POST['cardanonftpolicyid'] ?? ''),
            'expirationdate' => sanitize_text_field($_POST['cardanonftexpirationdate'] ?? ''),
            'unlimited' => isset($_POST['cardanonftunlimitedmint']) ? 1 : 0,
            'mintsallowedperwallet' => isset($_POST['cardanonftmintsallowedperwallet']) ? intval($_POST['cardanonftmintsallowedperwallet']) : 0,
            'price' => isset($_POST['cardanonftprice']) ? floatval($_POST['cardanonftprice']) : 60.00,
            'royalty' => sanitize_text_field($_POST['cardanonftroyaltyamount'] ?? ''),
            'royaltyaddress' => sanitize_text_field($_POST['cardanonftroyaltyaddress'] ?? ''),
            'image_id' => isset($_POST['cardanonftimageid']) && !empty($_POST['cardanonftimageid']) ? intval($_POST['cardanonftimageid']) : null,
            'collection_image_id' => isset($_POST['cardanonftcollectionimageid']) && !empty($_POST['cardanonftcollectionimageid']) ? intval($_POST['cardanonftcollectionimageid']) : null,
            'preview_image_id' => isset($_POST['cardanonftpreviewimageid']) && !empty($_POST['cardanonftpreviewimageid']) ? intval($_POST['cardanonftpreviewimageid']) : null,
            'preview_ipfs_cid_manual' => isset($_POST['preview_ipfs_cid_manual']) ? sanitize_text_field($_POST['preview_ipfs_cid_manual']) : null,
            'preview_media_type' => isset($_POST['preview_media_type']) ? sanitize_text_field($_POST['preview_media_type']) : null,
            'nft_metadata' => isset($_POST['cardanonftnftmetadata']) ? stripslashes($_POST['cardanonftnftmetadata']) : null,
            'policy_json' => $policy_json_validated,  // Validated JSON
            'quantity_total' => isset($_POST['cardanonftquantity']) ? intval($_POST['cardanonftquantity']) : 1,
            'status' => 'Active',
            'ipfs_cid' => isset($_POST['ipfs_cid']) ? sanitize_text_field($_POST['ipfs_cid']) : null,  // From Pinata pin button
            'ipfs_cid_manual' => isset($_POST['ipfs_cid_manual']) ? sanitize_text_field($_POST['ipfs_cid_manual']) : null,  // From manual paste input
            'media_type' => isset($_POST['media_type']) ? sanitize_text_field($_POST['media_type']) : null  // Format selector (used when ipfs_cid_manual is set)
        ];

        // Handle variant and collection_id based on mode
        if ($policyMode === 'existing' && $existingCollectionId) {
            // Adding to existing policy
            $mintData['collection_id'] = $existingCollectionId;
            $mintData['variant'] = CardanoMintPay\Models\MintModel::getNextVariant($existingCollectionId);

            // Inherit collection_image_id from parent variant (variant A)
            $parentVariant = CardanoMintPay\Models\MintModel::getMintById($existingCollectionId);
            if ($parentVariant && !empty($parentVariant['collection_image_id'])) {
                $mintData['collection_image_id'] = intval($parentVariant['collection_image_id']);
                error_log("Inheriting collection_image_id {$mintData['collection_image_id']} from parent variant A");
            }

            error_log("Adding variant {$mintData['variant']} to existing collection {$existingCollectionId}");
        } else {
            // Creating new policy - variant will be 'A', collection_id will be set to its own id after insert
            $mintData['collection_id'] = null;  // Will be set in insert_active_mint
            $mintData['variant'] = 'A';
            error_log("Creating new policy with first variant A");
        }

        error_log("Mint data being saved: " . print_r($mintData, true));

        // Check if this is an edit operation
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            $editId = intval($_POST['edit_id']);
            $result = CardanoMintPay\Models\MintModel::update_active_mint($editId, $mintData);
            if ($result !== false) {
                error_log("Asset updated successfully with ID: " . $editId);
                $message = 'Asset updated successfully!';
            } else {
                error_log("ERROR: Failed to update asset");
                $message = 'Error updating asset.';
            }
        } else {
            // Creating new asset
            $result = CardanoMintPay\Models\MintModel::insert_active_mint($mintData);
            if ($result) {
                error_log("Asset created successfully with ID: " . $result);
                $message = 'Asset created successfully!';
            } else {
                error_log("ERROR: Failed to create asset");
                $message = 'Error creating asset.';
            }
        }
        error_log("=== END MINT MANAGER FORM SUBMISSION ===");
    }
    
    include plugin_dir_path(__FILE__) . 'includes/views/mint-manager.php';
}

/**
 * Split active mints into [manageable, orphaned]. A mint is manageable if its
 * policy_json keyhash matches the active policy wallet OR its policy has an
 * imported signing key on file (skey-backed policies don't need a generated
 * wallet — the saved skey signs at submit time).
 */
function cardanomint_partition_mints($all_mints, $active_wallet, $skey_policy_ids) {
    $mints = [];
    $orphaned = [];
    $active_keyhash = $active_wallet ? ($active_wallet['payment_keyhash'] ?? '') : '';
    foreach ((array) $all_mints as $mint) {
        $kh = CardanoMintPay\Models\MintModel::extractKeyhashFromPolicyJson($mint['policy_json'] ?? '');
        $matches_wallet = $active_keyhash && $kh === $active_keyhash;
        $has_skey = in_array(strtolower((string) ($mint['policyid'] ?? '')), $skey_policy_ids, true);
        if ($matches_wallet || $has_skey) {
            $mints[] = $mint;
        } else {
            $orphaned[] = $mint;
        }
    }
    return [$mints, $orphaned];
}

function cardanomint_active_mints_page() {
    // Get current network and active wallet
    $network = get_option('cardano-mint-networkenvironment', 'preprod');
    $active_wallet = CardanoMintPay\Models\MintModel::getActivePolicyWallet($network);

    // Policies whose signing key was imported (skey+script / manual). These are
    // mintable without a generated policy wallet — the saved skey overrides it
    // at submit time — so they must NOT be treated as orphaned here.
    $skey_policy_ids = CardanoMintPay\Models\MintModel::getImportedSkeyPolicyIds();

    // Filter mints by active wallet's keyhash (the "cartridge save file" logic),
    // PLUS any policy backed by an imported skey.
    list($mints, $orphaned_mints) = cardanomint_partition_mints(
        CardanoMintPay\Models\MintModel::get_active_mints(),
        $active_wallet,
        $skey_policy_ids
    );

    // Count only policies from active wallet (not orphaned)
    $slots_used = CardanoMintPay\Models\MintModel::countActivePoliciesFromActiveWallets($network);
    $archived_count = CardanoMintPay\Models\MintModel::count_archived_policies();
    $max_slots = 10; // You can make this configurable
    $slots_left = $max_slots - $slots_used;
    $admin_url = admin_url();
    
    // Mint updates removed - table is now read-only for security
    
    // Handle mint deletion
    if (isset($_GET['deletemint'])) {
        $deleted = CardanoMintPay\Models\MintModel::delete_active_mint($_GET['deletemint']);
        if ($deleted) {
            wp_redirect(admin_url('admin.php?page=cardano-mint-page-2&deleted=1'));
            exit;
        }
    }
    
    // Handle delete all mints
    if (isset($_GET['deleteallmints']) && $_GET['deleteallmints'] == '1') {
        CardanoMintPay\Models\MintModel::delete_all_active_mints();
        wp_redirect(admin_url('admin.php?page=cardano-mint-page-2&deleted=1'));
        exit;
    }
    
    // Reload mints after any changes (need to re-filter by wallet + skey).
    list($mints, $orphaned_mints) = cardanomint_partition_mints(
        CardanoMintPay\Models\MintModel::get_active_mints(),
        $active_wallet,
        $skey_policy_ids
    );

    // Recalculate slots after deletion
    $slots_used = CardanoMintPay\Models\MintModel::countActivePoliciesFromActiveWallets($network);
    $slots_left = $max_slots - $slots_used;

    include plugin_dir_path(__FILE__) . 'includes/views/active-mints-list.php';
}

function cardanomint_how_to_use_page() {
    ?>
    <div class="wrap">
        <h1>Cardano Mint - How to Use</h1>

        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h2 style="color: white; margin-top: 0;">🚀 Cardano Mint Plugin v3.0</h2>
            <p style="font-size: 16px; margin-bottom: 0;">Complete NFT minting solution with dual-signature security, policy wallet management, IPFS pinning, per-wallet limits, CSV whitelist management, and automated CIP-27 royalty tokens.</p>
        </div>

        <!-- Quick Start Guide -->
        <div style="background: #e7f3ff; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">⚡ Quick Start Guide</h3>
            <ol style="line-height: 1.8;">
                <li><strong>Plugin Setup:</strong> Configure Anvil API keys, merchant address, network (mainnet/preprod), and Pinata (optional)</li>
                <li><strong>Policy Wallet:</strong> Generate a secure policy wallet for signing transactions (ONE TIME - save the seed phrase!)</li>
                <li><strong>Create Mint:</strong> Use Mint Manager to create NFT collections with metadata, images, and pricing</li>
                <li><strong>Generate Policy:</strong> Set expiration date and generate policy ID (triggers automatic CIP-27 royalty token)</li>
                <li><strong>Add Shortcode:</strong> Use <code>[cardano-mint mint-id="X"]</code> on your page</li>
                <li><strong>Track Mints:</strong> Monitor and manage minters via Active Mints page (CSV export/import)</li>
            </ol>
        </div>

        <!-- Shortcode Documentation -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🖼️ [cardano-mint] - NFT Minting Shortcode</h3>
            <p><strong>Description:</strong> Creates a "MINT NOW" button that opens a minting modal for purchasing and minting NFTs with dual-signature security.</p>
            <p><strong>Compatible with:</strong> Gutenberg, Classic Editor, Bricks Builder, Elementor, and page builders.</p>

            <h4>Available Attributes:</h4>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 20%;">Attribute</th>
                        <th style="width: 15%;">Required</th>
                        <th style="width: 15%;">Default</th>
                        <th style="width: 50%;">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>mint-id</code></td>
                        <td><span style="color: #d63638;">Yes</span></td>
                        <td>-</td>
                        <td>Collection ID or specific variant (e.g., "20" or "20-A")</td>
                    </tr>
                    <tr>
                        <td><code>nftname</code></td>
                        <td><span style="color: #00a32a;">No</span></td>
                        <td>From Database</td>
                        <td>Display name for the NFT (overrides database value)</td>
                    </tr>
                    <tr>
                        <td><code>class</code></td>
                        <td><span style="color: #00a32a;">No</span></td>
                        <td>-</td>
                        <td>CSS class for custom styling</td>
                    </tr>
                </tbody>
            </table>

            <h4>📝 Shortcode Usage Examples:</h4>

            <h5>1. Random Variant Selection (Mystery Box):</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20"]
            </div>
            <p><em>Randomly selects from ALL variants in collection 20 (20-A, 20-B, 20-C, etc.) using weighted rarity. Users see the "Collection Image" until mint is complete.</em></p>

            <h5>2. Specific Variant Selection:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20-A"]
            </div>
            <p><em>Mints ONLY variant A from collection 20. Users see the specific NFT image upfront.</em></p>

            <h5>3. With Custom Styling:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20-B" class="my-custom-button"]
            </div>

            <h5>4. Custom Display Name:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20" nftname="Mystery Art Drop"]
            </div>

        </div>

        <!-- Core Features -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🔥 Core Features</h3>

            <h4>1. Collection & Variant System</h4>
            <ul>
                <li><strong>Parent Collection (ID):</strong> Main collection identifier (e.g., "20")</li>
                <li><strong>Variants (ID-Letter):</strong> Different versions within a collection (e.g., "20-A", "20-B", "20-C")</li>
                <li><strong>Variant A = Parent:</strong> Always create the "A" variant first - it controls collection-wide settings</li>
                <li><strong>Weighted Rarity:</strong> Each variant has a rarity percentage that determines random selection probability</li>
                <li><strong>Shortcode Behavior:</strong>
                    <ul>
                        <li><code>mint-id="20"</code> → Random weighted selection from all variants</li>
                        <li><code>mint-id="20-A"</code> → Only mints that specific variant</li>
                    </ul>
                </li>
            </ul>

            <h4>2. Mystery Box / Blind Minting</h4>
            <ul>
                <li><strong>Collection Image:</strong> Upload a "mystery box" image that shows before reveal</li>
                <li><strong>When to Use:</strong> Use collection image when you want users to NOT see which variant they're getting until after mint</li>
                <li><strong>NFT Image:</strong> The actual NFT image revealed after minting completes</li>
                <li><strong>Editing:</strong> Collection image can ONLY be set in the parent "A" variant on Active Mints page</li>
                <li><strong>Display Logic:</strong> If collection image exists, show it instead of NFT image during minting</li>
            </ul>

            <h4>3. Policy Wallet System</h4>
            <ul>
                <li><strong>Purpose:</strong> Secure wallet for signing NFT minting transactions (dual-signature with customer)</li>
                <li><strong>ONE-TIME SEED:</strong> The 24-word seed phrase is shown ONLY ONCE during creation - WRITE IT DOWN!</li>
                <li><strong>Not for Funds:</strong> Policy wallet should NOT hold ADA - it's only for signing, not receiving payments</li>
                <li><strong>Encryption:</strong> Seed and keys encrypted using WordPress salts (see POLICY_WALLET_ENCRYPTION.md)</li>
                <li><strong>Deletion Warning:</strong> If you delete policy wallet, you CANNOT mint old NFTs associated with it</li>
                <li><strong>Multiple Wallets:</strong> You can only have ONE active policy wallet at a time</li>
                <li><strong>All Mints:</strong> The same policy wallet is used for ALL your minting policies</li>
            </ul>

            <h4>4. Dual-Signature Minting Flow</h4>
            <ol>
                <li><strong>Customer Connects Wallet:</strong> Via CIP-30 (Eternl, Lace, Vespr, Nami, etc.)</li>
                <li><strong>Build Transaction:</strong> Anvil API builds the mint transaction with metadata</li>
                <li><strong>Customer Signs:</strong> User signs with their wallet (proves ownership + payment)</li>
                <li><strong>Policy Wallet Signs:</strong> Server adds policy wallet signature (proves minting authority)</li>
                <li><strong>Submit Transaction:</strong> Dual-signed transaction submitted to blockchain</li>
                <li><strong>Record Mint:</strong> Per-wallet limit tracking updated, quantity decremented</li>
            </ol>

            <h4>5. Per-Wallet Mint Limits & Tracking</h4>
            <ul>
                <li><strong>Set Limit:</strong> Configure "Mints Allowed Per Wallet" in Mint Manager (0 = unlimited)</li>
                <li><strong>Enforcement:</strong> Checked BEFORE transaction is built - prevents wasted gas</li>
                <li><strong>Tracking Database:</strong> Records payment address, stake address, mints allowed, minted, remaining</li>
                <li><strong>CSV Export:</strong> Download complete mint history with wallet addresses and dates</li>
                <li><strong>CSV Import (Whitelist):</strong> Pre-authorize wallets by uploading CSV with allowed mint counts</li>
                <li><strong>View History:</strong> Modal view showing all minters, their limits, and remaining mints</li>
                <li><strong>Unlimited Tracking:</strong> Even unlimited (0) mints are tracked for analytics</li>
            </ul>

            <h4>6. CIP-27 Royalty Tokens (Automatic)</h4>
            <ul>
                <li><strong>When:</strong> Automatically minted with the FIRST NFT when policy is created</li>
                <li><strong>One Per Policy:</strong> Only ONE royalty token per policy ID</li>
                <li><strong>Configuration:</strong> Set royalty percentage and address in Mint Manager</li>
                <li><strong>Blockchain Standard:</strong> Follows CIP-27 specification for NFT royalties</li>
                <li><strong>Burned:</strong> Royalty token is burned automatically during minting process</li>
                <li><strong>No Manual Action:</strong> Completely automated - just fill in royalty info</li>
            </ul>

            <h4>7. Pinata IPFS Integration</h4>
            <ul>
                <li><strong>Optional:</strong> Not required - WordPress CDN is used by default</li>
                <li><strong>Recommended:</strong> Pinata (or other IPFS pinning) ensures NFTs live forever</li>
                <li><strong>Configuration:</strong> Add Pinata JWT or API keys in Plugin Setup</li>
                <li><strong>Image Name Length:</strong> Keep under 63 characters or metadata will be too long</li>
                <li><strong>Automatic Pinning:</strong> Images uploaded to WordPress can be pinned to IPFS via admin</li>
                <li><strong>Metadata:</strong> Currently only supports image pinning (metadata stays on WordPress)</li>
            </ul>

            <h4>8. CIP-25 Metadata Builder</h4>
            <ul>
                <li><strong>Custom Attributes:</strong> Add unlimited key-value pairs in Mint Manager</li>
                <li><strong>Standard Fields:</strong> Name, image, description automatically included</li>
                <li><strong>On-Chain Metadata:</strong> All metadata is included in the minting transaction</li>
                <li><strong>JSON Preview:</strong> View metadata JSON in Active Mints page</li>
            </ul>
        </div>

        <!-- Admin Pages Guide -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🎛️ Admin Pages Guide</h3>

            <h4>Plugin Setup</h4>
            <ul>
                <li><strong>Anvil API Keys:</strong> Mainnet and Preprod (testnet) keys</li>
                <li><strong>Network:</strong> Select mainnet or preprod environment</li>
                <li><strong>Merchant Address:</strong> Your Cardano wallet address to receive payments</li>
                <li><strong>Pinata Settings:</strong> JWT or API key + secret for IPFS pinning</li>
                <li><strong>Data Cleanup:</strong> Option to delete all data on plugin deactivation</li>
            </ul>

            <h4>Mint Manager (Create New Mints)</h4>
            <ul>
                <li><strong>Title:</strong> Internal name for your NFT</li>
                <li><strong>Asset Name:</strong> On-chain name (what appears on blockchain explorers)</li>
                <li><strong>Collection ID:</strong> Numeric identifier for the collection</li>
                <li><strong>Variant:</strong> Letter suffix (A, B, C, etc.) - creates ID-variant (e.g., 20-A)</li>
                <li><strong>NFT Image:</strong> The actual NFT artwork image</li>
                <li><strong>Collection Image:</strong> Mystery box image (optional, for blind mints)</li>
                <li><strong>Price (USD):</strong> Automatically converted to ADA at time of mint</li>
                <li><strong>Quantity:</strong> Total number available to mint</li>
                <li><strong>Rarity %:</strong> Probability weight for random selection (all variants should total 100%)</li>
                <li><strong>Policy ID:</strong> Generate via button (requires expiration date first)</li>
                <li><strong>Expiration Date:</strong> When policy closes (required for time-locked policies)</li>
                <li><strong>Mints Per Wallet:</strong> Limit per wallet address (0 = unlimited)</li>
                <li><strong>Royalty Info:</strong> Percentage and address for CIP-27 royalty token</li>
                <li><strong>Metadata:</strong> Add custom CIP-25 attributes</li>
                <li><strong>Status:</strong> Active or Inactive</li>
            </ul>

            <h4>Active Mints (Manage Existing)</h4>
            <ul>
                <li><strong>Policy Groups:</strong> Mints grouped by policy ID (collapsible cards)</li>
                <li><strong>Stats Display:</strong> Variants, quantity minted, unique minters</li>
                <li><strong>Edit Variants:</strong> Click Edit to modify existing mints</li>
                <li><strong>View Policy JSON:</strong> Button to see complete policy JSON</li>
                <li><strong>Mint Tracking Actions:</strong> Export CSV, Import CSV, View History per policy</li>
                <li><strong>Unique Minters:</strong> Shows count of unique wallets that minted</li>
                <li><strong>Delete:</strong> Individual variant deletion or delete all mints</li>
            </ul>

            <h4>Policy Wallet</h4>
            <ul>
                <li><strong>Generate New:</strong> Creates secure policy wallet with 24-word seed</li>
                <li><strong>ONE-TIME DISPLAY:</strong> Seed phrase shown only once - save it immediately!</li>
                <li><strong>Current Wallet Info:</strong> Shows payment and stake addresses</li>
                <li><strong>Copy Buttons:</strong> Easy copy for addresses</li>
                <li><strong>Delete Warning:</strong> Cannot mint old NFTs if wallet is deleted</li>
            </ul>
        </div>


        <!-- Database Tables -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🗄️ Database Tables</h3>
            <ul>
                <li><code>wp_cardanonftactivemints</code> - All mint configurations (variants, metadata, pricing)</li>
                <li><code>wp_cardanonftmintcounts</code> - Legacy mint counting (deprecated, use mint_wallets)</li>
                <li><code>wp_cardano_policy_wallets</code> - Encrypted policy wallet credentials</li>
                <li><code>wp_cardano_mint_wallets</code> - Per-wallet mint tracking with limits and history</li>
            </ul>
        </div>

        <!-- Step-by-Step Workflow -->
        <div style="background: #d1ecf1; padding: 20px; border-radius: 5px; border-left: 4px solid #0c5460; margin-top: 20px;">
            <h3>📋 Complete Workflow Example</h3>
            <h4>Creating a 3-Variant Mystery Box Collection:</h4>
            <ol style="line-height: 1.8;">
                <li><strong>Plugin Setup:</strong> Add Anvil API keys, set network to preprod, add merchant address</li>
                <li><strong>Create Policy Wallet:</strong> Go to Policy Wallet page → Generate → SAVE THE 24-WORD SEED!</li>
                <li><strong>Create Variant A (Parent):</strong>
                    <ul>
                        <li>Collection ID: 25</li>
                        <li>Variant: A</li>
                        <li>NFT Image: rare-dragon.png</li>
                        <li>Collection Image: mystery-box.png (the blind reveal image)</li>
                        <li>Price: $50</li>
                        <li>Quantity: 10</li>
                        <li>Rarity: 10% (rare)</li>
                        <li>Expiration: 2025-12-31</li>
                        <li>Mints Per Wallet: 3</li>
                        <li>Royalty: 5% to your wallet</li>
                    </ul>
                </li>
                <li><strong>Generate Policy ID:</strong> Click button → Save Policy JSON → CIP-27 royalty token auto-minted</li>
                <li><strong>Create Variant B:</strong> Collection ID 25, Variant B, rarity 40%, same policy ID</li>
                <li><strong>Create Variant C:</strong> Collection ID 25, Variant C, rarity 50%, same policy ID</li>
                <li><strong>Verify Rarities:</strong> 10% + 40% + 50% = 100% ✓</li>
                <li><strong>Add Shortcode:</strong> <code>[cardano-mint mint-id="25"]</code> to your page</li>
                <li><strong>Test Mint:</strong> User sees mystery-box.png → Mints → Weighted random selection → Reveals actual NFT</li>
                <li><strong>Track Mints:</strong> Go to Active Mints → View mint history → Export CSV</li>
                <li><strong>Whitelist VIPs:</strong> Export CSV → Add wallet addresses with higher limits → Import</li>
            </ol>
        </div>

        <!-- Pro Tips -->
        <div style="background: #e7f3ff; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1; margin-top: 20px;">
            <h3>💡 Pro Tips & Best Practices</h3>
            <ul>
                <li><strong>Always Create A First:</strong> Variant A is the parent - create it before other variants</li>
                <li><strong>Mystery Box Image:</strong> Only editable on variant A in Active Mints (not Mint Manager)</li>
                <li><strong>Rarity Must Total 100%:</strong> For weighted random to work, all variant rarities should equal 100%</li>
                <li><strong>Image Names:</strong> Keep under 63 characters for Pinata/IPFS compatibility</li>
                <li><strong>Policy Wallet Backup:</strong> Save the seed phrase offline immediately</li>
                <li><strong>Test on Preprod:</strong> Always test complete flow before mainnet launch</li>
                <li><strong>CSV Whitelist Workflow:</strong> Export → Modify in Excel → Re-import (full replacement)</li>
                <li><strong>Duplicate Policy Prevention:</strong> Plugin checks if policy ID exists before creation</li>
                <li><strong>Price Conversion:</strong> USD prices automatically converted to ADA at mint time</li>
                <li><strong>Wallet Support:</strong> Eternl, Lace, Vespr, Nami, Typhon, Yoroi, Flint (CIP-30 compatible)</li>
                <li><strong>Transaction Explorer:</strong> Links to Cardanoscan automatically generated based on network</li>
            </ul>
        </div>

        <!-- Important Warnings -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px;">
            <h3>⚠️ Critical Warnings</h3>
            <ol style="line-height: 1.8;">
                <li><strong>Policy Wallet Seed:</strong> Shown ONLY ONCE during generation - if you lose it, you cannot recover it</li>
                <li><strong>Delete Policy Wallet:</strong> If deleted, you CANNOT mint NFTs from old policies - only delete if you're done with all collections</li>
                <li><strong>Test First:</strong> Use Preprod network for complete testing before mainnet</li>
                <li><strong>Duplicate Policy IDs:</strong> Same expiration + same policy wallet = same policy ID - change expiration to get unique policy</li>
                <li><strong>Metadata Length:</strong> Keep image names short (63 char max) or on-chain metadata will fail</li>
                <li><strong>Royalty Token:</strong> Minted automatically with FIRST NFT only - one per policy</li>
                <li><strong>CSV Import:</strong> Replaces ALL existing records for that policy - export first to preserve data</li>
                <li><strong>No Refunds:</strong> Blockchain transactions are irreversible</li>
            </ol>
        </div>

        <!-- Security -->
        <div style="background: #f8d7da; padding: 20px; border-radius: 5px; border-left: 4px solid #dc3545; margin-top: 20px;">
            <h3>🔒 Security & Payment Information</h3>
            <ul>
                <li><strong>Real Payments:</strong> This plugin processes real ADA payments on mainnet</li>
                <li><strong>Dual-Signature:</strong> Both customer and policy wallet must sign transactions</li>
                <li><strong>Wallet Connection:</strong> Users need CIP-30 compatible Cardano wallet browser extension</li>
                <li><strong>Encryption:</strong> Policy wallet encrypted using WordPress AUTH_KEY and SECURE_AUTH_SALT</li>
                <li><strong>Merchant Payments:</strong> Customers pay to YOUR merchant address (set in Plugin Setup)</li>
                <li><strong>Policy Wallet Funds:</strong> Policy wallet should NOT hold ADA - it's only for signing authority</li>
                <li><strong>Blockchain Verification:</strong> All transactions verified on Cardano blockchain</li>
                <li><strong>Transaction Signing:</strong> Users must approve transaction in their wallet</li>
                <li><strong>No Password Storage:</strong> Plugin uses WordPress nonces and CIP-30 wallet authentication</li>
                <li><strong>HTTPS Required:</strong> Always use HTTPS for production sites handling payments</li>
            </ul>
        </div>

        <!-- Technical Details -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 20px;">
            <h3>⚙️ Technical Details</h3>
            <ul>
                <li><strong>APIs Used:</strong> ADA Anvil (transaction building/submission), Pinata (IPFS), Blockfrost (optional)</li>
                <li><strong>Wallet Standard:</strong> CIP-30 (Cardano dApp connector)</li>
                <li><strong>Metadata Standard:</strong> CIP-25 (NFT metadata)</li>
                <li><strong>Royalty Standard:</strong> CIP-27 (NFT royalty tokens)</li>
                <li><strong>Policy Wallet CLI:</strong> Deno-based Cardano Wallet CLI (compiled to bin/sign-tx.exe)</li>
                <li><strong>Encryption:</strong> AES-256-CBC with PBKDF2 key derivation</li>
                <li><strong>Signature Encoding:</strong> CBOR hex for transaction witnesses</li>
                <li><strong>Network Support:</strong> Mainnet and Preprod (testnet)</li>
                <li><strong>Requirements:</strong> WordPress 5.0+, PHP 7.4+</li>
            </ul>
        </div>

        <!-- Troubleshooting -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px;">
            <h3>🔧 Troubleshooting</h3>
            <ul>
                <li><strong>"Policy ID already exists":</strong> Change expiration date to generate unique policy</li>
                <li><strong>"Reached maximum mints":</strong> Wallet hit per-wallet limit - check Active Mints tracking</li>
                <li><strong>"Metadata too long":</strong> Shorten image file name to under 63 characters</li>
                <li><strong>"Transaction failed":</strong> Check wallet has enough ADA for payment + tx fees (~2 ADA minimum)</li>
                <li><strong>"Policy wallet not found":</strong> Generate policy wallet first in Policy Wallet page</li>
                <li><strong>"Nonce verification failed":</strong> Clear browser cache and reload page</li>
                <li><strong>CSV import fails:</strong> Ensure CSV has correct headers (Payment Address, Stake Address, etc.)</li>
                <li><strong>Shortcode not working:</strong> Verify mint ID exists and status is "Active"</li>
            </ul>
        </div>

        <!-- Support -->
        <div style="background: #d1ecf1; padding: 20px; border-radius: 5px; border-left: 4px solid #0c5460; margin-top: 20px;">
            <h3>📚 Additional Resources</h3>
            <ul>
                <li><strong>POLICY_WALLET_ENCRYPTION.md:</strong> Detailed encryption documentation</li>
                <li><strong>README.md:</strong> Plugin overview and installation</li>
                <li><strong>mvc-diagram.md:</strong> MVC architecture diagram</li>
                <li><strong>Browser Console:</strong> Press F12 to view detailed transaction logs during minting</li>
                <li><strong>WordPress Debug Log:</strong> Enable WP_DEBUG to see server-side logs</li>
            </ul>
        </div>
    </div>
    <?php
}
?>