# Changelog

All notable changes to **Cardano Easy Mint** are tracked here. Format follows [Keep a Changelog](https://keepachangelog.com/), and the project follows semantic versioning where the major number bumps on contract-breaking changes (REST shape, table shape, signing flow).

## [4.6.1] - 2026-09-03

### Changed
- The in-admin **How to Use** page now opens with a notice pointing to the GitHub README as the most up-to-date guide (the in-admin copy covers the core minting workflow and will be refreshed separately), and its banner shows the real plugin version via `CARDANO_MINT_VERSION` instead of a hard-coded string.

## [4.6.0] - 2026-09-03

Cleanup release. No schema changes and no behaviour changes for customers or
operators, plus one real bug fix that surfaced while verifying the cleanup.

### Fixed
- **Generating a policy wallet could fatal with `Class "Ed25519Compat" not found`.**
  `CardanoWalletPHP.php` guarded itself with a class-exists check placed *before*
  its own `require_once` of the Ed25519 compat layer. PHP binds the class at compile
  time, so the guard was already true when the file ran and the require never
  executed. It only worked when something earlier in the same request had loaded
  the transaction signer (which loads the compat layer too). The require now comes
  first. Verified on a live site: preprod and mainnet wallets generate with a
  24-word mnemonic, addresses, and extended signing key.

### Changed
- **All server logging goes through `cardanomint_log()`** (`includes/helpers/log.php`).
  Debug-level messages only reach the PHP error log when `WP_DEBUG` is on or the
  `cardano_mint_debug_log` filter returns true; failure paths log at error level and
  always appear. Four admin handlers that dumped the raw `$_POST` body to the log no
  longer do.
- **Browser console noise is gated the same way.** `cardano-nft-mint.js` routes its
  tracing through `cmDebug()`, a no-op unless `WP_DEBUG` is on (localized as
  `cardanoMint.debug`). `console.warn` / `console.error` are untouched.
- **The plugin entry file is a third of its old size.** The Plugin Setup page, the
  Mint Manager / Active Mints pages, the How to Use page, and the one-time
  migrations moved verbatim into `includes/admin/`. Plugin paths resolve through
  new `CARDANO_MINT_PLUGIN_DIR` / `_URL` / `_FILE` constants; `CARDANO_MINT_VERSION`
  is defined as well.
- `CardanoCLI` is now an honest 78-line facade over the pure-PHP signer and wallet
  generator (its public signatures are unchanged).

### Removed
- `includes/helpers/WalletGenerator.php`: never referenced; its body was a placeholder.
- The Deno-binary and Python fallbacks in `CardanoCLI`, the activation-time "fix
  binary permissions" step, and two address helpers keyed to the old binary's
  output format. Neither `bin/` nor `scripts/` ship with the plugin, so none of
  those paths could ever succeed; wallet generation and signing were already pure
  PHP and still are.
- The unreachable client-side address-conversion fallbacks (a Cardano Serialization
  Library path nothing loads, and a placeholder that fabricated an `addr1…` string).
  If the server-side conversion fails, the raw address is passed through, which is
  what the old chain ended up doing anyway.
- The orphaned `cardano_get_connected_wallet` AJAX handler (the last CardanoPress
  remnant) and the unauthenticated registration of the Anvil connectivity test
  (`cardano_test_anvil_api` is admin-only now; only Plugin Setup ever called it).

## [4.5.3] - 2026-09-02

Open-source release prep, plus one security fix.

### Security
- **The Anvil API key is no longer shipped to the browser.** The mint modal's
  address converter used a key localized into the page (`cardanoMint.anvilApiKey`)
  to call Anvil's `utils/addresses/parse` directly, so any visitor could read the
  operator's key from page source. The conversion now goes through a nonce-gated,
  rate-limited AJAX proxy (`cardano_convert_address`) and the key stays server-side.
  Behaviour is unchanged for customers; the README's "key is never exposed" claim is
  now actually true.

### Fixed
- **Wallet connector no longer fights a site-wide connector** (2026-06-25). The
  embedded CIP-30 manager auto-reconnected its last wallet on every page and could
  thrash against a nav-level connector. Init is now gated to pages that render the
  mint widget, connector wiring is one-shot, and there is no auto-reconnect on load.
  New `assets/weldpress-bridge.js` clears the saved wallet key at load and on a
  WeldPress disconnect; it is a harmless no-op on sites without WeldPress.

### Changed
- Plugin header: name is now **Cardano Easy Mint** (matches the repo and README),
  with License, Plugin URI, Requires at least, and Requires PHP fields.
- 2FA: the authenticator issuer is the WordPress site title (falls back to
  "Cardano Easy Mint"), and the recovery-code download no longer hard-codes a
  site name. Existing enrollments keep working (the secret is unchanged).
- Every PHP file now carries a direct-access guard.
- Added `LICENSE` (AGPL-3.0-or-later), `readme.txt`, `.gitignore`, `.distignore`.
- Removed an internal partner-onboarding note that did not belong in the repo.

## [4.5.2] - 2026-06-19

Discount codes — honest rejection messages + diagnosability (after a prod incident
where valid codes showed a generic "That code isn't valid.").

### Fixed
- **The generic "That code isn't valid." hid two very different causes.** It fired
  both when a code wasn't found AND when a code's **campaign row was missing**
  (orphaned code — e.g. codes that outlived their campaign across a
  redeploy/migration). These now read distinctly ("We couldn't find that code…"
  vs "This code is no longer set up (its promotion was removed)…").
- **The checkout JS fell back to that same string on any failed request** — so an
  unreachable validate endpoint looked identical to a bad code. It now says
  "Couldn't check that code right now — please try again." when the request
  doesn't land, instead of blaming the code.
- Every rejection path now carries a machine `reason` (`not_found`,
  `campaign_missing`, `expired`, `exhausted`, `wrong_collection`, `disabled`, …),
  and the validate endpoint **logs the code + policy + reason** server-side so a
  prod "code rejected" report is diagnosable at a glance.

### Notes
- No schema or data change. Expired / used / wrong-collection / paused already had
  specific messages; this closes the two ambiguous cases + the silent JS fallback.

## [4.5.1] - 2026-06-18

Discount codes **phase 2: alt-pay** (BTC / ETH / SOL).

### Added
- A code now applies to **every** payment method. The discount box moved to the
  payment-select step (right after wallet connect), so it's entered before a
  chain is chosen — for alt-pay it's baked into the **quoted crypto amount** at
  `/altpay/quote` (the deposit address the watcher waits on already reflects the
  discount); for ADA it still applies at build/sign.
- Alt-pay reservations are **held for the invoice's 24h life** (not the 15-min
  ADA TTL): `count_live_uses` counts invoice-linked holds regardless of age, the
  sweeper leaves them alone while the invoice is live, and releases them only
  when the invoice dies (`expired` / `cancelled` / `refunded`). The hold is
  committed when the mint is submitted (`commit_for_invoice`).

