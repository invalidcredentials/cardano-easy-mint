# Cardano Easy Mint

A WordPress plugin for minting NFTs on the Cardano blockchain using the Anvil API. Fully self-contained with native CIP-30 wallet connection, pure PHP Ed25519 signing, and an embeddable widget for deploying minting to external sites like Next.js games.

**A Pb Project** | Open Source

## Features

- **NFT Minting Engine** - Create NFT collections with variants, rarity weights, and CIP-25 metadata
- **Policy Wallet Management** - Generate secure server-side wallets for policy signing (BIP-39 / CIP-1852)
- **Advanced Key Import** - Import external signing keys, seed phrases, or full policy data from Cardano CLI or MetaDraft
- **Native Wallet Connection** - Built-in CIP-30 wallet detection (Eternl, Lace, Yoroi, VESPR, etc.) - no CardanoPress dependency
- **Widget Deployer** - Embed a self-contained minting widget on any external site via a single `<script>` tag
- **REST API** - Full REST API with API key auth and CORS for cross-origin widget usage
- **CIP-27 Royalties** - Auto-mint royalty tokens on first mint per policy
- **Metadata Upload** - Upload MetaDraft-formatted JSON metadata or build it manually in the admin
- **Pure PHP Crypto** - Ed25519 signing, CBOR encoding, BIP-39 wallet generation - no external binaries needed
- **AES-256-CBC Encryption** - All signing keys encrypted at rest using WordPress salts

## Requirements

