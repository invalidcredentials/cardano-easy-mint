<?php
if (!defined('ABSPATH')) exit;
// Expect: $metaurl, $expiration, $unlimited, $royalty, $royaltyaddr, $message, $editMode, $editData
if (!isset($metaurl)) $metaurl = '';
if (!isset($expiration)) $expiration = '';
if (!isset($unlimited)) $unlimited = '';
if (!isset($royalty)) $royalty = '';
if (!isset($royaltyaddr)) $royaltyaddr = '';
if (!isset($message)) $message = '';
if (!isset($editMode)) $editMode = false;
if (!isset($editData)) $editData = null;

// Get existing policies for dropdown
$existingPolicies = CardanoMintPay\Models\MintModel::getAllPolicies();
$importedPolicies = CardanoMintPay\Models\MintModel::getAllActiveMintPolicies();

// Determine if this is variant A or a later variant
$isVariantA = false;
$isLaterVariant = false;
if ($editMode && $editData) {
    $isVariantA = ($editData['variant'] === 'A');
    $isLaterVariant = ($editData['variant'] && $editData['variant'] !== 'A');
}
?>
<div class="wrap">
    <h1><?php echo $editMode ? 'Edit Asset' : 'Mint Manager'; ?></h1>
    <?php if (!empty($message)) : ?>
        <div class="updated notice">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('mintmanagersavenonce'); ?>
        <?php if ($editMode && $editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo esc_attr($editData['id']); ?>" />
            <div style="background: #d1ecf1; padding: 15px; border-left: 4px solid #0c5460; margin-bottom: 20px; border-radius: 4px;">
                <strong>✏️ Editing Asset:</strong> <?php echo esc_html($editData['asset_name'] ?: $editData['title']); ?>
                (Variant: <?php echo esc_html($editData['collection_id'] . ($editData['variant'] ? '-' . $editData['variant'] : '')); ?>)
                <?php if ($isLaterVariant): ?>
                    <br><span style="color: #856404; font-size: 12px; margin-top: 5px; display: inline-block;">⚠️ Policy-level fields are locked for variants other than the first variant (A)</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Policy Mode Selection (hidden in edit mode) -->
        <?php if (!$editMode): ?>
        <div style="background: #f0f0f1; padding: 20px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Policy Selection</h2>
            <p style="margin-bottom: 15px;">Choose whether to create a new NFT collection policy or add an asset to an existing one.</p>

            <label style="display: block; margin-bottom: 10px;">
                <input type="radio" name="policy_mode" value="new" id="policy_mode_new" checked onchange="togglePolicyMode()" />
                <strong>Create New Policy</strong> - Start a new NFT collection with a new policy ID
            </label>

            <label style="display: block; margin-bottom: 15px;">
                <input type="radio" name="policy_mode" value="existing" id="policy_mode_existing" onchange="togglePolicyMode()" />
                <strong>Add Asset to Existing Policy</strong> - Add a new variant to an existing collection
            </label>

            <div id="existing-policy-selector" style="display: none; margin-top: 15px; padding: 15px; background: white; border: 1px solid #c3c4c7; border-radius: 4px;">
                <label for="existing_collection_id" style="display: block; font-weight: bold; margin-bottom: 8px;">Select Existing Policy:</label>
                <select name="existing_collection_id" id="existing_collection_id" style="width: 100%; max-width: 600px; padding: 8px;" onchange="loadPolicyData()">
                    <option value="">-- Select a Policy --</option>
                    <?php foreach ($existingPolicies as $policy) : ?>
                        <option value="<?php echo esc_attr($policy['collection_id']); ?>"
                                data-policyid="<?php echo esc_attr($policy['policyid']); ?>"
                                data-expiration="<?php echo esc_attr($policy['expirationdate']); ?>"
                                data-policy-json="<?php echo esc_attr($policy['policy_json']); ?>"
                                data-asset-count="<?php echo esc_attr($policy['asset_count']); ?>"
                                data-title="<?php echo esc_attr($policy['title']); ?>"
                                data-price="<?php echo esc_attr($policy['price']); ?>"
                                data-royalty="<?php echo esc_attr($policy['royalty']); ?>"
                                data-royaltyaddress="<?php echo esc_attr($policy['royaltyaddress']); ?>"
                                data-mintsallowedperwallet="<?php echo esc_attr($policy['mintsallowedperwallet']); ?>">
                            <?php
                            echo esc_html($policy['title']) . ' - ' .
                                 esc_html(substr($policy['policyid'], 0, 20)) . '... - ' .
                                 'Expires: ' . esc_html(date('Y-m-d', strtotime($policy['expirationdate']))) .
                                 ' (' . esc_html($policy['asset_count']) . ' asset' . ($policy['asset_count'] != 1 ? 's' : '') . ')';
                            ?>
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($importedPolicies as $imp) : ?>
                        <option value="imported_<?php echo esc_attr($imp['id']); ?>"
                                data-policyid="<?php echo esc_attr($imp['policy_id']); ?>"
                                data-expiration="<?php echo esc_attr($imp['expiration_date']); ?>"
                                data-policy-json="<?php echo esc_attr($imp['policy_schema']); ?>"
                                data-asset-count="0"
                                data-title="<?php echo esc_attr($imp['name']); ?>"
                                data-price=""
                                data-royalty=""
                                data-royaltyaddress=""
                                data-mintsallowedperwallet=""
                                data-imported="1">
                            <?php
                            echo '(imported) ' . esc_html($imp['name']) . ' - ' .
                                 esc_html(substr($imp['policy_id'], 0, 20)) . '...' .
                                 ($imp['expiration_date'] ? ' - Expires: ' . esc_html(date('Y-m-d', strtotime($imp['expiration_date']))) : '');
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="policy-info-display" style="margin-top: 15px; display: none; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <p style="margin: 5px 0;"><strong>Next Variant:</strong> <span id="next-variant"></span></p>
                    <p style="margin: 5px 0;"><strong>Policy ID:</strong> <span id="display-policy-id" style="font-family: monospace; font-size: 11px;"></span></p>
                    <p style="margin: 5px 0;"><strong>Expiration:</strong> <span id="display-expiration"></span></p>
                </div>
            </div>
        </div>

        <!-- Build path: fill the form, or bulk-import mint-ready JSON -->
        <div id="cem-build-path" style="background:#eef6ec; padding:16px 20px; border-left:4px solid #46812b; margin-bottom:20px;">
            <h2 style="margin-top:0;">How do you want to add assets?</h2>
            <label style="display:block; margin-bottom:8px;">
                <input type="radio" name="cem_path" value="build" checked onchange="cemTogglePath()" />
                <strong>Build NFT</strong> — fill out the form below (image, name, metadata) for a single asset.
            </label>
            <label style="display:block;">
                <input type="radio" name="cem_path" value="import" onchange="cemTogglePath()" />
                <strong>Import JSON (bulk)</strong> — paste/upload mint-ready metadata. Each asset becomes a quantity-1 mint, minted verbatim in random order. Set the policy fields below (title, expiration, Policy ID, price, royalty) first, then import.
            </label>
        </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th>NFT Image</th>
                <td>
                    <input type="hidden" name="cardanonftimageid" id="cardanonftimageid" value="<?php echo esc_attr($editMode && $editData ? $editData['image_id'] : ''); ?>" />
                    <input type="hidden" name="cardanonftimagemime" id="cardanonftimagemime" value="<?php echo esc_attr($editMode && $editData && !empty($editData['image_id']) ? (get_post_mime_type($editData['image_id']) ?: '') : ''); ?>" />
                    <input type="hidden" name="ipfs_cid" id="ipfs_cid" value="<?php echo esc_attr($editMode && $editData ? ($editData['ipfs_cid'] ?? '') : ''); ?>" />
                    <button type="button" id="upload-image-btn" class="button">Select Image</button>

                    <?php if (get_option('cardano_mint_pinata_enabled', 0)): ?>
                        <button type="button" id="pin-to-ipfs-btn" class="button" style="margin-left: 10px; background: #6c757d; color: white; border-color: #6c757d;" disabled>Pin to IPFS</button>
                        <span id="pin-status" style="margin-left: 10px;"></span>
                    <?php endif; ?>

                    <div id="image-preview" style="margin-top: 10px;">
                        <?php if ($editMode && $editData && !empty($editData['image_id'])): ?>
                            <?php
                            $image_url = wp_get_attachment_url($editData['image_id']);
                            $image_mime = get_post_mime_type($editData['image_id']);
                            $is_video = $image_mime && strpos($image_mime, 'video/') === 0;
                            ?>
                            <?php if ($image_url): ?>
                                <?php if ($is_video): ?>
                                    <video src="<?php echo esc_url($image_url); ?>" controls muted playsinline style="max-width: 150px; max-height: 150px; border-radius: 4px;"></video>
                                <?php else: ?>
                                    <img src="<?php echo esc_url($image_url); ?>" style="max-width: 150px; max-height: 150px; border-radius: 4px;" />
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- IPFS Status Display -->
                    <div id="ipfs-status-display" style="margin-top: 10px;">
                        <?php if ($editMode && $editData): ?>
                            <?php if (!empty($editData['ipfs_cid_manual'])): ?>
                                <p style="color: #7c3aed; font-weight: 600;">✓ Manual IPFS: <?php echo esc_html($editData['ipfs_cid_manual']); ?></p>
                            <?php elseif (get_option('cardano_mint_pinata_enabled', 0) && !empty($editData['ipfs_cid'])): ?>
                                <p style="color: #10b981; font-weight: 600;">✓ Pinned to IPFS: <?php echo esc_html($editData['ipfs_cid']); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Manual IPFS Hash Input -->
                    <div style="margin-top: 15px; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">
                            Or paste IPFS CIDv0 hash (if pinned elsewhere):
                        </label>
                        <input type="text"
                               name="ipfs_cid_manual"
                               id="ipfs_cid_manual"
                               placeholder="Paste manual v0 CID here"
                               value="<?php echo esc_attr($editMode && $editData ? ($editData['ipfs_cid_manual'] ?? '') : ''); ?>"
                               size="60"
                               maxlength="60"
                               style="padding: 8px; font-family: monospace; font-size: 13px; width: auto;" />
                        <label style="margin-left: 12px; font-size: 12px; color: #374151;">
                            Format:
                            <?php $mt = $editMode && $editData ? ($editData['media_type'] ?? '') : ''; ?>
                            <select name="media_type" id="media_type" style="padding: 6px; font-size: 13px;">
                                <option value="image/png"     <?php selected($mt, 'image/png'); ?>>PNG</option>
                                <option value="image/gif"     <?php selected($mt, 'image/gif'); ?>>GIF</option>
                                <option value="image/jpeg"    <?php selected($mt, 'image/jpeg'); ?>>JPEG</option>
                                <option value="image/webp"    <?php selected($mt, 'image/webp'); ?>>WebP</option>
                                <option value="image/svg+xml" <?php selected($mt, 'image/svg+xml'); ?>>SVG</option>
                                <option value="video/mp4"     <?php selected($mt, 'video/mp4'); ?>>MP4</option>
                                <option value="video/webm"    <?php selected($mt, 'video/webm'); ?>>WebM</option>
                                <option value="video/quicktime" <?php selected($mt, 'video/quicktime'); ?>>MOV</option>
                                <option value="audio/mpeg"    <?php selected($mt, 'audio/mpeg'); ?>>MP3</option>
                                <option value="audio/wav"     <?php selected($mt, 'audio/wav'); ?>>WAV</option>
                            </select>
                        </label>
                        <p id="ipfs-validation-message" style="margin-top: 8px; margin-bottom: 8px; font-size: 12px; display: none;"></p>
                        <div id="ipfs-manual-preview" style="margin-top: 10px; display: none;">
                            <img id="ipfs-manual-preview-img" src="" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #7c3aed;" />
                        </div>
                        <button type="button" id="clear-manual-ipfs-btn" class="button" style="background: #ef4444; color: white; border-color: #ef4444; margin-top: 8px;">Clear Manual Hash</button>
                        <p class="description" style="margin-top: 12px;">
                            <strong>Priority:</strong> Manual hash > Pinata pin > WordPress CDN<br>
                            <strong>Format:</strong> CIDv0 must start with "Qm" and be 46 characters long
                        </p>
                    </div>

                    <p class="description" style="margin-top: 10px;">The actual NFT image that will be minted</p>
                </td>
            </tr>

            <tr>
                <th>Preview Image <span style="color: #c9a84c; font-weight: 400; font-size: 12px;">(still / GIF for video assets)</span></th>
                <td>
                    <input type="hidden" name="cardanonftpreviewimageid" id="cardanonftpreviewimageid" value="<?php echo esc_attr($editMode && $editData ? ($editData['preview_image_id'] ?? '') : ''); ?>" />
                    <button type="button" id="upload-preview-image-btn" class="button">Select Preview Image</button>
                    <button type="button" id="clear-preview-image-btn" class="button" style="margin-left: 8px; background: #ef4444; color: white; border-color: #ef4444;">Clear</button>

                    <div id="preview-image-display" style="margin-top: 10px;">
                        <?php if ($editMode && $editData && !empty($editData['preview_image_id'])): ?>
                            <?php $preview_url = wp_get_attachment_url($editData['preview_image_id']); ?>
                            <?php if ($preview_url): ?>
                                <img src="<?php echo esc_url($preview_url); ?>" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #c9a84c;" />
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Manual IPFS Hash for preview -->
                    <div style="margin-top: 15px; padding: 15px; background: #fdf6e3; border: 1px solid #e5d4a3; border-radius: 4px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #78611f;">
                            Or paste IPFS CIDv0 hash for the preview (if pinned elsewhere):
                        </label>
                        <input type="text"
                               name="preview_ipfs_cid_manual"
                               id="preview_ipfs_cid_manual"
                               placeholder="Qm... (still image or GIF only)"
                               value="<?php echo esc_attr($editMode && $editData ? ($editData['preview_ipfs_cid_manual'] ?? '') : ''); ?>"
                               size="60"
                               maxlength="60"
                               style="padding: 8px; font-family: monospace; font-size: 13px; width: auto;" />
                        <label style="margin-left: 12px; font-size: 12px; color: #374151;">
                            Format:
                            <?php $pmt = $editMode && $editData ? ($editData['preview_media_type'] ?? '') : ''; ?>
                            <select name="preview_media_type" id="preview_media_type" style="padding: 6px; font-size: 13px;">
                                <option value="image/png"  <?php selected($pmt, 'image/png'); ?>>PNG</option>
                                <option value="image/gif"  <?php selected($pmt, 'image/gif'); ?>>GIF</option>
                                <option value="image/jpeg" <?php selected($pmt, 'image/jpeg'); ?>>JPEG</option>
                                <option value="image/webp" <?php selected($pmt, 'image/webp'); ?>>WebP</option>
                                <option value="image/svg+xml" <?php selected($pmt, 'image/svg+xml'); ?>>SVG</option>
                            </select>
                        </label>
                        <p id="preview-ipfs-validation" style="margin-top: 8px; margin-bottom: 8px; font-size: 12px; display: none;"></p>
                        <div id="preview-ipfs-display" style="margin-top: 10px; display: none;">
                            <img id="preview-ipfs-img" src="" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #c9a84c;" />
                        </div>
                    </div>

                    <p class="description" style="margin-top: 10px;">
                        Optional. <strong>Required for video/audio mints</strong> if you want a thumbnail to render in wallets and marketplaces.
                        Image only — PNG, JPG, GIF, WebP, SVG. MP4 and other video formats are not accepted here.
                        <strong>Priority:</strong> Manual IPFS hash &gt; uploaded WordPress media.
                    </p>
                </td>
            </tr>
            <tr id="collection-image-row" style="display: none;">
                <th>Collection Image <span style="color: #2DB0B8;">(Mystery Box)</span></th>
                <td>
                    <?php if ($editMode && $isLaterVariant): ?>
                        <!-- Read-only display for child variants -->
                        <div id="collection-image-preview" style="margin-top: 10px;">
                            <?php if (!empty($editData['collection_image_id'])): ?>
                                <?php
                                $collection_image_url = wp_get_attachment_url($editData['collection_image_id']);
                                $collection_image_mime = get_post_mime_type($editData['collection_image_id']);
                                $collection_is_video = $collection_image_mime && strpos($collection_image_mime, 'video/') === 0;
                                ?>
                                <?php if ($collection_image_url): ?>
                                    <?php if ($collection_is_video): ?>
                                        <video src="<?php echo esc_url($collection_image_url); ?>" controls muted playsinline style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #2DB0B8;"></video>
                                    <?php else: ?>
                                        <img src="<?php echo esc_url($collection_image_url); ?>" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #2DB0B8;" />
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p style="color: #666;">No collection image set</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p style="color: #666;">No collection image set</p>
                            <?php endif; ?>
                        </div>
                        <p class="description" style="color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">
                            <strong>ℹ️ Inherited from parent variant:</strong> Collection image can only be edited on the parent variant (Variant A).
                            This ensures all variants in the collection share the same mystery box image.
                        </p>
                    <?php else: ?>
                        <!-- Editable for new policies and variant A -->
                        <input type="hidden" name="cardanonftcollectionimageid" id="cardanonftcollectionimageid" value="<?php echo esc_attr($editMode && $editData ? ($editData['collection_image_id'] ?? '') : ''); ?>" />
                        <button type="button" id="upload-collection-image-btn" class="button">Select Collection Image</button>
                        <div id="collection-image-preview" style="margin-top: 10px;">
                            <?php if ($editMode && $editData && !empty($editData['collection_image_id'])): ?>
                                <?php
                                $collection_image_url = wp_get_attachment_url($editData['collection_image_id']);
                                $collection_image_mime = get_post_mime_type($editData['collection_image_id']);
                                $collection_is_video = $collection_image_mime && strpos($collection_image_mime, 'video/') === 0;
                                ?>
                                <?php if ($collection_image_url): ?>
                                    <?php if ($collection_is_video): ?>
                                        <video src="<?php echo esc_url($collection_image_url); ?>" controls muted playsinline style="max-width: 150px; max-height: 150px; border-radius: 4px;"></video>
                                    <?php else: ?>
                                        <img src="<?php echo esc_url($collection_image_url); ?>" style="max-width: 150px; max-height: 150px; border-radius: 4px;" />
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <p class="description" style="color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">
                            <strong>🎁 Optional:</strong> Upload a "mystery box" or collection logo to show during checkout instead of the actual NFT image.
                            This prevents spoiling random/weighted mints. Leave empty to show the actual NFT image.
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Collection Title</th>
                <td>
                    <input type="text" name="cardanonfttitle" id="cardanonfttitle" required style="width: 100%; max-width: 500px;"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['title'] : ''); ?>"
                           <?php echo ($editMode && $isLaterVariant) ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                    <p class="description">Main collection name (e.g., "Mystic Dragons NFT Collection")
                    <?php if ($editMode && $isLaterVariant): ?><strong style="color: #856404;"> - Locked (policy-level field)</strong><?php endif; ?></p>
                </td>
            </tr>
            <tr>
                <th>Internal Asset Name <span style="color: #d63638;">*</span></th>
                <td>
                    <input type="text" name="cardanonftassetname" id="cardanonftassetname" required style="width: 100%; max-width: 500px;"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['asset_name'] : ''); ?>" />
                    <p class="description">For your reference only - not visible on-chain. Used internally to identify this variant (e.g., "Legendary Print", "Rare Edition A")</p>
                </td>
            </tr>
            <tr>
                <th>Quantity <span style="color: #d63638;">*</span></th>
                <td>
                    <input type="number" min="1" step="1" name="cardanonftquantity" id="cardanonftquantity"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['quantity_total'] : '1'); ?>" required style="width: 150px;" />
                    <p class="description">How many of this specific asset variant can be minted (affects rarity)
                    <?php if ($editMode && $editData && $editData['quantity_minted'] > 0): ?>
                        <br><strong style="color: #d63638;">⚠️ <?php echo esc_html($editData['quantity_minted']); ?> already minted - be careful changing this!</strong>
                    <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th>Mint Expiration (UTC) <span style="color: #d63638;">*</span></th>
                <td>
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px;">Date:</label>
                            <input type="date" name="cardanonftexpirationdate_date" id="cardanonftexpirationdate_date" style="padding: 8px; font-size: 14px;"
                                   <?php echo ($editMode || !empty($unlimited)) ? 'disabled style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px;">Time (24hr):</label>
                            <input type="time" name="cardanonftexpirationdate_time" id="cardanonftexpirationdate_time" style="padding: 8px; font-size: 14px;" value="23:59"
                                   <?php echo ($editMode || !empty($unlimited)) ? 'disabled style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                        </div>
                        <div style="background: #fff3cd; padding: 10px 15px; border-radius: 4px; border-left: 4px solid #ffc107;">
                            <strong style="display: block; margin-bottom: 3px;">⏰ Timezone: UTC</strong>
                            <span style="font-size: 12px; color: #666;">Set expiration in Coordinated Universal Time</span>
                        </div>
                    </div>
                    <!-- Hidden field for backward compatibility -->
                    <input type="hidden" name="cardanonftexpirationdate" id="cardanonftexpirationdate" value="<?php echo esc_attr($expiration); ?>" />
                    <p class="description" style="<?php echo $editMode ? '' : 'color: #d63638; font-weight: bold;'; ?> margin-top: 10px;">
                        <?php if ($editMode): ?>
                            <strong style="color: #856404;">Locked (policy-level field)</strong>
                        <?php else: ?>
                            ⚠️ Required before generating Policy ID
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th>Unlimited Minting</th>
                <td>
                    <input type="checkbox" name="cardanonftunlimitedmint" id="cardanonftunlimitedmint" value="1" <?php echo !empty($unlimited) ? 'checked' : ''; ?> onChange="toggleUnlimitedMinting(this.checked);">
                    <label for="cardanonftunlimitedmint">Unlimited (no expiration)</label>
                </td>
            </tr>
            <tr>
                <th>Policy ID <span style="color: #d63638;">*</span></th>
                <td>
                    <input type="text" name="cardanonftpolicyid" id="cardanonftpolicyid" required style="width: 60%;"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['policyid'] : ''); ?>"
                           placeholder="Set expiration date first, then generate policy" readonly
                           <?php echo $editMode ? 'style="background: #f5f5f5;"' : ''; ?> />
                    <?php if (!$editMode): ?>
                    <button type="button" id="generate-policy-btn" class="button button-secondary" style="margin-left: 10px;" disabled>
                        🔑 Generate Policy ID
                    </button>
                    <?php else: ?>
                    <span style="margin-left: 10px; color: #856404; font-size: 12px;">Locked in edit mode</span>
                    <?php endif; ?>
                    <div id="policy-generation-status" style="margin-top: 10px; padding: 10px; border-radius: 4px; display: none;"></div>
                    
                    <!-- Policy JSON Display -->
                    <div id="policy-json-container" style="margin-top: 15px; display: none;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px;">📋 Policy JSON (Save this for your records):</label>
                        <textarea id="policy-json-display" readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                        <button type="button" class="button" onclick="copyPolicyJson()" style="margin-top: 8px;">📋 Copy Policy JSON</button>
                        <input type="hidden" name="cardanonftpolicyjson" id="cardanonftpolicyjson"
                               value="<?php echo esc_attr($editMode && $editData ? $editData['policy_json'] : ''); ?>" />
                    </div>
                </td>
            </tr>
            <tr>
                <th>NFT Metadata</th>
                <td>
                    <p class="description" style="margin-bottom: 10px;">Add metadata attributes for your NFT (CIP-25 standard)</p>
                    <div id="metadata-builder">
                        <div style="display: flex; gap: 10px; margin-bottom: 10px; font-weight: bold;">
                            <div style="flex: 1;">Attribute Name</div>
                            <div style="flex: 1;">Value</div>
                            <div style="width: 80px;">Action</div>
                        </div>
                        <div id="metadata-rows">
                            <!-- Metadata rows will be added here -->
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="button" onclick="addMetadataRow()">+ Add Attribute</button>
                            <span style="color: #999;">or</span>
                            <label class="button" style="cursor: pointer; margin: 0;">
                                Upload JSON
                                <input type="file" id="metadata-json-upload" accept=".json" style="display: none;" onchange="handleMetadataUpload(this)">
                            </label>
                            <span id="metadata-upload-status" style="font-size: 12px; color: #666;"></span>
                        </div>
                        <p class="description" style="margin-top: 8px;">Upload a <a href="https://metadraft.io/" target="_blank" rel="noopener">MetaDraft</a>-formatted JSON file. The first asset's metadata will be loaded into the builder.</p>
                        <div id="metadata-upload-warning" style="display: none; margin-top: 10px; padding: 12px; background: #fff3cd; border-left: 4px solid #f0c040; border-radius: 4px;">
                            <strong style="color: #856404;">Warning:</strong> Minting is an on-chain action and is either irreversible or costs ADA to reverse. Check your metadata first at <a href="https://metadraft.io/" target="_blank" rel="noopener">metadraft.io</a> for errors and completeness. Double check your work!
                        </div>

                        <!-- Asset selector (shown when JSON has multiple assets) -->
                        <div id="metadata-asset-selector" style="display: none; margin-top: 10px; padding: 10px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px;">
                            <label style="font-weight: bold; margin-right: 8px;">Select Asset:</label>
                            <select id="metadata-asset-select" onchange="loadSelectedAsset()"></select>
                            <span id="metadata-asset-count" style="margin-left: 10px; color: #666; font-size: 12px;"></span>
                        </div>
                    </div>
                    <input type="hidden" name="cardanonftnftmetadata" id="cardanonftnftmetadata" />

                    <!-- Metadata Preview Section -->
                    <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px;">
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <button type="button" class="button button-secondary" onclick="toggleMetadataPreview()" id="preview-metadata-btn">
                                🔍 Preview Metadata JSON
                            </button>
                            <button type="button" class="button" onclick="copyMetadataToClipboard()" style="display: none;" id="copy-metadata-btn">
                                📋 Copy JSON
                            </button>
                            <button type="button" class="button" onclick="viewOnPoolPm()" style="display: none;" id="poolpm-btn">
                                🔗 Validate on Pool.pm
                            </button>
                        </div>

                        <div id="metadata-preview-container" style="display: none;">
                            <p style="margin: 0 0 10px 0; color: #0969da; font-weight: 600;">
                                📦 CIP-25 NFT Metadata Preview
                            </p>
                            <pre id="metadata-preview-json" style="background: #fff; padding: 15px; border: 1px solid #d0d7de; border-radius: 6px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; max-height: 400px; overflow-y: auto;"></pre>
                            <p style="margin: 10px 0 0 0; color: #666; font-size: 12px;">
                                💡 <strong>Note:</strong> "name" attribute from the table above is the on-chain display name for your NFT
                            </p>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th>Price (USD)</th>
                <td>
                    <input type="number" step="0.01" min="0" name="cardanonftprice" id="cardanonftprice"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['price'] : '0'); ?>"
                           <?php echo ($editMode && $isLaterVariant) ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                    <?php if ($editMode && $isLaterVariant): ?>
                        <p class="description"><strong style="color: #856404;">Locked (policy-level field) - Edit variant A to change price</strong></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>NFT Royalty %</th>
                <td>
                    <input type="number" step="0.01" min="0" max="100" name="cardanonftroyaltyamount" id="cardanonftroyaltyamount"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['royalty'] : $royalty); ?>"
                           <?php echo ($editMode && $isLaterVariant) ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                    <?php if ($editMode && $isLaterVariant): ?>
                        <p class="description"><strong style="color: #856404;">Locked (policy-level field)</strong></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Royalty Payout Address</th>
                <td>
                    <input type="text" name="cardanonftroyaltyaddress" id="cardanonftroyaltyaddress"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['royaltyaddress'] : $royaltyaddr); ?>" size="64"
                           <?php echo ($editMode && $isLaterVariant) ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                    <?php if ($editMode && $isLaterVariant): ?>
                        <p class="description"><strong style="color: #856404;">Locked (policy-level field)</strong></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Mints Allowed Per Wallet</th>
                <td>
                    <input type="number" min="0" step="1" name="cardanonftmintsallowedperwallet" id="cardanonftmintsallowedperwallet"
                           value="<?php echo esc_attr($editMode && $editData ? $editData['mintsallowedperwallet'] : '0'); ?>"
                           <?php echo ($editMode && $isLaterVariant) ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : ''; ?> />
                    <p class="description">0 means unlimited per wallet.
                    <?php if ($editMode && $isLaterVariant): ?><strong style="color: #856404;"> - Locked (policy-level field)</strong><?php endif; ?></p>
                </td>
            </tr>
        </table>

        <?php if (!$editMode): ?>
        <!-- Bulk JSON import panel (shown when the "Import JSON" path is chosen) -->
        <div id="cem-import-panel" style="display:none; border:2px solid #46812b; border-radius:8px; padding:20px; margin:10px 0 20px;">
            <h2 style="margin-top:0;">Import mint-ready JSON</h2>
            <p class="description" style="margin-bottom:12px;">
                Paste a JSON array of <code>{ "assetName": "...", "metadata": { ... } }</code> objects, or upload a <code>.json</code> file.
                Each entry becomes one quantity-1 asset minted <strong>verbatim</strong>. Token names must be unique within the policy and 1–32 bytes.
                Make sure the <strong>Policy ID</strong>, <strong>Collection Title</strong>, <strong>Expiration</strong>, <strong>Price</strong> and <strong>Royalty</strong> above are set first.
            </p>
            <p>
                <label class="button" style="cursor:pointer;">Upload .json
                    <input type="file" id="cem-import-file" accept=".json,.jsonl,application/json" style="display:none;" />
                </label>
                <span id="cem-import-file-name" style="margin-left:8px; color:#666; font-size:12px;"></span>
            </p>
            <textarea id="cem-import-json" rows="10" style="width:100%; font-family:monospace; font-size:12px;"
                placeholder='[ { "assetName": "Viperions_0002", "metadata": { "name": "Abyssal Champion", "image": "ipfs://...", "attributes": { } } } ]'></textarea>
            <p>
                <button type="button" class="button" id="cem-import-parse">Parse &amp; Preview</button>
                <span id="cem-import-summary" style="margin-left:10px; font-weight:600;"></span>
            </p>
            <div id="cem-import-errors" style="display:none; margin:10px 0; padding:10px; background:#fff3cd; border-left:4px solid #f0c040; border-radius:4px; max-height:180px; overflow:auto; font-size:12px;"></div>
            <div id="cem-import-preview-wrap" style="display:none;">
                <table class="widefat striped" style="margin-top:10px;">
                    <thead><tr><th style="width:50px;">#</th><th style="width:54px;">Img</th><th>Token name (on-chain)</th><th>Display name</th></tr></thead>
                    <tbody id="cem-import-preview-body"></tbody>
                </table>
                <div id="cem-import-pager" style="margin-top:8px;"></div>
            </div>
            <p style="margin-top:16px;">
                <button type="button" class="button button-primary" id="cem-import-go" disabled>Import assets</button>
                <span id="cem-import-progress" style="margin-left:10px;"></span>
            </p>
        </div>
        <?php endif; ?>

        <p class="submit" id="cem-build-submit">
            <input type="submit" name="mintmanagersave" id="submit-mint-btn" class="button-primary"
                   value="<?php echo $editMode ? 'Update Asset' : 'Add This Asset'; ?>" />
            <?php if ($editMode): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-1')); ?>" class="button" style="margin-left: 10px;">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>

