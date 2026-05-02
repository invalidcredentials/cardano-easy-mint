<?php
/**
 * ADA payment wallet tab.
 *
 * Cardano custodial wallets that live alongside BTC/ETH/SOL payment
 * wallets in wp_cm_chain_wallets (chain='ada'). They are completely
 * separate from policy wallets (wp_cm_policy_wallets) which sign mints.
 * This wallet just receives ADA — typically used as the merchant change
 * address for minting so funds flow into a wallet the operator controls
 * inside the plugin instead of an external wallet.
 *
 * Storage column reuse for ADA:
 *   xprv_encrypted -> encrypted payment_skey_extended
 *   xpub           -> the payment_address (NOT a Cardano public key)
 *   next_index     -> unused, stays 0 (single-address custodial, no HD derivation)
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;
use CardanoMintPay\Models\ChainWalletModel;

$networks       = AltPayAdminController::chainNetworks('ada');
$wallets        = ChainWalletModel::list_for_chain('ada', /*include_archived*/ true);
$active_wallets = array_values(array_filter($wallets, fn($w) => empty($w['archived'])));
?>

<div class="kg-altpay-chain" data-chain="ada">
    <div class="kg-altpay-chain-header">
        <h2>ADA Payment Wallets</h2>
        <p class="description" style="max-width: 760px;">
            Cardano custodial wallets for receiving ADA. Generate one here
            and copy the payment address into the plugin's Merchant Address
            setting if you want it to be the destination for minting change.
            <strong>This is NOT a policy wallet</strong>: this wallet
            receives funds, the policy wallet signs mints. They are stored
            in different tables and never mixed.
        </p>
    </div>

    <div class="kg-altpay-actions" style="display:flex; gap:12px; flex-wrap:wrap; margin: 14px 0;">
        <button type="button" class="button button-primary" data-action="altpay-generate" data-chain="ada">
            Generate New ADA Wallet
        </button>
    </div>

    <div class="kg-altpay-generate-form" data-form="altpay-generate" data-chain="ada" style="display:none; padding:12px; border:1px solid #ccd0d4; background:#f6f7f7; border-radius:6px; margin-bottom:18px;">
        <h3 style="margin-top:0;">Generate ADA Wallet</h3>
        <p class="description">A fresh 24-word recovery phrase will be created and shown <em>once</em>. Copy it to a safe place — there is no way to retrieve it after this session.</p>
        <p>
            <label style="display:block; margin-bottom:6px;">
                Wallet name:<br>
                <input type="text" data-field="name" placeholder="ADA receiving wallet" style="width:100%; max-width:360px;">
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
        <button type="button" class="button button-primary" data-action="altpay-generate-confirm" data-chain="ada">Generate</button>
        <button type="button" class="button" data-action="altpay-generate-cancel">Cancel</button>
    </div>

    <h3>Active Wallets</h3>
    <?php if (empty($active_wallets)): ?>
        <p><em>No active ADA wallet yet. Generate one above.</em></p>
    <?php else: ?>
        <table class="widefat fixed striped" style="max-width:1100px;">
            <thead>
                <tr>
                    <th style="width:14%">Name</th>
                    <th style="width:12%">Network</th>
                    <th>Payment Address</th>
                    <th style="width:10%">Source</th>
                    <th style="width:14%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($active_wallets as $w):
                $address = (string) ($w['xpub'] ?? '');
                ?>
                <tr data-wallet-id="<?php echo esc_attr($w['id']); ?>">
                    <td><strong><?php echo esc_html($w['name']); ?></strong></td>
                    <td>
                        <code style="font-family: ui-monospace, monospace; font-size: 12px;"><?php echo esc_html($w['network']); ?></code>
                        <p style="margin: 4px 0 0 0; font-size: 10px; color: #888;">
                            Network is fixed at generation; ADA addresses are network-specific.
                        </p>
                    </td>
                    <td>
                        <code style="word-break:break-all; font-size:11px; user-select:all;"><?php echo esc_html($address); ?></code>
                    </td>
                    <td><code><?php echo esc_html($w['source']); ?></code></td>
                    <td>
                        <button type="button" class="button button-small" data-action="altpay-archive" data-wallet-id="<?php echo esc_attr($w['id']); ?>">
                            Archive
                        </button>
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
                        — <code style="word-break:break-all; font-size:10px;"><?php echo esc_html($w['xpub'] ?? ''); ?></code>
                        <button type="button" class="button button-small button-link" data-action="altpay-unarchive" data-wallet-id="<?php echo esc_attr($w['id']); ?>">Unarchive</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>
