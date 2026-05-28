# Cardano Easy Mint — Asset Upgrade (Burn & Re-mint) Build Plan

> Adds a per-asset NFT upgrade flow: customer connects wallet → picks an asset under a configured policy → app burns the existing token and re-mints the same asset name with updated CIP-25 metadata in a single atomic transaction. This is the in-repo source of truth for the feature's scope, architecture, and locked decisions. Any meaningful architectural change should update this file (and `DECISIONS.md` if a locked-in decision is being revisited).

## Use case

Projects routinely need to refresh CIP-25 NFT metadata after launch — new artwork, traits, files, descriptions. Under CIP-25, on-chain metadata is set at mint time; the only way to "update" an asset is to burn the old one and re-mint a new one with the same `policy_id + asset_name` (same fingerprint) but a new `721` metadata block. Doing this manually across a 1,500-asset collection is impractical; this feature makes it a one-click, customer-driven flow with the project covering nothing but the policy signature.

Constraints from Cardano natively:
- Burn requires the original policy script to authorize it (same as mint). Plugin already holds the policy skey.
- Burn + re-mint can be combined into a single transaction with two mint operations on the same policy: quantity `-1` for the old asset and `+1` for the new one. Native scripts allow this.
- If the policy has a time-lock and that slot is in the past, the policy is permanently locked — no further mints or burns. The asset is frozen.

## Architecture overview

```
   Admin (WP)                     Customer (frontend / shortcode)
       |                                       |
       v                                       v
+-----------------+              +------------------------------+
| AssetUpgrade    |              | [cardano-upgrade] shortcode  |
| AdminController |              |  + upgrade-modal.js          |
+--------+--------+              +---------------+--------------+
         |                                       |
         |   AJAX (manage_options + nonce)       |   REST (public, nonce)
         v                                       v
+----------------------------------------------------------------+
|                  AssetUpgradeService (orchestrator)            |
+----+----------+----------------+---------------+---------------+
     |          |                |               |
     v          v                v               v
 Blockfrost  Anvil API     wp_cardano_       Cardano
 (assets/    (build /       asset_           TransactionSigner
  metadata/   submit  +     upgrades         PHP
  policy      multi-mint    table            (policy-wallet
  script)     payload)                        skey signs)
```

- **AssetUpgradeAdminController** — new admin tab "Asset Upgrades". Registers a policy, snapshots its assets, presents the spec editor.
- **AssetUpgradePublicController** — REST routes the frontend hits (eligible assets per wallet, build tx, submit tx).
- **AssetUpgradeService** — pure orchestration. Pulls assets, decodes policy scripts, computes diffs, builds Anvil payloads, dispatches signing.
- **Blockfrost client (extended)** — adds `assetsByPolicy()`, `assetMetadata()`, `policyScript()`. Today the client only does balance lookups.
- **Anvil client (reused)** — extends `buildMintTransaction()` to accept multiple mint operations (positive AND negative quantities) for the same policy. Anvil's `/transactions/build` already supports this; just hasn't been exposed by the wrapper.
- **CardanoTransactionSignerPHP (reused as-is)** — produces the policy signature once Anvil returns the unsigned tx + after customer co-signs via CIP-30.

## Phases

| # | What                                                                                                                                                                                                          | Est. days |
|---|---|---|
| 0 | This doc + `docs/DECISIONS.md` scaffold + DB migration entry                                                                                                                                                  | 0.5       |
| 1 | DB schema (2 new tables) + `BlockfrostClient::assetsByPolicy / assetMetadata / policyScript`                                                                                                                  | 1-2       |
| 2 | Admin tab UI: paste/select policy ID → fetch & snapshot all assets → render list with current metadata + time-lock state                                                                                       | 2         |
| 3 | Admin spec editor: "policy-wide patch" mode (merge a JSON diff onto every asset) + "per-asset override" mode (upload CSV or paste full JSON map) + draft/active/paused state                                  | 2         |
| 4 | Frontend `[cardano-upgrade]` shortcode + CIP-30 picker (lift from existing `[cardano-mint]`) + eligible-assets fetch + before/after diff UI                                                                    | 2         |
| 5 | Anvil multi-mint payload assembly + dual-sign flow + submit + history log entry                                                                                                                                | 2-3       |
| 6 | Admin history view: per-asset upgrade outcomes (built / submitted / confirmed / failed + tx hash)                                                                                                              | 1         |
| 7 | Polish: error states, retry logic, expired-policy guard at build time, multi-quantity holdings, deactivate path, `DECISIONS.md` write-up                                                                       | 1-2       |