### Files
- `AltPayService::quote` (accepts `discount_code`, discounts the USD before
  locking the amount, links the reservation to the invoice), `RestApiController`
  (`/altpay/quote` threads `discount_code`), `DiscountService`
  (`link_invoice` / `commit_for_invoice`, alt-pay-aware `sweep`), `DiscountModel`
  (alt-pay-aware `count_live_uses` / `release_stale` + `release_for_dead_invoices`
  + `get_reserved_by_invoice`), the mint modal (discount box on the payment step),
  and `altpay-checkout.js` (sends the code with the quote).

## [4.5.0] - 2026-06-18

Adds **discount codes** — e-commerce-style coupons for the mint checkout (phase 1: ADA path). See `docs/DISCOUNT_CODES_BUILD_PLAN.md`.

### Added
- **Discounts admin page** (top-level, under Cardano Mint). Create a *campaign* of codes scoped to a policy: pick the policy, a discount type (**% off** or **fixed $ off**), single- or multi-use, expiry, then generate either a **batch** of unique 8-char codes or **one shared code** (e.g. `VIPERS20`). Lists campaigns with code/redemption counts, a per-campaign detail view (codes + redemptions), pause/activate, disable-code, and CSV export. Gated by `manage_options` + nonces (no TOTP — DC-D6).
- **"Have a code?" entry in the mint modal** (Step 2, ADA checkout). Validates live and rewrites the order summary with a green **Discount** line and adjusted total; the network/service fees and the 1 ADA receipt are never discounted.
- **Three tables**: `wp_cm_discount_campaigns`, `wp_cm_discount_codes`, `wp_cm_discount_redemptions` (idempotent installer, JIT `maybe_install`).
- **Server-authoritative pricing** (DC-D1): the code only reduces the MSRP component of the DB price at build time (`ajaxBuildMintTransaction`); the client never supplies a price. Because the +1 ADA/asset minting fee is added *after* the USD→ADA conversion, fees survive even a 100%-off code (DC-D2).
- **Reserve → commit → release lifecycle** (DC-D4): a use is held at build, committed (atomically) at submit, and released by a 5-min sweeper cron if the checkout is abandoned — so a single-use code can't be double-spent or wasted.
- **`cardano_discount_validate`** admin-ajax endpoint (nonce + per-IP rate limit) backing the live preview.

### Notes
- Phase 1 is the ADA path. Alt-pay (BTC/ETH/SOL) discounting at quote time is phase 2; BOGO is phase 3. The discount entry is hidden under alt-pay for now.

## [4.4.3] - 2026-06-15

### Changed
- **Removed the 1-of-1-only guard on the upgrade burn.** The burn step previously refused any asset whose on-chain quantity wasn't exactly 1 (`multi_quantity` error). It now builds the burn (−1) regardless, so an accidental multi-mint can be cleaned up: sign the burn to take e.g. 2 → 1 and simply don't sign the re-mint. Burn quantity stays −1 per signature.

## [4.4.2] - 2026-06-15

More fixes for the bulk-import (verbatim) collections.

