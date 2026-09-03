<?php
/**
 * One-time schema migrations and just-in-time installers (all idempotent, run on admin_init).
 *
 * Moved out of cardano-nft-checkout.php in 4.6.0 unchanged; the plugin entry
 * file now only wires things together. Plugin paths resolve through the
 * CARDANO_MINT_PLUGIN_DIR / _URL constants defined there.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', function() {
    if (get_option('cardano_mint_preview_image_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::add_preview_image_columns();
        update_option('cardano_mint_preview_image_migration_run', '1');
        cardanomint_log('Cardano Mint: Preview image columns migration completed');
    }
});

// One-time migration hook for archive system (can be removed after first run)
add_action('admin_init', function() {
    if (get_option('cardano_mint_archive_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_active_mints_table();
        update_option('cardano_mint_archive_migration_run', '1');
        cardanomint_log('Cardano Mint: Archive system migration completed');
    }
});

// One-time migration hook for policy wallet archive system
add_action('admin_init', function() {
    if (get_option('cardano_wallet_archive_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_policy_wallets_table();
        update_option('cardano_wallet_archive_migration_run', '1');
        cardanomint_log('Cardano Mint: Policy wallet archive migration completed');
    }
});

// One-time migration for advanced import (source column + nullable mnemonic)
add_action('admin_init', function() {
    if (get_option('cardano_mint_import_migration_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_policy_wallets_table();
        update_option('cardano_mint_import_migration_run', '1');
        cardanomint_log('Cardano Mint: Advanced import migration completed');
    }
});

// One-time migration for mint policies table (imported policy storage)
add_action('admin_init', function() {
    if (get_option('cardano_mint_policies_table_run') !== '1') {
        CardanoMintPay\Models\MintModel::install_mint_policies_table();
        update_option('cardano_mint_policies_table_run', '1');
        cardanomint_log('Cardano Mint: Mint policies table created');
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

