<?php
/**
 * Policy Wallet Manager Admin Page
 * Allows admin to generate and manage secure policy wallets for minting
 */

if (!defined('ABSPATH')) {
    exit;
}

use CardanoMintPay\Models\MintModel;

// Get current network setting
$network = get_option('cardano-mint-networkenvironment', 'preprod');

// Get existing ACTIVE policy wallet for this network
$existing_wallet = MintModel::getActivePolicyWallet($network);

// Get archived wallets
$archived_wallets = MintModel::getArchivedPolicyWallets($network);
$archived_count = MintModel::countArchivedPolicyWallets($network);

// Check if we just created a wallet and need to display the mnemonic
$show_mnemonic = isset($_GET['created']) && $_GET['created'] == '1';
$mnemonic = $show_mnemonic ? get_transient('cardano_policy_wallet_mnemonic_' . get_current_user_id()) : false;

// Delete the transient after retrieving it (ONE-TIME view)
if ($mnemonic) {
    delete_transient('cardano_policy_wallet_mnemonic_' . get_current_user_id());
}

// Decrypt extended key for display (only if admin is viewing their own wallet)
$extended_key_hex = null;
if ($existing_wallet && current_user_can('manage_options')) {
    $extended_key_hex = \CardanoMintPay\Helpers\EncryptionHelper::decrypt($existing_wallet['skey_encrypted']);
}

?>

