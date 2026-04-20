<?php
// Variables expected: $mint (object|null), $atts['nftname'], $atts['price'], $atts['policyid'], $atts['metadata_url']
if (!isset($mint)) $mint = null;
if (!isset($atts) || !is_array($atts)) $atts = [];
if (!isset($atts['nftname'])) $atts['nftname'] = 'Cardano NFT';
if (!isset($atts['price'])) $atts['price'] = 0.00;
if (!isset($atts['policyid'])) $atts['policyid'] = '';
if (!isset($atts['metadata_url'])) $atts['metadata_url'] = '';

// Get mint details
$nft_name = $mint ? $mint['title'] : $atts['nftname'];
$nft_price = $mint ? floatval($mint['price'] ?? $atts['price']) : floatval($atts['price']);
$policy_id = $mint ? $mint['policyid'] : $atts['policyid'];
$metadata_url = $mint ? $mint['metadata_url'] ?? $atts['metadata_url'] : $atts['metadata_url'];
$mints_allowed = $mint ? intval($mint['mintsallowedperwallet'] ?? 0) : 0;

// Get image URL - prefer collection_image_id (mystery box) over actual NFT image
$nft_image_url = '';
$nft_image_mime = '';
if ($mint && !empty($mint['collection_image_id'])) {
    // Show collection/mystery box image if available
    $collection_image_url = wp_get_attachment_url($mint['collection_image_id']);
    if ($collection_image_url) {
        $nft_image_url = $collection_image_url;
        $nft_image_mime = get_post_mime_type($mint['collection_image_id']) ?: '';
    }
}
// Fall back to actual NFT image if no collection image
// Priority: Manual IPFS > Pinata IPFS > WordPress
if (empty($nft_image_url) && $mint) {
    if (!empty($mint['ipfs_cid_manual'])) {
        // Manual IPFS hash
        $nft_image_url = 'https://ipfs.io/ipfs/' . $mint['ipfs_cid_manual'];
    } elseif (!empty($mint['ipfs_cid'])) {
        // Pinata IPFS hash
        $nft_image_url = 'https://ipfs.io/ipfs/' . $mint['ipfs_cid'];
    } elseif (!empty($mint['image_id'])) {
        // WordPress media library
        $image_url = wp_get_attachment_url($mint['image_id']);
        if ($image_url) {
            $nft_image_url = $image_url;
            $nft_image_mime = get_post_mime_type($mint['image_id']) ?: '';
        }
    }
}
// Final fallback to metadata_url
if (empty($nft_image_url)) {
    $nft_image_url = $metadata_url;
}

$nft_is_video = $nft_image_mime && strpos($nft_image_mime, 'video/') === 0;
?>

<div id="cardano-nft-mint-widget" class="cardano-nft-mint">
    <!-- Simple Price Display -->
    <div class="nft-price">
        <div class="usd-price">$<?php echo esc_html(number_format($nft_price, 2)); ?> USD</div>
        <div class="ada-price"><?php 
            $ada_price = $nft_price / CardanoMintPay\Models\MintModel::getNFTPrice();
            echo esc_html(number_format($ada_price, 6)); 
        ?> ADA</div>
    </div>

    <!-- Mint Now Button -->
    <button type="button" id="cardano-mint-now-btn" class="cardano-mint-now-button"
            data-mint-id="<?php echo esc_attr($mint ? $mint['id'] : ''); ?>"
            data-nft-name="<?php echo esc_attr($nft_name); ?>"
            data-nft-price="<?php echo esc_attr($nft_price); ?>"
            data-policy-id="<?php echo esc_attr($policy_id); ?>"
            data-metadata-url="<?php echo esc_attr($metadata_url); ?>"
            data-mints-allowed="<?php echo esc_attr($mints_allowed); ?>"
            data-merchant-address="<?php echo esc_attr($atts['merchantaddress'] ?? ''); ?>">
        MINT NOW
    </button>
</div>