<?php if (!$editMode): ?>
<script>
(function(){
    var parsed = [], page = 0, perPage = 25;
    var nonce = '<?php echo wp_create_nonce('cardanocheckoutnonce'); ?>';

    window.cemTogglePath = function(){
        var sel = document.querySelector('input[name=cem_path]:checked');
        var importMode = sel && sel.value === 'import';
        // Hide the per-asset form rows in import mode (policy-level rows stay).
        ['upload-image-btn','upload-preview-image-btn','cardanonftassetname','cardanonftquantity','metadata-builder'].forEach(function(id){
            var el = document.getElementById(id); if(!el) return;
            var tr = el.closest('tr'); if(tr) tr.style.display = importMode ? 'none' : '';
        });
        // Resurface the collection (mystery-box) image picker during bulk import so
        // imported assets can show a cover instead of each asset's own image.
        var ci = document.getElementById('collection-image-row');
        if(ci){ if(importMode){ ci.style.display=''; } else if(window.toggleCollectionImageField){ window.toggleCollectionImageField(); } else { ci.style.display='none'; } }
        var sub = document.getElementById('cem-build-submit'); if(sub) sub.style.display = importMode ? 'none' : '';
        var panel = document.getElementById('cem-import-panel'); if(panel) panel.style.display = importMode ? 'block' : 'none';
    };

    function ipfs(u){ return (typeof u==='string' && u.indexOf('ipfs://')===0) ? 'https://ipfs.io/ipfs/'+u.slice(7) : (u||''); }
    function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
    function byteLen(s){ return unescape(encodeURIComponent(s)).length; }

    function renderPage(){
        var body=document.getElementById('cem-import-preview-body'); body.innerHTML='';
        var pages=Math.max(1, Math.ceil(parsed.length/perPage));
        if(page>=pages) page=pages-1;
        var start=page*perPage, end=Math.min(start+perPage, parsed.length);
        for(var i=start;i<end;i++){
            var a=parsed[i], m=a.metadata||{}, img=ipfs(m.image);
            var tr=document.createElement('tr');
            tr.innerHTML='<td>'+(i+1)+'</td><td>'+(img?'<img src="'+esc(img)+'" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" loading="lazy">':'')+'</td><td><code>'+esc(a.assetName)+'</code></td><td>'+esc(m.name||'')+'</td>';
            body.appendChild(tr);
        }
        var pager=document.getElementById('cem-import-pager');
        pager.innerHTML='Page '+(page+1)+' / '+pages+' &nbsp; '+
            '<button type="button" class="button" id="cem-prev" '+(page<=0?'disabled':'')+'>&lsaquo; Prev</button> '+
            '<button type="button" class="button" id="cem-next" '+(page>=pages-1?'disabled':'')+'>Next &rsaquo;</button>';
        var pv=document.getElementById('cem-prev'), nx=document.getElementById('cem-next');
        if(pv) pv.onclick=function(){ if(page>0){page--;renderPage();} };
        if(nx) nx.onclick=function(){ if(page<pages-1){page++;renderPage();} };
    }

    function doParse(){
        var raw=document.getElementById('cem-import-json').value.trim();
        var summary=document.getElementById('cem-import-summary');
        var errBox=document.getElementById('cem-import-errors');
        var go=document.getElementById('cem-import-go');
        errBox.style.display='none'; errBox.innerHTML=''; parsed=[]; go.disabled=true;
        document.getElementById('cem-import-preview-wrap').style.display='none';
        if(!raw){ summary.textContent='Nothing to parse.'; return; }
        var data; try{ data=JSON.parse(raw); }catch(e){ summary.textContent='❌ Invalid JSON: '+e.message; return; }
        if(!Array.isArray(data)){ summary.textContent='❌ Expected a JSON array of {assetName, metadata}.'; return; }
        var errs=[], seen={}, ok=[];
        data.forEach(function(a,i){
            var lbl='#'+(i+1);
            if(!a || typeof a!=='object' || !a.assetName || !a.metadata || typeof a.metadata!=='object'){ errs.push(lbl+': missing assetName or metadata object.'); return; }
            var n=String(a.assetName);
            var b=byteLen(n);
            if(b<1 || b>32){ errs.push(lbl+' ('+n+'): token name must be 1–32 bytes (is '+b+').'); return; }
            if(!/^[\x20-\x7E]+$/.test(n)){ errs.push(lbl+' ('+n+'): token name must be printable ASCII.'); return; }
            if(seen[n]){ errs.push(lbl+' ('+n+'): duplicate token name in this list.'); return; }
            seen[n]=1; ok.push({assetName:n, metadata:a.metadata});
        });
        parsed=ok;
        summary.textContent='✓ '+ok.length+' valid asset'+(ok.length===1?'':'s')+(errs.length?(' · '+errs.length+' skipped'):'');
        if(errs.length){ errBox.style.display='block'; errBox.innerHTML='<strong>'+errs.length+' issue(s):</strong><br>'+errs.slice(0,200).map(esc).join('<br>'); }
        if(ok.length){ page=0; document.getElementById('cem-import-preview-wrap').style.display='block'; renderPage(); go.disabled=false; go.textContent='Import '+ok.length+' asset'+(ok.length===1?'':'s'); }
    }

    function doImport(){
        if(!parsed.length) return;
        var policyid=(document.getElementById('cardanonftpolicyid')||{}).value||'';
        if(!/^[a-f0-9]{56}$/i.test(policyid)){ alert('Set or generate a valid Policy ID first (Policy ID field above).'); return; }
        var policyMode=(document.querySelector('input[name=policy_mode]:checked')||{}).value||'new';
        var existingId=(document.getElementById('existing_collection_id')||{}).value||'';
        var fields={
            policy_mode: policyMode,
            policyid: policyid,
            policy_json: (document.getElementById('cardanonftpolicyjson')||{}).value||'',
            expirationdate: (document.getElementById('cardanonftexpirationdate')||{}).value||'',
            unlimited: (document.getElementById('cardanonftunlimitedmint')||{}).checked?1:'',
            price: (document.getElementById('cardanonftprice')||{}).value||'0',
            royalty: (document.getElementById('cardanonftroyaltyamount')||{}).value||'',
            royaltyaddress: (document.getElementById('cardanonftroyaltyaddress')||{}).value||'',
            mintsallowedperwallet: (document.getElementById('cardanonftmintsallowedperwallet')||{}).value||'0',
            title: (document.getElementById('cardanonfttitle')||{}).value||'',
            collection_image_id: (document.getElementById('cardanonftcollectionimageid')||{}).value||''
        };
        // Existing numeric collection -> append; new or imported-policy -> 0 (server seeds it).
        var collectionId = (policyMode==='existing' && existingId && existingId.indexOf('imported_')!==0) ? parseInt(existingId,10) : 0;
        var go=document.getElementById('cem-import-go'), prog=document.getElementById('cem-import-progress');
        go.disabled=true;
        var batchSize=50, idx=0, totalInserted=0, allErrors=[];
        function nextBatch(){
            if(idx>=parsed.length){
                prog.innerHTML='<span style="color:#46812b; font-weight:600;">✓ Imported '+totalInserted+' asset(s).'+(allErrors.length?(' '+allErrors.length+' skipped.'):'')+' Redirecting…</span>';
                setTimeout(function(){ window.location='<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-2')); ?>'; }, 1600);
                return;
            }
            var batch=parsed.slice(idx, idx+batchSize);
            prog.innerHTML='Importing '+(idx+1)+'–'+Math.min(idx+batchSize,parsed.length)+' of '+parsed.length+'…';
            var fd=new FormData();
            fd.append('action','cardano_mint_import_assets'); fd.append('nonce',nonce);
            Object.keys(fields).forEach(function(k){ fd.append(k, fields[k]); });
            fd.append('collection_id', collectionId);
            fd.append('assets', JSON.stringify(batch));
            fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
                if(!res || !res.success){ go.disabled=false; prog.innerHTML='<span style="color:#d63638;">Error: '+((res&&res.data&&res.data.message)||'import failed')+'</span>'; return; }
                totalInserted += res.data.inserted||0;
                if(res.data.errors && res.data.errors.length) allErrors=allErrors.concat(res.data.errors);
                if(collectionId===0 && res.data.collection_id) collectionId=res.data.collection_id;
                idx+=batchSize; nextBatch();
            }).catch(function(e){ go.disabled=false; prog.innerHTML='<span style="color:#d63638;">Network error: '+e.message+'</span>'; });
        }
        nextBatch();
    }

    document.addEventListener('DOMContentLoaded', function(){
        var f=document.getElementById('cem-import-file');
        if(f) f.addEventListener('change', function(){
            var file=f.files[0]; if(!file) return;
            document.getElementById('cem-import-file-name').textContent=file.name;
            var rd=new FileReader(); rd.onload=function(){ document.getElementById('cem-import-json').value=rd.result; }; rd.readAsText(file);
        });
        var p=document.getElementById('cem-import-parse'); if(p) p.addEventListener('click', doParse);
        var g=document.getElementById('cem-import-go'); if(g) g.addEventListener('click', doImport);
    });
})();
</script>
<?php endif; ?>

