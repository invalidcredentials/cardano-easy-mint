<?php
if (!defined('ABSPATH')) exit;
/**
 * [cardano-upgrade] shortcode template.
 *
 * Renders a button + a hidden modal. The accompanying upgrade.js handles
 * CIP-30 wallet connection, calls /cardano-mint/v1/upgrade/eligible, then
 * renders eligible-asset cards and the before/after diff.
 *
 * Phase 4 surface only: connect wallet → list eligible upgrades → preview
 * diff. The actual burn-and-re-mint tx build/submit lands in phase 5; the
 * "Upgrade NFT" button on the diff step is wired to a placeholder that
 * tells the customer the feature is rolling out.
 *
 * Vars in scope from the shortcode callback:
 *   $instance_id   string  unique per shortcode-on-page (in case multiple
 *                          shortcodes appear, each gets isolated DOM ids)
 *   $policy_id     string  optional shortcode attr; if set, the eligibility
 *                          call is filtered to this policy client-side
 *   $button_label  string  override for the trigger button text
 */
?>
<div class="kg-cu-root" data-instance-id="<?php echo esc_attr($instance_id); ?>" data-policy-id="<?php echo esc_attr($policy_id); ?>">
    <button type="button" class="kg-cu-trigger button button-primary" data-target="kg-cu-modal-<?php echo esc_attr($instance_id); ?>">
        <?php echo esc_html($button_label); ?>
    </button>

    <div class="kg-cu-modal" id="kg-cu-modal-<?php echo esc_attr($instance_id); ?>" role="dialog" aria-modal="true" aria-hidden="true" style="display:none">
        <div class="kg-cu-modal-backdrop" data-close></div>

        <div class="kg-cu-modal-content" role="document">
            <button type="button" class="kg-cu-modal-close" data-close aria-label="Close">&times;</button>

            <!-- Step 1: connect wallet -->
            <section class="kg-cu-step kg-cu-step-connect" data-step="connect">
                <h2>Upgrade your NFTs</h2>
                <p>Connect a Cardano wallet to see which of your NFTs are eligible for an on-chain metadata refresh.</p>
                <div class="kg-cu-wallets" data-role="wallet-list">
                    <em>Detecting installed wallets…</em>
                </div>
                <p class="kg-cu-hint">
                    Supports any CIP-30 wallet: Eternl, Lace, Vespr, Typhon, Begin, Yoroi, Flint.
                </p>
            </section>

            <!-- Step 2: loading eligibility -->
            <section class="kg-cu-step kg-cu-step-loading" data-step="loading" style="display:none">
                <h2>Checking your wallet…</h2>
                <p>Looking up the assets in your wallet and matching them against active upgrade specs. This usually takes a few seconds.</p>
                <div class="kg-cu-spinner"></div>
                <p class="kg-cu-status" data-role="loading-status"></p>
            </section>

            <!-- Step 3: pick asset -->
            <section class="kg-cu-step kg-cu-step-pick" data-step="pick" style="display:none">
                <h2>Eligible upgrades</h2>
                <p class="kg-cu-eligible-count" data-role="eligible-count"></p>
                <div class="kg-cu-asset-grid" data-role="asset-grid"></div>
                <p class="kg-cu-empty" data-role="empty-state" style="display:none">
                    None of the NFTs in this wallet match any active upgrade spec right now. If you think this is wrong, the admin may have a paused or draft spec that hasn't been activated yet.
                </p>
            </section>

            <!-- Step 4: diff + confirm -->
            <section class="kg-cu-step kg-cu-step-diff" data-step="diff" style="display:none">
                <h2 data-role="diff-title">Review changes</h2>
                <p class="kg-cu-diff-summary" data-role="diff-summary"></p>
                <div class="kg-cu-diff">
                    <div class="kg-cu-diff-col">
                        <h4>Current on-chain</h4>
                        <pre data-role="diff-current"></pre>
                    </div>
                    <div class="kg-cu-diff-col">
                        <h4>After upgrade</h4>
                        <pre data-role="diff-resolved"></pre>
                    </div>
                </div>
                <div class="kg-cu-actions">
                    <button type="button" class="button" data-action="back-to-pick">Back to list</button>
                    <button type="button" class="button button-primary" data-action="upgrade">Upgrade NFT</button>
                </div>
                <p class="kg-cu-phase-note">
                    <em>Note: the transaction build &amp; sign flow is in the next release. This button is currently a preview.</em>
                </p>
            </section>

            <!-- Step 5: error -->
            <section class="kg-cu-step kg-cu-step-error" data-step="error" style="display:none">
                <h2>Something went wrong</h2>
                <p class="kg-cu-error-msg" data-role="error-msg"></p>
                <button type="button" class="button" data-action="restart">Start over</button>
            </section>
        </div>
    </div>
</div>