<!-- NFT Mint Modal -->
<div id="cardano-nft-mint-modal" class="cardano-modal" style="display: none;" onclick="if(event.target === this) { this.style.display = 'none'; document.body.style.overflow = 'auto'; }">
    <div class="cardano-modal-content">
        <div class="cardano-modal-header">
            <h2>Mint Your NFT</h2>
            <span class="cardano-modal-close" onclick="document.getElementById('cardano-nft-mint-modal').style.display = 'none'; document.body.style.overflow = 'auto';">&times;</span>
        </div>
        
        <div class="cardano-modal-body">
            <!-- Progress Steps -->
            <div class="mint-steps">
                <div class="step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-title">Confirm Wallet</span>
                </div>
                <div class="step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-title">Confirm Mint</span>
                </div>
                <div class="step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-title">Complete</span>
                </div>
            </div>

            <form id="cardano-nft-mint-form" method="post">
                <!-- Step 1: Wallet Confirmation -->
                <div class="mint-step" id="mint-step-1">
                    <h3>Get Ready to Mint</h3>
                    <div class="wallet-connection">
                        <p>Connect your wallet to proceed with minting your NFT.</p>
                        <div class="wallet-name-display" id="wallet-name-display" style="display: none;">
                            <p><strong>Wallet Provider:</strong> <span id="connected-wallet-name"></span></p>
                        </div>
                        <div class="wallet-address-display" id="wallet-address-display" style="display: none;">
                            <p><strong>Wallet Address:</strong> <span id="connected-wallet-address"></span></p>
                        </div>
                        <button type="button" id="connect-wallet-btn" class="btn-connect-wallet">Connect Wallet</button>
                    </div>
                    <button type="button" class="btn-next" id="proceed-to-confirm" style="display: none;">Continue to Mint</button>
                </div>

                <!-- Step 2: Mint Confirmation -->
                <div class="mint-step" id="mint-step-2" style="display: none;">
                    <h3>Confirm Your Mint</h3>
                    <div class="mint-review">
                        <!-- Centered NFT Image -->
                        <div class="nft-image-display">
                            <?php if ($nft_is_video && !empty($nft_image_url)): ?>
                                <video id="review-nft-image" src="<?php echo esc_url($nft_image_url); ?>" controls autoplay muted loop playsinline></video>
                            <?php else: ?>
                                <img id="review-nft-image" src="<?php echo esc_url($nft_image_url ?: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTIwIiBoZWlnaHQ9IjEyMCIgdmlld0JveD0iMCAwIDEyMCAxMjAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIxMjAiIGhlaWdodD0iMTIwIiBmaWxsPSIjRjhGOUZBIi8+CjxyZWN0IHg9IjEwIiB5PSIxMCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIHN0cm9rZT0iI0RFRTJFNiIgc3Ryb2tlLXdpZHRoPSIyIiBmaWxsPSJub25lIi8+Cjx0ZXh0IHg9IjYwIiB5PSI2NSIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiBmaWxsPSIjNkM3NTdEIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5ORlQ8L3RleHQ+Cjx0ZXh0IHg9IjYwIiB5PSI4NSIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjEyIiBmaWxsPSIjNkM3NTdEIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5JbWFnZTwvdGV4dD4KPC9zdmc+'); ?>" alt="<?php echo esc_attr($nft_name); ?>">
                            <?php endif; ?>
                            <h4 class="nft-name" id="review-nft-name"><?php echo esc_html($nft_name); ?></h4>
                        </div>

                        <!-- Receipt Card -->
                        <div class="mint-receipt-card">
                            <div class="receipt-header">
                                <h4>Order Summary</h4>
                            </div>

                            <div class="receipt-body">
                                <!-- Policy ID -->
                                <div class="receipt-info-row">
                                    <span class="info-label">Policy ID</span>
                                    <span class="info-value policy-id-text" id="review-policy-id"><?php echo esc_html($policy_id); ?></span>
                                </div>

                                <div class="receipt-divider"></div>

                                <!-- Line Items -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label">NFT Mint Price</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-nft-price-usd">$<?php echo esc_html(number_format($nft_price, 2)); ?> USD</div>
                                        <div class="price-ada" id="review-nft-price-ada"><?php
                                            $ada_price = CardanoMintPay\Models\MintModel::getNFTPrice();
                                            $ada_amount = $nft_price / $ada_price;
                                            echo esc_html(number_format($ada_amount, 4));
                                        ?> ADA</div>
                                    </div>
                                </div>

                                <!-- Anvil Minting Service -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label">Anvil Minting Service</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-anvil-usd">$<?php
                                            $anvil_ada = 1.15;
                                            $anvil_usd = $anvil_ada * $ada_price;
                                            echo esc_html(number_format($anvil_usd, 2));
                                        ?> USD</div>
                                        <div class="price-ada" id="review-anvil-ada"><?php echo esc_html(number_format($anvil_ada, 2)); ?> ADA</div>
                                    </div>
                                </div>

                                <!-- Cardano Network Fee -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label">Cardano Network Fee</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-network-usd">~$<?php
                                            $network_ada = 0.22;
                                            $network_usd = $network_ada * $ada_price;
                                            echo esc_html(number_format($network_usd, 2));
                                        ?> USD</div>
                                        <div class="price-ada" id="review-network-ada">~<?php echo esc_html(number_format($network_ada, 2)); ?> ADA</div>
                                    </div>
                                </div>

                                <div class="receipt-divider-bold"></div>

                                <!-- Total -->
                                <div class="receipt-total">
                                    <span class="total-label">Total Cost</span>
                                    <div class="total-value">
                                        <div class="total-usd" id="review-total-usd">$<?php
                                            // Total = NFT Price + Anvil + Network
                                            $total_usd = $nft_price + $anvil_usd + $network_usd;
                                            $total_ada = $ada_amount + $anvil_ada + $network_ada;
                                            echo esc_html(number_format($total_usd, 2));
                                        ?> USD</div>
                                        <div class="total-ada" id="review-total-ada"><?php
                                            echo esc_html(number_format($total_ada, 2));
                                        ?> ADA</div>
                                    </div>
                                </div>

                                <!-- UTxO Note -->
                                <p class="receipt-info-text">
                                    <strong>Note:</strong> You will see a 1.22 ADA fee that is a UTxO requirement. This looks like a fee but comes right back to you with the NFT.
                                </p>

                                <?php if ($mints_allowed > 0): ?>
                                    <div class="receipt-note">
                                        <span class="note-icon">ℹ️</span>
                                        <span class="note-text">Limit: <strong id="review-mints-allowed"><?php echo esc_html($mints_allowed); ?></strong> mints per wallet</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Wallet Info -->
                        <div class="wallet-info-card">
                            <div class="wallet-content">
                                <div class="wallet-label">Connected Wallet</div>
                                <div class="wallet-address" id="confirm-wallet-address"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="step-buttons">
                        <button type="button" class="btn-prev" onclick="prevMintStep(1)">Back to Wallet</button>
                        <button type="button" class="btn-mint" id="confirm-mint-btn">CONFIRM MINT</button>
                    </div>
                </div>

                <!-- Step 3: Mint Complete -->
                <div class="mint-step" id="mint-step-3" style="display: none;">
                    <h3>Mint Complete!</h3>
                    <div class="mint-success">
                        <div class="success-icon">✓</div>
                        <p>Your NFT has been minted, check your wallet to see it!</p>
                        <div class="transaction-details">
                            <p><strong>Transaction Hash:</strong> <span id="mint-tx-hash"></span></p>
                            <p><strong>Policy ID:</strong> <span id="final-policy-id"><?php echo esc_html($policy_id); ?></span></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close-modal" onclick="document.getElementById('cardano-nft-mint-modal').style.display = 'none'; document.body.style.overflow = 'auto';">Close</button>
                </div>

                <!-- Hidden fields -->
                <input type="hidden" id="wallet-address" name="wallet">
                <input type="hidden" id="policy-id" name="policyid" value="<?php echo esc_attr($policy_id); ?>">
                <input type="hidden" id="metadata" name="metadata" value="<?php echo esc_attr($metadata_url); ?>">
                <input type="hidden" id="nft-price" name="price" value="<?php echo esc_attr($nft_price); ?>">
                <?php wp_nonce_field('cardanocheckoutnonce', 'nonce'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Assets are enqueued by the plugin -->
<script>
/*
 * Portal the mint modal to <body> so it escapes any ancestor stacking context
 * (transform/filter/will-change on a parent section traps position:fixed z-index).
 */
(function () {
    function portal() {
        var modal = document.getElementById('cardano-nft-mint-modal');
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', portal);
    } else {
        portal();
    }
})();
</script>