### Fixed
- **Couldn't price an imported collection.** Pricing was locked to "variant A," but imports under an existing/imported policy have no variant A (all numeric variants), so price could never be set. Now editing **any** imported (verbatim) asset edits the **collection-wide** price / royalty / mints-per-wallet and cascades it to every asset under that policy (`update_active_mint` verbatim branch) — re-price from any asset, any time.
- **Edit form could corrupt imported metadata.** The metadata builder flattens nested JSON on save; for verbatim assets the editor now leaves the stored metadata, on-chain token name and image **untouched** — `update_active_mint` only writes the pricing fields. The edit screen hides the fixed per-asset fields (image, name, quantity, metadata builder), unlocks the pricing fields regardless of variant, and shows an "imported asset" notice.
- **Metadata viewer showed `[object Object]`.** The Active Mints metadata modal rendered nested values with `String(value)`, so `files` / `attributes` became `[object Object]` and `description` became a comma string. Nested objects/arrays now render as pretty JSON.
- **Imported NFT image now displays.** Imported assets have no WordPress attachment — their image is an `ipfs://` URI in the metadata. The Active Mints thumbnail now falls back to that (gateway-resolved; supports the CIP-25 chunked-array image form), with a gold border to denote imported.

## [4.4.1] - 2026-06-15

Fixes for using imported (skey+script) policies with the bulk JSON import, surfaced right after 4.4.0.

### Fixed
- **Active Mints hid imported-policy assets** and showed "No Active Policy Wallet." The tab filtered strictly by the active *generated* policy wallet's keyhash, but imported policies have no generated wallet — their signing key is the saved skey in `mint_policies` (which already overrides at submit time). The tab now also keeps mints whose policy has a saved imported skey (`MintModel::getImportedSkeyPolicyIds()` + a shared `cardanomint_partition_mints()` used by both filter passes), so skey-backed collections appear without a wallet.
- **Couldn't set price / royalty / mints-per-wallet for an imported policy** in the Mint Manager. Those policy-level fields were greyed as "inherited from variant A," which imported policies don't have, leaving price locked + empty. They're now editable when an imported (`data-imported`) policy is selected; the values apply to every asset under that policy, including the bulk import.
- **Imported-policy mints had no `preloadedScripts`.** Imported-policy asset rows can carry a bare/empty `policy_json`, so `buildMintTransaction` found no script and the mint would fail. It now falls back to the policy's native script from `mint_policies` (`policy_schema`, bare or wrapped) — mirroring the Asset Upgrade flow — so imported-policy assets actually mint.

## [4.4.0] - 2026-06-15

Adds a **bulk JSON import path** to the Mint Manager for collections that already have finished, mint-ready metadata — skip the per-asset form entirely.

### Added
- **Mint Manager → "Import JSON (bulk)"** path selector (alongside "Build NFT"). Paste or upload a JSON array of `{ "assetName": "...", "metadata": { ... } }` objects; each becomes its own **quantity-1** asset under the selected policy, minted **verbatim** in random order (the existing weighted-random picker handles ordering). Includes a paginated preview (thumbnail + on-chain token name + display name), per-asset validation (1–32-byte printable token name, in-list + on-chain duplicate detection), and a **batched** insert (50/request via AJAX) so thousand-asset collections don't time out. The policy-level fields (title, expiration, Policy ID, price, royalty, mints-per-wallet) are still set once at the top and applied to every imported asset.
- **`cardano_mint_import_assets`** admin AJAX endpoint backing the import (validates + inserts each batch; first batch of a new policy seeds the collection as variant A and returns its id for subsequent batches).
- **Verbatim mint path in `AnvilAPI::buildMintTransaction`.** When an asset is flagged `metadata_mode='verbatim'`, the minter uses the stored on-chain token name and the stored metadata object **exactly** — no generated `NFT_<ts>_<seq>` name, no `#NNN` suffix, no files[]/CIP-25 reconstruction, quantity forced to 1. (Form-built assets are unchanged.)

### Database
- New `metadata_mode` column on `wp_cardanonftactivemints` (`NULL` = normal/form-built, `'verbatim'` = bulk import). Added via the existing JIT column-migration. Imported assets use **numeric variants** beyond `A` (the A–Z `getNextVariant` scheme tops out at 26; the random picker selects by row id + quantity weight, so letters are irrelevant for bulk collections).

## [4.3.9] - 2026-06-15

Removes the temporary 502-investigation diagnostics now that the root cause is found and fixed (the submit endpoint's oversized response/headers were tripping nginx's default 8k `fastcgi_buffer_size` → 502; resolved server-side by raising the FastCGI buffers, and here by no longer emitting the diagnostic noise).

### Removed
- The per-checkpoint breadcrumb tracing in `eligible` / `build` / `submit` (the `cem_upgrade_*_trace` committed options + verbose `[asset-upgrade:*]` `error_log` lines) and the per-page Blockfrost timing log. These were scaffolding to chase a worker-death that turned out to be an nginx buffer limit — they add a DB write + log spam on every customer request, so they're gone.
- The `GET /upgrade/diag` endpoint (diagnostic only).
- A one-time migration (schema v3) deletes the leftover `cem_upgrade_eligible_trace` / `_build_trace` / `_submit_trace` options.

### Changed
- The REST crash guard now returns a **generic** error message to the client and logs the full detail (message + file:line + trace) server-side only — no more leaking server paths in the response.

