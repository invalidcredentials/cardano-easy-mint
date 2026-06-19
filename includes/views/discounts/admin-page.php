<?php
/**
 * Discounts admin page. Rendered by DiscountAdminController::render_page().
 * $policies is an array of first-asset-per-policy rows (policyid, title, price).
 *
 * The create form + lists are wired by assets/discounts/discount-admin.js over
 * admin-ajax (CM_DISCOUNT_ADMIN.{ajax_url,nonce}).
 */
if (!defined('ABSPATH')) exit;
/** @var array $policies */
?>
<div class="wrap cmd-wrap">
    <h1>Discounts</h1>
    <p class="cmd-intro">Create discount codes for a mint policy. A code reduces the
        item price (the network + service fees and the per-NFT receipt are unchanged);
        it works for ADA checkout today, and any payment method as alt-pay support lands.</p>

    <div class="cmd-cols">
        <!-- ───────── Create ───────── -->
        <div class="cmd-card cmd-create">
            <h2>New campaign</h2>
            <form id="cmd-create-form" onsubmit="return false;">
                <label class="cmd-field">
                    <span>Campaign title</span>
                    <input type="text" name="title" placeholder="Vipers Trial Mint $5 Mints" required>
                </label>

                <label class="cmd-field">
                    <span>Policy / collection</span>
                    <select name="policy_id" required>
                        <option value="">— select a policy —</option>
                        <?php foreach ((array) $policies as $p):
                            $pid   = (string) ($p['policyid'] ?? '');
                            $title = (string) ($p['title'] ?? '(untitled)');
                            $price = (float) ($p['price'] ?? 0);
                            if ($pid === '') continue;
                            $short = substr($pid, 0, 8) . '…' . substr($pid, -6);
                            ?>
                            <option value="<?php echo esc_attr($pid); ?>">
                                <?php echo esc_html($title); ?> — $<?php echo esc_html(number_format($price, 2)); ?> — <?php echo esc_html($short); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <fieldset class="cmd-field">
                    <span>Discount type</span>
                    <label class="cmd-radio"><input type="radio" name="discount_type" value="percent" checked> % off</label>
                    <label class="cmd-radio"><input type="radio" name="discount_type" value="fixed"> Fixed $ off</label>
                </fieldset>

                <label class="cmd-field" data-when="percent">
                    <span>Percent off</span>
                    <input type="number" name="percent_off" min="1" max="100" step="1" value="75"> %
                </label>
                <label class="cmd-field" data-when="fixed" hidden>
                    <span>Amount off (USD)</span>
                    $ <input type="number" name="fixed_off_usd" min="0.01" step="0.01" value="15">
                </label>

                <fieldset class="cmd-field">
                    <span>Codes</span>
                    <label class="cmd-radio"><input type="radio" name="code_mode" value="batch" checked> Batch of unique codes</label>
                    <label class="cmd-radio"><input type="radio" name="code_mode" value="shared"> One shared code</label>
                </fieldset>

                <label class="cmd-field" data-when="batch">
                    <span>How many codes</span>
                    <input type="number" name="batch_count" min="1" max="5000" step="1" value="50">
                </label>
                <label class="cmd-field" data-when="shared" hidden>
                    <span>Shared code (e.g. VIPERS20)</span>
                    <input type="text" name="shared_code" placeholder="VIPERS20" maxlength="32">
                </label>

                <label class="cmd-field">
                    <span>Uses per code <small>(1 = single-use; 0 = unlimited until expiry)</small></span>
                    <input type="number" name="uses_per_code" min="0" step="1" value="1">
                </label>

                <label class="cmd-field">
                    <span>Expires <small>(optional)</small></span>
                    <input type="datetime-local" name="expires_at">
                </label>

                <button type="submit" class="button button-primary" id="cmd-create-btn">Create campaign + generate codes</button>
                <div class="cmd-status" id="cmd-create-status"></div>
            </form>

            <div class="cmd-generated" id="cmd-generated" hidden>
                <h3>Generated codes <span id="cmd-generated-count"></span></h3>
                <textarea id="cmd-generated-list" readonly rows="8"></textarea>
                <div class="cmd-generated-actions">
                    <button type="button" class="button" id="cmd-copy-codes">Copy all</button>
                    <a class="button" id="cmd-download-codes" href="#">Download CSV</a>
                </div>
            </div>
        </div>

        <!-- ───────── List ───────── -->
        <div class="cmd-card cmd-list">
            <h2>Campaigns</h2>
            <table class="widefat striped" id="cmd-campaigns">
                <thead>
                    <tr><th>Title</th><th>Type</th><th>Codes</th><th>Redeemed</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody><tr><td colspan="6">Loading…</td></tr></tbody>
            </table>

            <div class="cmd-detail" id="cmd-detail" hidden></div>
        </div>
    </div>
</div>
