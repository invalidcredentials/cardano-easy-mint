<?php
namespace CardanoMintPay\Helpers;

if (!defined('ABSPATH')) exit;

class PinataAPI {

    /**
     * Upload image to Pinata IPFS and get CIDv0
     *
     * @param string $file_path Full path to the image file
     * @param string $name Optional name for the pinned file
     * @return array|WP_Error Array with ['cid' => 'QmXXX...', 'mediaType' => 'image/png'] or WP_Error on failure
     */
    public static function uploadImage($file_path, $name = '') {
        // Get Pinata credentials - try JWT first, then API Key + Secret
        $jwt = get_option('cardano_mint_pinata_jwt', '');
        $api_key = get_option('cardano_mint_pinata_api_key', '');
        $secret_key = get_option('cardano_mint_pinata_secret_key', '');

        // Build headers based on available credentials
        $headers = [];
        if (!empty($jwt)) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        } elseif (!empty($api_key) && !empty($secret_key)) {
            $headers['pinata_api_key'] = $api_key;
            $headers['pinata_secret_api_key'] = $secret_key;
        } else {
            return new \WP_Error('pinata_no_credentials', 'Pinata credentials not configured. Set JWT token OR API Key + Secret.');
        }

        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', 'Image file not found: ' . $file_path);
        }

        // Detect media type
        $mime_type = mime_content_type($file_path);
        if (!$mime_type || strpos($mime_type, 'image/') !== 0) {
            return new \WP_Error('invalid_file', 'File is not a valid image');
        }

        // Use filename if no name provided
        if (empty($name)) {
            $name = basename($file_path);
        }

        // Prepare Pinata request
        $url = 'https://api.pinata.cloud/pinning/pinFileToIPFS';

        // Use cURL directly for proper multipart/form-data handling
        $ch = curl_init($url);

        // Build form data
        $post_fields = [
            'pinataOptions' => json_encode([
                'cidVersion' => 0,
                'wrapWithDirectory' => false
            ]),
            'pinataMetadata' => json_encode([
                'name' => $name,
                'keyvalues' => [
                    'app' => 'cardano-mint',
                    'kind' => 'nft-image'
                ]
            ]),
            'file' => new \CURLFile($file_path, $mime_type, basename($file_path))
        ];

        // Build headers array for cURL
        $curl_headers = [];
        foreach ($headers as $key => $value) {
            $curl_headers[] = "$key: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_TIMEOUT => 120
        ]);

        $response_body = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return new \WP_Error('pinata_curl_error', 'cURL error: ' . $curl_error);
        }

        if ($status_code !== 200) {
            return new \WP_Error('pinata_upload_failed', 'Pinata upload failed: ' . $status_code . ' - ' . $response_body);
        }

        $result = json_decode($response_body, true);

        // Get CID from response
        $cid = $result['IpfsHash'] ?? $result['ipfsHash'] ?? '';

        if (empty($cid)) {
            return new \WP_Error('pinata_no_cid', 'Pinata did not return a CID');
        }

        // Validate it's CIDv0 (starts with Qm)
        if (substr($cid, 0, 2) !== 'Qm') {
            return new \WP_Error('pinata_invalid_cid', 'Expected CIDv0 (Qm...), got: ' . $cid);
        }

        return [
            'cid' => $cid,
            'mediaType' => $mime_type
        ];
    }

    /**
     * Test Pinata connection
     *
     * @return array|WP_Error Test results or error
     */
    public static function testConnection() {
        $jwt = get_option('cardano_mint_pinata_jwt', '');

        if (empty($jwt)) {
            return new \WP_Error('pinata_no_jwt', 'Pinata JWT token not configured');
        }

        // Test with pinList endpoint (lightweight)
        $url = 'https://api.pinata.cloud/data/pinList?pageLimit=1';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return [
                'success' => true,
                'message' => 'Pinata connection successful'
            ];
        } else {
            $error_body = wp_remote_retrieve_body($response);
            return new \WP_Error('pinata_test_failed', 'Pinata test failed: ' . $status_code . ' - ' . $error_body);
        }
    }
}
