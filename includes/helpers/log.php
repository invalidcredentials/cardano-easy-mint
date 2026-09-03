<?php
/**
 * Plugin-wide logging helper.
 *
 * Debug-level messages (the default) only reach the PHP error log when
 * WP_DEBUG is on, or when the `cardano_mint_debug_log` filter returns true.
 * Error-level messages always log. This keeps a production site's debug.log
 * quiet while preserving the full trace when an operator is investigating.
 *
 *   cardanomint_log('Anvil API call result: ' . print_r($result, true)); // debug
 *   cardanomint_log('Anvil API call failed: ' . $err, 'error');          // always
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cardanomint_log')) {
    function cardanomint_log($message, string $level = 'debug'): void {
        if ($level === 'debug') {
            $enabled = defined('WP_DEBUG') && WP_DEBUG;
            if (function_exists('apply_filters')) {
                $enabled = (bool) apply_filters('cardano_mint_debug_log', $enabled);
            }
            if (!$enabled) return;
        }
        if (!is_string($message)) {
            $message = print_r($message, true);
        }
        error_log($message);
    }
}
