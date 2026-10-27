# Ninja Lost

GitHub-first, reuse-first development on the existing Laravel/Filament backend. **Evaluation code only: no public game server, live Discord bot or APK is delivered yet.**

## Implemented surfaces

- MB01: existing server text intake, admin authentication/operations, isolated MySQL runtime and private-preview configuration. Actual user preview and human visual acceptance remain open in issue #2.
- MB02 (PR #7): player login/logout, owned-character selection, shared account tokens/character gold and inventory, catalog registry, starter/redeem campaigns, atomic claims and limited-supply ledger. New campaigns are drafts and catalog entries are disabled by default. No real community catalog or game assets were imported by MB02.

Player paths: `/portal/login`, `/portal/home`, `/portal/catalog`. Admin additions: `/admin/reward-campaigns`, `/admin/content-registry`. These paths require a running evaluation runtime; they are not a deployed public URL. Public registration and game mutation routes remain closed.

## Checks

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
```

Runtime setup: `ops/runtime/setup-ci.sh` in isolated GitHub Actions. MB02 integration, eight-worker race tests and actual Chromium portal flows are in `ops/rewards/`. Read the workflow results for the exact tested revision; code or a test definition alone is not proof of success. Browser waits are bounded and screenshots are ephemeral, not human visual approval.

No Actions artifact uploads, persistent dependency caches, automatic Codespaces or paid hosting. Runtime jobs use read-only tokens and synthetic evaluation MySQL data.

## Release gates and source scope

The controlled intake is pinned in `project/source-lock.json`, preserves upstream notices, and excludes client binaries, game assets, credentials, SQL dumps and default seeders. MIT server text is not proof of rights to all game assets.

Legacy direct grants/admin edits and stale client-save paths have not been migrated to the MB02 ledger/locking protocol. Issue #8 tracks this release gate: do not enable gameplay or infer whole-economy safety from the isolated new reward tests. Real catalog rights, production backups/security/load, Android/updater and real-device performance remain outstanding.

`main` is the accepted code baseline; changes are reviewed through pull requests. The intake job may create a new review branch, never write to main. Code rollback does not undo issued rewards or reverse database writes.

See `docs/MB01.md`, `docs/PRIVATE_PREVIEW.md`, `docs/MB02_PORTAL_REWARDS.md`, `docs/CLIENT_COMPATIBILITY.md`, and `docs/BATCH_EXECUTION.md`. Bootstrap B00 is preserved in Git history.
