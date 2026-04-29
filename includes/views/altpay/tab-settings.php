<?php
/**
 * Settings tab for alt-chain payments.
 *
 * Covers the master enable toggle, service fee, watcher tolerance, per-chain
 * network selectors, RPC overrides, and sweep target addresses. AJAX save
 * via cardano_altpay_save_settings.
 */
if (!defined('ABSPATH')) exit;

$enabled         = get_option('cardano_mint_altpay_enabled', '0') === '1';
$service_fee     = (int) get_option('cardano_mint_service_fee_ada', 5);
$tolerance_bps   = (int) get_option('cardano_mint_altpay_amount_tolerance_bps', 100);

$btc_network     = (string) get_option('cardano_mint_altpay_btc_network', 'mainnet');
$eth_network     = (string) get_option('cardano_mint_altpay_eth_network', 'mainnet');
$sol_network     = (string) get_option('cardano_mint_altpay_sol_network', 'mainnet');

$btc_rpc         = (string) get_option('cardano_mint_altpay_btc_rpc', '');
$eth_rpc_main    = (string) get_option('cardano_mint_altpay_eth_rpc_mainnet', '');
$eth_rpc_sep     = (string) get_option('cardano_mint_altpay_eth_rpc_sepolia', '');
$sol_rpc_main    = (string) get_option('cardano_mint_altpay_sol_rpc_mainnet', '');
$sol_rpc_devnet  = (string) get_option('cardano_mint_altpay_sol_rpc_devnet', '');

$btc_sweep       = (string) get_option('cardano_mint_altpay_btc_sweep_address', '');
$eth_sweep       = (string) get_option('cardano_mint_altpay_eth_sweep_address', '');
$sol_sweep       = (string) get_option('cardano_mint_altpay_sol_sweep_address', '');
?>

<div class="kg-altpay-settings">
    <h2>Settings</h2>

    <form id="kg-altpay-settings-form">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="kg-altpay-enabled">Enable alt-chain payments</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" id="kg-altpay-enabled" value="1"<?php checked($enabled); ?>>
                            Show the BTC/ETH/SOL payment picker on the customer mint flow.
                        </label>
                        <p class="description">When off, the customer mint flow stays exactly as it is today (single Cardano tx pays the merchant). Off by default.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-altpay-fee">Cardano service fee (ADA)</label></th>
                    <td>
                        <input type="number" min="2" max="20" step="1" name="service_fee_ada" id="kg-altpay-fee" value="<?php echo esc_attr($service_fee); ?>">
                        <p class="description">Charged on the Cardano-side mint tx whenever payment came from another chain. Customer's connected wallet still needs ~<?php echo (int) ($service_fee + 2); ?> ADA total to cover this plus 1 ADA receipt + ~0.17 ADA network fee + buffer.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-altpay-tolerance">Amount tolerance (bps)</label></th>
                    <td>
                        <input type="number" min="0" max="2000" step="10" name="tolerance_bps" id="kg-altpay-tolerance" value="<?php echo esc_attr($tolerance_bps); ?>">
                        <p class="description">100 = 1.00% (recommended). Outside this range, invoices flip to <code>underpaid</code> or <code>overpaid</code> for manual review.</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:24px;">BTC</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label>Network</label></th>
                    <td>
                        <select name="btc_network">
                            <?php foreach (['mainnet','testnet','signet'] as $n): ?>
                                <option value="<?php echo esc_attr($n); ?>"<?php selected($btc_network, $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>RPC override</label></th>
                    <td>
                        <input type="url" name="btc_rpc" value="<?php echo esc_attr($btc_rpc); ?>" placeholder="https://mempool.space/api" style="width:100%; max-width:520px;">
                        <p class="description">Leave blank to use the public mempool.space endpoint for the selected network.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Sweep target</label></th>
                    <td>
                        <input type="text" name="btc_sweep_address" value="<?php echo esc_attr($btc_sweep); ?>" placeholder="bc1q…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;">
                        <p class="description">Cold address used by the per-chain sweep tool (Phase 5).</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:24px;">ETH</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label>Network</label></th>
                    <td>
                        <select name="eth_network">
                            <?php foreach (['mainnet','sepolia'] as $n): ?>
                                <option value="<?php echo esc_attr($n); ?>"<?php selected($eth_network, $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Mainnet RPC</label></th>
                    <td>
                        <input type="url" name="eth_rpc_mainnet" value="<?php echo esc_attr($eth_rpc_main); ?>" placeholder="https://eth.llamarpc.com" style="width:100%; max-width:520px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Sepolia RPC</label></th>
                    <td>
                        <input type="url" name="eth_rpc_sepolia" value="<?php echo esc_attr($eth_rpc_sep); ?>" placeholder="https://ethereum-sepolia-rpc.publicnode.com" style="width:100%; max-width:520px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Sweep target</label></th>
                    <td>
                        <input type="text" name="eth_sweep_address" value="<?php echo esc_attr($eth_sweep); ?>" placeholder="0x…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;">
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:24px;">SOL</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label>Network</label></th>
                    <td>
                        <select name="sol_network">
                            <?php foreach (['mainnet','devnet','testnet'] as $n): ?>
                                <option value="<?php echo esc_attr($n); ?>"<?php selected($sol_network, $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Mainnet RPC</label></th>
                    <td>
                        <input type="url" name="sol_rpc_mainnet" value="<?php echo esc_attr($sol_rpc_main); ?>" placeholder="https://api.mainnet-beta.solana.com" style="width:100%; max-width:520px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Devnet RPC</label></th>
                    <td>
                        <input type="url" name="sol_rpc_devnet" value="<?php echo esc_attr($sol_rpc_devnet); ?>" placeholder="https://api.devnet.solana.com" style="width:100%; max-width:520px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Sweep target</label></th>
                    <td>
                        <input type="text" name="sol_sweep_address" value="<?php echo esc_attr($sol_sweep); ?>" placeholder="Base58 address" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;">
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="button" class="button button-primary" data-action="altpay-save-settings">Save Settings</button>
            <span class="kg-altpay-save-status" style="margin-left:10px;"></span>
        </p>
    </form>
</div>
