<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap">
    <h1>Cardano Minting &mdash; Widget Deployer</h1>

    <?php settings_errors( 'cardano_mint_settings' ); ?>

    <div class="notice notice-info" style="max-width:870px;">
        <p>Use the Widget Deployer only if you are deploying to an outside server/site (like a video game). If you want to deploy a minting widget on your WordPress website, go to <a href="<?php echo admin_url('admin.php?page=cardano-mint-how-to-use'); ?>">How to Use</a>.</p>
    </div>

    <!-- Panel 1: API Keys -->
    <div class="card" style="max-width:900px;margin-top:20px">
        <h2 style="margin-top:0">API Keys</h2>
        <p class="description">Generate API keys for external widget integrations. Each key can be restricted to specific origins and collections.</p>

        <div style="background:#f9f9f9;border:1px solid #ddd;padding:16px;border-radius:4px;margin:12px 0">
            <h3 style="margin-top:0">Generate New Key</h3>
            <table class="form-table" style="margin:0">
                <tr>
                    <th><label for="cm-key-label">Label</label></th>
                    <td><input type="text" id="cm-key-label" class="regular-text" placeholder="e.g. My Game Widget"></td>
                </tr>
                <tr>
                    <th><label for="cm-key-origins">Allowed Origins</label></th>
                    <td>
                        <textarea id="cm-key-origins" rows="3" class="large-text" placeholder="https://mygame.com&#10;https://staging.mygame.com"></textarea>
                        <p class="description">One URL per line. Leave empty to allow all origins.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Restrict to Collections</label></th>
                    <td>
                        <select id="cm-key-mints" multiple style="min-width:300px;min-height:80px">
                            <?php foreach ( $mints as $m ) : ?>
                                <option value="<?php echo (int) ( $m['collection_id'] ?? $m['id'] ); ?>">
                                    #<?php echo (int) ( $m['collection_id'] ?? $m['id'] ); ?> &mdash; <?php echo esc_html( $m['title'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Hold Ctrl/Cmd to select multiple. Leave empty to allow all collections.</p>
                    </td>
                </tr>
            </table>
            <p><button type="button" class="button button-primary" id="cm-generate-key">Generate API Key</button></p>
        </div>

        <!-- Key display modal (hidden until generated) -->
        <div id="cm-key-modal" style="display:none;background:#fff3cd;border:2px solid #f0c040;padding:16px;border-radius:4px;margin:12px 0">
            <h3 style="margin-top:0;color:#856404">Your API Key (shown once!)</h3>
            <code id="cm-key-display" style="display:block;padding:12px;background:#fff;border:1px solid #ccc;font-size:14px;word-break:break-all"></code>
            <p style="margin:8px 0">
                <button type="button" class="button" id="cm-copy-key">Copy Key</button>
            </p>
            <p class="description" style="color:#856404">Save this key now. It cannot be retrieved later &mdash; only a prefix is stored.</p>
        </div>

        <?php if ( ! empty( $api_keys ) ) : ?>
            <h3>Active Keys</h3>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:80px">ID</th>
                        <th>Label</th>
                        <th>Key Prefix</th>
                        <th>Origins</th>
                        <th>Collections</th>
                        <th style="width:140px">Created</th>
                        <th style="width:140px">Last Used</th>
                        <th style="width:80px">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $api_keys as $k ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $k['id'] ); ?></code></td>
                            <td><?php echo esc_html( $k['label'] ); ?></td>
                            <td><code><?php echo esc_html( $k['key_prefix'] ); ?>...</code></td>
                            <td><?php echo ! empty( $k['allowed_origins'] ) ? esc_html( implode( ', ', $k['allowed_origins'] ) ) : '<em>All</em>'; ?></td>
                            <td><?php echo ! empty( $k['mint_ids'] ) ? esc_html( implode( ', ', array_map( function( $id ) { return '#' . $id; }, $k['mint_ids'] ) ) ) : '<em>All</em>'; ?></td>
                            <td><?php echo esc_html( $k['created_at'] ); ?></td>
                            <td><?php echo $k['last_used'] ? esc_html( $k['last_used'] ) : '<em>Never</em>'; ?></td>
                            <td><button type="button" class="button button-small cm-revoke-key" data-key-id="<?php echo esc_attr( $k['id'] ); ?>">Revoke</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p style="color:#666">No API keys yet. Generate one above to get started.</p>
        <?php endif; ?>
    </div>

    <!-- Panel 2: Widget Appearance -->
    <div class="card" style="max-width:900px;margin-top:20px">
        <h2 style="margin-top:0">Widget Appearance</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'cm_save_widget_config', 'cm_widget_config_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="cm_widget_theme">Theme Preset</label></th>
                    <td>
                        <select name="cm_widget_theme" id="cm_widget_theme">
                            <option value="game-dark" <?php selected( $config['theme'], 'game-dark' ); ?>>Game Dark (Cyberpunk)</option>
                            <option value="dark" <?php selected( $config['theme'], 'dark' ); ?>>Dark</option>
                            <option value="light" <?php selected( $config['theme'], 'light' ); ?>>Light</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="cm_widget_accent">Accent Color</label></th>
                    <td><input type="color" name="cm_widget_accent" id="cm_widget_accent" value="<?php echo esc_attr( $config['accent'] ); ?>"> <code><?php echo esc_html( $config['accent'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="cm_widget_confirm">Confirm Button Color</label></th>
                    <td><input type="color" name="cm_widget_confirm" id="cm_widget_confirm" value="<?php echo esc_attr( $config['confirm'] ); ?>"> <code><?php echo esc_html( $config['confirm'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="cm_widget_background">Background Color</label></th>
                    <td><input type="color" name="cm_widget_background" id="cm_widget_background" value="<?php echo esc_attr( $config['background'] ); ?>"> <code><?php echo esc_html( $config['background'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="cm_widget_text">Text Color</label></th>
                    <td><input type="color" name="cm_widget_text" id="cm_widget_text" value="<?php echo esc_attr( $config['text'] ); ?>"> <code><?php echo esc_html( $config['text'] ); ?></code></td>
                </tr>
            </table>
            <?php submit_button( 'Save Appearance' ); ?>
        </form>
    </div>

    <!-- Panel 3: Embed Code Generator -->
    <div class="card" style="max-width:900px;margin-top:20px">
        <h2 style="margin-top:0">Embed Code Generator</h2>
        <table class="form-table">
            <tr>
                <th><label for="cm-embed-collection">Collection</label></th>
                <td>
                    <select id="cm-embed-collection">
                        <option value="">Select a collection...</option>
                        <?php foreach ( $mints as $m ) : ?>
                            <option value="<?php echo (int) ( $m['collection_id'] ?? $m['id'] ); ?>">#<?php echo (int) ( $m['collection_id'] ?? $m['id'] ); ?> &mdash; <?php echo esc_html( $m['title'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="cm-embed-key">API Key</label></th>
                <td>
                    <select id="cm-embed-key">
                        <option value="">Select a key...</option>
                        <?php foreach ( $api_keys as $k ) : ?>
                            <option value="<?php echo esc_attr( $k['key_prefix'] ); ?>..."><?php echo esc_html( $k['label'] ); ?> (<?php echo esc_html( $k['key_prefix'] ); ?>...)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">The actual key must be pasted manually into the snippet. Only the prefix is stored.</p>
                </td>
            </tr>
        </table>

        <h3>Embed Snippet</h3>
        <pre id="cm-embed-code" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">&lt;!-- Select a collection and API key above --&gt;</pre>
        <p>
            <button type="button" class="button" id="cm-copy-embed">Copy Embed Code</button>
        </p>

        <input type="hidden" id="cm-embed-rest-url" value="<?php echo esc_attr( $rest_url ); ?>">
        <input type="hidden" id="cm-embed-widget-url" value="<?php echo esc_attr( plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/cm-widget.js' ); ?>">
    </div>

    <!-- Panel 4: Preview -->
    <div class="card" style="max-width:900px;margin-top:20px">
        <h2 style="margin-top:0">Widget Preview</h2>
        <p class="description">Live preview of the widget. Select a collection above to render it.</p>
        <div id="cm-widget-preview-wrap" style="background:<?php echo esc_attr( $config['background'] ); ?>;border-radius:8px;min-height:200px;overflow:hidden">
            <div id="cm-widget-preview-placeholder" style="display:flex;align-items:center;justify-content:center;min-height:200px;color:<?php echo esc_attr( $config['text'] ); ?>">
                <p style="opacity:0.5">Select a collection in the Embed Code Generator to see a preview.</p>
            </div>
            <iframe id="cm-widget-preview-frame" style="display:none;width:100%;border:none;border-radius:8px" title="Widget Preview"></iframe>
        </div>
    </div>
</div>

<script>
(function() {
    var nonce = '<?php echo wp_create_nonce('cardanocheckoutnonce'); ?>';

    // Generate API Key
    document.getElementById('cm-generate-key').addEventListener('click', function() {
        var label = document.getElementById('cm-key-label').value.trim();
        var origins = document.getElementById('cm-key-origins').value.trim();
        var mintSelect = document.getElementById('cm-key-mints');
        var mintIds = Array.from(mintSelect.selectedOptions).map(function(o) { return o.value; });

        if (!label) { alert('Please enter a label.'); return; }

        this.disabled = true;
        this.textContent = 'Generating...';
        var btn = this;

        var formData = new FormData();
        formData.append('action', 'cardano_mint_generate_api_key');
        formData.append('nonce', nonce);
        formData.append('label', label);
        formData.append('allowed_origins', origins);
        mintIds.forEach(function(id) { formData.append('mint_ids[]', id); });

        fetch(ajaxurl, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.textContent = 'Generate API Key';
            if (res.success) {
                document.getElementById('cm-key-display').textContent = res.data.key;
                document.getElementById('cm-key-modal').style.display = 'block';
                document.getElementById('cm-key-label').value = '';
                document.getElementById('cm-key-origins').value = '';
                setTimeout(function() { location.reload(); }, 5000);
            } else {
                alert('Error: ' + (res.data || 'Failed'));
            }
        });
    });

    // Copy API Key
    document.getElementById('cm-copy-key').addEventListener('click', function() {
        var key = document.getElementById('cm-key-display').textContent;
        navigator.clipboard.writeText(key).then(function() { alert('API key copied!'); });
    });

    // Revoke API Key
    document.querySelectorAll('.cm-revoke-key').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var keyId = this.getAttribute('data-key-id');
            if (!confirm('Revoke this API key? Any widgets using it will stop working.')) return;

            this.disabled = true;
            this.textContent = '...';

            var formData = new FormData();
            formData.append('action', 'cardano_mint_revoke_api_key');
            formData.append('nonce', nonce);
            formData.append('key_id', keyId);

            fetch(ajaxurl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { location.reload(); }
                else { alert('Error: ' + (res.data || 'Failed')); }
            });
        });
    });

    // Embed Code Generator
    function updateEmbedCode() {
        var collectionId = document.getElementById('cm-embed-collection').value;
        var keyPrefix = document.getElementById('cm-embed-key').value;
        var restUrl = document.getElementById('cm-embed-rest-url').value;
        var widgetUrl = document.getElementById('cm-embed-widget-url').value;
        var pre = document.getElementById('cm-embed-code');

        if (!collectionId) {
            pre.textContent = '<!-- Select a collection and API key above -->';
            hidePreview();
            return;
        }

        var keyPlaceholder = keyPrefix || 'cmk_YOUR_API_KEY_HERE';

        var snippet = '<!--\n'
            + '  Cardano Minting Widget - Embeddable NFT Minting Component\n'
            + '  Shadow DOM isolated, zero dependencies, works on any site.\n'
            + '-->\n\n'
            + '<style>\n'
            + '  #cardano-mint-widget {\n'
            + '    --cmw-accent:     <?php echo esc_js( $config['accent'] ); ?>;  /* Buttons, links, glows */\n'
            + '    --cmw-bg:         <?php echo esc_js( $config['background'] ); ?>;  /* Widget background     */\n'
            + '    --cmw-text:       <?php echo esc_js( $config['text'] ); ?>;  /* Primary text color    */\n'
            + '    --cmw-confirm:    <?php echo esc_js( $config['confirm'] ); ?>;  /* Confirm button        */\n'
            + '  }\n'
            + '</style>\n\n'
            + '<div id="cardano-mint-widget"></div>\n'
            + '<script\n'
            + '  src="' + widgetUrl + '"\n'
            + '  data-api="' + restUrl + '"\n'
            + '  data-key="' + keyPlaceholder + '"\n'
            + '  data-collection="' + collectionId + '"\n'
            + '  data-container="cardano-mint-widget"\n'
            + '><\/script>';

        pre.textContent = snippet;
        document.getElementById('cm-copy-embed').setAttribute('data-copy', snippet);
        loadPreview(collectionId, restUrl, widgetUrl, keyPlaceholder);
    }

    document.getElementById('cm-embed-collection').addEventListener('change', updateEmbedCode);
    document.getElementById('cm-embed-key').addEventListener('change', updateEmbedCode);

    // Copy Embed Code
    document.getElementById('cm-copy-embed').addEventListener('click', function() {
        var code = document.getElementById('cm-embed-code').textContent;
        navigator.clipboard.writeText(code).then(function() { alert('Embed code copied!'); });
    });

    // Preview
    function loadPreview(collectionId, restUrl, widgetUrl, apiKey) {
        var frame = document.getElementById('cm-widget-preview-frame');
        var placeholder = document.getElementById('cm-widget-preview-placeholder');

        placeholder.style.display = 'none';
        frame.style.display = 'block';

        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            + 'body{margin:0;background:<?php echo esc_js( $config['background'] ); ?>;}'
            + '#cardano-mint-widget{'
            + '--cmw-accent:<?php echo esc_js( $config['accent'] ); ?>;'
            + '--cmw-bg:<?php echo esc_js( $config['background'] ); ?>;'
            + '--cmw-text:<?php echo esc_js( $config['text'] ); ?>;'
            + '--cmw-confirm:<?php echo esc_js( $config['confirm'] ); ?>;'
            + '}</style></head><body>'
            + '<div id="cardano-mint-widget"></div>'
            + '<script src="' + widgetUrl + '" data-api="' + restUrl + '" data-key="' + apiKey + '" data-collection="' + collectionId + '" data-container="cardano-mint-widget"><\/script>'
            + '</body></html>';

        frame.srcdoc = html;

        // Auto-resize iframe.
        frame.onload = function() {
            try {
                var h = frame.contentDocument.body.scrollHeight;
                frame.style.height = Math.max(h, 200) + 'px';
            } catch(e) {}
        };
    }

    function hidePreview() {
        document.getElementById('cm-widget-preview-frame').style.display = 'none';
        document.getElementById('cm-widget-preview-placeholder').style.display = 'flex';
    }
})();
</script>