Total: ~10-14 working days for v1.

## DB schema (2 new tables, `wp_cardano_asset_` prefix)

### `wp_cardano_asset_upgrades`

One row per upgrade spec. Per-asset rows take precedence over the policy-wide patch row (`asset_name IS NULL`).

```
id                  BIGINT AUTO_INCREMENT PRIMARY KEY
policy_id           VARCHAR(56) NOT NULL              -- 28-byte hex policy id
asset_name          VARCHAR(128) NULL                  -- hex-encoded asset name; NULL = policy-wide patch row
upgrade_label       VARCHAR(120) NOT NULL              -- admin's short name, e.g. "v2 images"
current_metadata    LONGTEXT NULL                      -- snapshot of on-chain 721 block at register time
new_metadata        LONGTEXT NOT NULL                  -- target 721 block to mint (patch or full)
mode                ENUM('patch','full') NOT NULL      -- patch = merge on top of current, full = replace
status              ENUM('draft','active','paused','completed','locked') DEFAULT 'draft'
policy_locks_at_slot  BIGINT NULL                      -- decoded from policy script, NULL if no time-lock
created_at, updated_at, completed_at
UNIQUE KEY uniq_policy_asset (policy_id, asset_name)
KEY idx_policy_status (policy_id, status)
```

### `wp_cardano_asset_upgrade_log`

Append-only per-customer-upgrade event log. One row per attempt, success or failure.

```
id                  BIGINT AUTO_INCREMENT PRIMARY KEY
upgrade_id          BIGINT NULL                        -- FK to wp_cardano_asset_upgrades.id (NULL if pre-spec-match)
policy_id           VARCHAR(56) NOT NULL
asset_name          VARCHAR(128) NOT NULL
wallet_address      VARCHAR(120) NOT NULL              -- customer's bech32 receive addr
tx_hash             VARCHAR(64) NULL
status              ENUM('built','submitted','confirmed','failed') NOT NULL
error_message       TEXT NULL
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
KEY idx_policy_asset (policy_id, asset_name)
KEY idx_wallet (wallet_address)
```

## REST + AJAX surface

### Admin AJAX (capability `manage_options`, `cardano_nonce` nonce, gated by existing 2FA-on-Payment-Wallets if enabled site-wide)

- `cardano_upgrade_register_policy` — POST `{policy_id}` → fetches asset list + metadata + script via Blockfrost; returns `{assets: [...], policy_lock_slot, scripts_ok}`. Idempotent.
- `cardano_upgrade_save_spec` — POST `{policy_id, mode, patch_json?, per_asset?:[{asset_name, new_metadata}]}` → upserts `wp_cardano_asset_upgrades` rows.
- `cardano_upgrade_list` — GET `{policy_id?}` → list of upgrade specs with current status counters.
- `cardano_upgrade_set_status` — POST `{id, status}` → activate/pause/complete a row.
- `cardano_upgrade_history` — GET `{policy_id?}` → recent log entries.

### Customer REST (namespace `cardano-mint/v1`, nonce-based, no auth required — same auth pattern as the existing `/mint/build` endpoint)

- `POST /upgrade/eligible` — `{wallet_address}` → array of `{policy_id, asset_name, current_metadata, new_metadata, upgrade_id, status}` for assets the wallet holds that match an `active` upgrade row.
- `POST /upgrade/build` — `{policy_id, asset_name, wallet_address}` → calls Anvil with multi-mint payload, returns `{unsigned_tx_hex, tx_id}`. Re-checks time-lock at build time, refuses if expired since admin registered.
- `POST /upgrade/submit` — `{tx_id, customer_witness_hex}` → adds the policy signature, submits via Anvil, returns `{tx_hash}`. Writes a `submitted` row to the log; the existing tx confirmation watcher (cron) flips to `confirmed` once the chain sees it.

## Anvil multi-mint payload (the load-bearing detail)