<?php if ($editMode && $editData && (($editData['metadata_mode'] ?? '') === 'verbatim')): ?>
<script>
// Editing an imported (verbatim) asset: metadata, on-chain name and image are
// fixed. Only the collection-wide pricing fields are editable, and saving any
// one asset re-prices the whole policy (see update_active_mint).
document.addEventListener('DOMContentLoaded', function(){
    ['cardanonftprice','cardanonftroyaltyamount','cardanonftroyaltyaddress','cardanonftmintsallowedperwallet','cardanonfttitle'].forEach(function(id){
        var el=document.getElementById(id); if(!el) return;
        el.readOnly=false; el.removeAttribute('readonly'); el.style.background=''; el.style.cursor='';
    });
    ['upload-image-btn','upload-preview-image-btn','cardanonftassetname','cardanonftquantity','metadata-builder'].forEach(function(id){
        var el=document.getElementById(id); if(!el) return;
        if(el.removeAttribute) el.removeAttribute('required'); // hidden required fields can block submit
        var tr=el.closest('tr'); if(tr) tr.style.display='none';
    });
    var ci=document.getElementById('collection-image-row'); if(ci) ci.style.display='none';
    var form=document.querySelector('form');
    if(form && form.parentNode){
        var n=document.createElement('div'); n.className='notice notice-info'; n.style.margin='10px 0';
        n.innerHTML='<p><strong>Imported asset.</strong> The on-chain metadata, token name and image are fixed. Saving here updates the <strong>price, royalty and mints-per-wallet for the entire collection</strong> (every asset under this policy).</p>';
        form.parentNode.insertBefore(n, form);
    }
});
</script>
<?php endif; ?>

