# Cardano Easy Mint

A WordPress plugin for minting NFTs on the Cardano blockchain via the [Ada Anvil](https://ada-anvil.io/) API. Self-contained with native CIP-30 wallet connection, pure-PHP cryptography (Ed25519 + secp256k1 + Keccak-256), and an embeddable widget for external sites. Customers can pay in ADA, pay with BTC / ETH / SOL (alt-chain payments), or buy ADA with a credit card (fiat on-ramp). Beyond minting, it can refresh an existing NFT's metadata in place via a coordinated burn → re-mint (Asset Upgrade).

**A Pb Project** | Open Source

## Highlights

- **NFT Minting Engine** — collections with variants, rarity weights, CIP-25 metadata, CIP-27 royalties. Per-asset form builder *or* bulk JSON import (verbatim, mint-ready collections).
- **Alt-chain Payments** — accept BTC, ETH, or SOL for a Cardano NFT mint. Per-mint deterministic deposit address, server-side watcher, automatic price-locked invoices.
- **Fiat On-Ramp** — customers buy ADA with a credit card (via Guardarian); the ADA lands directly in their own connected wallet, then the normal mint flow takes over. No operator wallet, no chargeback exposure.
- **Asset Upgrade** — refresh an existing NFT's CIP-25 metadata in place via a coordinated burn → re-mint (same `policyId` + `assetName`, new metadata). Per-asset or policy-wide patch, time-lock aware, append-only audit log.
- **Batch Mints** — customers mint 1 to 5 NFTs in a single Cardano transaction with one wallet signature.
- **Wallet Network Gate** — refuses to connect, quote, or build a mint when the customer's Cardano wallet network does not match the site's configured network.
- **Native CIP-30** — embedded wallet detection (Eternl, Lace, Vespr, Yoroi, Typhon, Gero) with no external wallet-connect dependency.
- **Pure-PHP Crypto** — Ed25519 (Cardano), secp256k1 (BTC/ETH), SLIP-0010 ed25519 (SOL), Keccak-256, Bech32, Base58. No native binaries needed.
- **Widget Deployer** — drop a self-contained mint widget on Next.js / React / vanilla HTML via one `<script>` tag.
- **Refund + Sweep** — admin tooling to refund a customer's off-chain deposit or sweep all child addresses to a cold wallet, per chain.
- **AES-256-CBC at rest** — every signing key (Cardano policy + alt-chain xprv) encrypted using WordPress salts.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- One of: `gmp` extension (recommended) or `bcmath` (slow but works) for big-int arithmetic
- `sodium` extension for Solana signing (built into PHP 7.2+; usually present)
- [Ada Anvil](https://ada-anvil.io/) API key (mainnet and/or preprod)
- Optional: [Pinata](https://www.pinata.cloud/) for IPFS pinning

## Installation

1. Upload `cardano-easy-mint/` to `wp-content/plugins/`.
2. Activate **Cardano Minting** in the WordPress admin.
3. Go to **Cardano Mint → Plugin Setup**: enter your Anvil API key, network (mainnet / preprod), merchant Cardano address, and (optional) Pinata config.
4. Go to **Policy Wallet** to generate or import a signing wallet.
5. Go to **Mint Manager** to create your first NFT collection.
6. (Optional) Go to **Alt-Chain Payments** to enable BTC / ETH / SOL payments and generate parent HD wallets.

## Admin Pages

| Page | Purpose |
|------|---------|
| **Plugin Setup** | Anvil API keys, merchant address, network (mainnet/preprod), Pinata IPFS config, alt-chain feature flag, service-fee ADA amount |
| **Mint Manager** | Create / edit NFT collections, variants, metadata, royalties, pricing. Two paths: **Build NFT** (per-asset form) or **Import JSON (bulk)** — paste/upload a JSON array of `{assetName, metadata}` to load a whole collection as quantity-1, verbatim-minted assets. |
| **Active Mints** | View mints by policy, archive, CSV export/import, mint history |
| **Policy Wallet** | Generate or import Cardano signing wallets, advanced key import (seed / skey / manual). Skey + Script / Manual import accept either a bare native script or the full exported policy wrapper (`{policyId, script, schema, …}`), and validate the key against any signer in a multisig policy. |
| **Payment Wallets** (Alt-Chain Payments) | BTC / ETH / SOL parent-wallet management, per-chain settings, RPC config, refund / sweep tooling, cross-chain invoice review. Hosts the **On-Ramp** tab (Guardarian fiat config + recent session review). 2FA (TOTP) gated. |
| **Asset Upgrades** | Register policies eligible for CIP-25 metadata refresh, define policy-wide patches or per-asset metadata bundles, preview the resolved diff, pause/activate specs, review the burn→re-mint audit log. Shares the Payment Wallets 2FA gate. |
| **Widget Deployer** | Generate API keys, restrict by origin, embed-snippet generator |
| **How to Use** | Shortcode + widget docs |

## Shortcode

```
[cardano-mint mint-id="20"]              Random variant by rarity weight
[cardano-mint mint-id="20-A"]            Specific variant
[cardano-mint mint-id="25" class="custom-button"]
```

For the metadata-refresh flow, drop the upgrade shortcode on any page:

```
[cardano-upgrade]                                    All eligible policies
[cardano-upgrade policy-id="<56 hex>"]               Restrict to one policy
[cardano-upgrade policy-id="<56 hex>" label="Refresh my art"]
```

The rendered modal walks customers through:

1. **Connect Cardano wallet.** Network is validated against site config; mismatched wallets are rejected with a clear "switch your wallet network" message.
2. **Pick payment method.** ADA (default) or BTC / ETH / SOL if alt-chain payments are enabled and at least one parent wallet is configured. The picker locks the price for 15 minutes when an alt-chain is selected and shows a per-mint deposit address with QR code.
3. **Confirm and sign.** Customer picks quantity (1 to 5), reviews the order summary, and signs with their Cardano wallet. Alt-paid mints carry only the configured ADA service fee + 1 ADA receipt + the minted NFTs; the bulk of the price already cleared off-chain.
4. **Receive NFT.** Anvil-built tx settles on Cardano, NFT lands in the connected wallet.

## Alt-Chain Payments (BTC / ETH / SOL)

Operators add a parent HD wallet per chain. Every mint that picks BTC / ETH / SOL gets a fresh derived child address. The plugin watches the chain for the expected payment amount, marks the invoice `funded`, and the Cardano mint tx is then built with the merchant output reduced to a flat ADA service fee.

| Layer | What it does |
|-------|--------------|
| `ChainPaymentProvider` interface | Per-chain shape: address derivation, balance check, refund tx builder, explorer URL |
| `BtcProvider` | BIP32-secp256k1 HD wallet, P2WPKH (BIP-141 SegWit v0), Bech32 addresses, mempool.space RPC |
| `EthProvider` | BIP32-secp256k1 HD wallet, EIP-1559 type-2 tx, Keccak-256 addresses, public llamarpc.com (or your own) |
| `SolProvider` | SLIP-0010 ed25519 HD wallet, base58 addresses, Solana JSON-RPC |
| `AltPayWatcher` | WP-Cron tick + on-demand reconcile that promotes invoices `pending → funded → consumed` |
| `PriceOracle` | CoinGecko bulk fetch with 5-minute transient cache and last-known-good fallback |

Customer payment flow: per-mint child address → watcher detects deposit → invoice flips to `funded` → modal banner says "payment received" → customer signs reduced-fee Cardano tx → invoice marked `consumed`.

Operator tooling per chain: parent-wallet generation / import (encrypted xprv via AES-256-CBC), child-address listing with status filters, **Refund** modal that builds, signs, and broadcasts a real on-chain refund tx, **Sweep** that drains consumed/refunded child addresses to a configured cold wallet (one batch tx for BTC, one tx per address for ETH, packed transfer for SOL).

Pure-PHP crypto stack lives entirely inside the plugin. No Composer install, no native binaries:

```
includes/altpay/lib/
  Bn.php          # GMP-or-BCMath big-int helper
  Secp256k1.php   # secp256k1 curve ops + RFC6979 deterministic-k ECDSA
  Keccak.php      # Keccak-256 (vendored kornrunner/keccak, MIT)
  Rlp.php         # RLP encoding for ETH txs
includes/altpay/encoding/
  Base58.php      # Base58 + Base58Check
  Bech32.php      # BIP-173 (BTC SegWit v0)
  KeccakAddress.php  # ETH address from public key
includes/altpay/
  Bip39.php           # BIP-39 mnemonic generation
  Bip32Secp.php       # BIP32 over secp256k1
  Slip10Ed25519.php   # SLIP-0010 ed25519 derivation (libsodium-backed)
```

## Fiat On-Ramp (Guardarian)

A sibling to Alt-Chain Payments, but pointed the other way: instead of accepting a foreign coin and sweeping it to the operator, the customer buys **ADA with a credit card** and the ADA is delivered straight to their **own** connected Cardano wallet. The operator never custodies the funds and carries no chargeback exposure — once the ADA arrives, the customer mints with the normal ADA flow.

Flow: customer enters a USD amount → `POST /onramp/quote` returns an indicative ADA estimate → `POST /onramp/sessions` creates a Guardarian transaction and returns the hosted-checkout `redirect_url` → customer pays on Guardarian → the modal polls `GET /onramp/wallet-balance` (Blockfrost) until the ADA lands. A session row tracks status for support/dashboard; it is **audit-only** and never auto-triggers a mint (the customer still signs).

| Layer | What it does |
|-------|--------------|
| `GuardarianClient` | Thin HTTP client for Guardarian (`/estimate`, `/market-info/min-max-range`, `POST /transaction`, `GET /transaction/{id}`). Keys encrypted at rest; error envelope flattened to `WP_Error`. |
| `GuardarianService` | Quote validation against min/max, idempotent session creation (dedups same address+amount within 5 min), status sync + transient cache, webhook application. |
| `OnrampSessionModel` | `wp_cm_onramp_sessions` CRUD + status enum (terminal: `finished/failed/expired/cancelled/refunded`). |
| `OnrampPublicController` | REST: quote, create/poll session, wallet-balance passthrough. |
| `OnrampWebhookController` | Optional Guardarian status webhook, **IP-allowlisted** (empty allowlist = refuse all). |

Operator config lives on the **Payment Wallets → On-Ramp** tab: encrypted API key (+ optional secret), environment (production/staging), fee-buffer ADA, optional preset payout address, webhook IP allowlist, and an enable toggle. A "Test connection" button probes the Guardarian API. Recent sessions are listed with manual refresh.

## Asset Upgrade (Burn & Re-mint)

Refresh an existing NFT's CIP-25 metadata **without changing its fingerprint** — same `policyId` + `assetName`, new metadata. Because the ledger rejects a net-zero mint, this is a coordinated **two-transaction** flow rather than one atomic update:

1. **Burn** — `mint: [{ quantity: -1, assetName, version: 'cip25' }]`, no metadata, no output. The target metadata is resolved *before* the burn (for policy-wide patches, chain data is gone afterward) and stashed in a 2-hour transient.
2. **Re-mint** — `mint: [{ quantity: +1, assetName, metadata }]` with a 1.5 ADA min-UTxO output back to the customer.

Customer flow (`[cardano-upgrade]`): connect CIP-30 wallet → backend resolves eligible NFTs via Blockfrost (stake-address asset lookup) → customer reviews the current-vs-proposed diff → signs the burn, then signs the re-mint → a 5-minute confirmation cron flips audit rows to `confirmed` once the txs land. The customer pays the ~0.17 ADA network fee (merchant subsidy is layerable later).

| Piece | What it does |
|-------|--------------|
| `AssetUpgradeService` | `build()` (burn or re-mint), `submit()` (adds policy-wallet witness, reuses the standard mint submit), `confirmation_tick()` (cron). Time-lock re-checked at build time, not just registration. |
| `MetadataResolver` | Shallow `applyPatch()` (per-asset rows beat policy-wide rows), CIP-25 bundle parsing, ASCII↔hex asset-name encoding. |
| `AssetUpgradeAdminController` | Register/pause/remove policies, save policy-wide patches or per-asset bundles, preview the resolved diff. Shares the Payment Wallets 2FA gate. |
| `AssetUpgradePublicController` | REST: `/upgrade/eligible`, `/upgrade/build`, `/upgrade/submit`. |

Specs live in `wp_cardano_asset_upgrades` (one row per `(policy_id, asset_name)`; empty `asset_name` = policy-wide patch); every build/submit/confirm/fail is recorded append-only in `wp_cardano_asset_upgrade_log`.

## Wallet Network Gate

Defense in depth at four layers. Any one of them rejects a mint where the customer's Cardano wallet is on a different network than the site:

1. **Wallet connect (browser).** `getNetworkId()` plus a redundant `addr_test1` / `addr1` prefix check.
2. **Pre-build click handler (browser).** Re-checks the resolved address right before submit, so mid-session wallet network changes are caught.
3. **AltPay quote endpoint (server).** Refuses to issue a deposit address bound to a wrong-network Cardano address. This is the load-bearing layer: prevents the customer from paying off-chain at all when the binding would be unusable.
4. **Mint build endpoint (server).** Last-line check on both AJAX (`ajaxBuildMintTransaction`) and REST (`mint_build`) paths.

Every error message names the actual network detected and the one expected, so the customer knows exactly which way to flip.

## Quantity Stepper (1-5 per tx)

Step 2 of the mint modal exposes a `−` / value / `+` stepper. Per-tx cap is 5; the per-wallet limit set on the mint record is unchanged, so a wallet allowed 20 mints can buy 4 batches of 5. Anvil receives N unique sequential asset names (`*_001`, `*_002`, ...) with per-asset CIP-25 metadata names (`Title #001`, etc.) and produces one tx the customer signs once. Merchant gets one consolidated payment UTxO; each NFT lands in its own customer receipt UTxO.

The stepper is hidden when alt-pay is active (alt-pay invoices lock at qty=1 in v4.0; multi-mint over alt-pay is on the roadmap).

## REST API

Namespace: `cardano-mint/v1`

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET  | `/collections`        | Public  | List active collections |
| GET  | `/collections/{id}`   | Public  | Collection detail |
| GET  | `/config`             | Public  | Site network + merchant info |
| GET  | `/price`              | Public  | Live ADA/USD price |
| POST | `/mint/build`         | API Key | Build the Cardano mint tx (Anvil proxy). Accepts optional `invoice_id` for alt-paid mints. Network-gated. |
| POST | `/mint/submit`        | API Key | Submit signed tx; adds policy signature server-side. |
| POST | `/altpay/quote`       | Public (rate-limited) | Issue a payment intent on a non-Cardano chain. Network-gated. |
| GET  | `/altpay/status`      | Public  | Poll invoice status + observed amount + confirmations |
| POST | `/altpay/cancel`      | Public  | Cancel a pending alt-pay invoice |
| POST | `/onramp/quote`       | Nonce   | Indicative ADA estimate for a fiat amount (Guardarian) |
| POST | `/onramp/sessions`    | Nonce   | Create a Guardarian on-ramp transaction; returns hosted-checkout `redirect_url` |
| GET  | `/onramp/sessions/{partner_link_id}` | Nonce | Poll on-ramp session status |
| GET  | `/onramp/wallet-balance` | Nonce | Blockfrost balance passthrough (detect ADA arrival) |
| POST | `/onramp/webhooks/guardarian` | IP allowlist | Receive Guardarian status webhooks |
| POST | `/upgrade/eligible`   | Nonce   | List wallet NFTs eligible for a metadata upgrade |
| POST | `/upgrade/build`      | Nonce   | Build the burn or re-mint tx (Anvil proxy). Time-lock gated. |
| POST | `/upgrade/submit`     | Nonce   | Submit signed burn/re-mint; adds policy signature server-side. |

API keys go in `X-CM-Api-Key`. CORS is enforced per registered origin. The on-ramp and upgrade endpoints are nonce-authenticated (rendered by their shortcodes) rather than API-key-gated.

## Widget Deployer

Embed the full mint flow on any external site:

```html
<div id="cardano-mint-widget"></div>
<script
  src="https://yoursite.com/wp-content/plugins/cardano-easy-mint/assets/js/cm-widget.js"
  data-api="https://yoursite.com/wp-json/cardano-mint/v1"
  data-key="cmk_YOUR_API_KEY"
  data-collection="42"
  data-container="cardano-mint-widget"></script>
```

The widget renders inside Shadow DOM so site styles can't bleed in. All Anvil traffic stays on the WordPress origin so your Anvil API key is never exposed.

## Architecture

```
cardano-easy-mint/
├── cardano-nft-checkout.php           Plugin entry, enqueues, REST + cron registration
├── README.md
├── CHANGELOG.md
├── assets/
│   ├── cardano-nft-mint.js            Mint modal + embedded CIP-30 wallet manager
│   ├── cardano-checkout.css           Modal styles (filemtime cache-bust)
│   ├── altpay/
│   │   ├── altpay-checkout.js         Customer alt-pay overlay (chip picker, watcher poll, receipt rewrite)
│   │   ├── altpay-checkout.css        Alt-pay overlay styles
│   │   └── altpay-admin.js            Admin alt-pay tools (generate/import/refund/sweep)
│   └── js/
│       └── cm-widget.js               Embeddable widget (Shadow DOM, zero deps)
├── includes/
│   ├── controllers/
│   │   ├── NFTCheckoutController.php  Mint modal AJAX, network gate, qty validation, bulk JSON import
│   │   ├── PolicyWalletController.php Cardano policy wallet management
│   │   ├── RestApiController.php      REST API + alt-pay endpoints + CORS
│   │   ├── AltPayAdminController.php  Alt-chain admin pages + AJAX
│   │   ├── OnrampPublicController.php / OnrampWebhookController.php / OnrampAdminController.php
│   │   ├── AssetUpgradeAdminController.php / AssetUpgradePublicController.php
│   │   ├── WidgetAdminController.php  Widget deployer admin
│   │   └── AJAXController.php         Anvil API test + rate limiting
│   ├── helpers/
│   │   ├── AnvilAPI.php               Anvil client; quantity-aware buildMintTransaction
│   │   ├── EncryptionHelper.php       AES-256-CBC over WordPress salts
│   │   ├── ApiKeys.php                Widget API keys
│   │   ├── PolicyImport.php           External Cardano policy import
│   │   ├── CardanoWalletPHP.php       BIP-39/CIP-1852 Cardano wallet generation
│   │   ├── CardanoTransactionSignerPHP.php
│   │   ├── Ed25519Compat.php / Ed25519Pure.php
│   │   ├── PinataAPI.php
│   │   └── bip39-wordlist.php
│   ├── altpay/
│   │   ├── ChainPaymentProvider.php   Provider interface
│   │   ├── AltPayService.php          Quote / status / finalize / refund / sweep orchestrator
│   │   ├── AltPayInstaller.php        Schema migration (3 tables)
│   │   ├── AltPayWatcher.php          WP-Cron tick + on-demand reconcile
│   │   ├── PriceOracle.php            CoinGecko bulk fetch + transient cache
│   │   ├── Bip39.php / Bip32Secp.php / Slip10Ed25519.php
│   │   ├── encoding/                  Bech32, Base58, Keccak-address
│   │   ├── lib/                       Bn (GMP/bcmath), Secp256k1, Keccak, Rlp
│   │   ├── providers/                 BtcProvider, EthProvider, SolProvider
│   │   └── rpc/                       MempoolClient, EthRpcClient, SolRpcClient
│   ├── onramp/                        Fiat→ADA on-ramp (Guardarian)
│   │   ├── GuardarianClient.php       Guardarian HTTP client (encrypted keys)
│   │   ├── GuardarianService.php      Quote / create session / status / webhook
│   │   └── OnrampInstaller.php        Schema migration (wp_cm_onramp_sessions)
│   ├── asset-upgrade/                 Burn & re-mint CIP-25 metadata refresh
│   │   ├── AssetUpgradeService.php    build (burn/remint) / submit / confirmation cron
│   │   ├── MetadataResolver.php       Patch merge + CIP-25 bundle parsing + name encoding
│   │   └── AssetUpgradeInstaller.php  Schema migration (specs + audit log)
│   ├── models/
│   │   ├── MintModel.php              Mint + per-wallet limit accounting
│   │   ├── ChainWalletModel.php       Alt-chain parent HD wallets
│   │   ├── ChainInvoiceModel.php      Alt-chain invoices
│   │   ├── ChainTxLogModel.php        On-chain tx audit log
│   │   └── OnrampSessionModel.php     Guardarian on-ramp sessions
│   └── views/
│       ├── mint-manager.php
│       ├── active-mints-list.php
│       ├── policy-wallet-manager.php
│       ├── nft-mint-form.php          Mint modal markup
│       ├── widget-deployer.php
│       └── altpay/                    Wallets / invoices / settings tabs + refund modal
```

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_cardanonftactivemints` | NFT collections, variants, metadata, pricing, supply |
| `wp_cardanonftmintcounts` | Legacy per-wallet mint tracking |
| `wp_cardano_policy_wallets` | Encrypted Cardano policy wallet keys |
| `wp_cardano_mint_wallets` | Per-wallet mint limit tracking |
| `wp_cardano_mint_policies` | Imported external Cardano policies |
| `wp_cm_chain_wallets` | Alt-chain parent HD wallets (encrypted xprv) |
| `wp_cm_chain_invoices` | Per-mint alt-chain payment intents |
| `wp_cm_chain_tx_log` | Inbound, refund, and sweep tx audit log |
| `wp_cm_onramp_sessions` | Guardarian fiat→ADA on-ramp sessions (audit + status) |
| `wp_cardano_asset_upgrades` | Asset-upgrade specs (policy-wide patch or per-asset metadata) |
| `wp_cardano_asset_upgrade_log` | Append-only burn→re-mint audit log |

## Security

- All Cardano + alt-chain signing keys encrypted at rest (AES-256-CBC, key derived from WP salts).
- Decrypted xprvs live only in a local variable inside `try/finally` with `unset` and `sodium_memzero`. Never logged.
- Server-authoritative amounts: client-supplied prices are ignored at build time; the DB price is the source of truth.
- Alt-pay quote rate limit: 5 per IP per minute, 50 per IP per day. Each quote burns one HD index.
- All AJAX endpoints use WordPress nonces; all admin POSTs go through `check_ajax_referer`.
- All DB queries use `$wpdb->prepare()`.
- HD address reuse: never. Cancelled invoices do not free the index.
- Refund operations are the hot-wallet exposure point. Document for operators: treat the receive tree as hot, sweep aggressively to a cold address kept off the WP host.
- Wallet-network gate: see "Wallet Network Gate" above.

## Credits

Built with [Ada Anvil](https://ada-anvil.io/) API and [Weld](https://github.com/ADA-Anvil/weld) wallet-connection patterns. Alt-chain crypto vendored from `simplito/elliptic-php` (secp256k1, MIT) and `kornrunner/keccak` (MIT). Metadata formatting: [MetaDraft](https://metadraft.io/).

## License

GPL v2 or later.