Anvil's `/transactions/build` accepts an array of mint operations. Burn + re-mint in one tx looks like (illustrative — exact field shape per Anvil docs):

```json
{
  "outputs": [
    { "address": "<customer_address>", "value": { "lovelace": "<min_utxo>", "assets": [
      { "policy_id": "<P>", "asset_name": "<N_hex>", "quantity": 1 }
    ]}}
  ],
  "mint": [
    { "policy_id": "<P>", "asset_name": "<N_hex>", "quantity": -1 },
    { "policy_id": "<P>", "asset_name": "<N_hex>", "quantity":  1 }
  ],
  "metadata": { "721": { "<P>": { "<N_ascii>": <new_metadata> } } },
  "inputs_from": "<customer_address>",
  "change_address": "<customer_address>",
  "required_signers": ["<policy_keyhash>", "<customer_keyhash>"]
}
```

The net effect on the customer's wallet: the old asset (with its old metadata) is destroyed, a new asset with the same `policy_id + asset_name` (same fingerprint) and new metadata is created at the customer's address. The output UTxO carries the same min-ADA the old one did; the customer pays only the ~0.17 ADA network fee from their change.

## Customer UX flow

1. Visit a page with `[cardano-upgrade policy-id="..."]` (or omit attribute to show eligible upgrades across all configured policies).
2. Click "Upgrade Assets" → modal opens, prompts wallet connection (CIP-30 picker reused from `[cardano-mint]`).
3. App calls `POST /upgrade/eligible` with the connected wallet's bech32 address.
4. Modal shows each eligible asset card: image, name, "v1 → v2" badge.
5. Click a card → side-by-side metadata diff (`current_metadata` vs `new_metadata`, JSON pretty-printed with changed keys highlighted).
6. "Upgrade" button → `POST /upgrade/build` → wallet's `signTx()` prompted → `POST /upgrade/submit` → success modal with tx hash + Cardanoscan link.
7. After ~30s the customer can re-check their wallet; the asset's metadata reflects the new version.

## Admin UX flow

1. Cardano Mint → **Asset Upgrades** (new menu page).
2. **Register a policy:** paste a policy ID or pick from Active Mints dropdown → "Snapshot" button.
3. Plugin fetches via Blockfrost, displays the asset list (paginated table: asset name, current image thumbnail, time-lock state). Stores snapshot in `current_metadata` columns.
4. **Define the upgrade:**
   - **Patch mode** (toggle ON when "same change for every asset"): admin pastes a JSON diff, e.g. `{"image": "ipfs://Qm.../updated", "version": 2}`. Plugin saves one row with `asset_name IS NULL`, mode='patch'. At build time the diff merges onto each asset's current metadata.
   - **Per-asset mode**: admin uploads a CSV (`asset_name,new_metadata_json`) or pastes the full `721` bundle from your message format (parser walks `metadata["721"][policy_id]` → one row per key). One row per asset, mode='full'.
   - Both can coexist: patch row as fallback, per-asset rows override.
5. Each row defaults to `status='draft'`. Admin reviews, then "Activate" to make eligible-on-frontend.
6. **History tab**: log of every customer upgrade attempt with status + tx hash + Cardanoscan link.

## Patterns to reuse

- **`PolicyWalletController` + `EncryptionHelper`** — policy skey encrypted at rest, decrypted in-process only when signing. No change needed.
- **`CardanoTransactionSignerPHP`** — already does the dual-sign-and-submit for `[cardano-mint]`; same code path here, just a different unsigned tx as input.
- **`AnvilAPI.php`** — extend `buildMintTransaction()` to accept an array of `{asset_name, quantity}` ops (today it builds one mint at a time).
- **`BlockfrostClient.php`** — extend with three reads (`assetsByPolicy`, `assetMetadata`, `policyScript`). Same auth/transport.
- **CIP-30 picker in `assets/cardano_nft_mint.js`** — factor the wallet connection bit into a small shared module, used by both `[cardano-mint]` and the new `[cardano-upgrade]`.
- **`AltPayAdminController::isTotpUnlocked()` style gate** — if the site has 2FA enabled, the Asset Upgrade admin page is gated the same way (one enrollment, one secret).
- **Existing mint-confirmation cron** — repurpose to flip `wp_cardano_asset_upgrade_log` rows from `submitted` → `confirmed` once the tx lands.

