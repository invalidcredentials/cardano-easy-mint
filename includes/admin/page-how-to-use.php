<?php
/**
 * How to Use admin page (in-admin guide).
 *
 * Moved out of cardano-nft-checkout.php in 4.6.0 unchanged; the plugin entry
 * file now only wires things together. Plugin paths resolve through the
 * CARDANO_MINT_PLUGIN_DIR / _URL constants defined there.
 */

if (!defined('ABSPATH')) exit;

function cardanomint_how_to_use_page() {
    ?>
    <div class="wrap">
        <h1>Cardano Mint - How to Use</h1>

        <div class="notice notice-info inline" style="margin: 12px 0 18px;">
            <p><strong>Heads up:</strong> this in-admin guide covers the core minting workflow and may lag behind newer features. The most up-to-date documentation is the README on GitHub: <a href="https://github.com/invalidcredentials/cardano-easy-mint#readme" target="_blank" rel="noopener">github.com/invalidcredentials/cardano-easy-mint</a>.</p>
        </div>

        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h2 style="color: white; margin-top: 0;">🚀 Cardano Easy Mint v<?php echo esc_html(CARDANO_MINT_VERSION); ?></h2>
            <p style="font-size: 16px; margin-bottom: 0;">Complete NFT minting solution with dual-signature security, policy wallet management, IPFS pinning, per-wallet limits, CSV whitelist management, and automated CIP-27 royalty tokens.</p>
        </div>

        <!-- Quick Start Guide -->
        <div style="background: #e7f3ff; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">⚡ Quick Start Guide</h3>
            <ol style="line-height: 1.8;">
                <li><strong>Plugin Setup:</strong> Configure Anvil API keys, merchant address, network (mainnet/preprod), and Pinata (optional)</li>
                <li><strong>Policy Wallet:</strong> Generate a secure policy wallet for signing transactions (ONE TIME - save the seed phrase!)</li>
                <li><strong>Create Mint:</strong> Use Mint Manager to create NFT collections with metadata, images, and pricing</li>
                <li><strong>Generate Policy:</strong> Set expiration date and generate policy ID (triggers automatic CIP-27 royalty token)</li>
                <li><strong>Add Shortcode:</strong> Use <code>[cardano-mint mint-id="X"]</code> on your page</li>
                <li><strong>Track Mints:</strong> Monitor and manage minters via Active Mints page (CSV export/import)</li>
            </ol>
        </div>

        <!-- Shortcode Documentation -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🖼️ [cardano-mint] - NFT Minting Shortcode</h3>
            <p><strong>Description:</strong> Creates a "MINT NOW" button that opens a minting modal for purchasing and minting NFTs with dual-signature security.</p>
            <p><strong>Compatible with:</strong> Gutenberg, Classic Editor, Bricks Builder, Elementor, and page builders.</p>

            <h4>Available Attributes:</h4>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 20%;">Attribute</th>
                        <th style="width: 15%;">Required</th>
                        <th style="width: 15%;">Default</th>
                        <th style="width: 50%;">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>mint-id</code></td>
                        <td><span style="color: #d63638;">Yes</span></td>
                        <td>-</td>
                        <td>Collection ID or specific variant (e.g., "20" or "20-A")</td>
                    </tr>
                    <tr>
                        <td><code>nftname</code></td>
                        <td><span style="color: #00a32a;">No</span></td>
                        <td>From Database</td>
                        <td>Display name for the NFT (overrides database value)</td>
                    </tr>
                    <tr>
                        <td><code>class</code></td>
                        <td><span style="color: #00a32a;">No</span></td>
                        <td>-</td>
                        <td>CSS class for custom styling</td>
                    </tr>
                </tbody>
            </table>

            <h4>📝 Shortcode Usage Examples:</h4>

            <h5>1. Random Variant Selection (Mystery Box):</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20"]
            </div>
            <p><em>Randomly selects from ALL variants in collection 20 (20-A, 20-B, 20-C, etc.) using weighted rarity. Users see the "Collection Image" until mint is complete.</em></p>

            <h5>2. Specific Variant Selection:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20-A"]
            </div>
            <p><em>Mints ONLY variant A from collection 20. Users see the specific NFT image upfront.</em></p>

            <h5>3. With Custom Styling:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20-B" class="my-custom-button"]
            </div>

            <h5>4. Custom Display Name:</h5>
            <div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin: 10px 0;">
                [cardano-mint mint-id="20" nftname="Mystery Art Drop"]
            </div>

        </div>

        <!-- Core Features -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🔥 Core Features</h3>

            <h4>1. Collection & Variant System</h4>
            <ul>
                <li><strong>Parent Collection (ID):</strong> Main collection identifier (e.g., "20")</li>
                <li><strong>Variants (ID-Letter):</strong> Different versions within a collection (e.g., "20-A", "20-B", "20-C")</li>
                <li><strong>Variant A = Parent:</strong> Always create the "A" variant first - it controls collection-wide settings</li>
                <li><strong>Weighted Rarity:</strong> Each variant has a rarity percentage that determines random selection probability</li>
                <li><strong>Shortcode Behavior:</strong>
                    <ul>
                        <li><code>mint-id="20"</code> → Random weighted selection from all variants</li>
                        <li><code>mint-id="20-A"</code> → Only mints that specific variant</li>
                    </ul>
                </li>
            </ul>

            <h4>2. Mystery Box / Blind Minting</h4>
            <ul>
                <li><strong>Collection Image:</strong> Upload a "mystery box" image that shows before reveal</li>
                <li><strong>When to Use:</strong> Use collection image when you want users to NOT see which variant they're getting until after mint</li>
                <li><strong>NFT Image:</strong> The actual NFT image revealed after minting completes</li>
                <li><strong>Editing:</strong> Collection image can ONLY be set in the parent "A" variant on Active Mints page</li>
                <li><strong>Display Logic:</strong> If collection image exists, show it instead of NFT image during minting</li>
            </ul>

            <h4>3. Policy Wallet System</h4>
            <ul>
                <li><strong>Purpose:</strong> Secure wallet for signing NFT minting transactions (dual-signature with customer)</li>
                <li><strong>ONE-TIME SEED:</strong> The 24-word seed phrase is shown ONLY ONCE during creation - WRITE IT DOWN!</li>
                <li><strong>Not for Funds:</strong> Policy wallet should NOT hold ADA - it's only for signing, not receiving payments</li>
                <li><strong>Encryption:</strong> Seed and keys encrypted using WordPress salts (see POLICY_WALLET_ENCRYPTION.md)</li>
                <li><strong>Deletion Warning:</strong> If you delete policy wallet, you CANNOT mint old NFTs associated with it</li>
                <li><strong>Multiple Wallets:</strong> You can only have ONE active policy wallet at a time</li>
                <li><strong>All Mints:</strong> The same policy wallet is used for ALL your minting policies</li>
            </ul>

            <h4>4. Dual-Signature Minting Flow</h4>
            <ol>
                <li><strong>Customer Connects Wallet:</strong> Via CIP-30 (Eternl, Lace, Vespr, Typhon, etc.)</li>
                <li><strong>Build Transaction:</strong> Anvil API builds the mint transaction with metadata</li>
                <li><strong>Customer Signs:</strong> User signs with their wallet (proves ownership + payment)</li>
                <li><strong>Policy Wallet Signs:</strong> Server adds policy wallet signature (proves minting authority)</li>
                <li><strong>Submit Transaction:</strong> Dual-signed transaction submitted to blockchain</li>
                <li><strong>Record Mint:</strong> Per-wallet limit tracking updated, quantity decremented</li>
            </ol>

            <h4>5. Per-Wallet Mint Limits & Tracking</h4>
            <ul>
                <li><strong>Set Limit:</strong> Configure "Mints Allowed Per Wallet" in Mint Manager (0 = unlimited)</li>
                <li><strong>Enforcement:</strong> Checked BEFORE transaction is built - prevents wasted gas</li>
                <li><strong>Tracking Database:</strong> Records payment address, stake address, mints allowed, minted, remaining</li>
                <li><strong>CSV Export:</strong> Download complete mint history with wallet addresses and dates</li>
                <li><strong>CSV Import (Whitelist):</strong> Pre-authorize wallets by uploading CSV with allowed mint counts</li>
                <li><strong>View History:</strong> Modal view showing all minters, their limits, and remaining mints</li>
                <li><strong>Unlimited Tracking:</strong> Even unlimited (0) mints are tracked for analytics</li>
            </ul>

            <h4>6. CIP-27 Royalty Tokens (Automatic)</h4>
            <ul>
                <li><strong>When:</strong> Automatically minted with the FIRST NFT when policy is created</li>
                <li><strong>One Per Policy:</strong> Only ONE royalty token per policy ID</li>
                <li><strong>Configuration:</strong> Set royalty percentage and address in Mint Manager</li>
                <li><strong>Blockchain Standard:</strong> Follows CIP-27 specification for NFT royalties</li>
                <li><strong>Burned:</strong> Royalty token is burned automatically during minting process</li>
                <li><strong>No Manual Action:</strong> Completely automated - just fill in royalty info</li>
            </ul>

            <h4>7. Pinata IPFS Integration</h4>
            <ul>
                <li><strong>Optional:</strong> Not required - WordPress CDN is used by default</li>
                <li><strong>Recommended:</strong> Pinata (or other IPFS pinning) ensures NFTs live forever</li>
                <li><strong>Configuration:</strong> Add Pinata JWT or API keys in Plugin Setup</li>
                <li><strong>Image Name Length:</strong> Keep under 63 characters or metadata will be too long</li>
                <li><strong>Automatic Pinning:</strong> Images uploaded to WordPress can be pinned to IPFS via admin</li>
                <li><strong>Metadata:</strong> Currently only supports image pinning (metadata stays on WordPress)</li>
            </ul>

            <h4>8. CIP-25 Metadata Builder</h4>
            <ul>
                <li><strong>Custom Attributes:</strong> Add unlimited key-value pairs in Mint Manager</li>
                <li><strong>Standard Fields:</strong> Name, image, description automatically included</li>
                <li><strong>On-Chain Metadata:</strong> All metadata is included in the minting transaction</li>
                <li><strong>JSON Preview:</strong> View metadata JSON in Active Mints page</li>
            </ul>
        </div>

        <!-- Admin Pages Guide -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🎛️ Admin Pages Guide</h3>

            <h4>Plugin Setup</h4>
            <ul>
                <li><strong>Anvil API Keys:</strong> Mainnet and Preprod (testnet) keys</li>
                <li><strong>Network:</strong> Select mainnet or preprod environment</li>
                <li><strong>Merchant Address:</strong> Your Cardano wallet address to receive payments</li>
                <li><strong>Pinata Settings:</strong> JWT or API key + secret for IPFS pinning</li>
                <li><strong>Data Cleanup:</strong> Option to delete all data on plugin deactivation</li>
            </ul>

            <h4>Mint Manager (Create New Mints)</h4>
            <ul>
                <li><strong>Title:</strong> Internal name for your NFT</li>
                <li><strong>Asset Name:</strong> On-chain name (what appears on blockchain explorers)</li>
                <li><strong>Collection ID:</strong> Numeric identifier for the collection</li>
                <li><strong>Variant:</strong> Letter suffix (A, B, C, etc.) - creates ID-variant (e.g., 20-A)</li>
                <li><strong>NFT Image:</strong> The actual NFT artwork image</li>
                <li><strong>Collection Image:</strong> Mystery box image (optional, for blind mints)</li>
                <li><strong>Price (USD):</strong> Automatically converted to ADA at time of mint</li>
                <li><strong>Quantity:</strong> Total number available to mint</li>
                <li><strong>Rarity %:</strong> Probability weight for random selection (all variants should total 100%)</li>
                <li><strong>Policy ID:</strong> Generate via button (requires expiration date first)</li>
                <li><strong>Expiration Date:</strong> When policy closes (required for time-locked policies)</li>
                <li><strong>Mints Per Wallet:</strong> Limit per wallet address (0 = unlimited)</li>
                <li><strong>Royalty Info:</strong> Percentage and address for CIP-27 royalty token</li>
                <li><strong>Metadata:</strong> Add custom CIP-25 attributes</li>
                <li><strong>Status:</strong> Active or Inactive</li>
            </ul>

            <h4>Active Mints (Manage Existing)</h4>
            <ul>
                <li><strong>Policy Groups:</strong> Mints grouped by policy ID (collapsible cards)</li>
                <li><strong>Stats Display:</strong> Variants, quantity minted, unique minters</li>
                <li><strong>Edit Variants:</strong> Click Edit to modify existing mints</li>
                <li><strong>View Policy JSON:</strong> Button to see complete policy JSON</li>
                <li><strong>Mint Tracking Actions:</strong> Export CSV, Import CSV, View History per policy</li>
                <li><strong>Unique Minters:</strong> Shows count of unique wallets that minted</li>
                <li><strong>Delete:</strong> Individual variant deletion or delete all mints</li>
            </ul>

            <h4>Policy Wallet</h4>
            <ul>
                <li><strong>Generate New:</strong> Creates secure policy wallet with 24-word seed</li>
                <li><strong>ONE-TIME DISPLAY:</strong> Seed phrase shown only once - save it immediately!</li>
                <li><strong>Current Wallet Info:</strong> Shows payment and stake addresses</li>
                <li><strong>Copy Buttons:</strong> Easy copy for addresses</li>
                <li><strong>Delete Warning:</strong> Cannot mint old NFTs if wallet is deleted</li>
            </ul>
        </div>


        <!-- Database Tables -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
            <h3>🗄️ Database Tables</h3>
            <ul>
                <li><code>wp_cardanonftactivemints</code> - All mint configurations (variants, metadata, pricing)</li>
                <li><code>wp_cardanonftmintcounts</code> - Legacy mint counting (deprecated, use mint_wallets)</li>
                <li><code>wp_cardano_policy_wallets</code> - Encrypted policy wallet credentials</li>
                <li><code>wp_cardano_mint_wallets</code> - Per-wallet mint tracking with limits and history</li>
            </ul>
        </div>

        <!-- Step-by-Step Workflow -->
        <div style="background: #d1ecf1; padding: 20px; border-radius: 5px; border-left: 4px solid #0c5460; margin-top: 20px;">
            <h3>📋 Complete Workflow Example</h3>
            <h4>Creating a 3-Variant Mystery Box Collection:</h4>
            <ol style="line-height: 1.8;">
                <li><strong>Plugin Setup:</strong> Add Anvil API keys, set network to preprod, add merchant address</li>
                <li><strong>Create Policy Wallet:</strong> Go to Policy Wallet page → Generate → SAVE THE 24-WORD SEED!</li>
                <li><strong>Create Variant A (Parent):</strong>
                    <ul>
                        <li>Collection ID: 25</li>
                        <li>Variant: A</li>
                        <li>NFT Image: rare-dragon.png</li>
                        <li>Collection Image: mystery-box.png (the blind reveal image)</li>
                        <li>Price: $50</li>
                        <li>Quantity: 10</li>
                        <li>Rarity: 10% (rare)</li>
                        <li>Expiration: 2025-12-31</li>
                        <li>Mints Per Wallet: 3</li>
                        <li>Royalty: 5% to your wallet</li>
                    </ul>
                </li>
                <li><strong>Generate Policy ID:</strong> Click button → Save Policy JSON → CIP-27 royalty token auto-minted</li>
                <li><strong>Create Variant B:</strong> Collection ID 25, Variant B, rarity 40%, same policy ID</li>
                <li><strong>Create Variant C:</strong> Collection ID 25, Variant C, rarity 50%, same policy ID</li>
                <li><strong>Verify Rarities:</strong> 10% + 40% + 50% = 100% ✓</li>
                <li><strong>Add Shortcode:</strong> <code>[cardano-mint mint-id="25"]</code> to your page</li>
                <li><strong>Test Mint:</strong> User sees mystery-box.png → Mints → Weighted random selection → Reveals actual NFT</li>
                <li><strong>Track Mints:</strong> Go to Active Mints → View mint history → Export CSV</li>
                <li><strong>Whitelist VIPs:</strong> Export CSV → Add wallet addresses with higher limits → Import</li>
            </ol>
        </div>

        <!-- Pro Tips -->
        <div style="background: #e7f3ff; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1; margin-top: 20px;">
            <h3>💡 Pro Tips & Best Practices</h3>
            <ul>
                <li><strong>Always Create A First:</strong> Variant A is the parent - create it before other variants</li>
                <li><strong>Mystery Box Image:</strong> Only editable on variant A in Active Mints (not Mint Manager)</li>
                <li><strong>Rarity Must Total 100%:</strong> For weighted random to work, all variant rarities should equal 100%</li>
                <li><strong>Image Names:</strong> Keep under 63 characters for Pinata/IPFS compatibility</li>
                <li><strong>Policy Wallet Backup:</strong> Save the seed phrase offline immediately</li>
                <li><strong>Test on Preprod:</strong> Always test complete flow before mainnet launch</li>
                <li><strong>CSV Whitelist Workflow:</strong> Export → Modify in Excel → Re-import (full replacement)</li>
                <li><strong>Duplicate Policy Prevention:</strong> Plugin checks if policy ID exists before creation</li>
                <li><strong>Price Conversion:</strong> USD prices automatically converted to ADA at mint time</li>
                <li><strong>Wallet Support:</strong> Any CIP-30 wallet: Eternl, Lace, Vespr, Typhon, Yoroi, Gero and more</li>
                <li><strong>Transaction Explorer:</strong> Links to Cardanoscan automatically generated based on network</li>
            </ul>
        </div>

        <!-- Important Warnings -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px;">
            <h3>⚠️ Critical Warnings</h3>
            <ol style="line-height: 1.8;">
                <li><strong>Policy Wallet Seed:</strong> Shown ONLY ONCE during generation - if you lose it, you cannot recover it</li>
                <li><strong>Delete Policy Wallet:</strong> If deleted, you CANNOT mint NFTs from old policies - only delete if you're done with all collections</li>
                <li><strong>Test First:</strong> Use Preprod network for complete testing before mainnet</li>
                <li><strong>Duplicate Policy IDs:</strong> Same expiration + same policy wallet = same policy ID - change expiration to get unique policy</li>
                <li><strong>Metadata Length:</strong> Keep image names short (63 char max) or on-chain metadata will fail</li>
                <li><strong>Royalty Token:</strong> Minted automatically with FIRST NFT only - one per policy</li>
                <li><strong>CSV Import:</strong> Replaces ALL existing records for that policy - export first to preserve data</li>
                <li><strong>No Refunds:</strong> Blockchain transactions are irreversible</li>
            </ol>
        </div>

        <!-- Security -->
        <div style="background: #f8d7da; padding: 20px; border-radius: 5px; border-left: 4px solid #dc3545; margin-top: 20px;">
            <h3>🔒 Security & Payment Information</h3>
            <ul>
                <li><strong>Real Payments:</strong> This plugin processes real ADA payments on mainnet</li>
                <li><strong>Dual-Signature:</strong> Both customer and policy wallet must sign transactions</li>
                <li><strong>Wallet Connection:</strong> Users need CIP-30 compatible Cardano wallet browser extension</li>
                <li><strong>Encryption:</strong> Policy wallet encrypted using WordPress AUTH_KEY and SECURE_AUTH_SALT</li>
                <li><strong>Merchant Payments:</strong> Customers pay to YOUR merchant address (set in Plugin Setup)</li>
                <li><strong>Policy Wallet Funds:</strong> Policy wallet should NOT hold ADA - it's only for signing authority</li>
                <li><strong>Blockchain Verification:</strong> All transactions verified on Cardano blockchain</li>
                <li><strong>Transaction Signing:</strong> Users must approve transaction in their wallet</li>
                <li><strong>No Password Storage:</strong> Plugin uses WordPress nonces and CIP-30 wallet authentication</li>
                <li><strong>HTTPS Required:</strong> Always use HTTPS for production sites handling payments</li>
            </ul>
        </div>

        <!-- Technical Details -->
        <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 20px;">
            <h3>⚙️ Technical Details</h3>
            <ul>
                <li><strong>APIs Used:</strong> ADA Anvil (transaction building/submission), Pinata (IPFS), Blockfrost (optional)</li>
                <li><strong>Wallet Standard:</strong> CIP-30 (Cardano dApp connector)</li>
                <li><strong>Metadata Standard:</strong> CIP-25 (NFT metadata)</li>
                <li><strong>Royalty Standard:</strong> CIP-27 (NFT royalty tokens)</li>
                <li><strong>Policy Wallet:</strong> pure-PHP CIP-1852 derivation and Ed25519 signing (no binaries, no Composer)</li>
                <li><strong>Encryption:</strong> AES-256-CBC with PBKDF2 key derivation</li>
                <li><strong>Signature Encoding:</strong> CBOR hex for transaction witnesses</li>
                <li><strong>Network Support:</strong> Mainnet and Preprod (testnet)</li>
                <li><strong>Requirements:</strong> WordPress 5.0+, PHP 7.4+</li>
            </ul>
        </div>

        <!-- Troubleshooting -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px;">
            <h3>🔧 Troubleshooting</h3>
            <ul>
                <li><strong>"Policy ID already exists":</strong> Change expiration date to generate unique policy</li>
                <li><strong>"Reached maximum mints":</strong> Wallet hit per-wallet limit - check Active Mints tracking</li>
                <li><strong>"Metadata too long":</strong> Shorten image file name to under 63 characters</li>
                <li><strong>"Transaction failed":</strong> Check wallet has enough ADA for payment + tx fees (~2 ADA minimum)</li>
                <li><strong>"Policy wallet not found":</strong> Generate policy wallet first in Policy Wallet page</li>
                <li><strong>"Nonce verification failed":</strong> Clear browser cache and reload page</li>
                <li><strong>CSV import fails:</strong> Ensure CSV has correct headers (Payment Address, Stake Address, etc.)</li>
                <li><strong>Shortcode not working:</strong> Verify mint ID exists and status is "Active"</li>
            </ul>
        </div>

        <!-- Support -->
        <div style="background: #d1ecf1; padding: 20px; border-radius: 5px; border-left: 4px solid #0c5460; margin-top: 20px;">
            <h3>📚 Additional Resources</h3>
            <ul>
                <li><strong>POLICY_WALLET_ENCRYPTION.md:</strong> Detailed encryption documentation</li>
                <li><strong>README.md:</strong> Plugin overview and installation</li>
                <li><strong>mvc-diagram.md:</strong> MVC architecture diagram</li>
                <li><strong>Browser Console:</strong> Press F12 to view detailed transaction logs during minting</li>
                <li><strong>WordPress Debug Log:</strong> Enable WP_DEBUG to see server-side logs</li>
            </ul>
        </div>
    </div>
    <?php
}
