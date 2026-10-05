<?php
if (!defined('ABSPATH')) exit;
// Expect $mints (array of mints); $slots_left (int); $max_slots (int); $admin_url (string)
if (!isset($mints) || !is_array($mints)) $mints = [];
if (!isset($slots_left)) $slots_left = 0;
if (!isset($max_slots)) $max_slots = 0;
if (!isset($admin_url)) $admin_url = admin_url();

// Group mints by policyid
$policies = [];
foreach ($mints as $mint) {
    $policyId = $mint['policyid'];
    if (!isset($policies[$policyId])) {
        $policies[$policyId] = [
            'policy_data' => null,
            'assets' => []
        ];
    }

    // First asset becomes the policy data
    if ($policies[$policyId]['policy_data'] === null) {
        $policies[$policyId]['policy_data'] = $mint;
    }

    $policies[$policyId]['assets'][] = $mint;
}
?>

<style>
.policy-group {
    margin-bottom: 30px;
    border: 2px solid #c3c4c7;
    border-radius: 8px;
    overflow: hidden;
}

.policy-header {
    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
    color: white;
    padding: 15px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
}

.policy-header:hover {
    background: linear-gradient(135deg, #135e96 0%, #0d4972 100%);
}

.policy-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

.policy-meta {
    font-size: 12px;
    opacity: 0.9;
    margin-top: 5px;
}

.policy-id-display {
    font-family: monospace;
    font-size: 11px;
    background: rgba(255,255,255,0.2);
    padding: 3px 8px;
    border-radius: 4px;
    margin-left: 10px;
}

.policy-stats {
    display: flex;
    gap: 20px;
    align-items: center;
}

.policy-stat {
    text-align: center;
}

.policy-stat-value {
    font-size: 20px;
    font-weight: bold;
}

.policy-stat-label {
    font-size: 11px;
    opacity: 0.9;
}

.policy-content {
    display: none;
    background: #f9f9f9;
    padding: 0;
}

.policy-content.active {
    display: block;
}

.assets-table {
    width: 100%;
    background: white;
    border-collapse: collapse;
}

.assets-table thead {
    background: #f0f0f1;
}

.assets-table th {
    padding: 12px 10px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    border-bottom: 2px solid #c3c4c7;
}

.assets-table td {
    padding: 10px;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: middle;
}

.assets-table tbody tr:hover {
    background: #f6f7f7;
}

.variant-badge {
    display: inline-block;
    background: #2271b1;
    color: white;
    padding: 3px 10px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 12px;
    min-width: 30px;
    text-align: center;
}

.quantity-display {
    font-size: 14px;
    font-weight: 600;
}

.quantity-bar {
    height: 6px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 4px;
}

.quantity-fill {
    height: 100%;
    background: linear-gradient(90deg, #53eb8e 0%, #2ea968 100%);
    transition: width 0.3s ease;
}

.policy-json-btn {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.4);
    color: white;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    margin-left: 10px;
}

.policy-json-btn:hover {
    background: rgba(255,255,255,0.3);
}

.expand-icon {
    transition: transform 0.3s ease;
    font-size: 18px;
    margin-left: 10px;
}

.expand-icon.rotated {
    transform: rotate(180deg);
}
</style>

<div class="wrap">
    <h1>Active Mints</h1>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
        <div class="notice notice-success"><p>Record(s) deleted successfully.</p></div>
    <?php endif; ?>

    <div style="background: #f0f0f1; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
        <h3 style="margin: 0 0 5px 0;">
            <span style="color: #2271b1; font-size: 24px;"><?php echo esc_html($slots_left); ?></span> / <?php echo esc_html($max_slots); ?> policy slots remaining
        </h3>
        <p style="margin: 0; color: #666; font-size: 13px;">
            Each unique policy counts as one slot
            <?php if (isset($archived_count) && $archived_count > 0): ?>
                | <a href="#archived-policies" style="color: #2271b1;"><?php echo esc_html($archived_count); ?> archived</a>
            <?php endif; ?>
        </p>
    </div>

    <?php if (empty($policies)): ?>
        <?php
        // Check if there's no active wallet vs no mints
        $network = get_option('cardano-mint-networkenvironment', 'preprod');
        $active_wallet = CardanoMintPay\Models\MintModel::getActivePolicyWallet($network);
        ?>
        <div style="text-align: center; padding: 60px 20px; background: white; border: 2px dashed #c3c4c7; border-radius: 8px;">
            <?php if (!$active_wallet): ?>
                <p style="font-size: 18px; color: #d63638; margin: 0 0 15px 0;">⚠️ No Active Policy Wallet</p>
                <p style="color: #666; margin: 0 0 20px 0;">You need an active policy wallet to view and create mints.</p>
                <p style="margin: 0;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cardano-policy-wallet')); ?>" class="button button-primary">
                        Go to Policy Wallet Manager
                    </a>
                </p>
            <?php else: ?>
                <p style="font-size: 18px; color: #666; margin: 0 0 15px 0;">No mints for this wallet yet</p>
                <p style="margin: 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-1')); ?>" class="button button-primary">Create Your First Mint</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <?php foreach ($policies as $policyId => $policyGroup): ?>
            <?php
            $policy = $policyGroup['policy_data'];
            $assets = $policyGroup['assets'];
            $totalAssets = count($assets);
            $totalQuantity = array_sum(array_column($assets, 'quantity_total'));
            $totalMinted = array_sum(array_column($assets, 'quantity_minted'));
            $percentMinted = $totalQuantity > 0 ? ($totalMinted / $totalQuantity) * 100 : 0;
            $uniqueMinters = \CardanoMintPay\Models\MintModel::getUniqueMinterCount($policyId);
            ?>

            <div class="policy-group">
                <div class="policy-header" onclick="togglePolicy('policy-<?php echo esc_attr($policy['id']); ?>')">
                    <div>
                        <h3 class="policy-title">
                            <?php echo esc_html($policy['title']); ?>
                            <span class="policy-id-display"><?php echo esc_html(substr($policyId, 0, 20)); ?>...</span>
                            <button type="button" class="policy-json-btn" onclick="event.stopPropagation(); viewPolicyJson(<?php echo esc_attr($policy['id']); ?>)">
                                View Policy JSON
                            </button>
                            <button type="button" class="button button-secondary" onclick="event.stopPropagation(); archivePolicy('<?php echo esc_js($policyId); ?>', '<?php echo esc_js($policy['title']); ?>')" style="margin-left: 10px; background: #f97316; color: white; border-color: #f97316;">
                                📦 Archive
                            </button>
                        </h3>
                        <div class="policy-meta">
                            Expires: <?php echo esc_html(gmdate('F j, Y g:i A', strtotime($policy['expirationdate']))); ?>
                            <?php if ($policy['unlimited']): ?>
                                | <span style="color: #53eb8e;">Unlimited Minting</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="policy-stats">
                        <div class="policy-stat">
                            <div class="policy-stat-value"><?php echo esc_html($totalAssets); ?></div>
                            <div class="policy-stat-label">Variant<?php echo $totalAssets != 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="policy-stat">
                            <div class="policy-stat-value"><?php echo esc_html($totalMinted); ?> / <?php echo esc_html($totalQuantity); ?></div>
                            <div class="policy-stat-label">Minted</div>
                        </div>
                        <div class="policy-stat">
                            <div class="policy-stat-value"><?php echo esc_html($uniqueMinters); ?></div>
                            <div class="policy-stat-label">Unique Minters</div>
                        </div>
                        <span class="expand-icon" id="icon-policy-<?php echo esc_attr($policy['id']); ?>">▼</span>
                    </div>
                </div>

                <!-- Mint Tracking Actions -->
                <div class="mint-tracking-actions" style="background: #f9f9f9; padding: 12px 20px; border-bottom: 1px solid #c3c4c7; display: flex; gap: 10px; align-items: center;">
                    <span style="font-size: 13px; color: #666; font-weight: 600; margin-right: 10px;">📊 Mint Tracking:</span>
                    <button type="button" class="button button-small" onclick="exportMintHistory('<?php echo esc_js($policyId); ?>', '<?php echo esc_js($policy['title']); ?>')">
                        📥 Export CSV
                    </button>
                    <button type="button" class="button button-small" onclick="document.getElementById('csv-upload-<?php echo esc_attr($policyId); ?>').click();">
                        📤 Import CSV
                    </button>
                    <input type="file" id="csv-upload-<?php echo esc_attr($policyId); ?>" accept=".csv" style="display: none;" onchange="importMintWhitelist('<?php echo esc_js($policyId); ?>', this)">
                    <button type="button" class="button button-small" onclick="viewMintHistory('<?php echo esc_js($policyId); ?>', '<?php echo esc_js($policy['title']); ?>')">
                        📋 View Mint History
                    </button>
                    <?php if ($uniqueMinters > 0): ?>
                        <span style="margin-left: auto; font-size: 12px; color: #2271b1;">
                            <strong><?php echo esc_html($uniqueMinters); ?></strong> unique wallet<?php echo $uniqueMinters != 1 ? 's' : ''; ?> tracked
                        </span>
                    <?php endif; ?>
                </div>

                <div class="policy-content" id="policy-<?php echo esc_attr($policy['id']); ?>">
                    <table class="assets-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th style="width: 80px;">Variant</th>
                                <th style="width: 100px;">NFT Image</th>
                                <th style="width: 100px;">Collection Image</th>
                                <th>Asset Name</th>
                                <th style="width: 150px;">Metadata</th>
                                <th style="width: 120px;">Quantity</th>
                                <th style="width: 100px;">Price (USD)</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $asset): ?>
                                <?php
                                $remaining = $asset['quantity_total'] - $asset['quantity_minted'];
                                $percentComplete = $asset['quantity_total'] > 0 ? ($asset['quantity_minted'] / $asset['quantity_total']) * 100 : 0;
                                $collectionDisplay = $asset['collection_id'] . ($asset['variant'] ? '-' . $asset['variant'] : '');
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($asset['id']); ?></strong></td>
                                    <td>
                                        <span class="variant-badge">
                                            <?php echo esc_html($collectionDisplay); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $display_image_url = null;
                                        $display_image_mime = null;
                                        $border_color = '#2271b1'; // Default blue for WordPress uploads

                                        // Priority: Manual IPFS > Pinata IPFS > WordPress
                                        if (!empty($asset['ipfs_cid_manual'])) {
                                            $display_image_url = 'https://ipfs.io/ipfs/' . $asset['ipfs_cid_manual'];
                                            $border_color = '#7c3aed'; // Purple for manual IPFS
                                        } elseif (!empty($asset['ipfs_cid'])) {
                                            $display_image_url = 'https://ipfs.io/ipfs/' . $asset['ipfs_cid'];
                                            $border_color = '#10b981'; // Green for Pinata
                                        } elseif (!empty($asset['image_id'])) {
                                            $display_image_url = wp_get_attachment_url($asset['image_id']);
                                            $display_image_mime = get_post_mime_type($asset['image_id']);
                                            $border_color = '#2271b1'; // Blue for WordPress
                                        } elseif (!empty($asset['nft_metadata'])) {
                                            // Imported (verbatim) assets carry the image as an ipfs:// URI
                                            // inside the metadata, with no WordPress attachment.
                                            $meta_for_img = json_decode($asset['nft_metadata'], true);
                                            if (is_array($meta_for_img) && !empty($meta_for_img['image'])) {
                                                // CIP-25 image may be a single string or an array of <=64-char chunks.
                                                $img_uri = is_array($meta_for_img['image']) ? implode('', $meta_for_img['image']) : (string) $meta_for_img['image'];
                                                $display_image_url = (strpos($img_uri, 'ipfs://') === 0)
                                                    ? 'https://ipfs.io/ipfs/' . substr($img_uri, 7)
                                                    : $img_uri;
                                                if (!empty($meta_for_img['mediaType'])) {
                                                    $display_image_mime = (string) $meta_for_img['mediaType'];
                                                }
                                                $border_color = '#c9a84c'; // Gold for imported/verbatim
                                            }
                                        }

                                        $is_video = $display_image_mime && strpos($display_image_mime, 'video/') === 0;

                                        if ($display_image_url) {
                                            if ($is_video) {
                                                echo '<video src="' . esc_url($display_image_url) . '" muted loop playsinline preload="metadata" style="width: 70px; height: 70px; object-fit: cover; border-radius: 6px; border: 2px solid ' . esc_attr($border_color) . ';" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0;"></video>';
                                            } else {
                                                echo '<img src="' . esc_url($display_image_url) . '" style="width: 70px; height: 70px; object-fit: cover; border-radius: 6px; border: 2px solid ' . esc_attr($border_color) . ';" alt="' . esc_attr($asset['asset_name'] ?: $asset['title']) . '" />';
                                            }
                                        } else {
                                            echo '<div style="width: 70px; height: 70px; background: #f1f1f1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #666; font-size: 11px;">No Image</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($asset['collection_image_id'])) {
                                            $collection_image_url = wp_get_attachment_url($asset['collection_image_id']);
                                            $collection_image_mime = get_post_mime_type($asset['collection_image_id']);
                                            $collection_is_video = $collection_image_mime && strpos($collection_image_mime, 'video/') === 0;
                                            if ($collection_image_url) {
                                                echo '<div style="position: relative;">';
                                                if ($collection_is_video) {
                                                    echo '<video src="' . esc_url($collection_image_url) . '" muted loop playsinline preload="metadata" style="width: 70px; height: 70px; object-fit: cover; border-radius: 6px; border: 2px solid #2DB0B8;" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0;"></video>';
                                                } else {
                                                    echo '<img src="' . esc_url($collection_image_url) . '" style="width: 70px; height: 70px; object-fit: cover; border-radius: 6px; border: 2px solid #2DB0B8;" alt="Collection Image" />';
                                                }
                                                echo '<div style="position: absolute; top: -5px; right: -5px; background: #2DB0B8; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;" title="Mystery Box">🎁</div>';
                                                echo '</div>';
                                            } else {
                                                echo '<div style="width: 70px; height: 70px; background: #f1f1f1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #666; font-size: 11px;">None</div>';
                                            }
                                        } else {
                                            echo '<div style="width: 70px; height: 70px; background: #f1f1f1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #666; font-size: 11px;">None</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($asset['asset_name'] ?: $asset['title']); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($asset['nft_metadata'])) {
                                            $metadata = json_decode($asset['nft_metadata'], true);
                                            if ($metadata && count($metadata) > 0) {
                                                echo '<button type="button" class="button button-small" onclick="viewMetadata(' . intval($asset['id']) . ')" style="font-size: 11px; padding: 3px 8px;">View (' . count($metadata) . ')</button>';
                                            } else {
                                                echo '<span style="color: #999; font-size: 12px;">None</span>';
                                            }
                                        } else {
                                            echo '<span style="color: #999; font-size: 12px;">None</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="quantity-display" style="color: <?php echo $remaining > 0 ? '#2ea968' : '#d63638'; ?>;">
                                            <?php echo esc_html($remaining); ?> / <?php echo esc_html($asset['quantity_total']); ?>
                                            <span style="font-size: 11px; color: #666;">remaining</span>
                                        </div>
                                        <div class="quantity-bar">
                                            <div class="quantity-fill" style="width: <?php echo esc_attr($percentComplete); ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color: #2271b1; font-size: 15px;">$<?php echo esc_html(number_format($asset['price'] ?? 0, 2)); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        if ($asset['status'] === 'Active') {
                                            echo '<span style="color: #2ea968; font-weight: 600;">● Active</span>';
                                        } else {
                                            echo '<span style="color: #666;">○ ' . esc_html($asset['status']) . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-1&edit=' . intval($asset['id']))); ?>"
                                           style="margin-right: 5px;">
                                            Edit
                                        </a>
                                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-2&deletemint=' . intval($asset['id']))); ?>"
                                           onclick="return confirm('Are you sure you want to delete this asset? This action cannot be undone.');"
                                           style="color: #d63638;">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
            <strong>⚠️ Danger Zone:</strong>
            <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-2&deleteallmints=1')); ?>"
               onclick="return confirm('Are you sure you want to delete ALL active mints and their variants? This action cannot be undone.');"
               style="color: #d63638; margin-left: 15px;">
                Delete All Mints
            </a>
        </div>

        <!-- Archived Policies Section -->
        <?php
        $archived_policies = CardanoMintPay\Models\MintModel::get_archived_policies();
        $archived_count = CardanoMintPay\Models\MintModel::count_archived_policies();

        // Group archived by policy ID
        $archived_groups = [];
        foreach ($archived_policies as $archived) {
            $pid = $archived['policyid'];
            if (!isset($archived_groups[$pid])) {
                $archived_groups[$pid] = [
                    'policy_data' => $archived,
                    'assets' => []
                ];
            }
            $archived_groups[$pid]['assets'][] = $archived;
        }
        ?>

        <?php if ($archived_count > 0): ?>
        <div id="archived-policies" style="margin-top: 30px; padding: 20px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer;" onclick="toggleArchivedSection()">
            <h2 style="margin: 0 0 15px 0; display: flex; align-items: center; justify-content: space-between;">
                <span>📦 Archived Policies (<?php echo esc_html($archived_count); ?> / 100 max)</span>
                <span id="archived-toggle-icon" style="font-size: 18px;">▼</span>
            </h2>
            <p style="margin: 0 0 15px 0; color: #666; font-size: 13px;">
                Archived policies are hidden from active mints but preserved for future use.
            </p>

            <div id="archived-content" style="display: none;">
                <?php foreach ($archived_groups as $policyId => $group): ?>
                    <?php
                    $policy = $group['policy_data'];
                    $assets = $group['assets'];
                    $totalVariants = count($assets);
                    $totalMinted = array_sum(array_column($assets, 'quantity_minted'));
                    $totalQuantity = array_sum(array_column($assets, 'quantity_total'));
                    ?>

                    <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 6px; border: 1px solid #ddd;">
                        <h3 style="margin: 0 0 10px 0; color: #374151;">
                            📋 <?php echo esc_html($policy['title']); ?>
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <div>
                                <strong>Policy ID:</strong><br>
                                <span style="font-family: monospace; font-size: 11px; color: #666;" title="<?php echo esc_attr($policyId); ?>">
                                    <?php echo esc_html(substr($policyId, 0, 20)); ?>...
                                </span>
                            </div>
                            <div>
                                <strong>Variants:</strong> <?php echo esc_html($totalVariants); ?>
                                (<?php
                                    $variantNames = array_map(function($a) {
                                        return $a['variant'] ?? 'A';
                                    }, $assets);
                                    echo esc_html(implode(', ', $variantNames));
                                ?>)
                            </div>
                            <div>
                                <strong>Total Minted:</strong> <?php echo esc_html($totalMinted); ?> / <?php echo esc_html($totalQuantity); ?>
                            </div>
                            <div>
                                <strong>Archived:</strong> <?php echo esc_html(current_time('M j, Y')); ?>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="button button-primary" onclick="unarchivePolicy('<?php echo esc_js($policyId); ?>', '<?php echo esc_js($policy['title']); ?>')">
                                ↑ Unarchive
                            </button>
                            <button type="button" class="button button-secondary" onclick="toggleArchivedDetails('archived-<?php echo esc_attr($policy['id']); ?>')">
                                👁 View Details
                            </button>
                            <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-2&deletemint=' . intval($policy['id']))); ?>"
                               onclick="return confirm('⚠️ Permanently delete this archived policy?\n\nThis will delete ALL variants and cannot be undone.');"
                               style="color: #d63638;">
                                🗑 Delete Permanently
                            </a>
                        </div>

                        <div id="archived-<?php echo esc_attr($policy['id']); ?>" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                            <h4 style="margin: 0 0 10px 0;">Variants (Read-Only)</h4>
                            <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                                <thead style="background: #f3f4f6;">
                                    <tr>
                                        <th style="padding: 8px; text-align: left;">Variant</th>
                                        <th style="padding: 8px; text-align: left;">Asset Name</th>
                                        <th style="padding: 8px; text-align: left;">Quantity</th>
                                        <th style="padding: 8px; text-align: left;">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assets as $asset): ?>
                                    <tr style="border-bottom: 1px solid #e5e7eb;">
                                        <td style="padding: 8px;"><?php echo esc_html($asset['variant'] ?? 'A'); ?></td>
                                        <td style="padding: 8px;"><?php echo esc_html($asset['asset_name'] ?: $asset['title']); ?></td>
                                        <td style="padding: 8px;"><?php echo esc_html($asset['quantity_minted']); ?> / <?php echo esc_html($asset['quantity_total']); ?></td>
                                        <td style="padding: 8px;">$<?php echo esc_html(number_format($asset['price'] ?? 0, 2)); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Orphaned Policies Section (from deleted/other wallets) -->
        <?php
        // Group orphaned mints by policy
        $orphaned_policies = [];
        if (isset($orphaned_mints) && !empty($orphaned_mints)) {
            foreach ($orphaned_mints as $mint) {
                $pid = $mint['policyid'];
                if (!isset($orphaned_policies[$pid])) {
                    $orphaned_policies[$pid] = [
                        'policy_data' => $mint,
                        'assets' => []
                    ];
                }
                $orphaned_policies[$pid]['assets'][] = $mint;
            }
        }
        ?>

        <?php if (!empty($orphaned_policies)): ?>
        <div id="orphaned-policies" style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
            <h2 style="margin: 0 0 15px 0; color: #856404;">
                ⚠️ Orphaned Policies (<?php echo count($orphaned_policies); ?>) - Using <?php echo count($orphaned_policies); ?> slot(s)
            </h2>
            <p style="margin: 0 0 20px 0; color: #856404; font-size: 13px;">
                These policies were created with a different or deleted policy wallet. They are using up your slot allocation but cannot be minted with the current active wallet. You should delete them to free up slots.
            </p>

            <?php foreach ($orphaned_policies as $policyId => $policyGroup): ?>
                <?php
                $policy = $policyGroup['policy_data'];
                $assets = $policyGroup['assets'];
                $totalVariants = count($assets);
                $mint_keyhash = CardanoMintPay\Models\MintModel::extractKeyhashFromPolicyJson($policy['policy_json']);
                ?>

                <div style="background: white; padding: 15px; margin-bottom: 10px; border-radius: 6px; border: 1px solid #ffc107;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h3 style="margin: 0 0 5px 0; color: #374151;">
                                📋 <?php echo esc_html($policy['title']); ?>
                            </h3>
                            <p style="margin: 0; font-size: 12px; color: #666;">
                                Policy ID: <code style="font-size: 11px;"><?php echo esc_html(substr($policyId, 0, 30)); ?>...</code>
                                | Wallet KeyHash: <code style="font-size: 11px;"><?php echo esc_html($mint_keyhash ? substr($mint_keyhash, 0, 20) . '...' : 'Unknown'); ?></code>
                                | Variants: <?php echo esc_html($totalVariants); ?>
                            </p>
                        </div>
                        <div>
                            <a class="button button-secondary"
                               href="<?php echo esc_url(admin_url('admin.php?page=cardano-mint-page-2&deletemint=' . intval($policy['id']))); ?>"
                               onclick="return confirm('⚠️ Delete this orphaned policy?\n\nThis will delete ALL <?php echo esc_js($totalVariants); ?> variant(s) and free up 1 slot.\n\nThis cannot be undone.');"
                               style="color: #d63638;">
                                🗑 Delete Policy
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Policy JSON Modal -->
    <div id="policy-json-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <h2 style="margin-top: 0;">Policy JSON</h2>
            <p style="color: #666;">Copy this JSON for your records. You'll need it to mint NFTs with this policy.</p>
            <textarea id="policy-json-display" readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; padding: 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;"></textarea>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="button" class="button button-primary" onclick="copyPolicyJsonModal()">Copy to Clipboard</button>
                <button type="button" class="button" onclick="closePolicyJsonModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Metadata Modal -->
    <div id="metadata-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <h2 style="margin-top: 0;">NFT Metadata (CIP-25)</h2>
            <div id="metadata-display" style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                <!-- Metadata will be displayed here -->
            </div>
            <div style="margin-top: 15px;">
                <button type="button" class="button" onclick="closeMetadataModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Mint History Modal -->
    <div id="mint-history-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 900px; width: 95%; max-height: 85vh; overflow-y: auto;">
            <h2 style="margin-top: 0;" id="mint-history-title">Mint History</h2>
            <div id="mint-history-loading" style="text-align: center; padding: 40px; color: #666;">
                <p>Loading mint history...</p>
            </div>
            <div id="mint-history-content" style="display: none;">
                <!-- History table will be displayed here -->
            </div>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="button" class="button button-primary" onclick="exportMintHistoryFromModal()">📥 Export CSV</button>
                <button type="button" class="button" onclick="closeMintHistoryModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// WordPress AJAX nonces for mint tracking
const cardanoMintNonces = {
    export: '<?php echo esc_js(wp_create_nonce('cardano_export_mint_history')); ?>',
    import: '<?php echo esc_js(wp_create_nonce('cardano_import_mint_whitelist')); ?>',
    history: '<?php echo esc_js(wp_create_nonce('cardano_get_mint_history')); ?>'
};

function togglePolicy(policyId) {
    const content = document.getElementById(policyId);
    const icon = document.getElementById('icon-' + policyId);

    if (content.classList.contains('active')) {
        content.classList.remove('active');
        icon.classList.remove('rotated');
    } else {
        content.classList.add('active');
        icon.classList.add('rotated');
    }
}

function viewPolicyJson(assetId) {
    // Find the policy JSON from the assets data
    <?php foreach ($policies as $policyId => $policyGroup): ?>
        <?php foreach ($policyGroup['assets'] as $asset): ?>
            if (<?php echo intval($asset['id']); ?> === assetId) {
                const policyJson = <?php echo json_encode($asset['policy_json'] ?: '{}'); ?>;
                document.getElementById('policy-json-display').value = JSON.stringify(JSON.parse(policyJson), null, 2);
                document.getElementById('policy-json-modal').style.display = 'flex';
                return;
            }
        <?php endforeach; ?>
    <?php endforeach; ?>
}

function closePolicyJsonModal() {
    document.getElementById('policy-json-modal').style.display = 'none';
}

function copyPolicyJsonModal() {
    const textarea = document.getElementById('policy-json-display');
    textarea.select();
    document.execCommand('copy');
    alert('Policy JSON copied to clipboard!');
}

// Close modal when clicking outside
document.getElementById('policy-json-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePolicyJsonModal();
    }
});