## Locked decisions

| # | Decision | Why |
|---|---|---|
| D1 | Burn + re-mint in **one atomic transaction** | Cardano supports it natively; gives all-or-nothing semantics; no race window where the old token is gone but new not minted. |
| D2 | **Same `policy_id + asset_name`** required; new metadata only | Preserves asset fingerprint, downstream identity. Re-naming or cross-policy migration is a different feature. |
| D3 | **Customer pays network fee** (~0.17 ADA from change) | Standard pattern. Removes a billing dimension from MVP. Merchant subsidy can be added later via the existing Payment Wallets stack. |
| D4 | **Time-lock check at both register and build time** | A policy can lock between admin setup and a user actually upgrading; we re-check at build to refuse rather than let users sign a doomed tx. |
| D5 | **Per-asset rows beat policy-wide patch rows** | Lets admins ship a global patch for 1,490 assets and per-asset overrides for the 10 with custom changes. |
| D6 | **Admin pause without delete** | `status='paused'` halts frontend eligibility but preserves the spec for later. Deletion is admin-only and audited. |
| D7 | **2FA gate** if enabled | Consistent with Payment Wallets. Shares the existing TOTP secret/unlock transient. |
| D8 | **Audit log is append-only** | One row per build/submit/confirm/fail event. Never overwritten; closed by status. |

## Acceptance criteria

- [ ] Admin can register a policy by ID and see all its assets within 5s for collections up to ~2,000.
- [ ] Time-lock state is displayed per policy ("no lock" / "locks in N days at slot X" / "LOCKED — read-only").
- [ ] Admin can save a policy-wide patch row and have it preview-merge correctly against three sample assets.
- [ ] Admin can upload a CSV of `asset_name,new_metadata_json` and see per-asset rows created + previews.
- [ ] Frontend `[cardano-upgrade]` shortcode renders; CIP-30 wallet picker reuses the existing modal.
- [ ] Eligible-asset call returns only assets the wallet ACTUALLY holds (verified via Blockfrost) and only those that match an `active` upgrade row.
- [ ] Customer signs one tx; resulting tx burns the old asset and mints the new one with the updated `721` metadata.
- [ ] Asset's new metadata is visible on Pool.pm and Cardanoscan within ~60s of submission.
- [ ] Each upgrade attempt is recorded in `wp_cardano_asset_upgrade_log`; failures include readable error messages.
- [ ] Admin history view shows the last 50 upgrades with tx hashes that link to Cardanoscan.

## Out of scope (v1)

- **CIP-27 royalty token updates.** Royalty token lives on the policy but its metadata is at policy level, not per-asset. Separate flow if needed.
- **Cross-policy migrations.** Cardano natively prevents this and the customer would need to send the old asset somewhere else to be burned by a different policy. Not in scope.
- **Paid upgrades.** No fee collection beyond the network fee in v1. Easy to layer on later using the existing alt-pay invoice infrastructure if a project wants to monetize updates.
- **Programmatic metadata generators.** Patch and per-asset JSON only; no rules engines, no on-the-fly computation. If you want "set every asset's `level` field to `floor(rand()*5)`", run it externally and upload the CSV.
- **Bulk customer self-service** (one click upgrades ALL of my eligible assets). v1 is one-at-a-time per signed tx; bulk requires per-tx fee math + UX scope creep.
- **Re-running an upgrade after `completed`.** Once an asset is logged as confirmed-upgraded, the row is closed. New upgrade = new spec.

## Open questions

1. ~~Anvil's exact field name for negative-quantity mints~~ — **resolved 2026-05-27**: same shape as the existing mint entry, just `quantity: -1` instead of `1`. The mint endpoint accepts negative quantities natively. No sandbox needed.
2. Storage for the asset-thumbnail in the admin list — pull through Blockfrost / IPFS each time, or cache locally? Probably cache via existing `previewImage` infra in `wp_cardanonftactivemints`. **Deferred to v4.4 polish.**
3. Do we want `[cardano-upgrade]` to also work without a connected wallet by showing "Connect to see your eligible upgrades", or require connection before the shortcode renders anything? **Resolved in code**: shortcode renders the trigger button immediately; modal handles the connect-first dance, matching the [cardano-mint] pattern.
