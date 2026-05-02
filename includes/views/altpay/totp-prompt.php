<?php
/**
 * Lockscreen for the Payment Wallets page when 2FA is enabled.
 *
 * Variables in scope (set by AltPayAdminController::renderTotpPrompt):
 *   $base_url     string  back-to-page URL
 *   $rate_limited bool    too many failed attempts in last 5 min
 *   $just_failed  bool    last submission failed (and we're not rate limited)
 *   $remaining    int     attempts remaining before cooldown kicks in
 *   $nonce        string  wp_create_nonce('kg_totp_unlock')
 */
if (!defined('ABSPATH')) exit;

use CardanoMintPay\Controllers\AltPayAdminController;

$recovery_left = AltPayAdminController::totpRecoveryRemaining();
?>
<div class="wrap kg-altpay-wrap">
    <h1>Payment Wallets</h1>

    <div class="kg-totp-gate" style="max-width: 520px; margin: 32px auto; padding: 28px 32px; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; box-shadow: 0 4px 14px rgba(0,0,0,0.06);">
        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 22px;">&#128274;</span>
            Two-Factor Authentication
        </h2>
        <p style="color: #555; line-height: 1.55;">
            This page is protected by 2FA. Enter the 6-digit code from your
            authenticator app, or one of your recovery codes.
        </p>

        <?php if ($rate_limited): ?>
            <div class="notice notice-error" style="margin: 14px 0; padding: 12px 14px;">
                <strong>Too many failed attempts.</strong> Wait ~5 minutes
                before trying again. (If you're truly locked out, an admin
                with SSH access can disable 2FA via:
                <code>wp option update <?php echo esc_html(AltPayAdminController::TOTP_OPT_ENABLED); ?> 0</code>.)
            </div>
        <?php elseif ($just_failed): ?>
            <div class="notice notice-error" style="margin: 14px 0; padding: 12px 14px;">
                <strong>Code did not match.</strong>
                <?php if ($remaining > 0): ?>
                    <?php echo (int) $remaining; ?> attempt<?php echo $remaining === 1 ? '' : 's'; ?> remaining before a 5-minute cooldown.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($base_url); ?>" autocomplete="off" style="margin-top: 18px;">
            <input type="hidden" name="kg_totp_unlock" value="1">
            <input type="hidden" name="_kg_totp_nonce" value="<?php echo esc_attr($nonce); ?>">
            <label for="kg-totp-code" style="display: block; font-weight: 600; margin-bottom: 6px;">Code</label>
            <input
                type="text"
                id="kg-totp-code"
                name="kg_totp_code"
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="123456 or XXXX-XXXX"
                maxlength="11"
                style="width: 100%; padding: 10px 12px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 18px; letter-spacing: 2px; border: 1px solid #c3c4c7; border-radius: 4px;"
                <?php echo $rate_limited ? 'disabled' : 'autofocus'; ?>
            >
            <p class="description" style="margin: 8px 0 16px 0; color: #666; font-size: 12px;">
                6 digits from Google Authenticator / Authy / 1Password / Bitwarden, or a single-use recovery code (<?php echo (int) $recovery_left; ?> left).
            </p>
            <button type="submit" class="button button-primary button-large" <?php disabled($rate_limited); ?>>
                Unlock
            </button>
        </form>

        <p style="margin-top: 22px; font-size: 11px; color: #888;">
            Session unlocks for 30 minutes after a successful code. WordPress
            admin login is still required separately.
        </p>
    </div>
</div>