- WordPress 5.0+
- PHP 7.4+ (with BCMath or sodium extension)
- [ADA Anvil](https://ada-anvil.io/) API key (mainnet and/or preprod)
- [Pinata](https://www.pinata.cloud/) API key (optional, for IPFS pinning)

## Installation

1. Upload the `cardano-minting` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Go to **Cardano Mint > Plugin Setup** and enter your Anvil API key and merchant wallet address
4. Go to **Policy Wallet** to generate a secure signing wallet
5. Go to **Mint Manager** to create your first NFT collection

## Admin Pages

| Page | Purpose |
|------|---------|
| **Plugin Setup** | Anvil API keys, merchant address, network (mainnet/preprod), Pinata IPFS config |
| **Mint Manager** | Create/edit NFT collections, variants, metadata, royalties, pricing |
| **Active Mints** | View mints by policy, archive/unarchive, CSV export/import, mint history |
| **Policy Wallet** | Generate or import signing wallets, advanced key import (seed/skey/manual) |
| **Widget Deployer** | Generate API keys, customize widget appearance, embed code generator |
| **How to Use** | Shortcode documentation |

## Shortcode

```
[cardano-mint mint-id="20"]           # Random variant selection (by rarity weight)
[cardano-mint mint-id="20-A"]         # Specific variant
[cardano-mint mint-id="25" class="custom-button"]
```

## Advanced: Import External Keys

In **Policy Wallet > Advanced: Import External Keys**, you can import policies from external sources:

| Method | What You Provide | What Happens |
|--------|-----------------|--------------|
| **Seed Phrase** | 12/15/24-word BIP-39 mnemonic | Wallet derived, keys encrypted, mnemonic NOT stored |
| **Skey + Script** | Signing key + native script JSON | Policy validated via Anvil, key encrypted, appears in Mint Manager dropdown |
| **Full Manual** | Policy ID + signing key + script | Policy ID verified against script, everything stored encrypted |

Imported policies appear in the Mint Manager's "Add Asset to Existing Policy" dropdown marked with "(imported)". When minting under an imported policy, the system uses the imported signing key automatically.

Format your metadata at [metadraft.io](https://metadraft.io/) before importing.

## Widget Deployer

Deploy a self-contained minting widget to any external site (Next.js, React, vanilla HTML):

1. Generate an API key in **Widget Deployer** (restrict by origin and collection)
2. Copy the embed snippet
3. Paste into your external site

```html
<div id="cardano-mint-widget"></div>
<script
  src="https://yoursite.com/wp-content/plugins/cardano-minting/assets/js/cm-widget.js"
  data-api="https://yoursite.com/wp-json/cardano-mint/v1"
  data-key="cmk_YOUR_API_KEY"
  data-collection="42"
  data-container="cardano-mint-widget"
></script>
```

The widget renders: collection image, title, price (USD + ADA), supply remaining, variant selector, wallet connect, and the full mint flow. All signing happens server-side - your Anvil API key is never exposed.

## REST API

Namespace: `cardano-mint/v1`

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/collections` | Public | List active collections |
| GET | `/collections/{id}` | Public | Collection detail with variants, pricing, supply |
| GET | `/config` | Public | Network and site info |
| GET | `/price` | Public | Live ADA/USD price |
| POST | `/mint/build` | API Key | Build minting transaction (Anvil proxy) |
| POST | `/mint/submit` | API Key | Submit signed transaction (adds policy signature server-side) |

API keys are passed via the `X-CM-Api-Key` header. CORS is handled automatically for whitelisted origins.

## Architecture

```
cardano-minting/
├── cardano-nft-checkout.php          # Plugin entry point
├── assets/
│   ├── cardano-nft-mint.js           # Shortcode minting JS (embedded wallet connection)
│   ├── cardano-checkout.css          # Shared styles
│   └── js/
│       └── cm-widget.js              # Embeddable widget (zero dependencies, Shadow DOM)
├── includes/
│   ├── controllers/
│   │   ├── NFTCheckoutController.php # Shortcode + minting AJAX
│   │   ├── PolicyWalletController.php# Wallet management + import AJAX
│   │   ├── RestApiController.php     # REST API + CORS
│   │   ├── WidgetAdminController.php # Widget deployer admin
│   │   └── AJAXController.php       # Anvil API test + rate limiting
│   ├── helpers/
│   │   ├── AnvilAPI.php              # Anvil API client (build/submit/policy gen)
│   │   ├── ApiKeys.php               # Widget API key management (SHA-256 + AES-256)
│   │   ├── PolicyImport.php          # External policy import (skey/seed/manual)
│   │   ├── EncryptionHelper.php      # AES-256-CBC encryption via WP salts
│   │   ├── CardanoWalletPHP.php      # BIP-39/CIP-1852 wallet generation
│   │   ├── CardanoTransactionSignerPHP.php # CBOR TX signing
│   │   ├── CardanoCLI.php            # Signing/wallet generation wrapper
│   │   ├── Ed25519Compat.php         # Ed25519 compat layer (sodium/FFI/pure PHP)
│   │   ├── Ed25519Pure.php           # Pure PHP Ed25519 (BCMath fallback)
│   │   ├── PinataAPI.php             # IPFS pinning via Pinata
│   │   ├── WalletGenerator.php       # Wallet generation utility
│   │   └── bip39-wordlist.php        # BIP-39 English wordlist (2048 words)
│   ├── models/
│   │   └── MintModel.php             # All database operations (5 tables)
│   └── views/
│       ├── mint-manager.php          # Create/edit NFT collections
│       ├── active-mints-list.php     # Manage existing mints
│       ├── policy-wallet-manager.php # Wallet gen + advanced import
│       ├── nft-mint-form.php         # Frontend minting modal
│       └── widget-deployer.php       # Widget deployer admin UI
└── README.md
```

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_cardanonftactivemints` | NFT collections, variants, metadata, pricing, supply |
| `wp_cardanonftmintcounts` | Legacy per-wallet mint tracking |
| `wp_cardano_policy_wallets` | Encrypted policy wallet keys (generated + imported seeds) |
| `wp_cardano_mint_wallets` | Per-wallet mint limit tracking |
| `wp_cardano_mint_policies` | Imported external policies with encrypted signing keys |

## Security

- All signing keys encrypted at rest (AES-256-CBC)
- PBKDF2 key derivation from WordPress salts
- SHA-256 hashed API keys (raw key shown once, never stored)
- CORS restricted to whitelisted origins per API key
- All AJAX endpoints use nonce verification
- All database queries use `$wpdb->prepare()`
- CIP-30 wallet connection happens client-side only
- Anvil API key never exposed to frontend

## Credits

Built with [ADA Anvil](https://ada-anvil.io/) API and [Weld](https://github.com/ADA-Anvil/weld) wallet connection patterns.

Metadata formatting: [MetaDraft](https://metadraft.io/)

## License

Open Source - GPL v2 or later
