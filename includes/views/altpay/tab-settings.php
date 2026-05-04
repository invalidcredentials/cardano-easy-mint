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

$ada_sweep       = (string) get_option('cardano_mint_altpay_ada_sweep_address', '');
$ada_splits_on   = get_option('cardano_mint_altpay_ada_sweep_splits_enabled', '0') === '1';
$ada_splits_raw  = (string) get_option('cardano_mint_altpay_ada_sweep_splits', '[]');
$ada_splits      = json_decode($ada_splits_raw, true);
if (!is_array($ada_splits)) $ada_splits = [];
$bf_mainnet      = (string) get_option('cardano_mint_altpay_blockfrost_mainnet', '');
$bf_preprod      = (string) get_option('cardano_mint_altpay_blockfrost_preprod', '');
$bf_preview      = (string) get_option('cardano_mint_altpay_blockfrost_preview', '');

$totp_enabled    = \CardanoMintPay\Controllers\AltPayAdminController::isTotpEnabled();
$totp_recovery   = \CardanoMintPay\Controllers\AltPayAdminController::totpRecoveryRemaining();
?>

<div class="kg-altpay-settings">

    <h2 style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 18px;">&#128274;</span>
        Two-Factor Authentication
    </h2>
    <p class="description" style="max-width: 760px;">
        Optional 2FA gate on the Payment Wallets page (this page). Adds a
        second factor on top of your WordPress admin login: even if your WP
        password is compromised, an attacker cannot view or generate
        payment wallets without your authenticator app. Compatible with
        Google Authenticator, Authy, 1Password, Bitwarden, and any RFC
        6238 TOTP app. Independent from WordPress core 2FA — only gates
        this admin page.
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label>Status</label></th>
                <td>
                    <?php if ($totp_enabled): ?>
                        <strong style="color: #0a7d22;">&#10003; Enabled</strong>
                        <span style="color:#888; font-size: 12px;">
                            (<?php echo (int) $totp_recovery; ?> recovery code<?php echo $totp_recovery === 1 ? '' : 's'; ?> remaining)
                        </span>
                        <p class="description" style="margin-top: 6px;">
                            All visits to this page require a 6-digit code or recovery code. Unlock lasts 30 minutes per WP user.
                        </p>
                        <button type="button" class="button" data-action="totp-disable" style="margin-top: 10px;">
                            Disable 2FA
                        </button>
                    <?php else: ?>
                        <strong style="color:#888;">Off</strong>
                        <p class="description" style="margin-top: 6px;">
                            Recommended if you have payment wallets with non-trivial balances or multiple admins.
                        </p>
                        <button type="button" class="button button-primary" data-action="totp-begin-setup" style="margin-top: 10px;">
                            Enable 2FA
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <hr style="margin: 28px 0;">

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
                        <p class="description">Default destination for the Dashboard <strong>Send funds</strong> button. Leave blank to paste it manually each time.</p>
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

        <h2 style="margin-top:24px;">ADA</h2>
        <p class="description" style="max-width: 760px;">
            ADA payment wallets use the Anvil API for transaction build/submit
            (already configured via the main plugin's API key) and Blockfrost
            for read-only balance lookups on the dashboard. Project IDs are
            network-specific — get yours at <a href="https://blockfrost.io/" target="_blank" rel="noopener">blockfrost.io</a> (free tier is plenty).
        </p>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label>Blockfrost project ID — Mainnet</label></th>
                    <td>
                        <input type="text" name="blockfrost_mainnet" value="<?php echo esc_attr($bf_mainnet); ?>" placeholder="mainnet…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;" autocomplete="off">
                        <p class="description">Required to show mainnet ADA wallet balances on the dashboard.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Blockfrost project ID — Preprod</label></th>
                    <td>
                        <input type="text" name="blockfrost_preprod" value="<?php echo esc_attr($bf_preprod); ?>" placeholder="preprod…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Blockfrost project ID — Preview</label></th>
                    <td>
                        <input type="text" name="blockfrost_preview" value="<?php echo esc_attr($bf_preview); ?>" placeholder="preview…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Sweep target</label></th>
                    <td>
                        <input type="text" name="ada_sweep_address" value="<?php echo esc_attr($ada_sweep); ?>" placeholder="addr1… (mainnet) or addr_test1…" style="width:100%; max-width:520px; font-family: ui-monospace, monospace;">
                        <p class="description">Default destination for the Dashboard <strong>Send funds</strong> button on ADA wallets. Leave blank to paste manually each time. Ignored when split payouts are enabled below.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Split payouts</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ada_sweep_splits_enabled" id="kg-ada-splits-enabled" value="1"<?php checked($ada_splits_on); ?>>
                            Split each ADA sweep across multiple addresses by percentage.
                        </label>
                        <p class="description">When on, clicking <strong>Send funds</strong> on an ADA wallet skips the single sweep target above and builds one transaction with one output per row below. Each tx is hard-capped at <strong>1,000 ADA</strong> server-side, so large balances are drained over multiple sweeps.</p>

                        <div id="kg-ada-splits-editor" style="margin-top:14px; max-width:760px;<?php echo $ada_splits_on ? '' : ' display:none;'; ?>">
                            <table class="widefat striped" style="margin-bottom:8px;">
                                <thead>
                                    <tr>
                                        <th style="width:64%;">Address</th>
                                        <th style="width:18%;">Percent</th>
                                        <th style="width:18%;">Label (optional)</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="kg-ada-splits-rows">
                                    <?php if (empty($ada_splits)): ?>
                                        <tr class="kg-ada-split-row">
                                            <td><input type="text" class="kg-ada-split-addr" value="" placeholder="addr1… or addr_test1…" style="width:100%; font-family: ui-monospace, monospace;"></td>
                                            <td><input type="number" class="kg-ada-split-pct" value="" min="0.01" max="100" step="0.01" style="width:90px;"> %</td>
                                            <td><input type="text" class="kg-ada-split-label" value="" placeholder="treasury" style="width:100%;"></td>
                                            <td><button type="button" class="button button-small kg-ada-split-remove" title="Remove row">&times;</button></td>
                                        </tr>
                                    <?php else: foreach ($ada_splits as $row): ?>
                                        <tr class="kg-ada-split-row">
                                            <td><input type="text" class="kg-ada-split-addr" value="<?php echo esc_attr($row['address'] ?? ''); ?>" placeholder="addr1… or addr_test1…" style="width:100%; font-family: ui-monospace, monospace;"></td>
                                            <td><input type="number" class="kg-ada-split-pct" value="<?php echo esc_attr(isset($row['percent']) ? (float) $row['percent'] : ''); ?>" min="0.01" max="100" step="0.01" style="width:90px;"> %</td>
                                            <td><input type="text" class="kg-ada-split-label" value="<?php echo esc_attr($row['label'] ?? ''); ?>" placeholder="treasury" style="width:100%;"></td>
                                            <td><button type="button" class="button button-small kg-ada-split-remove" title="Remove row">&times;</button></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                                <button type="button" class="button" id="kg-ada-split-add">+ Add row</button>
                                <div style="font-family: ui-monospace, monospace; font-size:13px;">
                                    Total: <strong id="kg-ada-splits-total" style="font-size:14px;">0.00</strong>%
                                    <span id="kg-ada-splits-status" style="margin-left:8px;"></span>
                                </div>
                            </div>
                            <p class="description" style="margin-top:8px;">Percentages must sum to exactly 100.00%. Each share must be at least 1 ADA after rounding (Cardano min-UTxO). Any sub-lovelace dust from rounding stays in the wallet for the next sweep.</p>
                        </div>
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
