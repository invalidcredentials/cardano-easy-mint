=== Cardano Easy Mint ===
Contributors: invalidcredentials
Tags: cardano, nft, minting, crypto, payments, bitcoin, ethereum, solana, web3
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 4.5.3
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Mint Cardano NFTs from WordPress. Native CIP-30 wallet connect, ADA / BTC / ETH / SOL / card payments, discount codes, and burn-and-re-mint metadata upgrades.

== Description ==

Cardano Easy Mint turns a WordPress site into a full NFT mint. It builds and submits Cardano transactions through the [Ada Anvil](https://ada-anvil.io/) API, signs with an encrypted policy wallet on your server, and lets customers pay in ADA, in BTC / ETH / SOL, or with a credit card.

**Minting engine (the 3.0 core)**

* Collections with lettered variants, weighted rarity, and mystery-box reveals
* Per-asset form builder or bulk JSON import of mint-ready CIP-25 metadata
* Dual-signature mints: customer wallet + your encrypted policy wallet
* Per-wallet mint limits, CSV export / whitelist import, mint history
* Automatic CIP-27 royalty token per policy
* Optional Pinata IPFS pinning with a WordPress media fallback

**Built on top of it (4.x)**

* Native CIP-30 wallet connection (Eternl, Lace, Vespr, Yoroi, Typhon, Gero) with no external wallet plugin
* Batch mints: 1 to 5 NFTs per transaction, one signature
* Alt-chain payments: BTC / ETH / SOL with per-mint deposit addresses, a server-side watcher, refunds and sweeps
* Fiat on-ramp: customers buy ADA by card (Guardarian) straight into their own wallet
* Discount codes: percent or fixed-amount campaigns, single or multi-use, for every payment method
* Asset Upgrade: refresh an existing NFT's metadata in place via a coordinated burn and re-mint
* Wallet network gate, optional TOTP 2FA on the wallet admin pages, REST API, and an embeddable widget for external sites

All cryptography (Ed25519, secp256k1, Keccak-256, Bech32, Base58) is pure PHP. No Composer, no native binaries.

== Installation ==

1. Upload the `cardano-easy-mint` folder to `/wp-content/plugins/`, or upload the release zip via Plugins > Add New > Upload Plugin.
2. Activate **Cardano Easy Mint**.
3. Go to **Cardano Mint > Plugin Setup** and enter your Anvil API key, merchant address, and network. Start on preprod.
4. Go to **Policy Wallet** and generate a wallet. Save the seed phrase; it is shown once.
5. Go to **Mint Manager** and create your first collection, then drop `[cardano-mint mint-id="1"]` on a page.

See the README on GitHub for the full guide.

== Frequently Asked Questions ==

= Do I need CardanoPress or another wallet plugin? =

No. Wallet connection is built in (CIP-30).

= Which API keys do I need? =

An Ada Anvil key is required. Pinata is optional (IPFS). A Blockfrost key is only needed for the alt-chain dashboard balances, the fiat on-ramp, and Asset Upgrades.

= Is my Anvil API key exposed to visitors? =

No. Every Anvil call is made server-side. The key never leaves WordPress.

= Can customers pay with something other than ADA? =

Yes. Enable Alt-Chain Payments for BTC / ETH / SOL, or the On-Ramp for card payments.

== Changelog ==

See CHANGELOG.md in the plugin folder for the full history.

= 4.5.3 =
* Security: the Anvil API key is no longer localized into the page; address conversion goes through a server-side proxy.
* Wallet connector no longer auto-reconnects on every page or fights a site-wide connector.
* Open-source release prep: license, plugin header fields, direct-access guards, generic 2FA branding.