### Kept
- The real fixes from 4.3.5–4.3.7 (bech32 reward-address normalization, two-tx burn→re-mint, `version: cip25` on the burn entry), the crash-to-JSON guard, the eligibility per-asset metadata cap, and `upgrade.js` non-JSON response handling.

## [4.3.8] - 2026-06-15

The burn leg now builds + submits and lands on-chain, but a Cloudflare-masked **502 (origin worker death)** strikes on the burn submit / re-mint build, so the re-mint never runs — leaving an asset burned-but-not-re-minted. A worker death (FPM timeout / OOM) can't be caught in PHP, so this adds committed breadcrumbs to pinpoint it.

### Added
- **Build + submit breadcrumb trace.** `AssetUpgradeService::build()` and `submit()` write a committed `cem_upgrade_build_trace` / `cem_upgrade_submit_trace` option at each checkpoint (notably right before/after the Anvil `transactions/build` and the policy-sign + `transactions/submit` round-trip). Surfaced in `GET /upgrade/diag` as `last_build_trace` / `last_submit_trace`. After a 502, the last stored stage is exactly where the worker died — the prime suspect being the submit's pure-PHP policy-sign + Anvil submit exceeding the origin's FPM/nginx timeout.

### Operational note
- A burned-but-not-re-minted asset is recoverable: the policy is still active, so re-minting the same policy+asset name restores the identical fingerprint. A "resume unfinished upgrade" path will follow once the 502 root cause is confirmed.

## [4.3.7] - 2026-06-14

### Fixed
- **Burn leg `Input validation failed`.** Anvil's `transactions/build` input schema requires `version: "cip25"` (or `cip68`) on **every** `mint` entry — including a metadata-less burn. The 4.3.6 burn entry omitted it and Anvil rejected the payload (`mint[0].version`). Added `version: 'cip25'` to the burn entry. Confirmed locally against Anvil: with `version` present the payload passes validation and proceeds to UTxO selection; `outputs` is not required for the burn.

## [4.3.6] - 2026-06-14

**Splits the burn-and-re-mint into two transactions.** The single-tx approach hit a hard ledger rule: a burn (-1) and re-mint (+1) of the same `(policy, assetName)` net to a 0-value mint, which Cardano rejects ("MintAssets cannot be created with 0 value"). CIP-25 metadata on a fixed-supply 1/1 can only be refreshed across two txs.

### Changed
- **`AssetUpgradeService::build($policy_id, $asset_name, $customer_address, $step, $burn_log_id)`** now builds one leg at a time:
  - `step='burn'` — `mint: [{ policyId, assetName, quantity: -1 }]` only (no metadata, no asset output); the NFT's UTxO is auto-selected from `changeAddress` and its ADA returns as change. Resolves the target metadata up front and stashes it in a transient keyed by the burn's log id (so policy-wide *patch* specs survive the burn, after which the on-chain metadata is gone).
  - `step='remint'` — `mint: [{ version:'cip25', policyId, assetName, quantity: +1, metadata }]` + an `outputs[]` returning the fresh asset to the customer; reads the stashed metadata via `burn_log_id` (full-mode falls back to re-resolving from the DB).
- **`AssetUpgradeService::submit($log_id, $transaction, $signatures)`** now reuses the proven mint submit path (`AnvilAPI::submitTransaction`): the client sends the unsigned tx + its CIP-30 witness set, and the server adds the policy-wallet witness before dispatching. (Previously it re-signed a full tx, an inconsistent path.)
- **`/upgrade/build`** accepts `step` (`burn`|`remint`) + `burn_log_id`; **`/upgrade/submit`** accepts `transaction` + `signatures[]` instead of `signed_tx_hex`.
- **`upgrade.js`** orchestrates the two legs back-to-back: build→sign→submit the burn, then build→sign→submit the re-mint (two wallet prompts). Success screen shows both tx hashes. The modal's stale "preview only" note is replaced with a "you'll sign twice" explainer.

### Known caveat
- Per design decision, the two txs are submitted **back-to-back without waiting for the burn to confirm**. On a wallet with few spare UTxOs, Anvil may reselect the burn's input when building the re-mint, causing the re-mint to fail; retrying (after the burn confirms) resolves it. Revisit with confirmation-gating if it bites in practice.

## [4.3.5] - 2026-06-14

**Root-cause fix for the `/upgrade/eligible` "502".** It was never a timeout, OOM, or worker crash (4.3.3/4.3.4 chased those and changed nothing). It was an address-normalization bug returning a clean application 502 that Cloudflare then re-skinned as its own "Bad gateway" HTML page — which is why the browser saw `<!DOCTYPE`/502 instead of the real error.

### Fixed
- **Reward (stake) address normalization.** CIP-30 `getRewardAddresses()` returns the reward address as raw hex (header `0xe?`/`0xf?` + 28-byte credential). `route_eligible` passed it to `AnvilAPI::convertAddressToBech32()`, which only handles *payment* addresses and returns a reward address unchanged. The `is_stake` check then failed, the request fell into the unsupported payment-address branch, and returned a 502 ("Payment-address lookup not yet supported"). Eligibility now bech32-encodes the hex reward address itself (new self-contained `bech32_encode`, BIP-173) → correct `stake1…`, then queries Blockfrost normally. Verified locally end-to-end against mainnet: the test wallet's `Viperions_2645` now resolves in <1s.
- Bundled a minimal `bech32_encode` + `normalize_stake_address` helper; no new dependency.

