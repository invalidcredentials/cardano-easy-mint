# Discount Codes — Build Plan

E-commerce-style discount codes for the mint checkout. An operator creates a
**campaign** of codes scoped to a policy; a customer enters a code at checkout to
reduce the price; the discount works across every payment method (ADA / BTC /
ETH / SOL); codes are tracked by campaign for usage + results.

First use case: Jaron generates 50 unique single-use codes that turn a $20 mint
into a $5 mint (75% off, or $15 off), hands them out one at a time, and each is
spent on redemption and never usable again.

> Status: **planning**. Decisions DC-D1..DC-D9 below are locked with PB
> (2026-06-18). Nothing here touches the chain logic — it intercepts the **price**
> before it is converted to ADA and sent to Anvil.

---

## Locked decisions

### DC-D1 — Server-authoritative pricing (never trust the client)
The discount is **always** computed server-side from the stored code + the stored
collection price. The client only ever sends a *code string*; it never sends a
price or a discount amount. Mirrors the existing rule: "client-supplied prices
are ignored at build time; the DB price is the source of truth." **Locked.**

### DC-D2 — Discount modifies only the MSRP / merchant-payment output
The Anvil service fee, the admin-configured service fee, and the 1 ADA receipt
per NFT are **untouched**. The coupon overrides the merchant-payment component
only (the `$usd_price` → merchant ADA output in `AnvilAPI::buildMintTransaction`,
and the quoted crypto amount for alt-pay). **Locked.**

### DC-D3 — v1 types = percent + fixed-amount; BOGO is phase 3
`percent` (e.g. 75% off) and `fixed` (e.g. $15 off) ship first — they cover the
launch use case and don't interact with quantity. `bogo` / buy-X-get-Y is
deferred: it depends on the 1–5 quantity stepper, and alt-pay locks qty=1, so it
would only work on the ADA path. The schema reserves the `bogo` enum value +
columns so phase 3 is additive. **Locked.**

### DC-D4 — Redemption lifecycle = reserve → commit → release
- **Reserve** when the code is applied (ADA build) or quoted (alt-pay), with a
  TTL (default 15 min, matching the alt-pay price lock).
- **Commit** (mark redeemed) when the mint tx is submitted / the alt-pay invoice
  is consumed.
- **Release** automatically when the checkout is abandoned (TTL cron) or fails.

Use limits are enforced with an **atomic conditional update**
(`UPDATE … SET uses_count = uses_count + 1 WHERE id = ? AND uses_count + active_reservations < uses_allowed`)
so two simultaneous redemptions can't exceed the cap. This is what makes alt-pay
safe — a customer can't deposit BTC against a code someone else already claimed.
**Locked.**

### DC-D5 — Code format: 8-char alphanumeric batches + optional single shared code
Batch codes are 8 chars from an unambiguous alphabet (no `0/O`, `1/I/L`), e.g.
`VPR7K2M9`. ~`28^8 ≈ 3.7e11` space — not brute-forceable. A campaign can instead
define **one shared, human code** (e.g. `VIPERS20`) with an N-use or expiry cap.
The validate endpoint is rate-limited (reuse the alt-pay limiter pattern: per-IP
per-minute + per-day) to kill enumeration. **Locked.**

### DC-D6 — No 2FA gate on the admin tab
The Discounts tab is gated by `manage_options` capability + WP nonces only (no
TOTP), to keep batch generation friction-free for the operator. (Payment Wallets
+ Asset Upgrades keep their TOTP gate; discounts intentionally do not.) **Locked
per PB.**

### DC-D7 — Scope is policy-level for v1
A campaign targets one `policy_id` (the whole collection), chosen from a dropdown
exactly like the asset-creation flow. Per-asset / per-variant scoping is a later
addition (schema leaves room via a nullable `asset_name`). **Locked.**

### DC-D8 — Two price-application points; validate is read-only
- **ADA path** → discount applied at **build** (`ajaxBuildMintTransaction` / REST
  `mint/build`).
- **Alt-pay path** → discount applied at **quote** (`/altpay/quote`), because the
  deposit amount the RPC watcher waits for is locked there; the discounted amount
  is stored on the invoice.
- **`/discount/validate`** is a read-only price preview for the modal; it reserves
  nothing and changes nothing. **Locked.**

### DC-D9 — Fixed-amount applies per order; clamp at the floor
A `fixed` discount is taken off the **order subtotal** (`price × quantity`), not
per asset. Percent is naturally per-order = per-asset. Any discount that would
drive the merchant payment below $0 is clamped to $0 (100%-off → omit the
merchant output entirely but keep the fees + a min-UTxO receipt so the tx is
still valid). **Locked.**

---

## Data model (3 tables, mirrors the altpay / asset-upgrade pattern)

### `wp_cm_discount_campaigns` — the rule + the tracking unit
```
id              BIGINT UNSIGNED PK
title           VARCHAR(190)   -- "Vipers Trial Mint $5 Mints"
policy_id       VARCHAR(56)    -- target collection
asset_name      VARCHAR(255) NULL  -- reserved for per-asset scope (v1: NULL)
network         VARCHAR(20)
discount_type   VARCHAR(16)    -- 'percent' | 'fixed' | 'bogo'(phase 3)
percent_off     DECIMAL(5,2) NULL   -- when type=percent (0–100)
fixed_off_usd   DECIMAL(10,2) NULL  -- when type=fixed
bogo_buy_qty    INT NULL            -- phase 3
bogo_free_qty   INT NULL            -- phase 3
code_mode       VARCHAR(16)    -- 'batch' | 'shared'
uses_per_code   INT UNSIGNED   -- 1 = single-use; 0 = unlimited
starts_at       DATETIME NULL
expires_at      DATETIME NULL
status          VARCHAR(16)    -- 'active' | 'paused' | 'expired'
created_at      DATETIME
KEY (policy_id), KEY (status)
```