function viewMetadata(assetId) {
    // Find the metadata from the assets data
    <?php foreach ($policies as $policyId => $policyGroup): ?>
        <?php foreach ($policyGroup['assets'] as $asset): ?>
            if (<?php echo intval($asset['id']); ?> === assetId) {
                const metadataJson = <?php echo json_encode($asset['nft_metadata'] ?: '{}'); ?>;
                const metadata = JSON.parse(metadataJson);

                let html = '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead style="background: #2271b1; color: white;"><tr><th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Attribute</th><th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Value</th></tr></thead>';
                html += '<tbody>';

                for (const [key, value] of Object.entries(metadata)) {
                    // Nested values (files[], attributes{}, description[]) must be
                    // rendered as readable JSON, not String(value) — which would
                    // print "[object Object]" / comma-joined arrays.
                    var cell;
                    if (value !== null && typeof value === 'object') {
                        cell = '<pre style="margin:0; white-space:pre-wrap; word-break:break-word; font-size:11px;">' + escapeHtml(JSON.stringify(value, null, 2)) + '</pre>';
                    } else {
                        cell = escapeHtml(String(value));
                    }
                    html += '<tr>';
                    html += '<td style="padding: 10px; border: 1px solid #ddd; font-weight: 600; background: #fff; vertical-align: top;">' + escapeHtml(key) + '</td>';
                    html += '<td style="padding: 10px; border: 1px solid #ddd; background: #fff; font-family: monospace; font-size: 12px;">' + cell + '</td>';
                    html += '</tr>';
                }

                html += '</tbody></table>';

                document.getElementById('metadata-display').innerHTML = html;
                document.getElementById('metadata-modal').style.display = 'flex';
                return;
            }
        <?php endforeach; ?>
    <?php endforeach; ?>
}

