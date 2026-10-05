<?php
/**
 * Per-chain tab view (BTC / ETH / SOL).
 *
 * Variables in scope:
 *   $chain string  one of btc|eth|sol
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\AltPay\AltPayService;

$networks       = AltPayAdminController::chainNetworks($chain);
$wallets        = ChainWalletModel::list_for_chain($chain, /*include_archived*/ true);
$active_wallets = array_values(array_filter($wallets, fn($w) => empty($w['archived'])));
$rate           = \CardanoMintPay\AltPay\PriceOracle::getRate($chain);
$chain_provider    = AltPayService::provider($chain);
$expected_for_1usd = $chain_provider ? $chain_provider->expectedAmountMinor(1.0, max(0.000001, $rate)) : null;
?>

<div class="kg-altpay-chain" data-chain="<?php echo esc_attr($chain); ?>">
    <div class="kg-altpay-chain-header">
        <h2><?php echo esc_html(strtoupper($chain)); ?> Wallets</h2>
        <p class="description">
            Live USD/<?php echo esc_html(strtoupper($chain)); ?> rate (5-min cached):
            <strong>
                <?php
                if ($rate > 0) {
                    echo '$' . esc_html(number_format($rate, 2)) . ' per ' . esc_html(strtoupper($chain));
                } else {
                    echo '<span style="color:#a00;">unavailable</span>';
                }
                ?>
            </strong>
            <?php if ($rate > 0 && $expected_for_1usd): ?>
                — $1 USD &asymp; <code><?php echo esc_html($expected_for_1usd); ?></code> minor units
            <?php endif; ?>
        </p>
    </div>

    <div class="kg-altpay-actions" style="display:flex; gap:12px; flex-wrap:wrap; margin: 14px 0;">
        <button type="button" class="button button-primary" data-action="altpay-generate" data-chain="<?php echo esc_attr($chain); ?>">
            Generate New <?php echo esc_html(strtoupper($chain)); ?> Wallet
        </button>
        <button type="button" class="button" data-action="altpay-import" data-chain="<?php echo esc_attr($chain); ?>">
            Import From Mnemonic
        </button>
    </div>

    <div class="kg-altpay-generate-form" data-form="altpay-generate" data-chain="<?php echo esc_attr($chain); ?>" style="display:none; padding:12px; border:1px solid #ccd0d4; background:#f6f7f7; border-radius:6px; margin-bottom:18px;">
        <h3 style="margin-top:0;">Generate <?php echo esc_html(strtoupper($chain)); ?> Wallet</h3>
        <p class="description">A fresh BIP39 mnemonic will be created and shown <em>once</em>. Copy it to a safe place — there is no way to retrieve it after this session.</p>
        <p>
            <label style="display:block; margin-bottom:6px;">
                Wallet name:<br>
                <input type="text" data-field="name" placeholder="<?php echo esc_attr(strtoupper($chain) . ' receiving wallet'); ?>" style="width:100%; max-width:360px;">
            </label>
            <label style="display:block; margin-bottom:6px;">
                Network:<br>
                <select data-field="network">
                    <?php foreach ($networks as $n): ?>
                        <option value="<?php echo esc_attr($n); ?>"><?php echo esc_html($n); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <button type="button" class="button button-primary" data-action="altpay-generate-confirm" data-chain="<?php echo esc_attr($chain); ?>">Generate</button>
        <button type="button" class="button" data-action="altpay-generate-cancel">Cancel</button>
    </div>

    <div class="kg-altpay-import-form" data-form="altpay-import" data-chain="<?php echo esc_attr($chain); ?>" style="display:none; padding:12px; border:1px solid #ccd0d4; background:#f6f7f7; border-radius:6px; margin-bottom:18px;">
        <h3 style="margin-top:0;">Import <?php echo esc_html(strtoupper($chain)); ?> Wallet</h3>
        <p class="description">Paste a 12 or 24 word BIP39 mnemonic. xprv-form import is not yet supported.</p>
        <p>
            <label style="display:block; margin-bottom:6px;">
                Wallet name:<br>
                <input type="text" data-field="name" placeholder="<?php echo esc_attr(strtoupper($chain) . ' imported wallet'); ?>" style="width:100%; max-width:360px;">
            </label>
            <label style="display:block; margin-bottom:6px;">
                Network:<br>
                <select data-field="network">
                    <?php foreach ($networks as $n): ?>
                        <option value="<?php echo esc_attr($n); ?>"><?php echo esc_html($n); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:block;">
                Mnemonic:<br>
                <textarea data-field="secret" rows="3" placeholder="word word word ..." style="width:100%; max-width:560px; font-family: ui-monospace, SFMono-Regular, monospace;"></textarea>
            </label>
        </p>
        <button type="button" class="button button-primary" data-action="altpay-import-confirm" data-chain="<?php echo esc_attr($chain); ?>">Import</button>
        <button type="button" class="button" data-action="altpay-import-cancel">Cancel</button>
    </div>

    <h3>Active Wallets</h3>
    <?php if (empty($active_wallets)): ?>
        <p><em>No active <?php echo esc_html(strtoupper($chain)); ?> wallet yet. Generate or import one above.</em></p>
    <?php else: ?>
        <table class="widefat fixed striped" style="max-width:1100px;">
            <thead>
                <tr>
                    <th style="width:14%">Name</th>
                    <th style="width:9%">Network</th>
                    <th>Receive Address (index 0)</th>
                    <th style="width:14%">Children Issued</th>
                    <th style="width:10%">Source</th>
                    <th style="width:14%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($active_wallets as $w):
                $addr0 = '';
                if ($prov = AltPayService::provider($chain)) {
                    try { $addr0 = (string) $prov->deriveChildAddress((int) $w['id'], 0); }
                    catch (\Throwable $e) { $addr0 = ''; }
                }
                $live = ($addr0 !== '') ? AltPayAdminController::liveBalance($chain, $addr0, $w['network']) : null;
                $invoices = ChainInvoiceModel::list_filtered(['chain' => $chain], 1);
                ?>
                <tr data-wallet-id="<?php echo esc_attr($w['id']); ?>">
                    <td><strong><?php echo esc_html($w['name']); ?></strong></td>
                    <td>
                        <select data-action="altpay-set-network"
                                data-wallet-id="<?php echo esc_attr($w['id']); ?>"
                                data-current="<?php echo esc_attr($w['network']); ?>"
                                style="font-family: ui-monospace, monospace; font-size: 12px;">
                            <?php foreach ($networks as $n): ?>
                                <option value="<?php echo esc_attr($n); ?>"<?php selected($w['network'], $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php
                        // We do not store address0 explicitly in the row — it is derivable.
                        // For Phase 3 list, show the next-index counter; the on-demand
                        // derivation lives behind invoice creation.
                        ?>
                        <em>derived per-mint (next index: <?php echo (int) $w['next_index']; ?>)</em>
                    </td>
                    <td><?php echo (int) $w['next_index']; ?></td>
                    <td><code><?php echo esc_html($w['source']); ?></code></td>
                    <td>
                        <button type="button" class="button button-small" data-action="altpay-archive" data-wallet-id="<?php echo esc_attr($w['id']); ?>">
                            Archive
                        </button>
                        <p style="margin: 4px 0 0 0; font-size: 11px; color: #888;">
                            Withdraw funds from the <a href="<?php echo esc_url(AltPayAdminController::pageUrl(['tab' => 'dashboard'])); ?>">Dashboard</a>.
                        </p>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    $archived = array_values(array_filter($wallets, fn($w) => !empty($w['archived'])));
    if (!empty($archived)):
    ?>
        <details style="margin-top:18px;">
            <summary>Archived (<?php echo count($archived); ?>)</summary>
            <ul style="margin-top:10px;">
                <?php foreach ($archived as $w): ?>
                    <li>
                        <code><?php echo esc_html($w['network']); ?></code>
                        <strong><?php echo esc_html($w['name']); ?></strong>
                        — <?php echo (int) $w['next_index']; ?> children issued
                        <button type="button" class="button button-small button-link" data-action="altpay-unarchive" data-wallet-id="<?php echo esc_attr($w['id']); ?>">Unarchive</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>
