# Cardano Easy Mint v4.3.0 — Asset Upgrade

**One-click on-chain metadata refresh for CIP-25 NFTs. Burn the old token, re-mint the new one with updated metadata, same fingerprint, atomic in a single transaction. Customer signs once, pays nothing but the ~0.17 ADA network fee.**

## Why this exists

Cardano CIP-25 sets an NFT's metadata at mint time. There's no `UPDATE` — to change the image, swap a CID, fix a typo, or upgrade artwork for a v2 release, you have to **burn the existing asset and mint a new one** with the same `policy_id + asset_name` (preserving the fingerprint) but new `721` metadata. Mechanically simple on the protocol (one tx, two mint entries, one `+1` one `-1`); painful to operationalize for a 1,500-asset collection without good tooling.

This release is that tooling. Admin defines what's changing once, customers do the upgrade themselves from their wallet, the plugin handles everything in between.

## The flow in 30 seconds

**Admin side** (Cardano Mint → Asset Upgrades):

1. Paste a policy ID, pick the network, click *Snapshot policy*. Plugin walks Blockfrost, counts the assets, decodes the policy script's time-lock state, persists a draft spec.
2. Open the edit view and choose how the metadata changes:
   - **Patch mode** — one JSON diff (`{"image": "ipfs://NEW_CID"}`) merged onto every asset's current metadata. Perfect for collection-wide visual refreshes.
   - **Per-asset mode** — paste a CIP-25 metadata bundle in the canonical `{"721": {<policy>: {<asset>: {...}}}}` shape. Plugin parses out every asset entry and creates per-NFT override rows. Use when different NFTs need different changes.
   - Both can coexist: patch as the default, per-asset overrides where needed.
3. Preview against any asset — fetches its current chain metadata, applies the spec, shows you side-by-side what the customer would see.
4. Flip status to `active` when you're ready. Customers immediately become eligible.

**Customer side** (`[cardano-upgrade]` shortcode on any page):

1. Click "Upgrade my NFTs"
2. Connect a Cardano wallet (Eternl, Lace, Vespr, Typhon — any CIP-30)
3. See a grid of every NFT they own that matches an active spec, with thumbnails pulled from current metadata
4. Click an NFT → side-by-side diff of current vs. upgraded metadata
5. Click *Upgrade NFT* → sign the tx in their wallet → done. Cardanoscan link in the success card.

That's it. The NFT in their wallet now reflects the new metadata, same fingerprint, no extra cost.

## What's in the box

### Two new admin surfaces

- **Cardano Mint → Asset Upgrades** main page: register policies, see the full list with color-coded time-lock state (no lock / locks in N days / LOCKED), assets count, view-assets pane with paginated hex+ASCII names.
- **Per-policy edit view**: patch JSON textarea + status toggle, preview pane, CIP-25 bundle import, per-asset rows table with inline status dropdown (draft/active/paused/completed), full per-policy history table.

Gated by the same TOTP unlock as Payment Wallets. One enrollment covers both pages.

### Customer-facing shortcode

`[cardano-upgrade]` renders a button + modal. Optional attributes:
- `policy-id="..."` — restrict eligibility to one policy (otherwise shows everything across all active specs)
- `label="Refresh my Knights"` — override the button text

Self-contained vanilla JS modal, no React, no bundler. Works in any theme.

### Two new REST endpoints

Under `cardano-mint/v1`:
- `POST /upgrade/eligible` — wallet address in, eligible assets with resolved metadata out
- `POST /upgrade/build` — composes the Anvil multi-mint payload, returns unsigned tx + audit row id
- `POST /upgrade/submit` — adds the policy signature, submits to chain, logs the result

### Append-only audit log

Every customer upgrade attempt writes a row per event into `wp_cardano_asset_upgrade_log`: **built → submitted → confirmed**, or **failed** with the error. A 5-minute WP-Cron watcher flips submitted to confirmed once the tx lands. Per-policy history is shown in the admin edit view with truncated wallet addresses + Cardanoscan links.