<script>
// Toggle unlimited minting
function toggleUnlimitedMinting(isUnlimited) {
    document.getElementById('cardanonftexpirationdate_date').disabled = isUnlimited;
    document.getElementById('cardanonftexpirationdate_time').disabled = isUnlimited;
    document.getElementById('cardanonftexpirationdate').disabled = isUnlimited;
}

// Policy mode toggle
function togglePolicyMode() {
    const isNewPolicy = document.getElementById('policy_mode_new').checked;
    const existingSelector = document.getElementById('existing-policy-selector');
    const generateBtn = document.getElementById('generate-policy-btn');
    const policyIdInput = document.getElementById('cardanonftpolicyid');
    const expirationInput = document.getElementById('cardanonftexpirationdate');
    const expirationDateInput = document.getElementById('cardanonftexpirationdate_date');
    const expirationTimeInput = document.getElementById('cardanonftexpirationdate_time');
    const unlimitedCheckbox = document.getElementById('cardanonftunlimitedmint');

    // Policy-level fields
    const titleInput = document.getElementById('cardanonfttitle');
    const priceInput = document.getElementById('cardanonftprice');
    const royaltyInput = document.getElementById('cardanonftroyaltyamount');
    const royaltyAddressInput = document.getElementById('cardanonftroyaltyaddress');
    const mintsPerWalletInput = document.getElementById('cardanonftmintsallowedperwallet');

    if (isNewPolicy) {
        // New policy mode - enable all fields
        existingSelector.style.display = 'none';
        generateBtn.style.display = 'inline-block';
        policyIdInput.readOnly = true;
        policyIdInput.value = '';
        policyIdInput.style.background = '';
        expirationInput.readOnly = false;
        expirationDateInput.disabled = false;
        expirationDateInput.style.background = '';
        expirationDateInput.style.cursor = '';
        expirationTimeInput.disabled = false;
        expirationTimeInput.style.background = '';
        expirationTimeInput.style.cursor = '';
        unlimitedCheckbox.disabled = false;
        document.getElementById('policy-info-display').style.display = 'none';

        // Enable policy-level fields
        titleInput.readOnly = false;
        titleInput.style.background = '';
        titleInput.style.cursor = '';
        priceInput.readOnly = false;
        priceInput.style.background = '';
        priceInput.style.cursor = '';
        royaltyInput.readOnly = false;
        royaltyInput.style.background = '';
        royaltyInput.style.cursor = '';
        royaltyAddressInput.readOnly = false;
        royaltyAddressInput.style.background = '';
        royaltyAddressInput.style.cursor = '';
        mintsPerWalletInput.readOnly = false;
        mintsPerWalletInput.style.background = '';
        mintsPerWalletInput.style.cursor = '';

        // Clear values
        titleInput.value = '';
        priceInput.value = '0';
        royaltyInput.value = '';
        royaltyAddressInput.value = '';
        mintsPerWalletInput.value = '0';
    } else {
        // Existing policy mode - disable policy-level fields
        existingSelector.style.display = 'block';
        generateBtn.style.display = 'none';
        policyIdInput.readOnly = true;
        policyIdInput.style.background = '#f5f5f5';
        expirationInput.readOnly = true;
        expirationDateInput.disabled = true;
        expirationDateInput.style.background = '#f5f5f5';
        expirationDateInput.style.cursor = 'not-allowed';
        expirationTimeInput.disabled = true;
        expirationTimeInput.style.background = '#f5f5f5';
        expirationTimeInput.style.cursor = 'not-allowed';
        unlimitedCheckbox.disabled = true;

        // Grey out policy-level fields (will be populated when policy is selected)
        titleInput.readOnly = true;
        titleInput.style.background = '#f5f5f5';
        titleInput.style.cursor = 'not-allowed';
        priceInput.readOnly = true;
        priceInput.style.background = '#f5f5f5';
        priceInput.style.cursor = 'not-allowed';
        royaltyInput.readOnly = true;
        royaltyInput.style.background = '#f5f5f5';
        royaltyInput.style.cursor = 'not-allowed';
        royaltyAddressInput.readOnly = true;
        royaltyAddressInput.style.background = '#f5f5f5';
        royaltyAddressInput.style.cursor = 'not-allowed';
        mintsPerWalletInput.readOnly = true;
        mintsPerWalletInput.style.background = '#f5f5f5';
        mintsPerWalletInput.style.cursor = 'not-allowed';
    }
}

