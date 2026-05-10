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

                    <?php
                    $altpay_enabled = get_option('cardano_mint_altpay_enabled', '0') === '1';
                    $altpay_chains  = [];
                    if ($altpay_enabled && class_exists('CardanoMintPay\\Models\\ChainWalletModel')) {
                        foreach (['btc', 'eth', 'sol'] as $c) {
                            $rows = \CardanoMintPay\Models\ChainWalletModel::list_for_chain($c, false);
                            if (!empty($rows)) $altpay_chains[] = $c;
                        }
                    }
                    if ($altpay_enabled && !empty($altpay_chains)):
                        $service_fee = (int) get_option('cardano_mint_service_fee_ada', 5);
                    ?>
                    <div class="altpay-picker" id="altpay-picker" data-mint-id="<?php echo esc_attr($mint ? (int)($mint['collection_id'] ?? $mint['id']) : 0); ?>" hidden>
                        <h4>Pay with</h4>
                        <div class="altpay-chips">
                            <button type="button" class="altpay-chip is-active" data-altpay-chain="ada">ADA</button>
                            <?php foreach ($altpay_chains as $c): ?>
                                <button type="button" class="altpay-chip" data-altpay-chain="<?php echo esc_attr($c); ?>"><?php echo esc_html(strtoupper($c)); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="altpay-hint">
                            Paying with another chain still uses your connected Cardano wallet to sign and pay a small <?php echo (int) $service_fee; ?> ADA service fee + ~0.17 ADA network fee + 1 ADA receipt. Make sure your Cardano wallet has at least <?php echo (int) ($service_fee + 2); ?> ADA available.
                        </p>

                        <div class="altpay-pay-panel" id="altpay-pay-panel" hidden>

                            <!-- Pre-init: chain selected but no payment session yet -->
                            <div class="altpay-preinit" data-altpay-state="preinit">
                                <p class="altpay-pay-instructions">
                                    Pay this mint with <strong class="altpay-chain-label">BTC</strong>. We'll generate a fresh deposit address tied to your Cardano wallet and watch the chain for your payment. The session stays resumable for 24 hours, even if you close this modal.
                                </p>
                                <div class="altpay-pay-actions">
                                    <button type="button" class="altpay-btn altpay-btn--primary" data-altpay-action="start">Pay with <span class="altpay-chain-label">BTC</span></button>
                                    <button type="button" class="altpay-btn altpay-btn--ghost" data-altpay-action="back">Use ADA instead</button>
                                </div>
                            </div>

                            <!-- Active: session live, polling /altpay/status -->
                            <div class="altpay-active" data-altpay-state="active" hidden>
                                <p class="altpay-pay-instructions">
                                    Send <strong><span class="altpay-amount-display">—</span></strong> to the address below. We watch the chain and unlock the next step automatically.
                                </p>
                                <div class="altpay-address-row">
                                    <code class="altpay-address">—</code>
                                    <button type="button" class="altpay-btn" data-altpay-action="copy-address">Copy</button>
                                </div>
                                <p class="altpay-status-line">
                                    Status: <strong class="altpay-status-text">waiting…</strong>
                                    <span class="altpay-observed" hidden></span>
                                </p>
                                <p class="altpay-cancel-row">
                                    <button type="button" class="altpay-cancel-link" data-altpay-action="cancel">Cancel payment session</button>
                                </p>
                            </div>

                        </div>
                    </div>
                    <input type="hidden" id="altpay-invoice-id" value="">
                    <input type="hidden" id="altpay-chain"      value="ada">
                    <?php endif; ?>

                    <?php
                    $onramp_enabled = (
                        get_option('cardano_mint_onramp_enabled', '0') === '1'
                        && class_exists('CardanoMintPay\\Onramp\\GuardarianClient')
                        && \CardanoMintPay\Onramp\GuardarianClient::isConfigured()
                    );
                    if ($onramp_enabled):
                        $onramp_amount = isset($nft_price) && $nft_price > 0 ? (int) ceil($nft_price + 5) : 100;
                    ?>
                    <div class="kg-onramp-cta-wrap" id="kg-onramp-cta-wrap" hidden style="margin-top: 14px;">
                        <p style="margin: 0 0 8px 0; font-size: 13px; color: #9b99a6;">Need ADA?</p>
                        <button type="button"
                                id="kg-onramp-cta"
                                class="kg-onramp-cta"
                                data-onramp-amount="<?php echo esc_attr($onramp_amount); ?>">
                            <span class="kg-onramp-cta__icon" aria-hidden="true">
                                <!-- credit card glyph -->
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
                                </svg>
                            </span>
                            Buy ADA with card
                        </button>
                        <p style="margin: 6px 0 0 0; font-size: 11px; color: #88838f; line-height: 1.4;">
                            Funds land in your connected wallet. You'll still mint with ADA — minimum ~<?php echo (int) \CardanoMintPay\Onramp\GuardarianService::feeBufferAda(); ?> ADA needed in the wallet to cover the mint network fee.
                        </p>
                    </div>
                    <script>
                    /* Reveal the on-ramp CTA only after a Cardano wallet is
                       connected (we need the address as the payout target).
                       Watches for the existing wallet-address-display flip. */
                    (function () {
                        var ctaWrap = document.getElementById('kg-onramp-cta-wrap');
                        var addrEl  = document.getElementById('connected-wallet-address');
                        var addrDisplay = document.getElementById('wallet-address-display');
                        if (!ctaWrap || !addrEl) return;

                        function tryReveal() {
                            var addr = (addrEl.textContent || '').trim();
                            if (!addr || (addr.indexOf('addr1') !== 0 && addr.indexOf('addr_test1') !== 0)) {
                                ctaWrap.setAttribute('hidden', '');
                                return;
                            }
                            ctaWrap.removeAttribute('hidden');
                        }
                        // Watch the display container for visibility flips and address mutations.
                        var observer = new MutationObserver(tryReveal);
                        if (addrDisplay) observer.observe(addrDisplay, { attributes: true, attributeFilter: ['style'] });
                        observer.observe(addrEl, { childList: true, characterData: true, subtree: true });
                        // Initial check in case wallet was already connected on page load.
                        setTimeout(tryReveal, 50);

                        var btn = document.getElementById('kg-onramp-cta');
                        if (btn) btn.addEventListener('click', function () {
                            var addr = (addrEl.textContent || '').trim();
                            if (!addr || typeof window.KGOnramp !== 'object') return;
                            var picker = document.getElementById('altpay-picker');
                            var mintId = picker ? parseInt(picker.getAttribute('data-mint-id') || '0', 10) || null : null;
                            var defaultAmount = parseInt(btn.getAttribute('data-onramp-amount') || '100', 10) || 100;
                            window.KGOnramp.open({
                                amountUsd:                defaultAmount,
                                customerCardanoAddress:   addr,
                                mintId:                   mintId,
                                onComplete: function () {
                                    /* Wallet is funded — surface the existing
                                       Continue button if it's still hidden. */
                                    var cont = document.getElementById('proceed-to-confirm');
                                    if (cont) cont.style.display = '';
                                }
                            });
                        });
                    })();
                    </script>
                    <?php endif; ?>

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

                                <!-- Quantity (1-5 per tx; users can re-mint for more) -->
                                <div class="receipt-quantity-row" id="receipt-quantity-row">
                                    <span class="quantity-label">Quantity</span>
                                    <div class="quantity-stepper" role="group" aria-label="Mint quantity">
                                        <button type="button" class="qty-btn" id="qty-dec" aria-label="Decrease quantity">−</button>
                                        <input type="text" id="qty-input" class="qty-input" value="1" readonly aria-live="polite">
                                        <button type="button" class="qty-btn" id="qty-inc" aria-label="Increase quantity">+</button>
                                    </div>
                                </div>
                                <p class="quantity-hint" id="quantity-hint">Mint up to 5 in a single transaction.</p>

                                <div class="receipt-divider"></div>

                                <?php
                                $ada_price   = CardanoMintPay\Models\MintModel::getNFTPrice();
                                $ada_amount  = $nft_price / max($ada_price, 0.0001);
                                $anvil_ada   = 1.15;
                                $anvil_usd   = $anvil_ada * $ada_price;
                                $network_ada = 0.22;
                                $network_usd = $network_ada * $ada_price;
                                $total_usd   = $nft_price + $anvil_usd + $network_usd;
                                $total_ada   = $ada_amount + $anvil_ada + $network_ada;
                                ?>
                                <!-- Line Items (per-unit values stored in data-unit-* for the qty stepper to scale) -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label" id="review-nft-price-label">NFT Mint Price</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-nft-price-usd"
                                             data-unit-usd="<?php echo esc_attr($nft_price); ?>"
                                             data-unit-ada="<?php echo esc_attr($ada_amount); ?>">
                                            $<?php echo esc_html(number_format($nft_price, 2)); ?> USD
                                        </div>
                                        <div class="price-ada" id="review-nft-price-ada"><?php echo esc_html(number_format($ada_amount, 4)); ?> ADA</div>
                                    </div>
                                </div>

                                <!-- Anvil Minting Service -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label">Anvil Minting Service</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-anvil-usd"
                                             data-unit-usd="<?php echo esc_attr($anvil_usd); ?>"
                                             data-unit-ada="<?php echo esc_attr($anvil_ada); ?>">
                                            $<?php echo esc_html(number_format($anvil_usd, 2)); ?> USD
                                        </div>
                                        <div class="price-ada" id="review-anvil-ada"><?php echo esc_html(number_format($anvil_ada, 2)); ?> ADA</div>
                                    </div>
                                </div>

                                <!-- Cardano Network Fee (one tx, scales slightly with assets but Anvil estimates it) -->
                                <div class="receipt-line-item">
                                    <span class="line-item-label">Cardano Network Fee</span>
                                    <div class="line-item-value">
                                        <div class="price-usd" id="review-network-usd"
                                             data-unit-usd="<?php echo esc_attr($network_usd); ?>"
                                             data-unit-ada="<?php echo esc_attr($network_ada); ?>">
                                            ~$<?php echo esc_html(number_format($network_usd, 2)); ?> USD
                                        </div>
                                        <div class="price-ada" id="review-network-ada">~<?php echo esc_html(number_format($network_ada, 2)); ?> ADA</div>
                                    </div>
                                </div>

                                <div class="receipt-divider-bold"></div>

                                <!-- Total -->
                                <div class="receipt-total">
                                    <span class="total-label">Total Cost</span>
                                    <div class="total-value">
                                        <div class="total-usd" id="review-total-usd"
                                             data-unit-usd="<?php echo esc_attr($total_usd); ?>"
                                             data-unit-ada="<?php echo esc_attr($total_ada); ?>">
                                            $<?php echo esc_html(number_format($total_usd, 2)); ?> USD
                                        </div>
                                        <div class="total-ada" id="review-total-ada"><?php echo esc_html(number_format($total_ada, 2)); ?> ADA</div>
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
