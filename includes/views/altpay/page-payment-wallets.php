<?php
/**
 * Payment Wallets - top-level admin page shell.
 *
 * Variables in scope (set by AltPayAdminController::renderPage):
 *   $tab       string  one of btc|eth|sol|invoices|settings
 *   $cap       array   capability check {ok: bool, missing: string[]}
 *   $base_url  string  admin page URL without ?tab=
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;

$tabs = [
    'dashboard' => 'Dashboard',
    'btc'       => 'BTC',
    'eth'       => 'ETH',
    'sol'       => 'SOL',
    'invoices'  => 'Invoices',
    'settings'  => 'Settings',
];
?>
<div class="wrap kg-altpay-wrap">
    <h1>Payment Wallets</h1>
    <p class="description" style="max-width: 760px;">
        Accept payment for a Cardano mint on a non-Cardano chain. Each
        chain has its own HD wallet here; once an operator generates or
        imports a parent, child addresses are minted per customer mint
        and watched for inbound payment. The customer always finishes by
        signing a small Cardano transaction (~5 ADA service fee + 1 ADA
        receipt + minted NFT), so policy signing is unchanged.
    </p>

    <?php if (!$cap['ok']): ?>
        <div class="notice notice-error">
            <p><strong>Required PHP extensions are missing:</strong>
            <?php echo esc_html(implode(', ', $cap['missing'])); ?>.
            Alt-chain payments will refuse to issue quotes until these are
            available on the host.</p>
        </div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper kg-altpay-tabs">
        <?php foreach ($tabs as $key => $label):
            $url = $base_url . '&tab=' . urlencode($key);
            $active = $tab === $key ? ' nav-tab-active' : '';
        ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </h2>

    <div class="kg-altpay-tab-body" style="margin-top: var(--space-md, 18px);">
        <?php
        $views_dir = plugin_dir_path(__FILE__);
        if ($tab === 'dashboard') {
            include $views_dir . 'tab-dashboard.php';
        } elseif (in_array($tab, ['btc','eth','sol'], true)) {
            $chain = $tab;
            include $views_dir . 'tab-chain.php';
        } elseif ($tab === 'invoices') {
            include $views_dir . 'tab-invoices.php';
        } elseif ($tab === 'settings') {
            include $views_dir . 'tab-settings.php';
        }
        ?>
    </div>
</div>