// Load policy data when selecting existing policy
function loadPolicyData() {
    const select = document.getElementById('existing_collection_id');
    const selectedOption = select.options[select.selectedIndex];

    if (!selectedOption.value) {
        document.getElementById('policy-info-display').style.display = 'none';
        return;
    }

    const policyId = selectedOption.getAttribute('data-policyid');
    const expiration = selectedOption.getAttribute('data-expiration');
    const policyJson = selectedOption.getAttribute('data-policy-json');
    const assetCount = selectedOption.getAttribute('data-asset-count');
    const collectionId = selectedOption.value;

    // Calculate next variant (A + asset count)
    const nextVariantCode = 65 + parseInt(assetCount); // A=65 in ASCII
    const nextVariant = String.fromCharCode(nextVariantCode);

    // Update display
    document.getElementById('next-variant').textContent = nextVariant;
    document.getElementById('display-policy-id').textContent = policyId;
    document.getElementById('display-expiration').textContent = new Date(expiration).toLocaleString();

    // Fill in form fields (read-only)
    document.getElementById('cardanonftpolicyid').value = policyId;
    document.getElementById('cardanonftpolicyjson').value = policyJson;

    // Parse and set expiration date/time
    if (expiration) {
        const expirationParts = expiration.substring(0, 16).split('T');
        if (expirationParts.length === 2) {
            document.getElementById('cardanonftexpirationdate_date').value = expirationParts[0];
            document.getElementById('cardanonftexpirationdate_time').value = expirationParts[1];
            document.getElementById('cardanonftexpirationdate').value = expiration.substring(0, 16);
        }
    }

    // Get policy-level data from the dropdown's data attributes
    const title = selectedOption.getAttribute('data-title');
    const price = selectedOption.getAttribute('data-price');
    const royalty = selectedOption.getAttribute('data-royalty');
    const royaltyAddress = selectedOption.getAttribute('data-royaltyaddress');
    const mintsAllowedPerWallet = selectedOption.getAttribute('data-mintsallowedperwallet');

    // Debug logging
    console.log('Loading policy data from variant A:');
    console.log('Title:', title);
    console.log('Price:', price);
    console.log('Royalty:', royalty);
    console.log('Royalty Address:', royaltyAddress);
    console.log('Mints Per Wallet:', mintsAllowedPerWallet);

    // Populate policy-level fields (they're already greyed from togglePolicyMode)
    document.getElementById('cardanonfttitle').value = title || '';
    document.getElementById('cardanonftprice').value = price || '0';
    document.getElementById('cardanonftroyaltyamount').value = royalty || '';
    document.getElementById('cardanonftroyaltyaddress').value = royaltyAddress || '';
    document.getElementById('cardanonftmintsallowedperwallet').value = mintsAllowedPerWallet || '0';

    console.log('Fields populated successfully');

    // Imported (skey) policies have no parent variant A to inherit pricing
    // from, so let the operator set the policy-level fields here. They apply to
    // every asset minted under this policy (incl. the bulk JSON import).
    var isImported = selectedOption.getAttribute('data-imported') === '1';
    ['cardanonftprice', 'cardanonftroyaltyamount', 'cardanonftroyaltyaddress', 'cardanonftmintsallowedperwallet', 'cardanonfttitle'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.readOnly = !isImported;
        el.style.background = isImported ? '' : '#f5f5f5';
        el.style.cursor = isImported ? '' : 'not-allowed';
    });

    // Show info display
    document.getElementById('policy-info-display').style.display = 'block';
}

