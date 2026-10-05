<?php
if (!defined('ABSPATH')) exit;
/**
 * Asset Upgrades — spec editor for one policy.
 *
 * Visited via ?page=cardano-asset-upgrades&action=edit&policy_id=...
 *
 * Renders the patch-mode editor (one JSON object that merges onto every
 * asset's current metadata) and the per-asset overrides table (each row
 * is a full-replacement spec for one asset). The per-asset row wins over
 * the patch at resolve time.
 *
 * AJAX-driven; the controller's ajax_get_policy_edit endpoint populates
 * everything on load.
 */
?>
<div class="wrap kg-asset-upgrade kg-au-edit">
    <h1>
        Edit upgrade spec
        <a href="<?php echo esc_url(admin_url('admin.php?page=cardano-asset-upgrades')); ?>" class="page-title-action">← Back to all policies</a>
    </h1>

    <div class="kg-au-policy-summary" id="kg-au-summary">
        <em>Loading policy…</em>
    </div>

    <h2>Policy-wide patch <span class="kg-au-mode-hint">applies to every asset that doesn't have a per-asset override</span></h2>
    <p class="description">
        A shallow-merge JSON diff. Top-level keys here replace the matching keys in each asset's current
        on-chain metadata; other keys are preserved. Most common shape:
        <code>{"image": "ipfs://NEW_CID"}</code>.
    </p>
    <textarea id="kg-au-patch" class="large-text code" rows="10" placeholder='{ "image": "ipfs://Qm...", "version": 2 }'></textarea>
    <p>
        <label>Status:
            <select id="kg-au-patch-status">
                <option value="draft">draft</option>
                <option value="active">active</option>
                <option value="paused">paused</option>
                <option value="completed">completed</option>
            </select>
        </label>
        <button class="button button-primary" id="kg-au-save-patch">Save patch</button>
        <span class="kg-au-spinner spinner"></span>
        <span class="kg-au-msg" id="kg-au-patch-msg"></span>
    </p>

    <hr>

    <h2>Preview against one asset</h2>
    <p class="description">Pick an asset hex name (paste from "View assets" on the main page) and we'll fetch its current chain metadata, apply the spec, and show the diff.</p>
    <p>
        <input type="text" id="kg-au-preview-asset" class="regular-text code" placeholder="asset name (hex)">
        <button class="button" id="kg-au-preview-go">Preview diff</button>
        <span class="kg-au-msg" id="kg-au-preview-msg"></span>
    </p>
    <div class="kg-au-diff" id="kg-au-diff" style="display:none">
        <div class="kg-au-diff-col">
            <h4>Current on-chain</h4>
            <pre id="kg-au-diff-current"></pre>
        </div>
        <div class="kg-au-diff-col">
            <h4>Resolved (what we'd mint)</h4>
            <pre id="kg-au-diff-resolved"></pre>
            <p class="description" id="kg-au-diff-source"></p>
        </div>
    </div>

    <hr>

    <h2>Per-asset overrides <span class="kg-au-mode-hint">full replacement — wins over the policy-wide patch</span></h2>
    <p class="description">
        Paste a CIP-25 metadata bundle (the canonical
        <code>{"721": {"&lt;policy_id&gt;": {"&lt;AssetName&gt;": {...}}}}</code> shape).
        Each asset entry under your policy becomes a per-asset row, replacing whatever the patch would
        have produced for that asset. ASCII asset names are converted to hex automatically.
    </p>
    <textarea id="kg-au-bundle" class="large-text code" rows="12" placeholder='{
  "721": {
    "<policy_id>": {
      "AssetName0001": {
        "image": "ipfs://...",
        "name":  "Whatever",
        ...
      }
    }
  }
}'></textarea>
    <p>
        <label>Status for these rows:
            <select id="kg-au-bundle-status">
                <option value="draft">draft</option>
                <option value="active">active</option>
                <option value="paused">paused</option>
            </select>
        </label>
        <button class="button button-primary" id="kg-au-save-bundle">Import bundle</button>
        <span class="kg-au-msg" id="kg-au-bundle-msg"></span>
    </p>

    <h3>Existing per-asset rows</h3>
    <table class="widefat striped kg-au-per-asset">
        <thead>
            <tr>
                <th>Asset name (hex)</th>
                <th>Asset name (ASCII)</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="kg-au-per-asset-body">
            <tr><td colspan="6"><em>Loading…</em></td></tr>
        </tbody>
    </table>

    <hr>

    <h2>Upgrade history</h2>
    <p class="description">
        Append-only audit of every customer upgrade attempt under this policy. Each tx typically shows up
        as three rows: <code>built</code>, <code>submitted</code>, and <code>confirmed</code> (the last is
        written by a 5-min cron once the tx lands on-chain). <code>failed</code> rows include the error.
    </p>
    <p>
        <button class="button" id="kg-au-history-refresh">Refresh</button>
        <span class="kg-au-msg" id="kg-au-history-msg"></span>
    </p>
    <table class="widefat striped kg-au-history">
        <thead>
            <tr>
                <th>When (UTC)</th>
                <th>Asset</th>
                <th>Wallet</th>
                <th>Status</th>
                <th>Tx hash</th>
                <th>Error</th>
            </tr>
        </thead>
        <tbody id="kg-au-history-body">
            <tr><td colspan="6"><em>Loading…</em></td></tr>
        </tbody>
    </table>
</div>