function closeMetadataModal() {
    document.getElementById('metadata-modal').style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close metadata modal when clicking outside
document.getElementById('metadata-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeMetadataModal();
    }
});

// ============================================
// Mint Tracking Functions
// ============================================

let currentPolicyForExport = '';
let currentMintTitleForExport = '';

function exportMintHistory(policyId, mintTitle) {
    currentPolicyForExport = policyId;
    currentMintTitleForExport = mintTitle;

    const formData = new FormData();
    formData.append('action', 'cardano_export_mint_history');
    formData.append('nonce', cardanoMintNonces.export);
    formData.append('policy_id', policyId);
    formData.append('mint_title', mintTitle);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Create download link
            const blob = new Blob([data.data.csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.data.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            alert('✅ CSV exported successfully! Check your downloads folder.');
        } else {
            alert('❌ Error: ' + (data.data?.message || 'Failed to export CSV'));
        }
    })
    .catch(error => {
        console.error('Export error:', error);
        alert('❌ Network error while exporting CSV');
    });
}

function importMintWhitelist(policyId, fileInput) {
    const file = fileInput.files[0];
    if (!file) return;

    if (!file.name.endsWith('.csv')) {
        alert('❌ Please select a CSV file');
        fileInput.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const csvContent = e.target.result;

        const formData = new FormData();
        formData.append('action', 'cardano_import_mint_whitelist');
        formData.append('nonce', cardanoMintNonces.import);
        formData.append('policy_id', policyId);
        formData.append('csv_content', csvContent);

        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ CSV imported successfully!\n\n' +
                      'Imported: ' + data.data.imported + ' wallet record(s)\n' +
                      (data.data.errors.length > 0 ? '\nWarnings:\n' + data.data.errors.join('\n') : ''));

                // Reload page to update unique minters count
                location.reload();
            } else {
                alert('❌ Error: ' + (data.data?.message || 'Failed to import CSV'));
            }
        })
        .catch(error => {
            console.error('Import error:', error);
            alert('❌ Network error while importing CSV');
        });

        fileInput.value = '';
    };

    reader.readAsText(file);
}

