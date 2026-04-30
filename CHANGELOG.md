# Changelog

All notable changes to **Cardano Easy Mint** are tracked here. Format follows [Keep a Changelog](https://keepachangelog.com/), and the project follows semantic versioning where the major number bumps on contract-breaking changes (REST shape, table shape, signing flow).

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
