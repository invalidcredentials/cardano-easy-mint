<?php
/*
Plugin Name: Cardano Easy Mint
Plugin URI: https://github.com/invalidcredentials/cardano-easy-mint
Description: NFT minting for Cardano sites via the Anvil API. Alt-chain payments (BTC / ETH / SOL / ADA), fiat on-ramp, discount codes, batch quantity (1-5 per tx), wallet-network gate, asset upgrades (burn & re-mint), optional 2FA gate on the Payment Wallets admin page, and dashboard send-funds via Anvil + Blockfrost balance lookups for ADA custodial wallets.
Version: 4.6.3
Author: Pb
Author URI: https://github.com/invalidcredentials
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Text Domain: cardano-minting
Requires at least: 5.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('CARDANO_MINT_VERSION', '4.6.3');
define('CARDANO_MINT_PLUGIN_FILE', __FILE__);
define('CARDANO_MINT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CARDANO_MINT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/helpers/log.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/AnvilAPI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/PinataAPI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/EncryptionHelper.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/CardanoCLI.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/MintBuildRegistry.php';
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

// Admin pages + migrations (plain functions, split out of this file in 4.6.0).
require_once plugin_dir_path(__FILE__) . 'includes/admin/migrations.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/page-setup.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/page-mints.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/page-how-to-use.php';

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
// signs a burn of the old token, then a re-mint of the same asset name with
// new metadata (two txs: the ledger rejects a net-zero mint in one).
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/AssetUpgradeInstaller.php';
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/MetadataResolver.php';
require_once plugin_dir_path(__FILE__) . 'includes/asset-upgrade/AssetUpgradeService.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AssetUpgradeAdminController.php';
require_once plugin_dir_path(__FILE__) . 'includes/controllers/AssetUpgradePublicController.php';
\CardanoMintPay\Controllers\AssetUpgradeAdminController::register();
\CardanoMintPay\Controllers\AssetUpgradePublicController::register();

// Discount codes (e-commerce coupons). Admin creates campaigns of codes per
// policy; a code reduces the MSRP component of the price server-side (fees +
// receipt untouched) at build time.
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
// Same tick drops expired mint build records (see MintBuildRegistry).
add_action('cardano_discount_sweep_tick', ['\\CardanoMintPay\\Helpers\\MintBuildRegistry', 'sweep']);
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

        // WeldPress bridge: makes the nav's disconnect clear the mint connector's
        // saved wallet too, and clears any stale key at load. Site-wide (it must
        // run on every page to catch a disconnect from anywhere), no jQuery dep.
        $bridge_js_path = plugin_dir_path(__FILE__) . 'assets/weldpress-bridge.js';
        wp_enqueue_script(
            'cardano-mint-weldpress-bridge',
            plugin_dir_url(__FILE__) . 'assets/weldpress-bridge.js',
            [],
            file_exists($bridge_js_path) ? filemtime($bridge_js_path) : '1.0.0',
            true
        );

        wp_enqueue_style('cardano-checkout-css', plugin_dir_url(__FILE__) . 'assets/cardano-checkout.css', [], file_exists($mint_css_path) ? filemtime($mint_css_path) : '1.0.0');
        
        // Localize script for AJAX
        wp_localize_script('cardano-mint-js', 'cardanoMint', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cardanocheckoutnonce'),
            'network' => cardanomint_get_network_name(),
            'debug' => (bool) (defined('WP_DEBUG') && WP_DEBUG)
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