function viewMintHistory(policyId, mintTitle) {
    currentPolicyForExport = policyId;
    currentMintTitleForExport = mintTitle;

    document.getElementById('mint-history-title').textContent = 'Mint History: ' + mintTitle;
    document.getElementById('mint-history-loading').style.display = 'block';
    document.getElementById('mint-history-content').style.display = 'none';
    document.getElementById('mint-history-modal').style.display = 'flex';

    const formData = new FormData();
    formData.append('action', 'cardano_get_mint_history');
    formData.append('nonce', cardanoMintNonces.history);
    formData.append('policy_id', policyId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('mint-history-loading').style.display = 'none';

        if (data.success) {
            const history = data.data.history;
            const uniqueMintersCount = data.data.unique_minters;

            if (history.length === 0) {
                document.getElementById('mint-history-content').innerHTML =
                    '<div style="text-align: center; padding: 40px; color: #666;">' +
                    '<p>No mints recorded yet for this policy.</p>' +
                    '</div>';
            } else {
                let html = '<div style="margin-bottom: 15px; padding: 10px; background: #e7f3ff; border-left: 4px solid #2271b1; border-radius: 4px;">';
                html += '<strong>Total Unique Minters:</strong> ' + uniqueMintersCount;
                html += ' | <strong>Total Records:</strong> ' + history.length;
                html += '</div>';

                html += '<div style="overflow-x: auto;">';
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';
                html += '<thead style="background: #2271b1; color: white;">';
                html += '<tr>';
                html += '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Payment Address</th>';
                html += '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Stake Address</th>';
                html += '<th style="padding: 10px; text-align: center; border: 1px solid #ddd;">Total Allowed</th>';
                html += '<th style="padding: 10px; text-align: center; border: 1px solid #ddd;">Total Minted</th>';
                html += '<th style="padding: 10px; text-align: center; border: 1px solid #ddd;">Remaining</th>';
                html += '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">First Mint</th>';
                html += '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Last Mint</th>';
                html += '</tr>';
                html += '</thead><tbody>';

                history.forEach((record, index) => {
                    const bgColor = index % 2 === 0 ? '#fff' : '#f9f9f9';
                    html += '<tr style="background: ' + bgColor + ';">';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; font-family: monospace; font-size: 11px;">' +
                            escapeHtml(record.payment_address.substring(0, 20) + '...') + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; font-family: monospace; font-size: 11px;">' +
                            (record.stake_address ? escapeHtml(record.stake_address.substring(0, 20) + '...') : '<em style="color: #999;">N/A</em>') + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: 600;">' +
                            (record.total_allowed === 0 ? '<span style="color: #2ea968;">Unlimited</span>' : record.total_allowed) + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: 600; color: #2271b1;">' +
                            record.total_minted + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: 600;">' +
                            (record.remaining === -1 ? '<span style="color: #2ea968;">∞</span>' :
                             (record.remaining > 0 ? '<span style="color: #2ea968;">' + record.remaining + '</span>' :
                              '<span style="color: #d63638;">0</span>')) + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; font-size: 11px;">' +
                            (record.first_mint_date || '<em style="color: #999;">N/A</em>') + '</td>';
                    html += '<td style="padding: 8px; border: 1px solid #ddd; font-size: 11px;">' +
                            (record.last_mint_date || '<em style="color: #999;">N/A</em>') + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                document.getElementById('mint-history-content').innerHTML = html;
            }

            document.getElementById('mint-history-content').style.display = 'block';
        } else {
            document.getElementById('mint-history-content').innerHTML =
                '<div style="text-align: center; padding: 40px; color: #d63638;">' +
                '<p>❌ Error loading history: ' + escapeHtml(data.data?.message || 'Unknown error') + '</p>' +
                '</div>';
            document.getElementById('mint-history-content').style.display = 'block';
        }
    })
    .catch(error => {
        console.error('History error:', error);
        document.getElementById('mint-history-loading').style.display = 'none';
        document.getElementById('mint-history-content').innerHTML =
            '<div style="text-align: center; padding: 40px; color: #d63638;">' +
            '<p>❌ Network error while loading history</p>' +
            '</div>';
        document.getElementById('mint-history-content').style.display = 'block';
    });
}

function exportMintHistoryFromModal() {
    if (currentPolicyForExport && currentMintTitleForExport) {
        exportMintHistory(currentPolicyForExport, currentMintTitleForExport);
    }
}

function closeMintHistoryModal() {
    document.getElementById('mint-history-modal').style.display = 'none';
}

// Close mint history modal when clicking outside
document.getElementById('mint-history-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeMintHistoryModal();
    }
});

