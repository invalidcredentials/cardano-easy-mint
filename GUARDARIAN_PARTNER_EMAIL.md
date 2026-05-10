# Guardarian partner setup — email to business@guardarian.com

Send the email below before the integration goes live. Items 1 and 2 are
load-bearing (the webhook IP allowlist will refuse every request without
them, and we can't safely test against production until staging is wired
up). Items 3 and 4 are nice-to-haves we can add later.

---

**To:** business@guardarian.com
**Subject:** Knights Guild — partner integration setup (sandbox + webhooks)

Hi team,

We're building a fiat-to-ADA on-ramp inside our NFT mint flow on
knightsguild.io using our existing Guardarian partner account. The
integration is live in code and ready to test — a few things from your
side would unblock us:

**1. Sandbox / staging credentials.** Production is fine for the eventual
launch, but we'd rather smoke-test the full flow against staging first.
Any test API keys + the staging API base URL would help.

**2. Webhook outbound IP list.** Our webhook endpoint authenticates by
source IP (HMAC isn't documented in the public OpenAPI). Could you send
the IPs Guardarian's webhooks originate from so we can allowlist them?

Webhook URL on our side:
`https://knightsguild.io/wp-json/cardano-mint/v1/onramp/webhooks/guardarian`

**3. (Optional, when convenient) Enable `allow_preset_payout_address` on
our partner account.** We're shipping with the customer entering their
own ADA address inside the iframe, which works fine — but if the
permission is available we'd flip the toggle to remove that step.

**4. (Optional) Access to the `customer_country` query parameter.** Lets
us geolocate from the backend so payment-method availability uses the
real customer IP rather than our VPS IP.

**5. Enable widget theming on our account.** We tried passing
`calc_background`, `body_background`, `select_background`, `button_background`,
`submit_button_color`, `title_color`, `active_tab_background`, etc. on the
calculator widget URL (per the documented `getWidgetUrlParams` list). The
widget silently ignored most overrides AND the Buy/Sell tabs and submit
button disappeared from the rendered UI when those params were present —
suggesting the theming permission isn't enabled on our partner account.
Could you flip whichever flag controls that, and confirm the canonical
param names if any have changed? We want a dark KG-themed embed.

For context on the integration: we POST `/v1/transaction` with `to_currency=ADA`,
`to_network=ADA`, `external_partner_link_id` for correlation, and
`X-Forwarded-For` set to the customer IP so the per-IP rate limit is
per-customer. ADA lands in the customer's own connected Cardano wallet
(payout address comes from their wallet connection, not our operator
wallet) — so chargebacks aren't our exposure.

Happy to hop on a call if anything is easier verbally.

Thanks,
[Name]
Knights Guild

---

## After Guardarian replies

In WP Admin → Cardano Mint → Payment Wallets → Card (Guardarian):

1. Paste the API key into "API key (x-api-key)" and save.
2. Click "Test connection" — should show ✓ ADA buyable.
3. If they sent a secret key, paste it too.
4. Paste the webhook IP list (one per line or comma-separated) into
   "Webhook IP allowlist" and save.
5. If they enabled `allow_preset_payout_address`, tick "Pre-fill wallet
   address" (advanced) — this skips the address-entry step in the iframe
   and presets the customer's connected wallet address for them.
6. Tick "Enable card payments" to turn the customer-facing CTA on.
