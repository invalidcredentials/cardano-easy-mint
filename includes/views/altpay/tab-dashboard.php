<?php
/**
 * Dashboard tab. Aggregates wallet balances + recent transactions across
 * all chains so the operator can see "what's coming in" at a glance.
 *
 * Balance numbers come from a per-card AJAX call so a slow chain RPC
 * doesn't block the whole page render. Transaction log is read straight
 * out of wp_cm_chain_tx_log.
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;
use CardanoMintPay\Models\ChainWalletModel;
use CardanoMintPay\Models\ChainInvoiceModel;
use CardanoMintPay\Models\ChainTxLogModel;
use CardanoMintPay\AltPay\AltPayService;
use CardanoMintPay\AltPay\PriceOracle;

global $wpdb;

$chains = ['btc', 'eth', 'sol'];
$chainSummary = [];
foreach ($chains as $c) {
    $wallets = ChainWalletModel::list_for_chain($c, false);
    $tbl = ChainInvoiceModel::table();
    $pendingCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$tbl` WHERE chain = %s AND status = %s", $c, 'pending'));
    $consumedCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$tbl` WHERE chain = %s AND status = %s", $c, 'consumed'));
    $rate = PriceOracle::getRate($c);
    $chainSummary[$c] = [
        'wallets'  => $wallets,
        'pending'  => $pendingCount,
        'consumed' => $consumedCount,
        'rate'     => $rate,
    ];
}

$logTbl = ChainTxLogModel::table();
$invTbl = ChainInvoiceModel::table();
$recent = $wpdb->get_results(
    "SELECT t.*, i.chain AS inv_chain, i.address AS inv_address, i.mint_id AS mint_id
       FROM `$logTbl` t
       LEFT JOIN `$invTbl` i ON i.id = t.invoice_id
      ORDER BY t.seen_at DESC
      LIMIT 25",
    ARRAY_A
);

function dash_format_minor(string $chain, $minor): string {
    $minor = (string) $minor;
    if ($minor === '' || $minor === '0') return '0';
    $decimals = ['btc' => 8, 'eth' => 18, 'sol' => 9][$chain] ?? 0;
    $display  = ['btc' => 8, 'eth' => 6, 'sol' => 4][$chain] ?? $decimals;
    if (function_exists('bcdiv')) {
        $whole = bcdiv($minor, bcpow('10', (string) $decimals), 0);
        $remainder = bcmod($minor, bcpow('10', (string) $decimals));
        $frac = str_pad($remainder, $decimals, '0', STR_PAD_LEFT);
        $frac = rtrim(substr($frac, 0, $display), '0');
        return $frac !== '' ? $whole . '.' . $frac : $whole;
    }
    return (string) ($minor / pow(10, $decimals));
}
?>

<div class="kg-altpay-dashboard">
    <h2 style="margin-top: 8px;">Dashboard</h2>
    <p class="description">Live balances for every parent wallet, plus the last 25 in/out transactions across all chains. Balance lookups are async per card.</p>

    <div class="kg-altpay-dashboard-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 14px;">
        <?php foreach ($chains as $c):
            $sum = $chainSummary[$c];
            $walletCount = count($sum['wallets']);
        ?>
            <div class="kg-altpay-chain-card" data-dash-chain="<?php echo esc_attr($c); ?>"
                 style="background:#fff; border:1px solid #ccd0d4; border-radius:8px; padding:16px;">
                <div style="display:flex; align-items:baseline; justify-content:space-between;">
                    <h3 style="margin:0;"><?php echo esc_html(strtoupper($c)); ?></h3>
                    <span style="font-size:12px; color:#666;">
                        $<?php echo $sum['rate'] > 0 ? esc_html(number_format($sum['rate'], 2)) : '—'; ?> per <?php echo esc_html(strtoupper($c)); ?>
                    </span>
                </div>
                <div style="margin-top:10px;">
                    <?php if ($walletCount === 0): ?>
                        <p style="margin:0; color:#666;"><em>No wallets configured yet. <a href="<?php echo esc_url(AltPayAdminController::pageUrl(['tab' => $c])); ?>">Generate one</a>.</em></p>
                    <?php else: ?>
                        <div class="kg-altpay-wallet-rows">
                            <?php foreach ($sum['wallets'] as $w): ?>
                                <div class="kg-altpay-wallet-row" data-dash-wallet-id="<?php echo esc_attr($w['id']); ?>" style="padding:8px 0; border-top:1px dashed #eee;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                        <strong><?php echo esc_html($w['name']); ?></strong>
                                        <code style="font-size:11px; color:#888;"><?php echo esc_html($w['network']); ?></code>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:4px;">
                                        <span style="color:#666; font-size:12px;"><?php echo (int) $w['next_index']; ?> children issued</span>
                                        <span class="kg-altpay-balance" data-dash-balance="<?php echo esc_attr($w['id']); ?>" style="font-family: ui-monospace, monospace; font-size:13px;">
                                            <em>checking…</em>
                                        </span>
                                    </div>
                                    <div style="margin-top:6px; text-align:right;">
                                        <button type="button" class="button button-small button-primary"
                                                data-action="altpay-send-from-wallet"
                                                data-wallet-id="<?php echo esc_attr($w['id']); ?>"
                                                data-chain="<?php echo esc_attr($c); ?>"
                                                style="font-size:12px;">
                                            Send funds &rarr;
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:12px; display:flex; gap:14px; font-size:12px; color:#555;">
                    <span><strong style="color:#222;"><?php echo $sum['pending']; ?></strong> pending invoice<?php echo $sum['pending'] === 1 ? '' : 's'; ?></span>
                    <span><strong style="color:#222;"><?php echo $sum['consumed']; ?></strong> minted</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h3 style="margin-top:28px;">Recent transactions</h3>
    <?php if (empty($recent)): ?>
        <p><em>No tx_log entries yet. Once an invoice is funded, observed payments and refunds/sweeps will appear here.</em></p>
    <?php else: ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">When</th>
                    <th style="width:60px;">Chain</th>
                    <th style="width:80px;">Direction</th>
                    <th>Amount</th>
                    <th>Tx</th>
                    <th style="width:64px;">Invoice</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $r):
                $chain = $r['inv_chain'] ?? '';
                $provider = $chain ? AltPayService::provider($chain) : null;
                $explorer = $provider ? $provider->explorerTxUrl($r['tx_hash'], AltPayService::network_for_chain($chain)) : '';
                $when = mysql2date('Y-m-d H:i', $r['seen_at']);
            ?>
                <tr>
                    <td style="font-size:12px;"><?php echo esc_html($when); ?></td>
                    <td><code><?php echo esc_html(strtoupper((string) $chain)); ?></code></td>
                    <td>
                        <?php
                        $dir = $r['direction'];
                        $color = $dir === 'in' ? '#0a7d22' : ($dir === 'refund' || $dir === 'sweep' || $dir === 'out' ? '#a00' : '#555');
                        ?>
                        <strong style="color: <?php echo esc_attr($color); ?>;"><?php echo esc_html($dir); ?></strong>
                    </td>
                    <td>
                        <code><?php echo esc_html(dash_format_minor((string) $chain, $r['amount_minor'])); ?> <?php echo esc_html(strtoupper((string) $chain)); ?></code>
                        <span style="color:#888; font-size:11px; margin-left:6px;">(<?php echo esc_html($r['amount_minor']); ?>)</span>
                    </td>
                    <td>
                        <?php if ($explorer): ?>
                            <a href="<?php echo esc_url($explorer); ?>" target="_blank" rel="noopener" style="font-family:ui-monospace, monospace; font-size:11px;">
                                <?php echo esc_html(substr($r['tx_hash'], 0, 10) . '…' . substr($r['tx_hash'], -6)); ?>
                            </a>
                        <?php else: ?>
                            <code style="font-size:11px;"><?php echo esc_html($r['tx_hash']); ?></code>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $r['invoice_id'] ? '#' . (int) $r['invoice_id'] : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
