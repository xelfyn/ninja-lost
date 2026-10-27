# Ninja Lost

GitHub-first, reuse-first development on the existing Laravel/Filament backend. **Evaluation code only: no public game server, live Discord bot or APK is delivered yet.**

## Implemented surfaces

- MB01: server text intake, admin authentication/operations, isolated MySQL runtime and private-preview configuration. User-accessible preview and human visual acceptance remain in issue #2.
- MB02: player login/logout, owned characters, shared account tokens/character gold and inventory, content registry, starter/redeem campaigns, atomic claims and limited-supply ledger. New campaigns are drafts; registry entries disabled by default. No community assets imported.
- G8: staff grants share issuance, inventory, receipts, ledger and supply. Account revisions protect the internal equipment-save contract. Unsafe legacy admin components and commands are quarantined, not claimed migrated.
- G8B: inactive-by-default immutable item/skill offers, explicit activation, transactional purchase debits plus shared issuance, retry and history. No default live prices.
- G8C: internal simple-mission battle lifecycle/settlement contract, frozen gold/XP/progression terms, persistent replay and atomic XP/level/reward/ledger/revision. **No real combat verifier exists yet; default verification refuses untrusted results.** QA uses an explicit fixture verifier, not a production win algorithm.

Player paths: `/portal/login`, `/portal/home`, `/portal/catalog`, `/portal/shop`. Admin additions: `/admin/reward-campaigns`, `/admin/content-registry`, `/admin/staff-grants`, `/admin/shop-offers`. These require a running evaluation runtime, not deployed public URLs. Registration, legacy game mutations and new battle HTTP routes remain closed. Internal state/battle services are not client-certified AMF endpoints.

## Verification

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
python3 scripts/ci/economy_inventory.py
```

Isolated runtime setup: `ops/runtime/setup-ci.sh`. Suites: `ops/rewards/` (MB02), `ops/economy/` (G8), `ops/shop/` (G8B), `ops/battles/` (G8C). These cover respective transactions/authorization, replay, upgrade, fault rollback, explicit verifier refusal and independent-process race cases. `ops/economy/browser.sh` exercises actual staff/portal/shop UI flows. Read exact revision CI results before claiming PASS. The source scanner is not a complete data-flow/security proof.

Routine PR/main CI has no artifact uploads or persistent caches. Standard runners and isolated synthetic MySQL only; no automatic Codespaces/paid hosting. User requested current visuals on 28 October 2026: separate opt-in `visual-review.yml` can export up to 1MiB of selected actual screenshots and source/hash manifest for one day. Screenshot download/review and private-preview acceptance are separate, not assumed. No screenshots committed as source. No real player data/credentials exported.

## Release boundaries

Intake pins source in `project/source-lock.json`, preserves notices and excludes client binaries, game assets, credentials, SQL dumps and default seeders. Server text licensing does not establish all asset rights.

Issue #8 stays OPEN. Real client contracts, combat verification, pet/recruit/fishing/entry-cost/drop settlement, other legacy purchase/sale/refund/PvP prices, gacha, mail, event and game writers require integration before activation. G8C does not provide a battle engine or open a win-claim endpoint. Internal tests and web refresh do not certify actual Android behavior. Catalog rights, metadata lifecycle, production cache/rate limits/backups/security/capacity, Discord linking, APK/updater and physical performance remain outstanding.

Ledger direction distinguishes credit/debit; historical grants default credit. Quantity is nonnegative and reconciliation uses direction plus before/after, not unsigned sums. XP entries use effective capped deltas; requested XP and levels are preserved in battle receipts. Purchase/battle tables hold terms and receipts, not separate balances. Direct SQL/PHP remains a trusted operator boundary, not tamper-proof storage.

`main` is accepted code; changes use reviewed PRs. Code rollback does not retract grants/refund purchases. Retain additive schemas, terminal results, claims/ledger/audit/purchase history. Destructive automatic down is refused. Keep runtime restricted and readers direction-aware during recovery.

See `docs/MB01.md`, `docs/PRIVATE_PREVIEW.md`, `docs/MB02_PORTAL_REWARDS.md`, `docs/G8_ECONOMY_INTEGRITY.md`, `docs/G8B_CONTROLLED_SHOP.md`, `docs/G8C_BATTLE_SETTLEMENT.md`, `docs/CLIENT_COMPATIBILITY.md` and `docs/BATCH_EXECUTION.md`. Earlier implementations remain in Git history.