### `wp_cm_discount_codes` — the strings
```
id            BIGINT UNSIGNED PK
campaign_id   BIGINT UNSIGNED   (FK)
code          VARCHAR(32)       -- stored uppercased; UNIQUE
uses_allowed  INT UNSIGNED      -- copied from campaign.uses_per_code (0=unltd)
uses_count    INT UNSIGNED DEFAULT 0   -- committed redemptions
status        VARCHAR(16)       -- 'active' | 'disabled' | 'exhausted'
created_at    DATETIME
UNIQUE KEY (code), KEY (campaign_id)
```

### `wp_cm_discount_redemptions` — reservations + results (audit)
```
id              BIGINT UNSIGNED PK
code_id         BIGINT UNSIGNED   (FK)
campaign_id     BIGINT UNSIGNED   (FK)
policy_id       VARCHAR(56)
wallet_address  VARCHAR(128)
payment_method  VARCHAR(8)        -- 'ada' | 'btc' | 'eth' | 'sol'
quantity        INT UNSIGNED
original_usd    DECIMAL(10,2)
discount_usd    DECIMAL(10,2)
final_usd       DECIMAL(10,2)
status          VARCHAR(12)       -- 'reserved' | 'redeemed' | 'released'
invoice_id      BIGINT UNSIGNED NULL  -- alt-pay linkage
tx_hash         VARCHAR(80) NULL
reserved_at     DATETIME
redeemed_at     DATETIME NULL
KEY (code_id), KEY (campaign_id), KEY (status), KEY (invoice_id)
```

---

## Endpoints

| Method | Route / action | Auth | Purpose |
|--------|----------------|------|---------|
| POST | `cardano-mint/v1/discount/validate` | nonce, rate-limited | Preview: `{code, policy_id, quantity, payment_method}` → `{valid, type, original_usd, discount_usd, final_usd, label, error}`. Reserves nothing. |
| (hook) | `mint/build` + `ajaxBuildMintTransaction` | existing | Accept optional `discount_code`; re-validate; **reserve**; build with discounted merchant output. |
| (hook) | `altpay/quote` | existing | Accept optional `discount_code`; re-validate; **reserve**; store discounted amount on the invoice. |
| (hook) | `mint/submit` + altpay consume | existing | **Commit** the matching reservation; bump `uses_count` atomically. |
| cron | discount reservation sweeper | — | **Release** reservations past TTL (reuse/extend an existing 5-min tick). |

Admin AJAX (all `manage_options` + nonce): `cardano_discount_create_campaign`
(validates + generates N codes), `cardano_discount_list_campaigns`,
`cardano_discount_view_campaign` (codes + redemptions), `cardano_discount_set_status`
(pause/activate), `cardano_discount_disable_code`, `cardano_discount_export_csv`.

---

## Admin tab — top-level "Discounts"

New top-level page alongside Policy Wallet / Payment Wallets. Create wizard:

1. **Policy** — dropdown of policies (same source as asset creation).
2. **Type** — percent / fixed (BOGO greyed "coming soon").
3. **Value(s)** — % or $ off.
4. **Use mode** — single-use or N-use; optional expiry.
5. **Code mode** — *batch* (how many random codes to generate) **or** *shared*
   (one human code string, e.g. `VIPERS20`).
6. **Title** — campaign name for tracking ("Vipers Trial Mint $5 Mints").
7. Generate → list of codes (copy / download CSV).

Campaign list shows title, policy, type, #codes, #redeemed, status. Drill-in
shows per-code usage + the redemptions audit, with CSV export (reuse the Active
Mints CSV pattern).

---

## Customer UX

At the payment step (right after wallet connect, any payment method), a
collapsible **"Have a code?"** input + Apply. Apply calls `/discount/validate`
and, on success, rewrites the order summary: ~~$20~~ **$5** with a "VIPERS75 –
75% off" chip. The code rides along into the build (ADA) or quote (alt-pay). The
fee lines stay visible and unchanged so it's clear what the discount did and
didn't touch.

---

## Edge cases to cover

- **100% off** → omit merchant payment output; keep service fee(s) + 1 ADA
  receipt so the tx still has a valid customer output.
- **Fixed ≥ price** → clamp final to $0 (treat as 100% off).
- **Expired / paused campaign, disabled / exhausted code, wrong policy** → clear,
  specific error from validate (and re-checked at build/quote — never trust the
  earlier validate).
- **Network mismatch** → existing wallet-network gate still applies; discount
  doesn't bypass it.
- **Reservation TTL** → 15 min; a released reservation frees the use again.
- **Quantity** → percent scales naturally; fixed is per-order (DC-D9); alt-pay is
  qty=1 so both are trivial there.

---

## Phasing

- **Phase 1 — foundation + ADA + admin + UI.** 3 tables + models, validate
  endpoint (rate-limited), reserve/commit/release + sweeper, ADA build
  integration, the Discounts admin tab (create/list/view/CSV), customer
  "Have a code?" UI. *Ships Jaron's use case on the ADA path.*
- **Phase 2 — alt-pay.** Apply the discount at `/altpay/quote`, store discounted
  amount on the invoice, commit on consume. BTC / ETH / SOL.
- **Phase 3 — BOGO.** Buy-X-get-Y on the ADA path (quantity-aware pricing).

Each phase is its own PR off `pb/discount-codes` (or sub-branches), reviewed
before merge.
