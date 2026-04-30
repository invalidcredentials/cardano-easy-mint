# Cardano Easy Mint

A WordPress plugin for minting NFTs on the Cardano blockchain via the [Ada Anvil](https://ada-anvil.io/) API. Self-contained with native CIP-30 wallet connection, pure-PHP cryptography (Ed25519 + secp256k1 + Keccak-256), an embeddable widget for external sites, and a complete alt-chain payment system that lets customers pay for Cardano NFTs with BTC, ETH, or SOL.

**A Pb Project** | Open Source

## Highlights

- **NFT Minting Engine** — collections with variants, rarity weights, CIP-25 metadata, CIP-27 royalties
- **Alt-chain Payments** — accept BTC, ETH, or SOL for a Cardano NFT mint. Per-mint deterministic deposit address, server-side watcher, automatic price-locked invoices.
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
| **Mint Manager** | Create / edit NFT collections, variants, metadata, royalties, pricing |
| **Active Mints** | View mints by policy, archive, CSV export/import, mint history |
| **Policy Wallet** | Generate or import Cardano signing wallets, advanced key import (seed / skey / manual) |
| **Alt-Chain Payments** | BTC / ETH / SOL parent-wallet management, per-chain settings, RPC config, refund / sweep tooling, cross-chain invoice review |
| **Widget Deployer** | Generate API keys, restrict by origin, embed-snippet generator |
| **How to Use** | Shortcode + widget docs |

## Shortcode

```
[cardano-mint mint-id="20"]              Random variant by rarity weight
[cardano-mint mint-id="20-A"]            Specific variant
[cardano-mint mint-id="25" class="custom-button"]
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

API keys go in `X-CM-Api-Key`. CORS is enforced per registered origin.

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
│   │   ├── NFTCheckoutController.php  Mint modal AJAX, network gate, qty validation
│   │   ├── PolicyWalletController.php Cardano policy wallet management
│   │   ├── RestApiController.php      REST API + alt-pay endpoints + CORS
│   │   ├── AltPayAdminController.php  Alt-chain admin pages + AJAX
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
│   ├── models/
│   │   ├── MintModel.php              Mint + per-wallet limit accounting
│   │   ├── ChainWalletModel.php       Alt-chain parent HD wallets
│   │   ├── ChainInvoiceModel.php      Alt-chain invoices
│   │   └── ChainTxLogModel.php        On-chain tx audit log
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