### Time-lock awareness

Policies with a `before slot N` time-lock are detected at registration and re-checked at customer build time. If the lock has fired, registration shows a clear "LOCKED — assets are frozen" badge and the customer-side build refuses with a friendly error rather than letting them sign a doomed transaction.

### Multi-quantity guard

The burn(-1)+mint(+1) pattern is only safe for 1-of-1 NFTs. If a customer somehow holds quantity > 1 of an asset they're trying to upgrade, the build refuses with a clear message rather than producing a confusing n-1 old + 1 new outcome.

## What you should know before flipping a policy to `active`

- **Time-lock is forever.** If a policy script has `before slot N` and that slot is in the past, the assets under it are frozen on-chain. Nothing this plugin (or any other) can do brings them back. Test this in preprod first, lock state is shown clearly in the admin.
- **Per-asset rows win.** If you have both a policy-wide patch and a per-asset override active for the same asset, the per-asset row is what gets minted. The patch is the fallback for everything else.
- **The customer pays the network fee** (~0.17 ADA). The plugin doesn't add a markup. Merchant subsidy would be a follow-up if you want to make upgrades free for your holders.
- **Atomic per-tx.** One customer, one signed tx, one NFT upgraded. Bulk-upgrade-all-my-assets in a single click is out of scope for v4.3.0 (would need per-tx fee math + a more complex UI). Each NFT is its own tx today.

## Architecture quick-reference

```
   Admin (WP)                       Customer (frontend)
       |                                       |
       v                                       v
+----------------+              +------------------------------+
| AssetUpgrade   |              | [cardano-upgrade] shortcode  |
| AdminController|              | + upgrade.js (CIP-30 modal)  |
+--------+-------+              +---------------+--------------+
         |                                      |
         |  AJAX (manage_options + TOTP)        | REST (public, nonce)
         v                                      v
+----------------------------------------------------------------+
|                AssetUpgradeService (orchestrator)              |
+----+-----------+-----------------+---------------+-------------+
     |           |                 |               |
     v           v                 v               v
 Blockfrost   Anvil API        wp_cardano_       Cardano
 (assets,     (build /          asset_*          TransactionSigner
  metadata,    submit  +        tables           PHP
  policy       multi-mint)                       (policy-wallet
  script)                                         skey signs)
```

7 files added, 3 modified across the plugin tree. Total ~3,000 LOC including comments. Full feature done across 6 build phases:

| Phase | What |
|-------|------|
| 1 | DB schema (2 tables) + Blockfrost reads (assets, metadata, policy script) |
| 2 | Admin tab: register policy, list, time-lock state |
| 3 | Spec editor: patch JSON + per-asset bundle + preview diff |
| 4 | Customer `[cardano-upgrade]` shortcode + CIP-30 modal + eligibility REST |
| 5 | Anvil burn-and-re-mint build + dual-sign + submit |
| 6 | Per-policy history view + 5-min confirmation watcher cron |

Full design lives in [docs/BUILD_PLAN.md](BUILD_PLAN.md). Locked design decisions in [docs/DECISIONS.md](DECISIONS.md). Wire-by-wire change log in [CHANGELOG.md](../CHANGELOG.md).

## Known thing to verify on first real upgrade

CIP-30 `signTx(tx, true)` returns differently across wallets — Eternl returns a witness set, Lace returns a fully replaced tx. The server hands whatever the wallet returned to `CardanoCLI::signTransaction`. If your first test wallet shows the policy signature failing to merge, that's the witness-set-vs-tx ambiguity — drop a note in the audit log and we'll add an explicit witness-merge step. Every other piece has been verified against the existing mint flow's behavior.

## Credit

The Anvil API team gets the assist for already supporting negative-quantity mints in their build endpoint — that one design choice made the whole flow tractable as a single-tx atomic operation rather than a two-step coordination dance.