### Note
- The diagnostics added in 4.3.3/4.3.4 (checkpoint trace, `/upgrade/diag`, N+1 cap) are retained — they're harmless and useful — but the N+1 was not the cause here (the test wallet holds only 7 assets).

## [4.3.4] - 2026-06-14

The N+1 cap (4.3.3) didn't clear the `/upgrade/eligible` 502, so the worker is dying earlier than the per-asset loop. Adds self-service diagnostics that don't require server-log access.

### Added
- **DB breadcrumb trace.** Each eligibility checkpoint now writes a committed `cem_upgrade_eligible_trace` option (stage + elapsed seconds + UTC time), in addition to the error_log line. A committed DB write survives the worker being killed, so the last stored stage is exactly where the request died.
- **`GET /cardano-mint/v1/upgrade/diag`.** A dependency-free endpoint (no external calls, can't crash like eligible) that returns PHP version, `memory_limit`, `max_execution_time`, Blockfrost configured-per-network booleans, Anvil mint key set (boolean), active-spec count, and the last eligibility breadcrumb. Reproduce the 502, then open this URL to see how far eligible got and whether the environment is configured. No secrets are exposed — only booleans.

## [4.3.3] - 2026-06-14

Diagnoses and mitigates a Cloudflare **502** on `/upgrade/eligible` — the PHP-FPM worker was being killed (timeout/OOM) before it could return, so no JSON came back. A 502 is an origin worker death, not a catchable PHP error, so the 4.3.2 try/catch guard couldn't surface it.

### Changed
- **Capped the eligibility N+1.** `route_eligible` fetched current on-chain metadata via a **separate Blockfrost call per matching asset**, on top of walking the wallet's full asset list (up to 200 paginated calls). On a large wallet this ran past PHP-FPM's `request_terminate_timeout` and the worker was killed → 502. Per-asset metadata fetches are now capped (30/request); assets beyond the cap are still returned eligible with `current` resolved lazily at the diff/build step.

### Added
- **Checkpoint logging through the eligibility path** (`[asset-upgrade:eligible]` with elapsed seconds): address normalize, active-spec count, wallet-asset count, and each Blockfrost metadata call. Plus per-page timing in `BlockfrostClient::assetsAtStakeAddress` (`[asset-upgrade:blockfrost]`). Because a worker death can't be caught in PHP, the last log line before the gap pinpoints exactly which call killed the request.

## [4.3.2] - 2026-06-13

Fixes the **Asset Upgrade** customer flow (`[cardano-upgrade]`) failing with a browser-side `Unexpected token '<', "<!DOCTYPE"... is not valid JSON`, and a build-path bug that prevented imported policies from being upgraded.

### Fixed
- **Imported-policy script lookup.** `AssetUpgradeService::load_policy_script_json()` read a non-existent `policy_json` column on the `cardano_mint_policies` table, so policies brought in via the Policy Wallet skey+script importer were never found and the build refused with "Policy script JSON not available locally." It now reads `policy_schema` (where imports actually store the native script), keeping the active-mints `policy_json` fallback for plugin-minted collections.

### Added
- **Crash-to-JSON guard on the upgrade REST routes.** `route_eligible` / `route_build` / `route_submit` now run inside a try/catch that converts any uncaught `Throwable` into a JSON `{ ok:false, error:"<message> @ file:line" }` (HTTP 500) and `error_log`s the trace. Previously an uncaught fatal let WordPress serve its HTML "critical error" page, which the frontend `fetch().json()` choked on with the `<!DOCTYPE` parse error — hiding the real cause.
- **Non-JSON response handling in `upgrade.js`.** `parseJson` now reads the body as text and, when it isn't valid JSON, surfaces `"Server returned a non-JSON response (HTTP <status>): <snippet>"` instead of the opaque `Unexpected token '<'`. `fetchEligible` routes through the same helper.

## [4.3.1] - 2026-06-13

Fixes the **Advanced → Skey + Script / Full Manual** policy import on the Policy Wallet page, which rejected valid input with a generic "Invalid native script JSON" error.

### Fixed
- **Import accepts the full policy wrapper, not just the bare native script.** Our own policy-derivation tooling exports `{policyId, script, schema, policyKeyHash, signers}`, where the native script lives under `schema`. The importer previously required a top-level `type` field and bailed on the wrapper. It now auto-unwraps the `schema` object, so the exported policy JSON can be pasted as-is. Bare native scripts (`{"type":"all",...}`) still work unchanged.
- **Multisig signer validation.** Key-against-script validation walked the script for the *first* `sig` keyHash only and compared against that one hash. For `any` / `atLeast` multisig policies this falsely rejected a signing key that was a legitimate signer further down the script. It now collects *every* signer keyHash and accepts the key if it matches **any** of them.
- **Actionable parse errors.** `json_decode` failures now surface `json_last_error_msg()` plus a hint to paste the bare script; structural failures (no resolvable `type`) say so explicitly instead of one catch-all message. Input is also tolerated for a leading UTF-8 BOM and smart/curly quotes from copy-paste.
- **Anvil serialize payload shape.** The three import paths called `utils/native-scripts/serialize` with the bare native script as the request body, which Anvil rejected with "Input validation failed". The endpoint expects the script wrapped as `{ "schema": <native script> }` (matching how `generatePolicy()` already calls it); all three call sites now wrap correctly.

### Notes
- Native-script policy IDs are network-independent (blake2b of the script bytes), so a preprod-vs-mainnet setting never affects whether an imported policy ID resolves correctly — only slot→expiration conversion and mint-time tx building are network-sensitive.

## [4.3.0] - 2026-05-27

Adds the **Asset Upgrade** subsystem: a per-asset CIP-25 metadata refresh flow that burns the existing NFT and re-mints the same asset name with new metadata in a single transaction. Customer connects their wallet, picks an eligible NFT, signs once — same fingerprint, new metadata.

### Added
- **New admin page** Cardano Mint → Asset Upgrades. Register a policy by ID, snapshot every asset under it via Blockfrost (paginated `/assets/policy/{id}`), decode the policy's native script for time-lock state, persist a draft policy-wide spec row. Gated by the same TOTP unlock as Payment Wallets — one enrollment covers both.
- **Spec editor** with two complementary modes. *Patch mode* (one JSON diff that shallow-merges onto every asset's current metadata) for collection-wide changes like swapping all images to a new IPFS CID. *Per-asset mode* (full-replacement bundle paste in the canonical `{"721": {<policy>: {<asset>: {...}}}}` CIP-25 shape) for per-NFT overrides. Per-asset rows take precedence at resolve time. Draft / active / paused / completed status per row, with activation refused if `new_metadata` is empty.
- **Preview pane** that fetches an asset's current chain metadata via Blockfrost and shows the resolved result side-by-side with the source label (per-asset full vs. policy-wide patch).
- **Customer-facing `[cardano-upgrade]` shortcode.** Self-contained vanilla-JS modal that detects installed CIP-30 wallets (`window.cardano.*`), enables on click, pulls a reward (stake) address via `api.getRewardAddresses()`, calls a new REST endpoint to fetch eligible assets, renders a grid with IPFS thumbnails, opens a before/after JSON diff on card click, and runs the build-sign-submit dance on the Upgrade button. Optional `policy-id` attr filters eligibility to one policy; optional `label` attr overrides the trigger text.
- **REST surface** under `cardano-mint/v1`. `POST /upgrade/eligible` matches the connected wallet's holdings to active upgrade specs, applies per-asset overrides or merges the policy-wide patch onto current chain metadata, returns the resolved list. `POST /upgrade/build` composes an Anvil multi-mint payload (one entry at `quantity=-1`, one at `+1` with new metadata, same policy + asset name) and returns the unsigned tx + audit log id. `POST /upgrade/submit` adds the policy signature via `CardanoCLI` and submits.
- **Append-only audit log** `wp_cardano_asset_upgrade_log`. One row per event (built / submitted / confirmed / failed) per upgrade attempt. Per-policy history panel on the edit page; rows include short-form tx hashes linked to Cardanoscan.
- **Confirmation watcher** WP-Cron `cardano_asset_upgrade_confirm_tick` at 5-minute interval. Picks up to 25 'submitted' audit rows per tick (deduped by tx_hash), queries Blockfrost `/txs/{hash}`, inserts a 'confirmed' event when the tx lands. 404 treated as "not yet"; other errors close the row as 'failed' so we stop polling.
- **`BlockfrostClient` extensions.** Four new reads: `assetsByPolicy`, `assetMetadata`, `policyScript`, `assetsAtStakeAddress`, `getTransaction`. Shared private `get()` helper returning `{ok, data, status, error}` so callers don't have to disambiguate 404 vs. genuine failures. `decodePolicyLockSlot()` walker pulls the first `before` slot out of typical CIP-25 policy script structures (`all` of: sig, before).
- **Time-lock guard at both register and build time.** If a policy's `before slot N` is in the past, registration refuses with a clear message; if it lands in the past between registration and a customer build, the build refuses before any wallet signature is requested.
- **Multi-quantity guard.** Refuses to burn-and-re-mint when the asset's on-chain quantity is > 1 — the burn(-1)+mint(+1) pattern is only safe for 1-of-1 CIP-25 NFTs.

### Database
- New table `wp_cardano_asset_upgrades` (one row per upgrade spec, keyed `(policy_id, asset_name)` with `asset_name = ''` marking the policy-wide patch row). Empty string instead of NULL so the UNIQUE KEY actually enforces one-per-(policy, asset). Schema version flag in `cardano_mint_asset_upgrade_schema_version`.
- New table `wp_cardano_asset_upgrade_log` (append-only event log; see audit log above).
- Both created on activation and re-verified via the same `admin_init` JIT-migration pattern AltPay uses.

### Docs
- `docs/BUILD_PLAN.md` — feature design, architecture, phases, schema, REST contract, locked decisions, acceptance criteria, OOS list.
- `docs/DECISIONS.md` — the 8 locked decisions (AU-D1 through AU-D8) the feature is built on.

### Known unverified
- CIP-30 `signTx(tx, true)` return shape varies (Eternl returns a witness set, Lace returns a full tx). The server hands whatever the wallet returned to `CardanoCLI::signTransaction`; if a sandbox round shows we need to merge witness sets explicitly, we'll add a witness-merge step.

### Verified
- **Anvil burn payload shape**: `quantity: -1` on a CIP-25 mint entry (same shape as our existing positive-quantity mint flow, just with the negative). Confirmed by pb 2026-05-27 — the mint endpoint accepts negative quantities natively.

## [4.1.0] - 2026-05-01

Adds ADA as a fourth chain in the Payment Wallets surface (custodial receive), a 2FA gate on the Payment Wallets page, and a richer dashboard with Blockfrost-backed balances, send-funds via Anvil, and per-wallet receive-address display + copy for every chain.

### Added
- **ADA payment wallet.** New `ada` chain in `wp_cm_chain_wallets` (chain='ada'), single-address custodial model. Reuses `CardanoCLI::generateWallet()` from the policy-wallet flow but persists into the alt-pay polymorphic table so the wallet lives next to BTC/ETH/SOL in the UI. Stays cleanly separate from policy wallets in `wp_cm_policy_wallets` by design — this wallet receives funds, the policy wallet signs mints. Network is fixed at generation time (mainnet / preprod / preview) since ADA addresses are network-specific.
- **2FA / TOTP gate on the Payment Wallets page.** New `TOTPHelper` (pure-PHP RFC 6238, no Composer dependency). Settings tab toggle to enable. Setup flow shows the base32 secret + otpauth URI for any TOTP authenticator (Google Authenticator, Authy, 1Password, Bitwarden), and only persists the secret after a successful first verification. Compatible with `EncryptionHelper` for the at-rest secret. 30-min unlock per WP user, 5-fail/5-min cooldown, lockscreen documents the SSH escape hatch.
- **Recovery codes.** 5 single-use codes generated at TOTP setup, shown once with copy + download .txt actions, persisted via `password_hash()` so DB compromise does not reveal them. Recovery codes work as a drop-in replacement for the 6-digit code on the lockscreen.
- **Cardano dashboard card.** New ADA card on the Payment Wallets dashboard with Blockfrost-backed balance lookup. Inherits the existing `data-dash-balance` async pattern.
- **Send funds for ADA wallets.** `ajaxSendFromWallet` branches on `chain='ada'` and calls a new `sendFromAdaWallet` private method that POSTs to Anvil `/transactions/build` with auto UTxO selection, signs locally via `CardanoTransactionSignerPHP` using the wallet's encrypted `payment_skey_extended`, and submits via Anvil `/transactions/submit`. Bypasses `AnvilAPI::submitTransaction` so policy-wallet co-signing is not triggered (this is a transfer, not a mint).
- **`BlockfrostClient` helper.** Address-balance read endpoint, network-aware project IDs, 404-as-zero-balance handling. Free tier is sufficient for dashboard polling. Used only for ADA reads; transaction build/submit go through Anvil.
- **Receive-address display + copy on every wallet row** in the dashboard. ADA shows the stored payment address; BTC/ETH/SOL derive index-0 via the provider's `deriveChildAddress`, cached 1h since the index-0 address is deterministic per wallet. Copy button uses the existing `copyText` helper, which already has a Clipboard API + `execCommand('copy')` fallback for `http://*.local` Local sites.

### Changed
- `AltPayAdminController::renderPage`: added `'ada'` to the allowed-tabs list and a 2FA gate at the top — when TOTP is enabled and the current user has no unlock transient, renders the lockscreen instead of any tab.
- `AltPayAdminController::ajaxGenerateWallet`: ADA branch routes to `generateAdaWallet` (which uses `CardanoCLI` directly) instead of `AltPayService::provider()`, which has no ADA implementation.
- `AltPayAdminController::ajaxWalletBalances`: ADA branch returns a single-child synthetic shape (`children = [{index: 0, address, balance_minor}]`) so the existing dashboard JS works unchanged.
- `AltPayAdminController::chainNetworks('ada')` returns `['mainnet', 'preprod', 'preview']`.
- Settings tab: new "ADA" section with three Blockfrost project ID slots (mainnet / preprod / preview) and an ADA sweep address option, persisted via existing `ajaxSaveSettings` in the same shape as the other chains.
- `dash_format_minor` decimals/display tables extended with `ada => 6`. JS `chainMinorLabel`, `formatMajor`, and `FEE_BUFFER` likewise handle `'ada'` (label 'lovelace', decimals 6, fee buffer 1.25 ADA to keep the change output above min-UTxO).

### Security
- TOTP unlock POST handled in `admin_init` (not in render) so `wp_safe_redirect` works before admin-header.php emits headers.
- Recovery codes stored as `password_hash()` rather than plaintext — DB compromise alone does not reveal them.
- TOTP setup uses a 5-min `set_transient` for the *pending* secret; the secret is only promoted to the active option after a valid first verification. Unverified secrets are never persisted.
- Disabling 2FA requires a valid current code or a recovery code, so a stolen WP admin session cannot simply turn the gate off.

## [4.0.0] - 2026-04-30

Major release. Adds alt-chain payments (BTC / ETH / SOL), batch mints (1-5 per tx), and a wallet-network gate that protects customers from off-chain payments bound to a wrong-network Cardano address.

### Added
- **Alt-chain payments.** New `Alt-Chain Payments` admin menu, `AltPayService` orchestrator, `AltPayWatcher` WP-Cron + on-demand reconciler, and three providers (`BtcProvider`, `EthProvider`, `SolProvider`) with pure-PHP BIP32-secp256k1 / SLIP-0010 ed25519 wallet derivation, address generation, balance checking, and refund-tx signing.
- **Three new tables** via dedicated `AltPayInstaller`: `wp_cm_chain_wallets`, `wp_cm_chain_invoices`, `wp_cm_chain_tx_log`.
- **Three new public REST endpoints** under `cardano-mint/v1`: `POST /altpay/quote`, `GET /altpay/status`, `POST /altpay/cancel`. `POST /mint/build` now accepts an optional `invoice_id`.
- **Quantity stepper** in the mint modal (1-5 per tx). `AnvilAPI::buildMintTransaction` accepts a `$quantity` parameter and produces N unique sequential asset names with per-asset CIP-25 metadata names. Server validates remaining supply and per-wallet headroom for the batch.
- **Wallet network gate** at four layers (wallet connect, pre-build click, AltPay quote, mint build). Refuses to proceed when the customer's Cardano wallet is on a different network than the site, preventing off-chain payments bound to a wrong-network address.
- **Per-tx alt-pay receipt** in Step 2: explicit Cross-Chain Service Fee + NFT Receipt UTxO line items so totals match what the customer actually signs.
- **Refund + sweep tooling** per chain. `Refund` modal builds, signs, and broadcasts a real on-chain refund tx via the same crypto stack used for parent-wallet derivation. `Sweep` drains consumed/refunded child addresses to a configured cold wallet (one batch tx for BTC, one tx per address for ETH, packed transfer for SOL).
- **Resume banners** for in-flight alt-chain payments. Per-chain localStorage sessions survive page reloads and modal close/reopen.
- **`kg:mint-completed` event.** After a successful mint the alt-pay overlay receives this event and wipes its receipt overlay + clears the localStorage invoice for the chain it used, so the next mint sees pristine ADA-priced state.
- **filemtime() asset cache busting.** All four checkout JS/CSS files use `filemtime()` for the `?ver=` query string, so any git pull or edit auto-busts every browser cache without anyone bumping a version string.

### Changed
- `AnvilAPI::buildMintTransaction` signature: added `$quantity = 1` parameter (default preserves single-mint behavior).
- Step 2 modal: tighter spacing (smaller h3, 90px NFT thumbnail vs 110, lower padding throughout) so the receipt fits on standard viewports without scrolling.
- Wallet picker no longer lists Nami (defunct, merged into Lace).
- Plugin description updated to reflect alt-pay scope.

### Fixed
- Receipt info note (`.receipt-info-text`) was unreadable in the dark theme: a duplicate light-theme rule was using `!important` and winning over the intended dark-theme rule. Removed the dupe.
- After a successful alt-pay mint, reopening the modal could leave the receipt in alt-pay state with the previous SOL/ETH/BTC totals showing, even though the customer had no active off-chain payment. The `kg:mint-completed` event now wipes the overlay and the modal-open click handler also re-runs `restoreAdaReceipt()` defensively. Server-side build path was always correct; this was UI-only but could mislead a customer into clicking Confirm.
- Quantity stepper no longer interferes with the alt-pay receipt overlay (it's hidden when alt-pay is active and `recomputeReviewTotals()` bails immediately).
- Tower template detection elsewhere in the theme is now resilient to filename-hierarchy template loading. (Theme change, but unblocked the network-gate work since Tower is wallet-aware.)

### Security
- Parent xprvs encrypted at rest with `EncryptionHelper` (AES-256-CBC, WP-salts derived). Decrypted material is wiped via `sodium_memzero` + `unset` in a `try/finally`.
- Alt-pay quote endpoint rate-limited to 5/IP/min and 50/IP/day. Each quote consumes one HD child index, so this also bounds address-graph enumeration cost.
- Server-authoritative amounts: build path ignores client-supplied prices; the DB record is authoritative.
- Address reuse: never. Cancelled invoices do not free the HD index.

## [3.0.0] - 2026-04-17

- Native CIP-30 wallet connection layer (no CardanoPress dependency).
- Widget Deployer for embedding the mint flow on external sites.
- REST API namespace `cardano-mint/v1` with API-key auth and per-origin CORS.
- CIP-27 royalty token auto-mint on first mint per policy.
- Pure-PHP Ed25519 + CBOR signing path with sodium / FFI / pure-PHP fallbacks.
- AES-256-CBC encryption for all signing keys at rest.