<div class="wrap">
    <h1>Policy Wallet Manager</h1>

    <script>
    // Copy functions for clipboard operations
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('✅ Address copied to clipboard!');
        }, function(err) {
            console.error('Failed to copy: ', err);
            alert('❌ Failed to copy address. Please copy manually.');
        });
    }

    function copyMnemonicToClipboard() {
        const mnemonicElements = document.querySelectorAll('.mnemonic-word');
        const words = Array.from(mnemonicElements).map(el => el.textContent.trim());
        const mnemonic = words.join(' ');

        navigator.clipboard.writeText(mnemonic).then(function() {
            alert('✅ Mnemonic copied! Store it safely offline.');
        }, function(err) {
            alert('❌ Failed to copy. Please copy manually from above.');
        });
    }

    // Toggle extended key visibility
    let extendedKeyRevealed = false;
    function toggleExtendedKey() {
        const display = document.getElementById('extended-key-display');
        const button = document.getElementById('toggle-extended-key');

        if (!extendedKeyRevealed) {
            display.style.filter = 'none';
            display.style.userSelect = 'text';
            button.textContent = '🙈 Hide Extended Key';
            extendedKeyRevealed = true;
        } else {
            display.style.filter = 'blur(8px)';
            display.style.userSelect = 'none';
            button.textContent = '👁️ Reveal Extended Key (128 chars)';
            extendedKeyRevealed = false;
        }
    }
    </script>

    <?php if ($mnemonic): ?>
        <!-- CRITICAL: Display Mnemonic ONE TIME -->
        <div class="notice notice-error" style="border-left: 4px solid #dc3545; background: #fff;">
            <h2 style="color: #dc3545; margin-top: 10px;">🔴 CRITICAL: SAVE YOUR RECOVERY PHRASE NOW!</h2>
            <p style="font-size: 16px;"><strong>⚠️ THIS WILL ONLY BE SHOWN ONCE! Make sure your screen is private!</strong></p>
            <p>Write down these 24 words in order and store them in a secure location. You will need this to recover your policy wallet if the database is lost.</p>

            <div style="background: #000; color: #0f0; padding: 20px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 18px; margin: 20px 0; user-select: all;">
                <?php
                $words = explode(' ', $mnemonic);
                $chunks = array_chunk($words, 6);
                foreach ($chunks as $chunk) {
                    echo esc_html(implode('  ', $chunk)) . '<br>';
                }
                ?>
            </div>

            <button type="button" class="button button-primary button-large" onclick="copyMnemonicText('<?php echo esc_js($mnemonic); ?>')">
                📋 Copy to Clipboard
            </button>

            <script>
            function copyMnemonicText(mnemonic) {
                navigator.clipboard.writeText(mnemonic).then(function() {
                    alert('✅ Mnemonic copied! Store it safely offline.');
                }, function(err) {
                    alert('❌ Failed to copy. Please copy manually from above.');
                });
            }
            </script>
        </div>
    <?php endif; ?>

    <div class="notice notice-info">
        <p><strong>What is a Policy Wallet?</strong></p>
        <p>A policy wallet is a secure server-side wallet used to sign minting transactions. This ensures only your server can authorize NFT mints under your policies, preventing unauthorized minting.</p>
        <p><strong>Current Network:</strong> <code><?php echo esc_html(strtoupper($network)); ?></code></p>
    </div>

    <?php if ($existing_wallet): ?>
        <!-- Existing Wallet Display -->
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>✅ Policy Wallet Active</h2>

            <table class="form-table">
                <tr>
                    <th>Wallet Name</th>
                    <td><?php echo esc_html($existing_wallet['wallet_name']); ?></td>
                </tr>
                <tr>
                    <th>Payment Address</th>
                    <td>
                        <code style="display: block; padding: 10px; background: #f0f0f0; border-radius: 4px; word-break: break-all;">
                            <?php echo esc_html($existing_wallet['payment_address']); ?>
                        </code>
                        <button type="button" class="button button-small" onclick="copyToClipboard('<?php echo esc_js($existing_wallet['payment_address']); ?>')">
                            Copy Address
                        </button>
                    </td>
                </tr>
                <tr>
                    <th>Payment KeyHash</th>
                    <td>
                        <code style="display: block; padding: 10px; background: #f0f0f0; border-radius: 4px; word-break: break-all;">
                            <?php echo esc_html($existing_wallet['payment_keyhash']); ?>
                        </code>
                        <p class="description">This keyhash is used in policy scripts to require your signature for minting.</p>
                    </td>
                </tr>
                <?php if ($extended_key_hex): ?>
                <tr>
                    <th>Extended Signing Key</th>
                    <td>
                        <div style="position: relative;">
                            <code id="extended-key-display" style="display: block; padding: 10px; background: #f0f0f0; border-radius: 4px; word-break: break-all; filter: blur(8px); user-select: none;">
                                <?php echo esc_html($extended_key_hex); ?>
                            </code>
                            <button type="button" id="toggle-extended-key" class="button button-small" style="margin-top: 10px;" onclick="toggleExtendedKey()">
                                👁️ Reveal Extended Key (128 chars)
                            </button>
                        </div>
                        <p class="description">
                            <strong>⚠️ Extended key for CIP-1852 signing.</strong>
                            This is your full 128-character extended signing key (kL||kR).
                            Keep this secret - it can sign transactions for this wallet.
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Network</th>
                    <td><code><?php echo esc_html(strtoupper($existing_wallet['network'])); ?></code></td>
                </tr>
                <tr>
                    <th>Created</th>
                    <td><?php echo esc_html($existing_wallet['created_at']); ?></td>
                </tr>
            </table>

            <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                <h3 style="margin-top: 0;">⚠️ Security Notice</h3>
                <p><strong>Your mnemonic phrase and signing keys are encrypted in the database.</strong></p>
                <p>Never share your policy wallet mnemonic or expose it publicly. This wallet authorizes all minting operations on your server.</p>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="button" class="button button-secondary" onclick="archivePolicyWallet(<?php echo esc_js($existing_wallet['id']); ?>, '<?php echo esc_js($existing_wallet['wallet_name']); ?>')" style="background: #f97316; color: white; border-color: #f97316;">
                    📦 Archive Wallet
                </button>
                <button type="button" class="button button-secondary" onclick="if(confirm('⚠️ Are you sure you want to DELETE this policy wallet?\n\nThis will permanently delete the wallet and you will NOT be able to mint policies created with this wallet.\n\nThis cannot be undone!')) document.getElementById('delete-wallet-form').submit();" style="color: #d63638;">
                    🗑️ Delete Policy Wallet
                </button>
            </div>

            <form id="delete-wallet-form" method="post" style="display: none;">
                <?php wp_nonce_field('cardano_delete_policy_wallet', 'delete_wallet_nonce'); ?>
                <input type="hidden" name="action" value="delete_policy_wallet">
                <input type="hidden" name="wallet_id" value="<?php echo esc_attr($existing_wallet['id']); ?>">
            </form>
        </div>

    <?php else: ?>
        <!-- Generate New Wallet Form -->
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>🔐 Generate Policy Wallet</h2>
            <p>Create a new secure policy wallet for <strong><?php echo esc_html(strtoupper($network)); ?></strong> network.</p>

            <form method="post" id="generate-wallet-form">
                <?php wp_nonce_field('cardano_generate_policy_wallet', 'generate_wallet_nonce'); ?>
                <input type="hidden" name="action" value="generate_policy_wallet">

                <table class="form-table">
                    <tr>
                        <th><label for="wallet_name">Wallet Name</label></th>
                        <td>
                            <input type="text" id="wallet_name" name="wallet_name" value="<?php echo esc_attr(ucfirst($network) . ' Policy Wallet'); ?>" class="regular-text">
                            <p class="description">A friendly name for this wallet (for your reference only)</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large" id="generate-btn">
                        🔑 Generate Secure Policy Wallet
                    </button>
                    <span id="generating-spinner" style="display: none; margin-left: 10px;">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        Generating wallet... (this may take a few seconds)
                    </span>
                </p>
            </form>

            <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
                <h3 style="margin-top: 0;">ℹ️ What happens when you generate a wallet?</h3>
                <ul style="margin-bottom: 0;">
                    <li>A 24-word mnemonic phrase is generated using cryptographic randomness</li>
                    <li>Payment and stake keys are derived using BIP39/CIP-1852 standards</li>
                    <li>The mnemonic and signing key are encrypted with WordPress salts</li>
                    <li>Encrypted data is stored securely in your database</li>
                    <li>The payment keyhash is used to create signature-required policies</li>
                </ul>
            </div>
        </div>

        <script>
        document.getElementById('generate-wallet-form').addEventListener('submit', function(e) {
            console.log('🔑 Policy Wallet Generation - Form submitted');
            console.log('Form action:', this.action);
            console.log('Form method:', this.method);
            console.log('Wallet name:', document.getElementById('wallet_name').value);

            document.getElementById('generate-btn').disabled = true;
            document.getElementById('generating-spinner').style.display = 'inline-block';

            console.log('🔄 Submit button disabled, spinner shown');
            console.log('⏳ Waiting for server response...');
        });

        // Log when page loads/reloads
        console.log('📄 Policy Wallet Manager page loaded');
        console.log('Current URL:', window.location.href);
        console.log('Has "created" param?', window.location.search.includes('created=1'));
        </script>

    <?php endif; ?>

    <!-- Advanced: Import External Keys Section -->
    <div style="margin-top: 30px; padding: 20px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 8px; max-width: 800px;">
        <div style="cursor: pointer;" onclick="toggleAdvancedImport()">
            <h2 style="margin: 0; display: flex; align-items: center; justify-content: space-between;">
                <span>Advanced: Import External Keys</span>
                <span id="advanced-import-toggle-icon" style="font-size: 18px;">&#9660;</span>
            </h2>
            <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                Import an existing signing key, seed phrase, or full policy from an external wallet or Cardano CLI.
            </p>
        </div>

        <div id="advanced-import-content" style="display: none; margin-top: 20px;">

            <!-- Import Tabs -->
            <div style="display: flex; gap: 0; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb;">
                <button type="button" class="mint-import-tab active" data-import-tab="seed" style="padding: 10px 20px; border: 2px solid #e5e7eb; border-bottom: 2px solid #0073aa; background: #fff; cursor: pointer; font-weight: 600; color: #0073aa; border-radius: 4px 4px 0 0; margin-bottom: -2px;">
                    Seed Phrase
                </button>
                <button type="button" class="mint-import-tab" data-import-tab="skey" style="padding: 10px 20px; border: 2px solid transparent; border-bottom: 2px solid #e5e7eb; background: #f9fafb; cursor: pointer; font-weight: 500; color: #666; border-radius: 4px 4px 0 0; margin-bottom: -2px;">
                    Skey + Script
                </button>
                <button type="button" class="mint-import-tab" data-import-tab="manual" style="padding: 10px 20px; border: 2px solid transparent; border-bottom: 2px solid #e5e7eb; background: #f9fafb; cursor: pointer; font-weight: 500; color: #666; border-radius: 4px 4px 0 0; margin-bottom: -2px;">
                    Full Manual
                </button>
            </div>

            <!-- Tab 1: Seed Phrase -->
            <div id="mint-import-seed" class="mint-import-tab-content" style="display: block;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 6px;">
                    <h3 style="margin-top: 0;">Import from Seed Phrase</h3>
                    <p style="color: #666;">Derive a wallet from an existing BIP-39 mnemonic. The seed phrase will <strong>NOT</strong> be stored &mdash; only the derived signing key is kept (encrypted).</p>

                    <table class="form-table" style="margin-top: 10px;">
                        <tr>
                            <th><label for="import-seed-name">Wallet Name</label></th>
                            <td><input type="text" id="import-seed-name" class="regular-text" placeholder="e.g. My Imported Wallet"></td>
                        </tr>
                        <tr>
                            <th><label for="import-seed-mnemonic">Seed Phrase</label></th>
                            <td>
                                <textarea id="import-seed-mnemonic" rows="3" class="large-text" placeholder="Enter 12, 15, or 24-word BIP-39 mnemonic separated by spaces..."></textarea>
                                <p class="description">Your seed phrase is used for derivation only and is never stored.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="import-seed-expiry">Policy Expiration</label></th>
                            <td>
                                <input type="datetime-local" id="import-seed-expiry" value="<?php echo esc_attr(gmdate('Y-m-d\TH:i', strtotime('+1 year'))); ?>">
                                <p class="description">When this policy expires and minting is no longer allowed. Default: 1 year.</p>
                            </td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="btn-import-seed" onclick="importFromSeed()">
                        Import from Seed
                    </button>
                    <span id="import-seed-spinner" style="display: none; margin-left: 10px;">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        Deriving wallet and importing...
                    </span>
                    <div id="import-seed-result" style="margin-top: 10px;"></div>
                </div>
            </div>

            <!-- Tab 2: Skey + Script -->
            <div id="mint-import-skey" class="mint-import-tab-content" style="display: none;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 6px;">
                    <h3 style="margin-top: 0;">Import Signing Key + Policy Script</h3>
                    <p style="color: #666;">Provide a signing key (from Cardano CLI or raw hex) and its associated native script JSON. The key will be validated against the script's keyHash. This policy will appear in the Mint Manager's "Add Asset to Existing Policy" dropdown.</p>

                    <table class="form-table" style="margin-top: 10px;">
                        <tr>
                            <th><label for="import-skey-name">Collection Name</label></th>
                            <td><input type="text" id="import-skey-name" class="regular-text" placeholder="e.g. My NFT Collection"></td>
                        </tr>
                        <tr>
                            <th><label for="import-skey-key">Signing Key</label></th>
                            <td>
                                <textarea id="import-skey-key" rows="3" class="large-text" placeholder='Cardano CLI .skey JSON or raw hex (64 or 128 chars)...'></textarea>
                                <p class="description">Accepts Cardano CLI JSON format <code>{"type":"...","cborHex":"5820..."}</code> or raw hex (64 or 128 chars).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="import-skey-script">Native Script JSON</label></th>
                            <td>
                                <textarea id="import-skey-script" rows="5" class="large-text" placeholder='{"type":"all","scripts":[{"type":"sig","keyHash":"abc..."},{"type":"before","slot":123456}]}'></textarea>
                                <p class="description">The native script (policy) JSON. Must contain a "sig" entry matching your signing key.</p>
                            </td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="btn-import-skey" onclick="importFromSkey()">
                        Import Skey + Script
                    </button>
                    <span id="import-skey-spinner" style="display: none; margin-left: 10px;">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        Validating and importing...
                    </span>
                    <div id="import-skey-result" style="margin-top: 10px;"></div>
                </div>
            </div>

            <!-- Tab 3: Full Manual -->
            <div id="mint-import-manual" class="mint-import-tab-content" style="display: none;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 6px;">
                    <h3 style="margin-top: 0;">Full Manual Import</h3>
                    <p style="color: #666;">Provide all details: policy ID, signing key, and native script. The policy ID will be verified against the script via the Anvil API. This policy will appear in the Mint Manager's "Add Asset to Existing Policy" dropdown.</p>

                    <table class="form-table" style="margin-top: 10px;">
                        <tr>
                            <th><label for="import-manual-name">Collection Name</label></th>
                            <td><input type="text" id="import-manual-name" class="regular-text" placeholder="e.g. My External Collection"></td>
                        </tr>
                        <tr>
                            <th><label for="import-manual-policy-id">Policy ID</label></th>
                            <td>
                                <input type="text" id="import-manual-policy-id" class="large-text" placeholder="56-character hex policy ID" maxlength="56">
                                <p class="description">Exactly 56 hex characters.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="import-manual-skey">Signing Key</label></th>
                            <td>
                                <textarea id="import-manual-skey" rows="3" class="large-text" placeholder='Cardano CLI .skey JSON or raw hex (64 or 128 chars)...'></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="import-manual-script">Native Script JSON</label></th>
                            <td>
                                <textarea id="import-manual-script" rows="5" class="large-text" placeholder='{"type":"all","scripts":[{"type":"sig","keyHash":"abc..."},{"type":"before","slot":123456}]}'></textarea>
                            </td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="btn-import-manual" onclick="importManual()">
                        Import Manual
                    </button>
                    <span id="import-manual-spinner" style="display: none; margin-left: 10px;">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        Verifying and importing...
                    </span>
                    <div id="import-manual-result" style="margin-top: 10px;"></div>
                </div>
            </div>

        </div>
    </div>

    <script>
    // Toggle advanced import section
    function toggleAdvancedImport() {
        var content = document.getElementById('advanced-import-content');
        var icon = document.getElementById('advanced-import-toggle-icon');
        if (content.style.display === 'none') {
            content.style.display = 'block';
            icon.innerHTML = '&#9650;';
        } else {
            content.style.display = 'none';
            icon.innerHTML = '&#9660;';
        }
    }

    // Tab switching
    document.querySelectorAll('.mint-import-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('data-import-tab');

            // Update tab styles
            document.querySelectorAll('.mint-import-tab').forEach(function(t) {
                t.style.background = '#f9fafb';
                t.style.color = '#666';
                t.style.fontWeight = '500';
                t.style.borderColor = 'transparent';
                t.style.borderBottomColor = '#e5e7eb';
                t.classList.remove('active');
            });
            this.style.background = '#fff';
            this.style.color = '#0073aa';
            this.style.fontWeight = '600';
            this.style.borderColor = '#e5e7eb';
            this.style.borderBottomColor = '#0073aa';
            this.classList.add('active');

            // Show target tab content
            document.querySelectorAll('.mint-import-tab-content').forEach(function(c) {
                c.style.display = 'none';
            });
            document.getElementById('mint-import-' + target).style.display = 'block';
        });
    });

    // Import from Seed Phrase
    function importFromSeed() {
        var name = document.getElementById('import-seed-name').value.trim();
        var mnemonic = document.getElementById('import-seed-mnemonic').value.trim();
        var expiry = document.getElementById('import-seed-expiry').value;

        if (!name) { alert('Please enter a wallet name.'); return; }
        if (!mnemonic) { alert('Please enter a seed phrase.'); return; }

        var wordCount = mnemonic.split(/\s+/).length;
        if ([12, 15, 24].indexOf(wordCount) === -1) {
            alert('Seed phrase must be 12, 15, or 24 words. You entered ' + wordCount + ' words.');
            return;
        }

        document.getElementById('btn-import-seed').disabled = true;
        document.getElementById('import-seed-spinner').style.display = 'inline-block';
        document.getElementById('import-seed-result').innerHTML = '';

        var formData = new FormData();
        formData.append('action', 'cardano_mint_import_mnemonic');
        formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
        formData.append('name', name);
        formData.append('mnemonic', mnemonic);
        formData.append('expiration_date', expiry);

        fetch(ajaxurl, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('btn-import-seed').disabled = false;
            document.getElementById('import-seed-spinner').style.display = 'none';
            if (data.success) {
                document.getElementById('import-seed-result').innerHTML =
                    '<div class="notice notice-success" style="padding: 10px;"><strong>Wallet imported successfully!</strong><br>KeyHash: <code>' + data.data.keyhash + '</code><br>Reloading...</div>';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                document.getElementById('import-seed-result').innerHTML =
                    '<div class="notice notice-error" style="padding: 10px;">' + (data.data || 'Import failed.') + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('btn-import-seed').disabled = false;
            document.getElementById('import-seed-spinner').style.display = 'none';
            document.getElementById('import-seed-result').innerHTML =
                '<div class="notice notice-error" style="padding: 10px;">Network error: ' + err.message + '</div>';
        });
    }

    // Import from Skey + Script
    function importFromSkey() {
        var name = document.getElementById('import-skey-name').value.trim();
        var skey = document.getElementById('import-skey-key').value.trim();
        var script = document.getElementById('import-skey-script').value.trim();

        if (!name) { alert('Please enter a wallet name.'); return; }
        if (!skey) { alert('Please enter a signing key.'); return; }
        if (!script) { alert('Please enter the native script JSON.'); return; }

        document.getElementById('btn-import-skey').disabled = true;
        document.getElementById('import-skey-spinner').style.display = 'inline-block';
        document.getElementById('import-skey-result').innerHTML = '';

        var formData = new FormData();
        formData.append('action', 'cardano_mint_import_skey');
        formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
        formData.append('name', name);
        formData.append('skey', skey);
        formData.append('script', script);

        fetch(ajaxurl, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('btn-import-skey').disabled = false;
            document.getElementById('import-skey-spinner').style.display = 'none';
            if (data.success) {
                document.getElementById('import-skey-result').innerHTML =
                    '<div class="notice notice-success" style="padding: 10px;"><strong>Wallet imported successfully!</strong><br>Policy ID: <code>' + data.data.policy_id + '</code><br>KeyHash: <code>' + data.data.keyhash + '</code><br>Reloading...</div>';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                document.getElementById('import-skey-result').innerHTML =
                    '<div class="notice notice-error" style="padding: 10px;">' + (data.data || 'Import failed.') + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('btn-import-skey').disabled = false;
            document.getElementById('import-skey-spinner').style.display = 'none';
            document.getElementById('import-skey-result').innerHTML =
                '<div class="notice notice-error" style="padding: 10px;">Network error: ' + err.message + '</div>';
        });
    }

    // Import Manual
    function importManual() {
        var name = document.getElementById('import-manual-name').value.trim();
        var policyId = document.getElementById('import-manual-policy-id').value.trim();
        var skey = document.getElementById('import-manual-skey').value.trim();
        var script = document.getElementById('import-manual-script').value.trim();

        if (!name) { alert('Please enter a wallet name.'); return; }
        if (!policyId) { alert('Please enter a policy ID.'); return; }
        if (!/^[0-9a-fA-F]{56}$/.test(policyId)) { alert('Policy ID must be exactly 56 hex characters.'); return; }
        if (!skey) { alert('Please enter a signing key.'); return; }
        if (!script) { alert('Please enter the native script JSON.'); return; }

        document.getElementById('btn-import-manual').disabled = true;
        document.getElementById('import-manual-spinner').style.display = 'inline-block';
        document.getElementById('import-manual-result').innerHTML = '';

        var formData = new FormData();
        formData.append('action', 'cardano_mint_import_manual');
        formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
        formData.append('name', name);
        formData.append('policy_id', policyId);
        formData.append('skey', skey);
        formData.append('script', script);

        fetch(ajaxurl, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('btn-import-manual').disabled = false;
            document.getElementById('import-manual-spinner').style.display = 'none';
            if (data.success) {
                document.getElementById('import-manual-result').innerHTML =
                    '<div class="notice notice-success" style="padding: 10px;"><strong>Wallet imported successfully!</strong><br>Policy ID: <code>' + data.data.policy_id + '</code><br>KeyHash: <code>' + data.data.keyhash + '</code><br>Reloading...</div>';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                document.getElementById('import-manual-result').innerHTML =
                    '<div class="notice notice-error" style="padding: 10px;">' + (data.data || 'Import failed.') + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('btn-import-manual').disabled = false;
            document.getElementById('import-manual-spinner').style.display = 'none';
            document.getElementById('import-manual-result').innerHTML =
                '<div class="notice notice-error" style="padding: 10px;">Network error: ' + err.message + '</div>';
        });
    }
    </script>

    <!-- Archived Policy Wallets Section -->
    <?php if ($archived_count > 0): ?>
    <div id="archived-wallets" style="margin-top: 30px; padding: 20px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer;" onclick="toggleArchivedWallets()">
        <h2 style="margin: 0 0 15px 0; display: flex; align-items: center; justify-content: space-between;">
            <span>📦 Archived Policy Wallets (<?php echo esc_html($archived_count); ?> / 10 max)</span>
            <span id="archived-wallets-toggle-icon" style="font-size: 18px;">▼</span>
        </h2>
        <p style="margin: 0 0 15px 0; color: #666; font-size: 13px;">
            Archived wallets are preserved but cannot sign mints. Unarchive to restore minting capability.
        </p>

        <div id="archived-wallets-content" style="display: none;">
            <?php foreach ($archived_wallets as $wallet): ?>
                <?php
                // Get policy count for this wallet
                $policy_count = MintModel::countPoliciesByWalletKeyhash($wallet['payment_keyhash'], true);
                ?>
                <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 6px; border: 1px solid #ddd;">
                    <h3 style="margin: 0 0 10px 0; color: #374151;">
                        📋 <?php echo esc_html($wallet['wallet_name']); ?>
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;">
                        <div>
                            <strong>Payment Address:</strong><br>
                            <span style="font-family: monospace; font-size: 11px; color: #666; word-break: break-all;">
                                <?php echo esc_html(substr($wallet['payment_address'], 0, 30)); ?>...
                            </span>
                        </div>
                        <div>
                            <strong>KeyHash:</strong><br>
                            <span style="font-family: monospace; font-size: 11px; color: #666;">
                                <?php echo esc_html(substr($wallet['payment_keyhash'], 0, 20)); ?>...
                            </span>
                        </div>
                        <div>
                            <strong>Policies:</strong> <?php echo esc_html($policy_count); ?>
                        </div>
                        <div>
                            <strong>Archived:</strong> <?php echo esc_html($wallet['archived_at'] ? gmdate('M j, Y', strtotime($wallet['archived_at'])) : 'N/A'); ?>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="button button-primary" onclick="event.stopPropagation(); unarchivePolicyWallet(<?php echo esc_js($wallet['id']); ?>, '<?php echo esc_js($wallet['wallet_name']); ?>')">
                            ↑ Unarchive
                        </button>
                        <button type="button" class="button button-secondary" onclick="event.stopPropagation(); toggleWalletDetails('wallet-<?php echo esc_attr($wallet['id']); ?>')">
                            👁 View Details
                        </button>
                        <button type="button" class="button button-secondary" onclick="event.stopPropagation(); if(confirm('⚠️ Permanently delete this archived wallet?\n\nThis will delete the wallet but NOT the policies. You will not be able to mint those policies anymore.\n\nThis cannot be undone!')) deleteArchivedWallet(<?php echo esc_js($wallet['id']); ?>);" style="color: #d63638;">
                            🗑 Delete Permanently
                        </button>
                    </div>

                    <div id="wallet-<?php echo esc_attr($wallet['id']); ?>" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                        <h4 style="margin: 0 0 10px 0;">Full Wallet Details</h4>
                        <table style="width: 100%; font-size: 13px;">
                            <tr>
                                <th style="padding: 8px; text-align: left; background: #f3f4f6;">Payment Address</th>
                                <td style="padding: 8px; font-family: monospace; font-size: 11px; word-break: break-all;"><?php echo esc_html($wallet['payment_address']); ?></td>
                            </tr>
                            <tr>
                                <th style="padding: 8px; text-align: left; background: #f3f4f6;">Payment KeyHash</th>
                                <td style="padding: 8px; font-family: monospace; font-size: 11px; word-break: break-all;"><?php echo esc_html($wallet['payment_keyhash']); ?></td>
                            </tr>
                            <tr>
                                <th style="padding: 8px; text-align: left; background: #f3f4f6;">Stake Address</th>
                                <td style="padding: 8px; font-family: monospace; font-size: 11px; word-break: break-all;"><?php echo esc_html($wallet['stake_address'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th style="padding: 8px; text-align: left; background: #f3f4f6;">Created</th>
                                <td style="padding: 8px;"><?php echo esc_html($wallet['created_at']); ?></td>
                            </tr>
                            <tr>
                                <th style="padding: 8px; text-align: left; background: #f3f4f6;">Archived</th>
                                <td style="padding: 8px;"><?php echo esc_html($wallet['archived_at'] ?: 'N/A'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// Archive wallet
function archivePolicyWallet(walletId, walletName) {
    if (!confirm('Archive "' + walletName + '"?\n\nThis will make ALL policies created with this wallet read-only (unmintable) until you unarchive it.\n\nThe wallet and policies will be preserved.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cardano_archive_policy_wallet');
    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
    formData.append('wallet_id', walletId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Policy wallet archived successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.data?.message || 'Failed to archive wallet'));
        }
    })
    .catch(error => {
        console.error('Archive error:', error);
        alert('❌ Network error while archiving wallet');
    });
}

