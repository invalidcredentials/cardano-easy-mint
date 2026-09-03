# Cardano Easy Mint

**Mint Cardano NFTs from WordPress.** Native CIP-30 wallet connect, ADA / BTC / ETH / SOL / credit-card payments, discount codes, batch mints, and in-place metadata upgrades via burn and re-mint. Built on the [Ada Anvil](https://ada-anvil.io/) API with pure-PHP cryptography. No Composer, no native binaries, no wallet-connect plugin.

**A Pb Project** · Open source under AGPL-3.0 · Version 4.6.1 · WordPress 5.0+ · PHP 7.4+

---

## Table of contents

1. [What you get](#what-you-get)
2. [Quick start](#quick-start)
3. [Get your API keys](#get-your-api-keys)
4. [Plugin Setup](#plugin-setup)
5. [Policy Wallet](#policy-wallet)
6. [Create your first mint](#create-your-first-mint)
7. [Bulk JSON import](#bulk-json-import)
8. [Shortcodes](#shortcodes)
9. [Admin pages](#admin-pages)
10. [Payments](#payments) — ADA, batch, alt-chain, card on-ramp, discount codes
11. [Asset Upgrade (burn and re-mint)](#asset-upgrade-burn-and-re-mint)
12. [Wallet network gate](#wallet-network-gate)
13. [REST API](#rest-api)
14. [Widget Deployer](#widget-deployer)
15. [Architecture](#architecture)
16. [Database tables](#database-tables)
17. [Security](#security)
18. [Critical warnings](#critical-warnings)
19. [Best practices](#best-practices)
20. [Troubleshooting](#troubleshooting)
21. [Building the release zip](#building-the-release-zip)
22. [Contributing](#contributing)
23. [Credits and license](#credits-and-license)

---

## What you get

### The minting core (3.0)

- **Collections and variants.** A collection ID (`20`) with lettered variants (`20-A`, `20-B`, `20-C`), each with its own image, supply, price, and rarity weight.
- **Mystery box / blind minting.** Show a collection image until the mint lands, then reveal the variant the customer actually got.
- **Dual-signature mints.** Every transaction is signed by the customer's wallet *and* your server-side policy wallet. The policy key is AES-256-CBC encrypted with your WordPress salts and never leaves the server.
- **CIP-25 metadata builder.** Unlimited custom attributes per asset, or bulk-import a mint-ready JSON collection verbatim.
- **Per-wallet mint limits** with CSV export, CSV whitelist import, and a live mint-history modal.
- **CIP-27 royalty token** minted automatically, once per policy.
- **Pinata IPFS pinning** (optional) with a WordPress media-library fallback.
- **Time-locked policies** generated from the Mint Manager with an expiration date.

### Built on top of it (4.x)

- **Native CIP-30 wallet connection.** Detects any CIP-30 wallet injected into the page (Eternl, Lace, Vespr, Typhon, Yoroi, Gero, and others). No CardanoPress or other wallet plugin required.
- **Batch mints.** 1 to 5 NFTs per transaction, one signature, unique sequential asset names.
- **Alt-chain payments.** Accept BTC, ETH, or SOL for a Cardano mint. Per-mint deposit address, server-side watcher, price-locked invoices, refund and sweep tooling.
- **Fiat on-ramp.** Customers buy ADA with a card (Guardarian). The ADA lands in *their* wallet, then the normal mint flow takes over. You never custody funds.
- **Discount codes.** Percent or fixed-amount campaigns, single or multi-use, expiry, batch code generation, works with every payment method.
- **Asset Upgrade.** Refresh an existing NFT's CIP-25 metadata in place (same policy ID and asset name) with a coordinated burn then re-mint.
- **Wallet network gate.** Refuses to connect, quote, or build when the customer's wallet is on the wrong network.
- **TOTP 2FA** (optional) on the Payment Wallets and Asset Upgrades admin pages.
- **REST API and embeddable widget** for minting from Next.js, React, or any external site.
- **Pure-PHP crypto.** Ed25519 (Cardano), secp256k1 (BTC/ETH), SLIP-0010 ed25519 (SOL), Keccak-256, Bech32, Base58.

---

## Quick start

1. **Install.** Download the release zip from the [Releases](https://github.com/invalidcredentials/cardano-easy-mint/releases) page, then in WordPress go to **Plugins → Add New → Upload Plugin**, upload, and **Activate**. (Or copy the `cardano-easy-mint/` folder into `wp-content/plugins/`.)
2. **Add keys.** Go to **Cardano Mint → Plugin Setup**. Paste your Anvil API key, set your merchant wallet address, and choose **Preprod** to start.
3. **Create a policy wallet.** Go to **Cardano Mint → Policy Wallet** and click generate. **Write down the 24-word seed phrase now. It is shown exactly once.**
4. **Create a collection.** Go to **Cardano Mint → Mint Manager**, fill in variant A, generate a policy, and save.
5. **Drop the shortcode** `[cardano-mint mint-id="1"]` on any page and mint a test NFT on preprod.

Then flip the network to mainnet in Plugin Setup when you are ready.

---

## Get your API keys

| Service | Needed for | Free tier | Get it |
|---|---|---|---|
| **Ada Anvil** | Building and submitting every Cardano transaction. **Required.** | Yes | [ada-anvil.io](https://ada-anvil.io/) |
| **Pinata** | IPFS pinning for NFT images. Optional; without it images are served from your WordPress media library. | Yes | [pinata.cloud](https://pinata.cloud/) |
| **Blockfrost** | Balance and asset lookups. Only needed for the alt-chain dashboard, the card on-ramp, and Asset Upgrades. Not needed for plain minting. | Yes | [blockfrost.io](https://blockfrost.io/) |

Anvil keys are network-specific. Get one for preprod to test and one for mainnet to launch.

---

## Plugin Setup

**Cardano Mint → Plugin Setup** (the top-level Cardano Mint menu item).

| Field | What it does |
|---|---|
| **ADA Anvil API Key Mainnet / Testnet** | One key per network. The plugin uses whichever matches the selected network. |
| **Merchant Wallet Address** | Where mint payments land. Must match the selected network (`addr1…` for mainnet, `addr_test1…` for preprod). |
| **Network Environment** | `preprod` or `mainnet`. Switches the Anvil endpoint, the wallet-network gate, and the address validation everywhere. |
| **Enable Pinata IPFS** + JWT (or legacy API key + secret) | Turns on IPFS pinning for uploaded images. |
| **Data Cleanup** | If ticked, deactivating the plugin drops the core mint tables and options. Leave it off unless you mean it. |

Alt-chain, on-ramp, and Blockfrost settings live on **Payment Wallets → Settings**, not here.

---

## Policy Wallet

**Cardano Mint → Policy Wallet.** The policy wallet is the second signer on every mint and the key that controls your minting policies.

- **Generate** creates a new CIP-1852 wallet in pure PHP. The 24-word seed phrase is displayed **one time only**; the private key is encrypted at rest with AES-256-CBC using your WordPress salts.
- **Import** lets you bring an existing wallet in by seed phrase, by signing key (`.skey`), or manually. Skey + Script and Manual import accept either a bare native script or the full exported policy wrapper (`{policyId, script, schema, …}`) and validate the key against any signer in a multisig policy.
- **Deleting the wallet** means you can no longer mint under policies it signed. Back up the seed before you touch it.

The wallet address is shown so you can copy it, but the policy wallet does not need to hold ADA. Customers pay the network fee and the mint price.

---

## Create your first mint

**Cardano Mint → Mint Manager.** Two paths: **Build NFT** (one asset at a time, form builder) or **Import JSON (bulk)** (see the next section).

### Collections and variants

- A **collection** is a number (`20`). A **variant** is a letter under it (`20-A`, `20-B`).
- **Always create variant A first.** It is the parent: it owns the policy, the collection image, and collection-wide pricing.
- Each variant has its own **NFT image**, **quantity**, and **rarity weight**. Rarity weights across a collection should total 100.
- `[cardano-mint mint-id="20"]` picks a random variant by weight. `[cardano-mint mint-id="20-A"]` mints only that variant.

### Fields that matter

| Field | Notes |
|---|---|
| **NFT image** | The actual artwork. Keep file names under 63 characters so on-chain metadata stays valid. Image priority on chain: a manually pasted IPFS CID, then the Pinata CID, then the WordPress media URL. |
| **Collection image** | The mystery-box image shown before reveal. Only editable on variant A. Leave blank for an open (non-blind) mint. |
| **Price (USD)** | Converted to ADA at mint time from the live price. The server is the source of truth; the client cannot change it. |
| **Quantity** | Supply for this variant. |
| **Mints per wallet** | Per-wallet cap. `0` = unlimited but still tracked. Batch mints count each NFT. |
| **Expiration (UTC)** | The policy time-lock. After this date nothing more can be minted under the policy, ever. |
| **Royalty % and address** | Written into the CIP-27 royalty token that is auto-minted with the first NFT of the policy. |
| **Metadata attributes** | Free-form CIP-25 key/values. |

### Generate the policy

Click **Generate Policy** on variant A. The plugin derives a time-locked native script from your policy wallet and the expiration date, stores the policy ID, and mints the CIP-27 royalty token alongside the first NFT. The same wallet plus the same expiration produces the same policy ID, so change the expiration to get a fresh policy.

### Worked example: a 3-variant mystery box

1. Plugin Setup: Anvil preprod key, merchant address, network = preprod.
2. Policy Wallet: generate, save the seed.
3. Mint Manager, variant **25-A**: NFT image `rare-dragon.png`, collection image `mystery-box.png`, price $50, quantity 10, rarity 10, mints per wallet 3, royalty 5% to your address. Generate policy. Save.
4. Variants **25-B** and **25-C**: same collection ID, rarity 40 and 50.
5. Put `[cardano-mint mint-id="25"]` on a page. Customers see the mystery box, mint, and get a weighted-random reveal.
6. Track it on **Active Mints**: history modal, unique minters, CSV export.

---

## Bulk JSON import

**Mint Manager → Import JSON (bulk).** Paste or upload a JSON array of `{ "assetName": "...", "metadata": { ... } }` objects and the whole collection is loaded as quantity-1, verbatim-minted assets under one policy. Collection-wide price, mints per wallet, expiration, and collection image are set once for the import. A metadata viewer lets you inspect each asset before it goes live, and `ipfs://` images resolve to thumbnails in the admin.

Use this when your metadata is already generated by an external tool (for example [MetaDraft](https://metadraft.io/)) and you do not want to rebuild it in the form.

---

## Shortcodes

### `[cardano-mint]`

Renders a **Mint** button that opens the checkout modal.

| Attribute | Required | Description |
|---|---|---|
| `mint-id` | Yes | `"20"` for weighted-random across the collection, `"20-A"` for a specific variant. |
| `nftname` | No | Override the displayed name. |
| `class` | No | Extra CSS class on the button. |

```
[cardano-mint mint-id="20"]
[cardano-mint mint-id="20-A" nftname="Rare Dragon"]
[cardano-mint mint-id="25" class="custom-button"]
```

The modal walks the customer through:

1. **Connect wallet.** Network is validated against your site config. Wrong-network wallets get a clear "switch your wallet network" message.
2. **Pick payment method and quantity.** ADA by default. BTC / ETH / SOL if alt-chain payments are on, or **Buy ADA with card** if the on-ramp is on. A "Have a code?" box applies discount codes. The quantity stepper allows 1 to 5 per transaction.
3. **Confirm and sign.** Order summary, then one wallet signature.
4. **Receive.** Anvil submits the transaction. The NFT lands in the connected wallet.

Works in Gutenberg, the Classic editor, Bricks, Elementor, and any builder that renders shortcodes.

### `[cardano-upgrade]`

Renders the Asset Upgrade button for the burn-and-re-mint flow.

```
[cardano-upgrade]                                     All active upgrade specs
[cardano-upgrade policy-id="<56 hex>"]                One policy only
[cardano-upgrade policy-id="<56 hex>" label="Refresh my art"]
```

---

## Admin pages

All under the **Cardano Mint** menu.

| Page | Purpose |
|---|---|
| **Plugin Setup** | Anvil keys, merchant address, network, Pinata, data-cleanup toggle. |
| **Mint Manager** | Create and edit collections, variants, metadata, royalties, pricing. Build NFT or Import JSON (bulk). |
| **Active Mints** | Mints grouped by policy, edit, archive, CSV export / whitelist import, mint history, unique-minter counts. |
| **Policy Wallet** | Generate or import the Cardano signing wallet. |
| **Payment Wallets** | BTC / ETH / SOL parent wallets, the alt-pay on/off switch and service fee, per-chain RPC and Blockfrost settings, refund and sweep tools, invoice review, and the **On-Ramp** (card) tab. TOTP 2FA gated. |
| **Discounts** | Campaigns of discount codes scoped to a policy: percent or fixed, single or multi-use, expiry, batch generation, CSV export, redemption log. |
| **Asset Upgrades** | Register policies for metadata refresh, define policy-wide patches or per-asset bundles, preview the diff, pause or activate, audit log. Shares the Payment Wallets 2FA gate. |
| **Widget Deployer** | Generate `cmk_…` API keys, restrict by origin, copy the embed snippet for external sites. |
| **How to Use** | In-admin guide covering the shortcode, collections, variants, and mystery boxes. |

---

## Payments

### ADA (default)

Customer connects a CIP-30 wallet, signs once, and pays the USD price converted to ADA at that moment plus the network fee. The merchant output goes to your **Merchant Wallet Address**. All amounts are server-authoritative.

### Batch mints (1 to 5 per transaction)

The quantity stepper on the payment step lets a customer mint up to 5 NFTs in one transaction with one signature. Anvil receives N unique sequential asset names (`*_001`, `*_002`, …) with per-asset CIP-25 names (`Title #001`, …). You get one consolidated payment UTxO; each NFT lands in its own receipt UTxO. Per-wallet limits count every NFT. The stepper is hidden when an alt-chain payment is selected (alt-pay invoices lock at quantity 1).

### Alt-chain payments (BTC / ETH / SOL)

Turn it on under **Payment Wallets → Settings** (enable alt-chain payments and set the ADA service fee, 2 to 20 ADA), then add a parent HD wallet per chain on the same page.

Every mint that picks BTC / ETH / SOL gets a fresh derived child address and a 15-minute price lock. A WP-Cron watcher (plus on-demand reconcile when the customer polls) promotes the invoice `pending → funded → consumed`. Once funded, the Cardano mint transaction is built with the merchant output reduced to a flat ADA **service fee** you configure, and the customer signs that.

| Layer | What it does |
|---|---|
| `ChainPaymentProvider` | Per-chain interface: address derivation, balance check, refund builder, explorer URL |
| `BtcProvider` | BIP32-secp256k1, P2WPKH (SegWit v0), Bech32, mempool.space RPC |
| `EthProvider` | BIP32-secp256k1, EIP-1559 type-2 transactions, Keccak-256 addresses, llamarpc.com or your own RPC |
| `SolProvider` | SLIP-0010 ed25519, base58 addresses, Solana JSON-RPC |
| `AltPayService::watcher_tick` | WP-Cron tick that scans open invoices |
| `PriceOracle` | CoinGecko bulk fetch, 5-minute transient cache, last-known-good fallback |

Operator tooling per chain: encrypted parent xprv (AES-256-CBC), child-address listing with status filters, a **Refund** modal that builds, signs, and broadcasts a real on-chain refund, and **Sweep** to drain consumed and refunded child addresses to a cold wallet (one batch transaction for BTC, one per address for ETH, packed transfers for SOL).

Treat the receive tree as a hot wallet. Sweep aggressively to a cold address that is not on the WordPress host.

### Fiat on-ramp (card → ADA)

**Payment Wallets → On-Ramp** tab. Pointed the opposite way from alt-pay: the customer buys **ADA with a card** through Guardarian and the ADA is delivered to their **own** connected wallet. You never custody the funds and carry no chargeback exposure. When the ADA lands, the normal ADA mint flow takes over.

Flow: customer enters a USD amount → `POST /onramp/quote` gives an ADA estimate → `POST /onramp/sessions` creates the Guardarian transaction and returns a hosted-checkout URL → customer pays → the modal polls `GET /onramp/wallet-balance` (Blockfrost) until the ADA arrives. Session rows are audit-only and never auto-trigger a mint; the customer still signs.

Setup: paste your Guardarian API key (encrypted at rest), pick production or staging, set a fee-buffer in ADA, optionally allowlist Guardarian's webhook IPs (an empty allowlist refuses all webhooks), click **Test connection**, then tick **Enable card payments**. You will need a Guardarian partner account.

### Discount codes

**Cardano Mint → Discounts.** Create a campaign scoped to a policy: percent off or fixed USD off, single-use or multi-use, optional expiry, then generate a batch of unique 8-character codes or one shared code (`VIPERS20`). Customers enter it on the payment step. For ADA it applies at build time; for alt-pay it is baked into the quoted crypto amount. Network fees, the service fee, and the 1 ADA receipt are never discounted.

Uses follow a reserve → commit → release lifecycle so a single-use code cannot be double-spent or burned by an abandoned checkout. Rejections carry a machine reason (`not_found`, `expired`, `exhausted`, `wrong_collection`, …) and are logged server-side.

---

## Asset Upgrade (burn and re-mint)

Refresh an existing NFT's CIP-25 metadata **without changing its fingerprint**: same `policyId` + `assetName`, new metadata. Because the ledger rejects a net-zero mint, this is a coordinated **two-transaction** flow:

1. **Burn.** `quantity: -1`, no metadata, no output. The target metadata is resolved *before* the burn and stashed in a 2-hour transient.
2. **Re-mint.** `quantity: +1` with the new metadata and a 1.5 ADA min-UTxO output back to the customer.

**Admin:** register a policy (the plugin snapshots it via Blockfrost and decodes its time-lock), then define a **policy-wide patch** (`{"image": "ipfs://NEW_CID"}` merged onto every asset) and/or **per-asset bundles** (paste a full `{"721": {...}}` bundle). Per-asset rows beat the policy-wide patch. Preview the resolved diff, then set the spec to `active`.

**Customer:** `[cardano-upgrade]` → connect wallet → eligible NFTs are resolved via Blockfrost → review current vs proposed → sign the burn → sign the re-mint. A 5-minute cron confirms both landed. The customer pays only the network fee.

Time-lock is re-checked at build time, not just at registration. Every build, submit, confirm, and failure is written to an append-only audit log. Requires a Blockfrost key on **Payment Wallets → Settings**.

---

## Wallet network gate

Four independent checks, any one of which rejects a mint where the customer's wallet is on a different network than the site:

1. **Wallet connect (browser).** `getNetworkId()` plus an `addr_test1` / `addr1` prefix check.
2. **Pre-build click (browser).** Re-checks the address right before submit to catch mid-session wallet changes.
3. **Alt-pay quote (server).** Refuses to issue a deposit address bound to a wrong-network Cardano address. This is the load-bearing layer for off-chain payments.
4. **Mint build (server).** Last-line check on both the AJAX and REST build paths.

Every error names the network detected and the one expected.

---

## REST API

Namespace: `cardano-mint/v1`. Widget keys go in the `X-CM-Api-Key` header. CORS is enforced per registered origin.

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/collections` | Public | List active collections |
| GET | `/collections/{id}` | Public | Collection detail |
| GET | `/config` | Public | Site network and merchant info |
| GET | `/price` | Public | Live ADA/USD price |
| POST | `/mint/build` | API key | Build the mint transaction (Anvil proxy). Optional `invoice_id` and `discount_code`. Network-gated. |
| POST | `/mint/submit` | API key | Submit the signed transaction; the policy signature is added server-side. |
| POST | `/altpay/quote` | Public, rate-limited | Issue a deposit address and price lock on BTC / ETH / SOL. Network-gated. |
| GET | `/altpay/status` | Public | Poll invoice status, observed amount, confirmations |
| POST | `/altpay/cancel` | Public | Cancel a pending invoice |
| POST | `/onramp/quote` | Nonce | ADA estimate for a fiat amount |
| POST | `/onramp/sessions` | Nonce | Create a card on-ramp session; returns the hosted-checkout URL |
| GET | `/onramp/sessions/{partner_link_id}` | Nonce | Poll on-ramp status |
| GET | `/onramp/wallet-balance` | Nonce | Blockfrost balance passthrough |
| POST | `/onramp/webhooks/guardarian` | IP allowlist | Guardarian status webhook |
| POST | `/upgrade/eligible` | Nonce | NFTs in a wallet eligible for an upgrade |
| POST | `/upgrade/build` | Nonce | Build the burn or re-mint. Time-lock gated. |
| POST | `/upgrade/submit` | Nonce | Submit the signed burn or re-mint |

Every Anvil call is made from the server. The Anvil API key is never sent to the browser.

---

## Widget Deployer

Embed the full mint flow on any external site (Next.js, React, plain HTML) with one script tag:

```html
<div id="cardano-mint-widget"></div>
<script
  src="https://yoursite.com/wp-content/plugins/cardano-easy-mint/assets/js/cm-widget.js"
  data-api="https://yoursite.com/wp-json/cardano-mint/v1"
  data-key="cmk_YOUR_API_KEY"
  data-collection="42"
  data-container="cardano-mint-widget"></script>
```

Generate the key and register the allowed origin on **Cardano Mint → Widget Deployer**. The widget renders inside Shadow DOM so host styles cannot bleed in, and all Anvil traffic stays on your WordPress origin. If you are minting on the WordPress site itself, use the shortcode instead.

---

## Architecture

```
cardano-easy-mint/
├── cardano-nft-checkout.php           Plugin entry: constants, requires, activation, menus, enqueues, cron
├── readme.txt                         WordPress.org-style readme
├── README.md / CHANGELOG.md / LICENSE
├── assets/
│   ├── cardano-nft-mint.js            Mint modal + embedded CIP-30 wallet manager
│   ├── cardano-checkout.css           Modal styles
│   ├── weldpress-bridge.js            Clears the saved wallet on a site-wide disconnect (no-op without WeldPress)
│   ├── altpay/                        Alt-pay checkout overlay + admin tools (JS/CSS)
│   ├── onramp/                        Card on-ramp modal + admin (JS/CSS)
│   ├── discounts/                     Discounts admin (JS/CSS)
│   ├── asset-upgrade/                 Upgrade modal + admin (JS/CSS)
│   └── js/cm-widget.js                Embeddable widget (Shadow DOM, zero deps)
├── includes/
│   ├── admin/                         Plugin Setup, Mint Manager / Active Mints, How to Use pages + one-time migrations
│   ├── controllers/
│   │   ├── NFTCheckoutController.php  Mint modal AJAX, network gate, quantity validation, bulk JSON import, address-conversion proxy
│   │   ├── PolicyWalletController.php Policy wallet generate / import
│   │   ├── RestApiController.php      REST API, alt-pay endpoints, CORS
│   │   ├── AJAXController.php         Anvil connectivity test + rate limiting
│   │   ├── WidgetAdminController.php  Widget deployer
│   │   ├── AltPayAdminController.php  Payment Wallets pages + 2FA gate
│   │   ├── Onramp{Admin,Public,Webhook}Controller.php
│   │   ├── Discount{Admin,Public}Controller.php
│   │   └── AssetUpgrade{Admin,Public}Controller.php
│   ├── helpers/
│   │   ├── AnvilAPI.php               Anvil client; quantity-aware buildMintTransaction
│   │   ├── log.php                    cardanomint_log(): WP_DEBUG-gated logging
│   │   ├── CardanoWalletPHP.php       BIP-39 / CIP-1852 wallet derivation (pure PHP)
│   │   ├── CardanoTransactionSignerPHP.php  CBOR codec + Ed25519 extended-key signing
│   │   ├── CardanoCLI.php             Thin facade over the pure-PHP signer and wallet generator
│   │   ├── Ed25519Compat.php / Ed25519Pure.php
│   │   ├── EncryptionHelper.php       AES-256-CBC over WordPress salts
│   │   ├── PolicyImport.php           External policy import + multisig validation
│   │   ├── ApiKeys.php                Widget API keys
│   │   ├── BlockfrostClient.php       Balance / asset lookups
│   │   ├── TOTPHelper.php             RFC-6238 2FA
│   │   ├── PinataAPI.php
│   │   └── bip39-wordlist.php
│   ├── altpay/
│   │   ├── AltPayService.php          Quote / status / finalize / refund / sweep / watcher_tick
│   │   ├── AltPayInstaller.php        Schema migration (3 tables)
│   │   ├── ChainPaymentProvider.php   Provider interface
│   │   ├── PriceOracle.php            CoinGecko + transient cache
│   │   ├── Bip39.php / Bip32Secp.php / Slip10Ed25519.php
│   │   ├── encoding/                  Bech32, Base58, Keccak address
│   │   ├── lib/                       Bn (GMP/bcmath), Secp256k1, Keccak, Rlp
│   │   ├── providers/                 BtcProvider, EthProvider, SolProvider
│   │   └── rpc/                       MempoolClient, EthRpcClient, SolRpcClient
│   ├── onramp/                        GuardarianClient, GuardarianService, OnrampInstaller
│   ├── discounts/                     DiscountService, DiscountInstaller
│   ├── asset-upgrade/                 AssetUpgradeService, MetadataResolver, AssetUpgradeInstaller
│   ├── models/                        MintModel, ChainWalletModel, ChainInvoiceModel, ChainTxLogModel,
│   │                                  OnrampSessionModel, DiscountModel
│   └── views/                         mint-manager, active-mints-list, policy-wallet-manager,
│                                      nft-mint-form (modal markup), widget-deployer,
│                                      altpay/, discounts/, asset-upgrade/
└── docs/                              Build plans, locked decisions, historical release notes
```

---

## Database tables

| Table | Purpose |
|---|---|
| `wp_cardanonftactivemints` | Collections, variants, metadata, pricing, supply |
| `wp_cardanonftmintcounts` | Legacy per-wallet mint tracking |
| `wp_cardano_policy_wallets` | Encrypted policy wallet keys |
| `wp_cardano_mint_wallets` | Per-wallet mint limits and history |
| `wp_cardano_mint_policies` | Imported external policies |
| `wp_cm_chain_wallets` | Alt-chain parent HD wallets (encrypted xprv) |
| `wp_cm_chain_invoices` | Per-mint alt-chain payment intents |
| `wp_cm_chain_tx_log` | Inbound, refund, and sweep transaction log |
| `wp_cm_onramp_sessions` | Card on-ramp sessions (audit and status) |
| `wp_cm_discount_campaigns` / `_codes` / `_redemptions` | Discount campaigns, codes, and the reserve/commit log |
| `wp_cardano_asset_upgrades` | Upgrade specs (policy-wide patch or per-asset metadata) |
| `wp_cardano_asset_upgrade_log` | Append-only burn and re-mint audit log |

---

## Security

- All Cardano and alt-chain signing keys are encrypted at rest (AES-256-CBC, key derived from WordPress salts). Decrypted keys live only in a local variable inside `try/finally` with `unset` and `sodium_memzero`, and are never logged.
- Every Anvil call is server-side. The Anvil API key is never localized into a page or sent to a browser.
- Server-authoritative amounts: client-supplied prices are ignored. The database price is the source of truth.
- All AJAX endpoints use WordPress nonces; all admin POSTs go through `check_ajax_referer`. The public address-conversion proxy and alt-pay quotes are rate-limited per IP.
- All database queries use `$wpdb->prepare()`.
- Alt-pay quote rate limit: 5 per IP per minute. Each quote burns one HD index. HD addresses are never reused; cancelled invoices do not free the index.
- Wallet network gate at four layers (see above).
- Optional TOTP 2FA with recovery codes on the Payment Wallets and Asset Upgrades pages.
- Refunds and sweeps are the hot-wallet exposure point. Sweep to a cold address that is not on the WordPress host.

---

## Critical warnings

1. **The policy wallet seed is shown once.** Write it down before you click anything else. It cannot be recovered.
2. **Deleting the policy wallet** means you can never mint again under the policies it signed.
3. **Test the whole flow on preprod first.** Every time. Mainnet mints are irreversible.
4. **Same wallet + same expiration = same policy ID.** Change the expiration to get a new policy.
5. **Keep image file names under 63 characters** or the on-chain metadata will be rejected.
6. **The CIP-27 royalty token mints once per policy**, with the first NFT. Set the royalty before that first mint.
7. **CSV import replaces all wallet records for the policy.** Export first.
8. **Data Cleanup on deactivation** drops the core mint tables. Leave it off unless you are removing the plugin for good.
9. **Alt-chain receive addresses are hot.** Sweep often.

---

## Best practices

- Create variant A first. It owns the policy and the collection image.
- Rarity weights across a collection should total 100.
- Store the policy wallet seed offline, immediately.
- Use HTTPS on any site that takes payments.
- Debug tracing (server log and browser console) only appears when `WP_DEBUG` is on, or when the `cardano_mint_debug_log` filter returns true. Keep it off in production.
- CSV whitelist workflow: Export → edit in a spreadsheet → Import.
- If you run WP-Cron via a real cron job, make sure the `cardano_altpay_minute` schedule fires; the watcher and the discount sweeper depend on it.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Policy ID already exists" | Same wallet and expiration as an existing policy. Change the expiration. |
| "Reached maximum mints" | The wallet hit its per-wallet limit. |
| "Metadata too long" | Shorten the image file name. |
| "Transaction failed" | Customer wallet is short on ADA (about 2 ADA minimum plus price). |
| "Policy wallet not found" | Generate or import one on Policy Wallet. |
| "Nonce verification failed" | Page cache served a stale nonce. Exclude mint pages from full-page caching. |
| "Switch your wallet network" | Wallet is on preprod and the site is on mainnet, or the reverse. |
| Alt-pay invoice never funds | WP-Cron is not running, or the chain RPC in Payment Wallets → Settings is wrong. |
| Card on-ramp webhook 403 | Empty or wrong webhook IP allowlist. Polling still works without webhooks. |
| Shortcode renders nothing | Mint does not exist or is not Active. |
| Alt-chain quotes refuse | Missing `gmp` or `bcmath` extension. The admin notice on activation names the missing one. |

Browser console (F12) shows the full client-side flow. Enable `WP_DEBUG_LOG` temporarily for server logs.

---

## Building the release zip

The distributable is the plugin folder zipped with `cardano-easy-mint/` as the top-level directory, excluding everything listed in `.distignore` (git metadata, docs, build output). With [7-Zip](https://www.7-zip.org/) on Windows:

```powershell
# from the folder that contains cardano-easy-mint/
& "C:\Program Files\7-Zip\7z.exe" a -tzip cardano-easy-mint-4.6.1.zip cardano-easy-mint\ `
  -xr!.git -xr!.gitignore -xr!.distignore -xr!docs -xr!build -xr!*.zip
```

Upload the result through **Plugins → Add New → Upload Plugin**.

---

## Contributing

Issues and pull requests are welcome. A few pointers:

- Read `docs/DECISIONS.md` before changing anything that looks deliberate. Locked decisions are there for a reason; supersede them with a new entry rather than quietly changing them.
- Keep the crypto pure PHP. No Composer, no native binaries.
- Every new AJAX or REST endpoint needs a nonce or key check and `$wpdb->prepare()`. Log through `cardanomint_log()`, never bare `error_log()`.
- Test on preprod. Note the WordPress and PHP versions you tested on in the PR.
- Add a `CHANGELOG.md` entry under the next version.

---

## Credits and license

Built with the [Ada Anvil](https://ada-anvil.io/) API and [Weld](https://github.com/ADA-Anvil/weld) wallet-connection patterns. Alt-chain crypto vendored from `simplito/elliptic-php` (secp256k1, MIT) and `kornrunner/keccak` (MIT). Metadata formatting: [MetaDraft](https://metadraft.io/).

Licensed under the **GNU Affero General Public License v3.0 or later**. See [LICENSE](LICENSE). You can use, modify, and redistribute it, including commercially, as long as you keep the license and share your source when you distribute it or run a modified version as a network service. Attribution to the original project is appreciated.
