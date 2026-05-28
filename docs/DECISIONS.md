# Cardano Easy Mint — Locked Decisions

> Append-only log of architectural decisions that took real thought, so we don't re-litigate them every time someone new lands in the codebase. Reach for this when you find yourself asking "wait, why is it done this way?". If you're revisiting a locked decision, don't quietly change it — add a new entry that supersedes the old one and explain what changed.
>
> Format per decision:
> 1. **Title** + ID (`D1`, `D2`, ...)
> 2. **Context** — what problem prompted this
> 3. **Decision** — what we picked
> 4. **Why** — the alternative(s) considered and why this beat them
> 5. **Trade-offs** — what we're paying for this choice
> 6. **Status** — locked / superseded-by-D... / under-review

---

## Asset Upgrade (burn & re-mint)

The eight decisions enumerated in [BUILD_PLAN.md § Locked decisions](BUILD_PLAN.md#locked-decisions) (D1–D8) apply here. Expanded versions will be filled in here as each is implemented and verified; entries below are the placeholders, not the final write-ups.

### AU-D1 — Burn + re-mint in one atomic transaction

**Status:** locked at design time. Anvil payload shape confirmed by pb 2026-05-27 — burn entry is the same shape as the mint entry with `quantity: -1` instead of `1`; both ride in the same `mint` array under the same policy id. CIP-25 atomic update semantics work natively.

### AU-D2 — Same `policy_id + asset_name`, new metadata only

**Status:** locked. Fingerprint preservation is the whole point of the feature; cross-policy / renamed migrations are explicitly out of scope.

### AU-D3 — Customer pays the ~0.17 ADA network fee

**Status:** locked for v1. Merchant subsidy is layerable later via the existing Payment Wallets infrastructure if a project wants to monetize updates.

### AU-D4 — Time-lock check at both register and build time

**Status:** locked. Single-check-at-register is insufficient: a policy can lock between admin setup and a user actually upgrading. Re-checking at build refuses doomed signatures.

### AU-D5 — Per-asset spec rows beat policy-wide patch rows

**Status:** locked. Enforced in the resolver: any row with non-empty `asset_name` wins over the matching `asset_name = ''` (policy-wide) row. Empty string instead of NULL so the unique key actually enforces one-per-(policy, asset).

### AU-D6 — Admin pause without delete

**Status:** locked. `status = 'paused'` halts frontend eligibility but preserves the spec. Deletion is an explicit second step and is audited.

### AU-D7 — Asset Upgrade admin page shares the Payment Wallets 2FA gate

**Status:** locked. One TOTP enrollment, one secret, one 30-minute unlock window across both pages. Implementation mirrors `AltPayAdminController::isTotpUnlocked()`.

### AU-D8 — Audit log is append-only

**Status:** locked. `wp_cardano_asset_upgrade_log` rows are never overwritten; the `status` column tracks lifecycle (built / submitted / confirmed / failed). Closes by status, never deleted.