// ============================================
// Archive System Functions
// ============================================

function archivePolicy(policyId, policyTitle) {
    if (!confirm('Archive "' + policyTitle + '" and all its variants?\n\nThe policy will be hidden from active mints but can be restored later.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cardano_archive_policy');
    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
    formData.append('policy_id', policyId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Policy archived successfully!\n\n' + data.data.variants_archived + ' variant(s) archived.');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.data?.message || 'Failed to archive policy'));
        }
    })
    .catch(error => {
        console.error('Archive error:', error);
        alert('❌ Network error while archiving policy');
    });
}

function unarchivePolicy(policyId, policyTitle) {
    if (!confirm('Restore "' + policyTitle + '" to active mints?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cardano_unarchive_policy');
    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
    formData.append('policy_id', policyId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Policy unarchived successfully!\n\n' + data.data.variants_unarchived + ' variant(s) restored.');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.data?.message || 'Failed to unarchive policy'));
        }
    })
    .catch(error => {
        console.error('Unarchive error:', error);
        alert('❌ Network error while unarchiving policy');
    });
}

function toggleArchivedSection() {
    const content = document.getElementById('archived-content');
    const icon = document.getElementById('archived-toggle-icon');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▲';
    } else {
        content.style.display = 'none';
        icon.textContent = '▼';
    }
}

function toggleArchivedDetails(elementId) {
    const details = document.getElementById(elementId);
    const button = event.target;

    if (details.style.display === 'none') {
        details.style.display = 'block';
        button.textContent = '👁 Hide Details';
    } else {
        details.style.display = 'none';
        button.textContent = '👁 View Details';
    }
}
</script>