// Unarchive wallet
function unarchivePolicyWallet(walletId, walletName) {
    if (!confirm('Restore "' + walletName + '" to active?\n\nThis will make it the active signing wallet for mints.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cardano_unarchive_policy_wallet');
    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
    formData.append('wallet_id', walletId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Policy wallet unarchived successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.data?.message || 'Failed to unarchive wallet'));
        }
    })
    .catch(error => {
        console.error('Unarchive error:', error);
        alert('❌ Network error while unarchiving wallet');
    });
}

// Delete archived wallet
function deleteArchivedWallet(walletId) {
    const formData = new FormData();
    formData.append('action', 'delete_policy_wallet');
    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardano_delete_policy_wallet')); ?>');
    formData.append('wallet_id', walletId);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert('✅ Wallet deleted successfully!');
        location.reload();
    })
    .catch(error => {
        console.error('Delete error:', error);
        alert('❌ Network error while deleting wallet');
    });
}

// Toggle archived wallets section
function toggleArchivedWallets() {
    const content = document.getElementById('archived-wallets-content');
    const icon = document.getElementById('archived-wallets-toggle-icon');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▲';
    } else {
        content.style.display = 'none';
        icon.textContent = '▼';
    }
}

// Toggle wallet details
function toggleWalletDetails(elementId) {
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

<style>
.card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}
</style>
