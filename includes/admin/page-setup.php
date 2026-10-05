<?php
/**
 * Plugin Setup admin page (Anvil keys, merchant address, network, Pinata).
 *
 * Moved out of cardano-nft-checkout.php in 4.6.0 unchanged; the plugin entry
 * file now only wires things together. Plugin paths resolve through the
 * CARDANO_MINT_PLUGIN_DIR / _URL constants defined there.
 */

if (!defined('ABSPATH')) exit;

function cardanomint_setup_page() {
    $prefix = 'cardano-mint';
    $settingsmessage = '';

    if (isset($_POST['pluginsetupsave'])) {
        check_admin_referer('pluginsetupoptions');
        
        // Determine API URL based on network selection
        $network = sanitize_text_field($_POST['networkenvironment']);
        $api_url = ($network === 'mainnet') ? 'https://prod.api.ada-anvil.app/v2/services' : 'https://preprod.api.ada-anvil.app/v2/services';
        
        // Use appropriate API key based on network
        $api_key = ($network === 'mainnet') ? sanitize_text_field($_POST['anvilapikeymainnet']) : sanitize_text_field($_POST['anvilapikeytestnet']);
        
        // Save standardized options for AnvilAPI helper
        update_option('cardano_mint_anvil_api_key', $api_key);
        update_option('cardano_mint_anvil_api_url', $api_url);
        update_option('cardano_mint_merchant_address', sanitize_text_field($_POST['merchantwalletaddress']));
        
        // Keep legacy options for backward compatibility
        update_option($prefix . '-anvilapikeymainnet', sanitize_text_field($_POST['anvilapikeymainnet']));
        update_option($prefix . '-anvilapikeytestnet', sanitize_text_field($_POST['anvilapikeytestnet']));
        update_option($prefix . '-merchantwalletaddress', sanitize_text_field($_POST['merchantwalletaddress']));
        update_option($prefix . '-networkenvironment', $network);
        update_option($prefix . '-delete_data_on_deactivation', isset($_POST['delete_data_on_deactivation']) ? 1 : 0);

        // Pinata settings
        update_option('cardano_mint_pinata_jwt', sanitize_text_field($_POST['pinata_jwt'] ?? ''));
        update_option('cardano_mint_pinata_api_key', sanitize_text_field($_POST['pinata_api_key'] ?? ''));
        update_option('cardano_mint_pinata_secret_key', sanitize_text_field($_POST['pinata_secret_key'] ?? ''));
        update_option('cardano_mint_pinata_enabled', isset($_POST['pinata_enabled']) ? 1 : 0);

        $settingsmessage = 'Settings saved!';
    }

    $anvilapikeymainnet = get_option($prefix . '-anvilapikeymainnet', '');
    $anvilapikeytestnet = get_option($prefix . '-anvilapikeytestnet', '');
    $merchantwalletaddress = get_option($prefix . '-merchantwalletaddress', '');
    $networkenvironment = get_option($prefix . '-networkenvironment', 'mainnet');
    $delete_data_on_deactivation = get_option($prefix . '-delete_data_on_deactivation', 0);
    $pinata_jwt = get_option('cardano_mint_pinata_jwt', '');
    $pinata_api_key = get_option('cardano_mint_pinata_api_key', '');
    $pinata_secret_key = get_option('cardano_mint_pinata_secret_key', '');
    $pinata_enabled = get_option('cardano_mint_pinata_enabled', 0);

    ?>
    <div class="wrap">
        <h1>Cardano Mint Plugin Setup</h1>
        <?php if ($settingsmessage): ?>
            <div class="notice notice-success">
                <p><?php echo esc_html($settingsmessage); ?></p>
            </div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('pluginsetupoptions'); ?>
            <table class="form-table">
                <tr>
                    <th>ADA Anvil API Key Mainnet</th>
                    <td>
                        <input type="text" name="anvilapikeymainnet" value="<?php echo esc_attr($anvilapikeymainnet); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>ADA Anvil API Key Testnet</th>
                    <td>
                        <input type="text" name="anvilapikeytestnet" value="<?php echo esc_attr($anvilapikeytestnet); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>Merchant Wallet Address</th>
                    <td>
                        <input type="text" name="merchantwalletaddress" value="<?php echo esc_attr($merchantwalletaddress); ?>" size="60" />
                    </td>
                </tr>
                <tr>
                    <th>Network Environment</th>
                    <td>
                        <select name="networkenvironment">
                            <option value="mainnet" <?php selected($networkenvironment, 'mainnet', true); ?>>Mainnet</option>
                            <option value="preprod" <?php selected($networkenvironment, 'preprod', true); ?>>Preprod Testnet</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Data Cleanup</th>
                    <td>
                        <input type="checkbox" name="delete_data_on_deactivation" id="delete_data_on_deactivation" value="1" <?php checked($delete_data_on_deactivation, 1); ?> />
                        <label for="delete_data_on_deactivation">Delete all plugin data when deactivated</label>
                        <p class="description">This will remove all mints, mint counts, and settings when the plugin is deactivated.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top: 30px;">Pinata IPFS Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Enable Pinata IPFS</th>
                    <td>
                        <input type="checkbox" name="pinata_enabled" id="pinata_enabled" value="1" <?php checked($pinata_enabled, 1); ?> />
                        <label for="pinata_enabled">Use Pinata for NFT image storage</label>
                        <p class="description">When enabled, NFT images will be uploaded to IPFS via Pinata instead of using WordPress CDN URLs.</p>
                    </td>
                </tr>
                <tr>
                    <th>Pinata JWT Token</th>
                    <td>
                        <input type="password" name="pinata_jwt" value="<?php echo esc_attr($pinata_jwt); ?>" size="60" autocomplete="off" />
                        <p class="description">Get your JWT token from <a href="https://app.pinata.cloud/keys" target="_blank">Pinata Dashboard → API Keys</a> (Recommended)</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2" style="text-align: center; padding: 10px; background: #f0f0f0;">OR use API Key + Secret (Legacy)</th>
                </tr>
                <tr>
                    <th>Pinata API Key</th>
                    <td>
                        <input type="text" name="pinata_api_key" value="<?php echo esc_attr($pinata_api_key); ?>" size="60" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th>Pinata Secret API Key</th>
                    <td>
                        <input type="password" name="pinata_secret_key" value="<?php echo esc_attr($pinata_secret_key); ?>" size="60" autocomplete="off" />
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="pluginsetupsave" class="button-primary" value="Save Settings" />
                <button type="button" id="test-anvil-api" class="button" style="margin-left: 10px;">Test Anvil API Connection</button>
            </p>
            
            <div id="anvil-test-results" style="margin-top: 20px; padding: 15px; border-radius: 5px; display: none;">
                <h3>Anvil API Test Results</h3>
                <div id="test-results-content"></div>
            </div>
            
            <script>
            document.getElementById('test-anvil-api').addEventListener('click', function() {
                const button = this;
                const resultsDiv = document.getElementById('anvil-test-results');
                const contentDiv = document.getElementById('test-results-content');
                
                button.disabled = true;
                button.textContent = 'Testing...';
                resultsDiv.style.display = 'block';
                contentDiv.innerHTML = '<p>Testing Anvil API connection...</p>';
                
                const formData = new FormData();
                formData.append('action', 'cardano_test_anvil_api');
                formData.append('nonce', '<?php echo esc_js(wp_create_nonce('cardanocheckoutnonce')); ?>');
                
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const results = data.data;
                        let html = '<ul>';
                        html += '<li><strong>API Key:</strong> ' + (results.api_key_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>API URL:</strong> ' + (results.api_url_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>Merchant Address:</strong> ' + (results.merchant_address_configured ? '✅ Configured' : '❌ Missing') + '</li>';
                        html += '<li><strong>API URL:</strong> ' + results.api_url + '</li>';
                        html += '<li><strong>Merchant Address:</strong> ' + results.merchant_address + '</li>';
                        
                        if (results.api_health_check === 'success') {
                            html += '<li><strong>API Health Check:</strong> ✅ Success (HTTP ' + results.api_response_code + ')</li>';
                        } else if (results.api_health_check === 'error') {
                            html += '<li><strong>API Health Check:</strong> ❌ Error - ' + results.api_error + '</li>';
                        } else {
                            html += '<li><strong>API Health Check:</strong> ⏭️ Skipped (missing configuration)</li>';
                        }
                        
                        html += '</ul>';
                        contentDiv.innerHTML = html;
                    } else {
                        contentDiv.innerHTML = '<p style="color: red;">Test failed: ' + (data.data?.message || 'Unknown error') + '</p>';
                    }
                })
                .catch(error => {
                    contentDiv.innerHTML = '<p style="color: red;">Test failed: ' + error.message + '</p>';
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = 'Test Anvil API Connection';
                });
            });
            </script>
        </form>
    </div>
    <?php
}

