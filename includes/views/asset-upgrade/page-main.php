<?php
if (!defined('ABSPATH')) exit;
/**
 * Asset Upgrades — main admin page.
 *
 * Phase 2 surface only: register a policy, list registered policies, view
 * the asset list for a registered policy. Spec editor and customer
 * frontend land in later phases. See docs/BUILD_PLAN.md.
 */
?>
<div class="wrap kg-asset-upgrade">
    <h1>Asset Upgrades</h1>
    <p class="description">
        Burn-and-re-mint flow for refreshing CIP-25 metadata on existing NFTs.
        Register a policy below; later phases add the spec editor and customer-facing
        upgrade widget. See <code>docs/BUILD_PLAN.md</code> for the full design.
    </p>

    <h2>Register a policy</h2>
    <table class="form-table kg-au-register">
        <tr>
            <th scope="row"><label for="kg-au-policy-id">Policy ID</label></th>
            <td>
                <input type="text" id="kg-au-policy-id" class="large-text code" placeholder="56 hex characters" maxlength="56">
                <p class="description">The 28-byte policy script hash, hex-encoded. Find it on Pool.pm / Cardanoscan / your mint settings.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="kg-au-network">Network</label></th>
            <td>
                <select id="kg-au-network">
                    <option value="mainnet">mainnet</option>
                    <option value="preprod">preprod</option>
                    <option value="preview">preview</option>
                </select>
                <span class="description">Must match where the policy actually lives on-chain.</span>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="kg-au-label">Label (optional)</label></th>
            <td>
                <input type="text" id="kg-au-label" class="regular-text" placeholder="e.g. Knights v2 image refresh">
                <p class="description">Short human name shown in the registered list. Defaults to <code>Policy &lt;first 10 chars&gt;</code>.</p>
            </td>
        </tr>
    </table>
    <p>
        <button class="button button-primary" id="kg-au-register">Snapshot policy</button>
        <span class="kg-au-spinner spinner"></span>
        <span class="kg-au-msg" id="kg-au-register-msg"></span>
    </p>

    <hr>

    <h2>Registered policies</h2>
    <table class="widefat striped kg-au-policies">
        <thead>
            <tr>
                <th>Label</th>
                <th>Policy ID</th>
                <th>Network</th>
                <th>Assets</th>
                <th>Time-lock</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="kg-au-policies-body">
            <tr class="kg-au-policies-empty"><td colspan="7"><em>Loading…</em></td></tr>
        </tbody>
    </table>

    <div id="kg-au-assets-pane" class="kg-au-pane" style="display:none">
        <h3>Assets under <code id="kg-au-pane-policy"></code></h3>
        <p>
            <button class="button" id="kg-au-pane-refresh">Refresh from chain</button>
            <button class="button" id="kg-au-pane-close">Close</button>
            <span class="kg-au-pane-meta" id="kg-au-pane-meta"></span>
        </p>
        <div id="kg-au-pane-content"></div>
    </div>
</div>