jQuery(document).ready(function($) {
    // Sync date and time fields with the hidden datetime-local field
    function syncExpirationFields() {
        const dateInput = $('#cardanonftexpirationdate_date');
        const timeInput = $('#cardanonftexpirationdate_time');
        const hiddenInput = $('#cardanonftexpirationdate');

        if (dateInput.val() && timeInput.val()) {
            const combinedValue = dateInput.val() + 'T' + timeInput.val();
            hiddenInput.val(combinedValue);
        }
    }

    // Update hidden field when date or time changes
    $('#cardanonftexpirationdate_date, #cardanonftexpirationdate_time').on('change', syncExpirationFields);

    // Initialize date/time fields from existing value (for editing)
    const existingValue = $('#cardanonftexpirationdate').val();
    if (existingValue) {
        const parts = existingValue.split('T');
        if (parts.length === 2) {
            $('#cardanonftexpirationdate_date').val(parts[0]);
            $('#cardanonftexpirationdate_time').val(parts[1]);
        }
    }

    // Media library integration for image selection
    // Preview image (still / GIF) for video & audio mints
    $('#upload-preview-image-btn').click(function(e) {
        e.preventDefault();
        var uploader = wp.media({
            title: 'Select Preview Image (still or GIF)',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: ['image'] }   // image only — no mp4/video
        });
        uploader.on('select', function() {
            var att = uploader.state().get('selection').first().toJSON();
            // Defensive double-check: refuse non-image attachments
            if (att.type !== 'image') {
                alert('Preview image must be a still image or GIF. Videos are not accepted here.');
                return;
            }
            $('#cardanonftpreviewimageid').val(att.id);
            $('#preview-image-display').html(
                '<img src="' + att.url + '" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 2px solid #c9a84c;" />'
            );
        });
        uploader.open();
    });
    $('#clear-preview-image-btn').click(function(e) {
        e.preventDefault();
        $('#cardanonftpreviewimageid').val('');
        $('#preview-image-display').html('');
    });
    // IPFS hash validation for preview field (mirrors the main NFT image one)
    $('#preview_ipfs_cid_manual').on('input', function() {
        var hash = $(this).val().trim();
        var $msg = $('#preview-ipfs-validation');
        var $box = $('#preview-ipfs-display');
        var $img = $('#preview-ipfs-img');
        if (hash === '') { $msg.hide(); $box.hide(); return; }
        var ok = /^Qm[1-9A-HJ-NP-Za-km-z]{44}$/.test(hash);
        if (ok) {
            $msg.css('color', '#10b981').html('✓ Valid CIDv0 hash').show();
            $img.attr('src', 'https://ipfs.io/ipfs/' + hash);
            $box.show();
        } else {
            $msg.css('color', '#ef4444').html('✗ Invalid CIDv0 — must start with Qm and be 46 chars').show();
            $box.hide();
        }
    }).trigger('input');

    $('#upload-image-btn').click(function(e) {
        e.preventDefault();

        var mediaUploader = wp.media({
            title: 'Select NFT Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#cardanonftimageid').val(attachment.id);
            $('#cardanonftimagemime').val(attachment.mime || '');

            var isVideo = attachment.type === 'video' || (attachment.mime && attachment.mime.indexOf('video/') === 0);
            var previewHtml = isVideo
                ? '<video src="' + attachment.url + '" controls muted playsinline style="max-width: 150px; max-height: 150px; border-radius: 4px;"></video>'
                : '<img src="' + attachment.url + '" style="max-width: 150px; max-height: 150px; border-radius: 4px;" />';
            $('#image-preview').html(previewHtml);

            // Pin to IPFS is image-only — videos are served from the WordPress CDN
            if ($('#pin-to-ipfs-btn').length) {
                if (isVideo) {
                    $('#pin-to-ipfs-btn').prop('disabled', true).css('background', '#9ca3af').css('border-color', '#9ca3af');
                    $('#pin-status').html('<span style="color: #6b7280; font-size: 12px;">Videos are served from the WordPress CDN — IPFS pinning is not required.</span>');
                } else {
                    $('#pin-to-ipfs-btn').prop('disabled', false).css('background', '#2DB0B8').css('border-color', '#2DB0B8');
                    $('#pin-status').html('');
                }
            }
        });

        mediaUploader.open();
    });

    // IPFS CIDv0 Validation
    function validateIPFSHash(hash) {
        // CIDv0: starts with Qm, exactly 46 characters, base58 encoded
        const cidv0Pattern = /^Qm[1-9A-HJ-NP-Za-km-z]{44}$/;
        return cidv0Pattern.test(hash);
    }

    function updateIPFSStatus() {
        const manualHash = $('#ipfs_cid_manual').val().trim();
        const pinataHash = $('#ipfs_cid').val();
        const $statusDisplay = $('#ipfs-status-display');

        if (manualHash) {
            $statusDisplay.html('<p style="color: #7c3aed; font-weight: 600;">✓ Manual IPFS: ' + manualHash + '</p>');
        } else if (pinataHash) {
            $statusDisplay.html('<p style="color: #10b981; font-weight: 600;">✓ Pinned to IPFS: ' + pinataHash + '</p>');
        } else {
            $statusDisplay.html('');
        }
    }

    // Manual IPFS hash input validation
    $('#ipfs_cid_manual').on('input', function() {
        const hash = $(this).val().trim();
        const $validationMsg = $('#ipfs-validation-message');
        const $pinBtn = $('#pin-to-ipfs-btn');
        const $previewContainer = $('#ipfs-manual-preview');
        const $previewImg = $('#ipfs-manual-preview-img');

        if (hash === '') {
            // Empty - clear validation and enable pin button
            $validationMsg.hide();
            $previewContainer.hide();
            if ($pinBtn.length && $('#cardanonftimageid').val()) {
                $pinBtn.prop('disabled', false).css('background', '#2DB0B8').css('border-color', '#2DB0B8');
            }
            updateIPFSStatus();
            return;
        }

        if (validateIPFSHash(hash)) {
            // Valid CIDv0
            $validationMsg
                .css('color', '#10b981')
                .html('✓ Valid CIDv0 hash - Loading preview...')
                .show();

            // Load IPFS preview image
            const ipfsGateway = 'https://ipfs.io/ipfs/';
            const imageUrl = ipfsGateway + hash;

            // Show preview container and set image source
            $previewImg.attr('src', imageUrl);
            $previewContainer.show();

            // Update validation message when image loads
            $previewImg.on('load', function() {
                $validationMsg
                    .css('color', '#10b981')
                    .html('✓ Valid CIDv0 hash - Preview loaded')
                    .show();
            });

            // Handle image load errors
            $previewImg.on('error', function() {
                $validationMsg
                    .css('color', '#f59e0b')
                    .html('⚠ Valid CIDv0 format, but preview failed to load (image may still work on-chain)')
                    .show();
                $previewContainer.hide();
            });

            // Disable pin button since manual hash takes priority
            if ($pinBtn.length) {
                $pinBtn.prop('disabled', true).css('background', '#9ca3af').css('border-color', '#9ca3af');
            }
            $('#pin-status').html('<span style="color: #6b7280; font-size: 12px;">Manual hash takes priority</span>');
        } else {
            // Invalid format
            $validationMsg
                .css('color', '#ef4444')
                .html('✗ Invalid CIDv0 format. Must start with "Qm" and be 46 characters long.')
                .show();
            $previewContainer.hide();

            // Keep pin button disabled for invalid hash
            if ($pinBtn.length) {
                $pinBtn.prop('disabled', true).css('background', '#9ca3af').css('border-color', '#9ca3af');
            }
        }

        updateIPFSStatus();
    });

    // Clear manual IPFS hash button
    $('#clear-manual-ipfs-btn').click(function(e) {
        e.preventDefault();
        $('#ipfs_cid_manual').val('').trigger('input');
        $('#ipfs-manual-preview').hide();
    });

    // Pin to IPFS button handler
    $('#pin-to-ipfs-btn').click(function(e) {
        e.preventDefault();

        // Check if manual hash is entered
        const manualHash = $('#ipfs_cid_manual').val().trim();
        if (manualHash) {
            alert('Manual IPFS hash is set. Clear it first if you want to use Pinata pinning.');
            return;
        }

        var imageId = $('#cardanonftimageid').val();
        var assetName = $('#cardanonftassetname').val() || $('#cardanonfttitle').val() || 'NFT Image';

        if (!imageId) {
            alert('Please select an image first');
            return;
        }

        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Pinning...');
        $('#pin-status').html('<span style="color: #999;">Uploading to IPFS...</span>');

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'cardano_pin_to_ipfs',
                nonce: '<?php echo wp_create_nonce('cardanocheckoutnonce'); ?>',
                image_id: imageId,
                name: assetName
            },
            success: function(response) {
                if (response.success && response.data.cid) {
                    $('#ipfs_cid').val(response.data.cid);
                    $('#pin-status').html('<span style="color: #10b981; font-weight: 600;">✓ Pinned! CID: ' + response.data.cid + '</span>');
                    $btn.text('Re-Pin').prop('disabled', false);
                    updateIPFSStatus();
                } else {
                    $('#pin-status').html('<span style="color: #dc3545;">✗ Failed: ' + (response.data.message || 'Unknown error') + '</span>');
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#pin-status').html('<span style="color: #dc3545;">✗ Error: ' + error + '</span>');
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Media library integration for COLLECTION image selection (mystery box)
    $('#upload-collection-image-btn').click(function(e) {
        e.preventDefault();

        var mediaUploader = wp.media({
            title: 'Select Collection/Mystery Box Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#cardanonftcollectionimageid').val(attachment.id);

            var isVideo = attachment.type === 'video' || (attachment.mime && attachment.mime.indexOf('video/') === 0);
            var previewHtml = isVideo
                ? '<video src="' + attachment.url + '" controls muted playsinline style="max-width: 150px; max-height: 150px; border-radius: 4px;"></video>'
                : '<img src="' + attachment.url + '" style="max-width: 150px; max-height: 150px; border-radius: 4px;" />';
            $('#collection-image-preview').html(previewHtml);
        });

        mediaUploader.open();
    });

    // Show/hide collection image field based on policy mode
    function toggleCollectionImageField() {
        var policyMode = $('input[name="policy_mode"]:checked').val();
        var isEditMode = <?php echo $editMode ? 'true' : 'false'; ?>;
        var isVariantA = <?php echo ($editMode && $isVariantA) ? 'true' : 'false'; ?>;

        // Show collection image field if:
        // 1. Creating new policy, OR
        // 2. Editing variant A (parent variant)
        if (policyMode === 'new' || (isEditMode && isVariantA)) {
            $('#collection-image-row').show();
        } else {
            $('#collection-image-row').hide();
        }
    }
    // Expose so the bulk-import path (separate IIFE) can restore correct visibility on mode switch.
    window.toggleCollectionImageField = toggleCollectionImageField;

    // Initial toggle on page load
    toggleCollectionImageField();

    // Toggle when radio buttons change
    $('input[name="policy_mode"]').on('change', toggleCollectionImageField);

    // Enable "Pin to IPFS" button if image is already selected (edit mode)
    if ($('#cardanonftimageid').val() && $('#pin-to-ipfs-btn').length) {
        $('#pin-to-ipfs-btn').prop('disabled', false).css('background', '#2DB0B8').css('border-color', '#2DB0B8');
    }

    // Load IPFS preview if manual hash exists (edit mode)
    const existingManualHash = $('#ipfs_cid_manual').val().trim();
    if (existingManualHash && validateIPFSHash(existingManualHash)) {
        const ipfsGateway = 'https://ipfs.io/ipfs/';
        const imageUrl = ipfsGateway + existingManualHash;
        $('#ipfs-manual-preview-img').attr('src', imageUrl);
        $('#ipfs-manual-preview').show();
    }
    
    // Enable/disable policy generation based on expiration date
    function checkExpirationDate() {
        var expirationDate = $('#cardanonftexpirationdate').val();
        var dateValue = $('#cardanonftexpirationdate_date').val();
        var isUnlimited = $('#cardanonftunlimitedmint').is(':checked');
        var generateBtn = $('#generate-policy-btn');

        if (isUnlimited || expirationDate || dateValue) {
            generateBtn.prop('disabled', false);
        } else {
            generateBtn.prop('disabled', true);
        }
    }

    $('#cardanonftexpirationdate, #cardanonftexpirationdate_date, #cardanonftexpirationdate_time').on('change', checkExpirationDate);
    $('#cardanonftunlimitedmint').on('change', checkExpirationDate);
    checkExpirationDate();
    
    // Policy ID Generation
    $('#generate-policy-btn').click(function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $statusDiv = $('#policy-generation-status');
        var $policyInput = $('#cardanonftpolicyid');
        var $policyJsonContainer = $('#policy-json-container');
        var $policyJsonDisplay = $('#policy-json-display');
        var $policyJsonHidden = $('#cardanonftpolicyjson');
        
        // Get expiration date
        var expirationDate = $('#cardanonftexpirationdate').val();
        var isUnlimited = $('#cardanonftunlimitedmint').is(':checked');
        
        if (!isUnlimited && !expirationDate) {
            alert('Please set an expiration date first');
            return;
        }
        
        // Convert to ISO format if we have a date
        var expirationISO = null;
        if (!isUnlimited && expirationDate) {
            expirationISO = new Date(expirationDate).toISOString();
        }
        
        // Disable button and show loading state
        $btn.prop('disabled', true).text('⏳ Generating Policy...');
        $statusDiv.hide();
        $policyJsonContainer.hide();
        
        // Make AJAX call to generate policy
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cardano_generate_policy',
                nonce: '<?php echo wp_create_nonce('cardanomint_generate_policy'); ?>',
                expiration_date: expirationISO
            },
            success: function(response) {
                if (response.success) {
                    // Success! Fill in the policy ID
                    $policyInput.val(response.data.policyId);
                    
                    // Show full policy JSON
                    var policyJson = JSON.stringify(response.data, null, 2);
                    $policyJsonDisplay.val(policyJson);
                    $policyJsonHidden.val(policyJson);
                    $policyJsonContainer.show();
                    
                    $statusDiv
                        .removeClass('notice-error')
                        .addClass('notice notice-success')
                        .html('<strong>✅ Success!</strong> Policy ID generated. Save the JSON below for your records.')
                        .show();
                    
                    console.log('Policy generated:', response.data);
                } else {
                    // Error
                    $statusDiv
                        .removeClass('notice-success')
                        .addClass('notice notice-error')
                        .html('<strong>❌ Error:</strong> ' + (response.data?.message || 'Failed to generate policy'))
                        .show();
                    
                    console.error('Policy generation failed:', response.data);
                }
            },
            error: function(xhr, status, error) {
                $statusDiv
                    .removeClass('notice-success')
                    .addClass('notice notice-error')
                    .html('<strong>❌ Error:</strong> Network error occurred. Please try again.')
                    .show();
                
                console.error('AJAX error:', error);
            },
            complete: function() {
                // Re-enable button
                $btn.prop('disabled', false).text('🔑 Generate Policy ID');
            }
        });
    });
    
    // Initialize metadata rows
    <?php if ($editMode && $editData && !empty($editData['nft_metadata'])): ?>
        // Edit mode - load existing metadata
        const existingMetadata = <?php echo $editData['nft_metadata'] ?: '{}'; ?>;
        if (existingMetadata && typeof existingMetadata === 'object') {
            for (const [key, value] of Object.entries(existingMetadata)) {
                addMetadataRow(key, value);
            }
        } else {
            // Fallback to defaults if metadata is invalid
            addMetadataRow('name', '');
            addMetadataRow('description', '');
            addMetadataRow('rarity', '');
        }
    <?php else: ?>
        // New mint - use defaults (name is the on-chain display name!)
        addMetadataRow('name', '');
        addMetadataRow('description', '');
        addMetadataRow('rarity', '');
    <?php endif; ?>

    // Form submission validation - ensure policy is generated (skip in edit mode)
    $('#submit-mint-btn').on('click', function(e) {
        const isEditMode = <?php echo $editMode ? 'true' : 'false'; ?>;

        // Skip validation in edit mode - policy already exists
        if (isEditMode) {
            console.log('Edit mode - skipping policy generation validation');
            return true;
        }

        const policyId = $('#cardanonftpolicyid').val();
        const policyJson = $('#cardanonftpolicyjson').val();

        if (!policyId || policyId.trim() === '') {
            e.preventDefault();
            alert('⚠️ Please generate a Policy ID before submitting!');
            return false;
        }

        if (!policyJson || policyJson.trim() === '') {
            e.preventDefault();
            alert('⚠️ Policy data is missing! Please regenerate the Policy ID.');
            console.error('Policy ID exists but policy JSON is empty');
            return false;
        }

        console.log('Form validation passed:');
        console.log('Policy ID:', policyId);
        console.log('Policy JSON length:', policyJson.length);
        console.log('Policy JSON preview:', policyJson.substring(0, 100));
    });
});

