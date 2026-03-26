<?php
namespace CardanoMintPay\Controllers;

class AJAXController
{
    public static function register()
    {
        // Test endpoint for Anvil API
        add_action('wp_ajax_cardano_test_anvil_api', [self::class, 'testAnvilAPI']);
        add_action('wp_ajax_nopriv_cardano_test_anvil_api', [self::class, 'testAnvilAPI']);
    }

    public static function testAnvilAPI() {
        check_ajax_referer('cardanocheckoutnonce', 'nonce');

        $api_key = get_option('cardano_mint_anvil_api_key', '');
        $api_url = get_option('cardano_mint_anvil_api_url', '');
        $merchant_address = get_option('cardano_mint_merchant_address', '');

        $test_results = [
            'api_key_configured' => !empty($api_key),
            'api_url_configured' => !empty($api_url),
            'merchant_address_configured' => !empty($merchant_address),
            'api_url' => $api_url,
            'merchant_address' => $merchant_address
        ];

        // Test a simple API call
        if (!empty($api_key) && !empty($api_url)) {
            try {
                $response = wp_remote_get($api_url . '/health', [
                    'headers' => [
                        'X-Api-Key' => $api_key
                    ],
                    'timeout' => 10
                ]);

                if (!is_wp_error($response)) {
                    $test_results['api_health_check'] = 'success';
                    $test_results['api_response_code'] = wp_remote_retrieve_response_code($response);
                } else {
                    $test_results['api_health_check'] = 'error';
                    $test_results['api_error'] = $response->get_error_message();
                }
            } catch (Exception $e) {
                $test_results['api_health_check'] = 'error';
                $test_results['api_error'] = $e->getMessage();
            }
        } else {
            $test_results['api_health_check'] = 'skipped';
        }

        wp_send_json_success($test_results);
    }

    /**
     * Check rate limit for API calls
     */
    public static function checkRateLimit($action, $max_requests, $time_window) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rate_limit_{$action}_{$ip}";

        $requests = get_transient($key);
        if ($requests === false) {
            $requests = 0;
        }

        if ($requests >= $max_requests) {
            return false;
        }

        set_transient($key, $requests + 1, $time_window);
        return true;
    }
}
