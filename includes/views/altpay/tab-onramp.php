<?php
/**
 * On-ramp tab — Guardarian fiat-to-ADA settings + recent sessions.
 *
 * Variables in scope: none required (renders standalone). Uses
 * cardanoOnramp.* JS object for AJAX (registered by OnrampAdminController).
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Onramp\GuardarianClient;
use CardanoMintPay\Onramp\GuardarianService;
use CardanoMintPay\Controllers\OnrampWebhookController;
use CardanoMintPay\Models\OnrampSessionModel;

$enabled       = get_option('cardano_mint_onramp_enabled', '0') === '1';
$env           = GuardarianClient::env();
$has_api_key   = GuardarianClient::isConfigured();
$has_secret    = GuardarianClient::secretKey() !== '';
$fee_buffer    = GuardarianService::feeBufferAda();
$preset_payout = GuardarianService::isPresetPayoutEnabled();
$allowlist_raw = (string) get_option(OnrampWebhookController::OPT_ALLOWLIST, '');

$recent_sessions = OnrampSessionModel::list_filtered([], 25, 0);
?>
<div class="kg-onramp-settings">

    <h2>Card Payments (Buy ADA via Guardarian)</h2>
    <p class="description" style="max-width: 760px;">
        Lets a customer buy ADA with a credit card directly inside the mint
        flow. ADA lands in the customer's own connected Cardano wallet,
        funded by Guardarian. The customer then mints normally with their
        newly-funded wallet — no operator wallet involvement, no chargeback
        exposure on this side.
    </p>

    <form id="kg-onramp-settings-form" style="margin-top: 16px;">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="kg-onramp-enabled">Enable card payments</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="onramp_enabled" id="kg-onramp-enabled" value="1"<?php checked($enabled); ?>>
                            Show the "Buy ADA with card" widget on the customer mint flow.
                        </label>
                        <p class="description">Off by default. Requires a configured API key and the <code>allow_preset_payout_address</code> partner permission on your Guardarian account.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-env">Environment</label></th>
                    <td>
                        <select name="guardarian_env" id="kg-onramp-env">
                            <option value="<?php echo esc_attr(GuardarianClient::ENV_PRODUCTION); ?>"<?php selected($env, GuardarianClient::ENV_PRODUCTION); ?>>Production</option>
                            <option value="<?php echo esc_attr(GuardarianClient::ENV_STAGING); ?>"<?php selected($env, GuardarianClient::ENV_STAGING); ?>>Staging</option>
                        </select>
                        <p class="description">Staging requires separate credentials from Guardarian (email business@guardarian.com).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-api-key">API key (x-api-key)</label></th>
                    <td>
                        <input type="password"
                               name="guardarian_api_key"
                               id="kg-onramp-api-key"
                               value=""
                               autocomplete="new-password"
                               placeholder="<?php echo $has_api_key ? '••••••••• (saved — leave blank to keep)' : 'paste your Guardarian partner API key'; ?>"
                               style="width: 100%; max-width: 520px; font-family: ui-monospace, monospace;">
                        <p class="description">
                            Stored encrypted. Leave blank when re-saving to keep the existing key.
                            <?php if ($has_api_key): ?><strong style="color:#0a7d22;">&#10003; key on file</strong><?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-secret-key">Secret key (x-secret-key, optional)</label></th>
                    <td>
                        <input type="password"
                               name="guardarian_secret_key"
                               id="kg-onramp-secret-key"
                               value=""
                               autocomplete="new-password"
                               placeholder="<?php echo $has_secret ? '••••••••• (saved — leave blank to keep)' : 'optional, only needed for partner-wide list endpoint'; ?>"
                               style="width: 100%; max-width: 520px; font-family: ui-monospace, monospace;">
                        <p class="description">Only required if you intend to call the partner-wide <code>GET /v1/transactions</code> for reconciliation. Customer flow does not need this.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-preset-payout">Pre-fill wallet address</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="preset_payout_enabled" id="kg-onramp-preset-payout" value="1"<?php checked($preset_payout); ?>>
                            Skip the address-entry step in the Guardarian iframe (advanced).
                        </label>
                        <p class="description">
                            Default off. When off, the customer types/pastes their wallet address into the iframe — our modal puts it on their clipboard automatically. Turn on only if Guardarian has enabled the <code>allow_preset_payout_address</code> permission on your partner account; otherwise transactions will be rejected.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-fee-buffer">ADA fee buffer recommendation</label></th>
                    <td>
                        <input type="number" min="1" max="100" step="1" name="fee_buffer_ada" id="kg-onramp-fee-buffer" value="<?php echo esc_attr($fee_buffer); ?>" style="width: 100px;">
                        <span style="margin-left: 6px;">ADA</span>
                        <p class="description">Shown in the customer-facing copy ("you'll need ~7 ADA in your wallet to mint"). Default 7 covers tx fee + min UTXO + headroom on a typical mint.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kg-onramp-webhook-ips">Webhook IP allowlist</label></th>
                    <td>
                        <textarea name="webhook_ip_allowlist" id="kg-onramp-webhook-ips" rows="4" style="width: 100%; max-width: 520px; font-family: ui-monospace, monospace;" placeholder="One IPv4 per line, or comma-separated."><?php echo esc_textarea($allowlist_raw); ?></textarea>
                        <p class="description">
                            Guardarian webhooks are not signed. Authentication is by source IP only. Email <code>business@guardarian.com</code> for their outbound IP list, then paste here. The webhook endpoint refuses every request when this is empty.
                        </p>
                        <p class="description" style="margin-top: 4px;">
                            Webhook URL: <code><?php echo esc_url(rest_url('cardano-mint/v1/onramp/webhooks/guardarian')); ?></code>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top: 16px;">
            <button type="submit" class="button button-primary" data-action="onramp-save">Save settings</button>
            <button type="button" class="button" data-action="onramp-test-connection" style="margin-left: 8px;">Test connection</button>
            <span id="kg-onramp-test-result" style="margin-left: 12px;"></span>
        </p>
    </form>

    <hr style="margin: 32px 0;">

    <h2>Recent sessions</h2>
    <p class="description" style="max-width: 760px;">
        Last 25 on-ramp attempts. Sessions where Guardarian reports
        <code>finished</code> mean ADA was delivered to the customer wallet
        — whether they then used it to mint is shown by the existing mint
        history. Click <strong>Refresh</strong> on any non-terminal row to
        re-poll Guardarian for its current state.
    </p>

    <?php if (empty($recent_sessions)): ?>
        <p style="margin-top: 16px; color: #888;"><em>No on-ramp sessions yet.</em></p>
    <?php else: ?>
        <table class="widefat striped" style="margin-top: 16px;">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Customer wallet</th>
                    <th>Pays</th>
                    <th>Gets (est.)</th>
                    <th>Status</th>
                    <th>Provider tx</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_sessions as $s):
                    $is_terminal = in_array($s['status'], OnrampSessionModel::TERMINAL_STATUSES, true);
                    $addr_short = strlen($s['customer_cardano_address']) > 20
                        ? substr($s['customer_cardano_address'], 0, 10) . '…' . substr($s['customer_cardano_address'], -6)
                        : $s['customer_cardano_address'];
                ?>
                    <tr data-partner-link="<?php echo esc_attr($s['external_partner_link_id']); ?>">
                        <td><?php echo esc_html(human_time_diff(strtotime($s['created_at']), current_time('timestamp')) . ' ago'); ?></td>
                        <td title="<?php echo esc_attr($s['customer_cardano_address']); ?>" style="font-family: ui-monospace, monospace;"><?php echo esc_html($addr_short); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['from_amount'], 2)); ?> <?php echo esc_html($s['from_currency']); ?></td>
                        <td><?php echo $s['to_amount_estimated'] !== null ? esc_html($s['to_amount_estimated']) . ' ADA' : '<span style="color:#999;">—</span>'; ?></td>
                        <td>
                            <span class="kg-onramp-status kg-onramp-status--<?php echo esc_attr($s['status']); ?>">
                                <?php echo esc_html($s['status']); ?>
                            </span>
                        </td>
                        <td style="font-family: ui-monospace, monospace; font-size: 11px; color: #666;">
                            <?php echo esc_html($s['provider_tx_id'] ?? '—'); ?>
                        </td>
                        <td>
                            <?php if (!$is_terminal): ?>
                                <button type="button" class="button button-small" data-action="onramp-refresh-session" data-partner-link="<?php echo esc_attr($s['external_partner_link_id']); ?>">Refresh</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
    .kg-onramp-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .kg-onramp-status--new,
    .kg-onramp-status--waiting_for_customer,
    .kg-onramp-status--waiting_for_deposit,
    .kg-onramp-status--exchanging,
    .kg-onramp-status--sending           { background: #fef3c7; color: #92400e; }
    .kg-onramp-status--on_hold           { background: #fed7aa; color: #9a3412; }
    .kg-onramp-status--finished          { background: #d1fae5; color: #065f46; }
    .kg-onramp-status--failed,
    .kg-onramp-status--expired,
    .kg-onramp-status--cancelled,
    .kg-onramp-status--refunded          { background: #fee2e2; color: #991b1b; }
</style>