// Metadata builder functions
let metadataRowCounter = 0;

function addMetadataRow(key = '', value = '') {
    const rowId = 'metadata-row-' + metadataRowCounter++;
    const html = `
        <div class="metadata-row" id="${rowId}" style="display: flex; gap: 10px; margin-bottom: 8px;">
            <input type="text" class="metadata-key" placeholder="e.g., name, artist, rarity" value="${key}" style="flex: 1; padding: 8px;" />
            <input type="text" class="metadata-value" placeholder="Value" value="${value}" style="flex: 1; padding: 8px;" />
            <button type="button" class="button" onclick="removeMetadataRow('${rowId}')" style="width: 80px;">Remove</button>
        </div>
    `;
    jQuery('#metadata-rows').append(html);
    updateMetadataJson();
}

function removeMetadataRow(rowId) {
    jQuery('#' + rowId).remove();
    updateMetadataJson();
}

function updateMetadataJson() {
    const metadata = {};
    jQuery('.metadata-row').each(function() {
        const key = jQuery(this).find('.metadata-key').val().trim();
        const value = jQuery(this).find('.metadata-value').val().trim();
        if (key && value) {
            metadata[key] = value;
        }
    });
    jQuery('#cardanonftnftmetadata').val(JSON.stringify(metadata));
}

