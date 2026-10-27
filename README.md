# Ninja Lost

GitHub-first, reuse-first development on the existing Laravel/Filament backend. **Evaluation code only: no public game server, live Discord bot or APK is delivered yet.**

## Implemented surfaces

- MB01: controlled server text intake, admin authentication/operations, isolated MySQL runtime and private-preview configuration. User-accessible preview and human visual acceptance remain open in issue #2.
- MB02: player login/logout, owned-character selection, shared account tokens/character gold and inventory, catalog registry, starter/redeem campaigns, atomic claims and limited-supply ledger. New campaigns are drafts; registry entries are disabled by default. No real community catalog or assets were imported by MB02.
- G8 continuation: controlled staff grants use the same issuance, inventory, receipt, ledger and supply limits. Account-wide revisions protect the new internal equipment-save contract against stale state. Unsafe legacy admin resources/components and application commands are quarantined rather than claimed migrated. See `docs/G8_ECONOMY_INTEGRITY.md` for the exact boundary.

Player paths: `/portal/login`, `/portal/home`, `/portal/catalog`. Admin additions: `/admin/reward-campaigns`, `/admin/content-registry`, `/admin/staff-grants`. Paths require a running evaluation runtime; they are not deployed public URLs. Public registration and legacy game mutation endpoints remain closed. `ClientStateService` is an internal tested contract, not a newly published or client-certified AMF endpoint.

## Checks

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
python3 scripts/ci/economy_inventory.py
```

Runtime setup: `ops/runtime/setup-ci.sh` in isolated GitHub Actions. MB02 suites are in `ops/rewards/`. G8 suites in `ops/economy/` cover staff/portal transactions, rejected stale state, signed legacy component hydration, nonempty schema upgrade, concurrent writers and real browser staff-to-player flows. Read actual workflow results for the tested revision: test definitions alone are not PASS evidence. The source inventory is a conservative candidate scan, not a complete data-flow proof or vulnerability count.

No Actions artifact uploads, persistent dependency caches, automatic Codespaces or paid hosting. Runtime jobs use read-only tokens and synthetic isolated MySQL data. Browser waits are bounded; screenshots are ephemeral and not human visual approval or physical-device testing.

## Release gates and source scope

The intake is pinned in `project/source-lock.json`, preserves upstream notices, and excludes client binaries, game assets, credentials, SQL dumps and default seeders. Server text licensing is not proof of rights to all game assets.

Issue #8 remains OPEN for live gameplay: shop/debit, battle rewards, events, mailbox, gacha and real client packets must be migrated and tested before their legacy routes can be activated. Containment is not equivalent to full gameplay integration. The controlled portal/staff paths do not certify unrestricted raw PHP/SQL access, metadata lifecycle changes or the entire game economy. Real catalog rights, backups/security/load, Discord account binding, Android/updater and device performance remain outstanding.

`main` is the accepted code baseline; changes use pull requests. The intake job may create a new review branch, never write to main. Code rollback cannot undo issued rewards; retain receipt/ledger/audit data and additive revision schema. The new integrity migration refuses automatic destructive rollback. Keep runtime restricted during recovery rather than restoring old writers alongside new rewards.

See `docs/MB01.md`, `docs/PRIVATE_PREVIEW.md`, `docs/MB02_PORTAL_REWARDS.md`, `docs/G8_ECONOMY_INTEGRITY.md`, `docs/CLIENT_COMPATIBILITY.md`, and `docs/BATCH_EXECUTION.md`. Bootstrap B00 and earlier implementations are preserved in Git history.
