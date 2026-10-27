# Ninja Lost

GitHub-first, reuse-first development on the existing Laravel/Filament backend. **Evaluation code only: no public game server, live Discord bot or APK is delivered yet.**

## Implemented surfaces

- MB01: controlled server text intake, admin authentication/operations, isolated MySQL runtime and private-preview configuration. User-accessible preview and human visual acceptance remain open in issue #2.
- MB02: player login/logout, owned-character selection, shared account tokens/character gold and inventory, catalog registry, starter/redeem campaigns, atomic claims and limited-supply ledger. New campaigns are drafts; registry entries are disabled by default. No real community catalog or assets were imported by MB02.
- G8 continuation: controlled staff grants use the same issuance, inventory, receipt, ledger and supply limits. Account-wide revisions protect the internal equipment-save contract against stale state. Unsafe legacy admin resources/components and application commands are quarantined rather than claimed migrated. See `docs/G8_ECONOMY_INTEGRITY.md`.
- G8B controlled shop: operator-authored inactive item/skill offers, explicit activation, immutable prices, definition-change rejection, owned-character purchases, transactional token/gold debit plus shared issuance, account-scoped retries and purchase history. No default offer or live economy pricing. See `docs/G8B_CONTROLLED_SHOP.md` for limits and recovery.

Player paths: `/portal/login`, `/portal/home`, `/portal/catalog`, `/portal/shop`. Admin additions: `/admin/reward-campaigns`, `/admin/content-registry`, `/admin/staff-grants`, `/admin/shop-offers`. Paths require a running evaluation runtime; they are not deployed public URLs. Public registration and legacy game mutation endpoints remain closed. `ClientStateService` is an internal contract, not a client-certified AMF endpoint.

## Checks

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
python3 scripts/ci/economy_inventory.py
```

Runtime setup: `ops/runtime/setup-ci.sh` in isolated GitHub Actions. MB02 suites are in `ops/rewards/`. G8 suites in `ops/economy/` cover staff/portal transactions, stale state, signed legacy component hydration, schema upgrade and concurrency. G8B suites in `ops/shop/` cover purchase/debit integrity, populated G8 upgrades, fault rollback and five additional ready-barrier races. `ops/economy/browser.sh` exercises real G8 and shop UI flows using the same temporary pinned Playwright install. Read actual workflow results for the exact revision: test definitions alone are not PASS evidence. Source inventory is a conservative candidate scan, not complete data-flow proof or a vulnerability count.

No Actions artifact uploads, persistent dependency caches, automatic Codespaces or paid hosting. Runtime jobs use read-only tokens and synthetic isolated MySQL data. Browser waits are bounded; screenshots are ephemeral and not human visual approval or physical-device testing.

## Release gates and source scope

The intake is pinned in `project/source-lock.json`, preserves upstream notices, and excludes client binaries, game assets, credentials, SQL dumps and default seeders. Server text licensing is not proof of rights to all game assets.

Issue #8 remains OPEN for live gameplay. The controlled item/skill offer path is not migration of all legacy shop/debit protocols, PvP/library prices, sales/refunds, pet multiplicity, battle rewards, events, mailbox, gacha or real client packets. Those contracts require integration/testing before activation. Containment is not full gameplay integration. Controlled portal/staff/shop paths do not certify unrestricted raw PHP/SQL access, the full metadata lifecycle or the entire game economy. Real catalog rights, backups/security/load, Discord binding, Android/updater and device performance remain outstanding.

The existing ledger now distinguishes `credit` and `debit`; historical grant entries default to `credit`. Quantity remains nonnegative. Reconciliation uses direction and before/after balances, not an unsigned sum of quantities. The purchase table stores original costs/terms only; balances and inventory remain on the existing account/character tables.

`main` is the accepted code baseline; changes use pull requests. Code rollback cannot undo issued rewards or refund purchases. Retain receipt/ledger/audit/purchase history and additive schema. Integrity/shop migrations refuse automatic destructive rollback. Keep runtime restricted during recovery and ensure readers handle debit/credit rather than restoring unsafe writers.

See `docs/MB01.md`, `docs/PRIVATE_PREVIEW.md`, `docs/MB02_PORTAL_REWARDS.md`, `docs/G8_ECONOMY_INTEGRITY.md`, `docs/G8B_CONTROLLED_SHOP.md`, `docs/CLIENT_COMPATIBILITY.md`, and `docs/BATCH_EXECUTION.md`. Bootstrap B00 and earlier implementations are preserved in Git history.