// Update metadata JSON on any input change
jQuery(document).on('input', '.metadata-key, .metadata-value', updateMetadataJson);

// ========================================
// Metadata JSON Upload
// ========================================

var uploadedMetadataAssets = null; // Store full uploaded array for asset selector

function handleMetadataUpload(input) {
    var file = input.files[0];
    var status = document.getElementById('metadata-upload-status');
    if (!file) return;

    if (!file.name.endsWith('.json')) {
        status.textContent = 'Please upload a .json file.';
        status.style.color = '#d63638';
        return;
    }

    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var data = JSON.parse(e.target.result);

            // Support both array format (MetaDraft) and single object
            var assets = Array.isArray(data) ? data : [data];

            if (assets.length === 0) {
                status.textContent = 'JSON file contains no assets.';
                status.style.color = '#d63638';
                return;
            }

            // Validate first asset has expected structure
            var first = assets[0];
            if (!first.metadata && !first.name) {
                status.textContent = 'Invalid format. Expected MetaDraft JSON with { assetName, metadata: {...} }.';
                status.style.color = '#d63638';
                return;
            }

            // Store all assets for selector
            uploadedMetadataAssets = assets;

            // Show asset selector if multiple assets
            var selector = document.getElementById('metadata-asset-selector');
            var select = document.getElementById('metadata-asset-select');
            var countLabel = document.getElementById('metadata-asset-count');

            if (assets.length > 1) {
                select.innerHTML = '';
                for (var i = 0; i < assets.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = i;
                    var a = assets[i];
                    var label = (a.assetName || (a.metadata && a.metadata.name) || 'Asset ' + (i + 1));
                    opt.textContent = label;
                    select.appendChild(opt);
                }
                countLabel.textContent = assets.length + ' assets in file';
                selector.style.display = 'block';
            } else {
                selector.style.display = 'none';
            }

            // Load first asset
            loadMetadataFromAsset(first);

            status.textContent = 'Loaded: ' + file.name + ' (' + assets.length + ' asset' + (assets.length > 1 ? 's' : '') + ')';
            status.style.color = '#00a32a';

            // Show upload warning
            var warning = document.getElementById('metadata-upload-warning');
            if (warning) warning.style.display = 'block';

        } catch (err) {
            status.textContent = 'Invalid JSON: ' + err.message;
            status.style.color = '#d63638';
        }
    };
    reader.readAsText(file);

    // Reset input so same file can be re-uploaded
    input.value = '';
}

function loadSelectedAsset() {
    if (!uploadedMetadataAssets) return;
    var idx = parseInt(document.getElementById('metadata-asset-select').value);
    if (uploadedMetadataAssets[idx]) {
        loadMetadataFromAsset(uploadedMetadataAssets[idx]);
    }
}

function loadMetadataFromAsset(asset) {
    // Clear existing rows
    jQuery('#metadata-rows').empty();
    metadataRowCounter = 0;

    // Get the metadata object (MetaDraft format: { assetName, metadata: {...} })
    var meta = asset.metadata || asset;

    // Load ALL attributes — the user provides complete metadata, we mint it as-is
    var keys = Object.keys(meta);
    for (var i = 0; i < keys.length; i++) {
        var key = keys[i];
        var val = meta[key];

        // Handle nested "attributes" object (MetaDraft style) — flatten into rows
        if (key === 'attributes' && typeof val === 'object' && val !== null && !Array.isArray(val)) {
            var attrKeys = Object.keys(val);
            for (var j = 0; j < attrKeys.length; j++) {
                addMetadataRow(attrKeys[j], String(val[attrKeys[j]]));
            }
            continue;
        }

        // Convert arrays/objects to JSON string for the row value
        if (typeof val === 'object') {
            val = JSON.stringify(val);
        } else {
            val = String(val);
        }

        addMetadataRow(key, val);
    }

    updateMetadataJson();
}

// ========================================
// Metadata Preview Functions
// ========================================

function toggleMetadataPreview() {
    const container = document.getElementById('metadata-preview-container');
    const btn = document.getElementById('preview-metadata-btn');
    const copyBtn = document.getElementById('copy-metadata-btn');
    const poolpmBtn = document.getElementById('poolpm-btn');

    if (container.style.display === 'none') {
        // Show preview
        buildMetadataPreview();
        container.style.display = 'block';
        copyBtn.style.display = 'inline-block';
        poolpmBtn.style.display = 'inline-block';
        btn.textContent = '🔼 Hide Preview';
    } else {
        // Hide preview
        container.style.display = 'none';
        copyBtn.style.display = 'none';
        poolpmBtn.style.display = 'none';
        btn.textContent = '🔍 Preview Metadata JSON';
    }
}

function buildMetadataPreview() {
    // Get form values
    const title = jQuery('#cardanonftname').val() || 'Collection Name';
    const policyId = jQuery('#cardanonftpolicyid').val() || 'policy_id_not_generated_yet';

    // Get IPFS hash (check manual first, then Pinata, then WordPress)
    let imageHash = jQuery('#ipfs_cid_manual').val() || jQuery('#ipfs_cid').val();
    if (!imageHash) {
        const imageId = jQuery('#cardanonftimage').val();
        imageHash = imageId ? 'wordpress_image_' + imageId : 'no_image_uploaded';
    }

    // Build image URL
    const imageUrl = imageHash.startsWith('Qm') ? `ipfs://${imageHash}` : `ipfs://${imageHash}`;

    // Get metadata attributes from table
    const customMetadata = {};
    jQuery('.metadata-row').each(function() {
        const key = jQuery(this).find('.metadata-key').val().trim();
        const value = jQuery(this).find('.metadata-value').val().trim();
        if (key && value) {
            customMetadata[key] = value;
        }
    });

    // Determine media type from the uploaded WP attachment (falls back to image/png)
    const mediaType = jQuery('#cardanonftimagemime').val() || 'image/png';

    // Build the asset name (use from metadata 'name' or fallback to title)
    const assetName = customMetadata.name || title;

    // Build CIP-25 metadata structure
    const metadata = {
        "721": {
            [policyId]: {
                [assetName]: {
                    ...customMetadata, // Spread custom attributes first
                    "image": imageUrl,
                    "mediaType": mediaType,
                    "files": [
                        {
                            "name": assetName,
                            "mediaType": mediaType,
                            "src": imageUrl
                        }
                    ]
                }
            }
        }
    };

    // Pretty print JSON
    const jsonString = JSON.stringify(metadata, null, 2);
    document.getElementById('metadata-preview-json').textContent = jsonString;
}

function copyMetadataToClipboard() {
    const jsonText = document.getElementById('metadata-preview-json').textContent;
    navigator.clipboard.writeText(jsonText).then(function() {
        alert('✅ Metadata JSON copied to clipboard!');
    }, function(err) {
        console.error('Failed to copy: ', err);
        alert('❌ Failed to copy. Please copy manually.');
    });
}

function viewOnPoolPm() {
    const jsonText = document.getElementById('metadata-preview-json').textContent;

    // URL encode the JSON for Pool.pm
    const encodedMetadata = encodeURIComponent(jsonText);
    const poolpmUrl = `https://pool.pm/test/metadata?metadata=${encodedMetadata}`;

    // Open in new tab
    window.open(poolpmUrl, '_blank');
}

function copyPolicyJson() {
    const policyJsonDisplay = document.getElementById('policy-json-display');
    policyJsonDisplay.select();
    policyJsonDisplay.setSelectionRange(0, 99999);
    document.execCommand('copy');
    
    // Visual feedback
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '✅ Copied!';
    btn.style.background = '#28a745';
    btn.style.color = 'white';
    
    setTimeout(() => {
        btn.textContent = originalText;
        btn.style.background = '';
        btn.style.color = '';
    }, 2000);
}
</script>