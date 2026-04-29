<?php
/**
 * Invoices tab. Cross-chain filterable list of payment intents.
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\AltPay\AltPayService;

$filters = [
    'chain'   => isset($_GET['flt_chain'])   ? sanitize_key($_GET['flt_chain'])    : '',
    'status'  => isset($_GET['flt_status'])  ? sanitize_key($_GET['flt_status'])   : '',
    'mint_id' => isset($_GET['flt_mint_id']) ? (int) $_GET['flt_mint_id']          : 0,
];
$rows = ChainInvoiceModel::list_filtered($filters, 200);

$status_options = [
    ''          => 'Any status',
    'pending'   => 'pending',
    'funded'    => 'funded',
    'underpaid' => 'underpaid',
    'overpaid'  => 'overpaid',
    'expired'   => 'expired',
    'cancelled' => 'cancelled',
    'consumed'  => 'consumed',
    'refunded'  => 'refunded',
    'swept'     => 'swept',
];
$chain_options = ['' => 'Any chain', 'btc' => 'BTC', 'eth' => 'ETH', 'sol' => 'SOL'];
?>

<div class="kg-altpay-invoices">
    <h2>Invoices</h2>

    <form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
        <input type="hidden" name="page" value="cardano-payment-wallets">
        <input type="hidden" name="tab"  value="invoices">

        <label>Chain<br>
            <select name="flt_chain">
                <?php foreach ($chain_options as $k => $label): ?>
                    <option value="<?php echo esc_attr($k); ?>"<?php selected($filters['chain'], $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Status<br>
            <select name="flt_status">
                <?php foreach ($status_options as $k => $label): ?>
                    <option value="<?php echo esc_attr($k); ?>"<?php selected($filters['status'], $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mint ID<br>
            <input type="number" name="flt_mint_id" value="<?php echo $filters['mint_id'] ?: ''; ?>" min="1" style="width:110px;">
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button" href="<?php echo esc_url(AltPayAdminController::pageUrl(['tab' => 'invoices'])); ?>">Reset</a>
    </form>

    <?php if (empty($rows)): ?>
        <p><em>No invoices match the current filters.</em></p>
    <?php else: ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:48px;">ID</th>
                    <th style="width:60px;">Chain</th>
                    <th style="width:90px;">Status</th>
                    <th>Address</th>
                    <th>Expected</th>
                    <th>Observed</th>
                    <th style="width:64px;">Mint</th>
                    <th style="width:130px;">Created</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $explorer = '';
                if (!empty($r['observed_tx'])) {
                    $provider = AltPayService::provider($r['chain']);
                    if ($provider) {
                        $network = AltPayService::network_for_chain($r['chain']);
                        $explorer = $provider->explorerTxUrl($r['observed_tx'], $network);
                    }
                }
            ?>
                <tr data-invoice-id="<?php echo (int) $r['id']; ?>">
                    <td>#<?php echo (int) $r['id']; ?></td>
                    <td><code><?php echo esc_html(strtoupper($r['chain'])); ?></code></td>
                    <td><strong><?php echo esc_html($r['status']); ?></strong></td>
                    <td><code style="word-break:break-all;"><?php echo esc_html($r['address']); ?></code></td>
                    <td><code><?php echo esc_html($r['expected_amount_minor']); ?></code></td>
                    <td>
                        <?php if (!empty($r['observed_amount_minor'])): ?>
                            <code><?php echo esc_html($r['observed_amount_minor']); ?></code>
                            <?php if ($explorer): ?>
                                <br><a href="<?php echo esc_url($explorer); ?>" target="_blank" rel="noopener">tx&nbsp;&rarr;</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <em>—</em>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $r['mint_id']; ?></td>
                    <td><?php echo esc_html(mysql2date('Y-m-d H:i', $r['created_at'])); ?></td>
                    <td>
                        <button type="button" class="button button-small" data-action="altpay-rescan" data-invoice-id="<?php echo (int) $r['id']; ?>">Rescan</button>
                        <?php if (in_array($r['status'], ['funded','underpaid','overpaid','consumed'], true)): ?>
                            <button type="button" class="button button-small" style="margin-top:4px;"
                                    data-action="altpay-refund"
                                    data-invoice-id="<?php echo (int) $r['id']; ?>"
                                    data-chain="<?php echo esc_attr($r['chain']); ?>"
                                    data-default-amount="<?php echo esc_attr($r['observed_amount_minor'] ?: $r['expected_amount_minor']); ?>">
                                Refund
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
