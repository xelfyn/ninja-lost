# Ninja Lost

GitHub-first, reuse-first development on the existing Laravel/Filament backend. **Evaluation code only: no public game server, live Discord bot or APK is delivered yet.**

## Current direction

The owner rejected the current website design/layout as final on 28 October 2026. Comprehensive UI/UX refactoring is **DEFERRED**, not accepted canon. Continue functional integration first. No visual redesign in G8D; see `docs/UI_UX_REFACTOR.md`. Functional preview access and eventual visual approval remain separate items in #2.

## Implemented surfaces

- MB01: server text intake, admin authentication/operations, isolated MySQL runtime and private-preview configuration.
- MB02: player login/logout, owned characters, shared account tokens/character gold and inventory, content registry, starter/redeem campaigns, atomic claims and limited-supply ledger. New campaigns are drafts; registry entries disabled by default. No community assets imported.
- G8: staff grants share issuance, inventory, receipts, ledger and supply. Account revisions protect internal equipment saves. Unsafe legacy admin components and commands are quarantined, not claimed migrated.
- G8B: inactive-by-default immutable item/skill offers, explicit activation, transactional purchase debits plus shared issuance, retry/history. No default live prices.
- G8C: internal simple-mission battle settlement with frozen gold/XP/progression terms and atomic receipt/revision. **Real combat verification remains unavailable; default refuses untrusted results.** QA verdicts are explicit test doubles.
- G8D: opt-in loopback JSON diagnostic adapter plus source-owned Node client using existing Laravel web sessions/CSRF and domain services. Login, owned state/inventory, discovery, starter/redeem, purchase retry, equipment revision and logout are tested over real HTTP. **Not the inherited SWF/Android game client, an engine replacement or a playable game.** See `docs/G8D_CLIENT_GATEWAY.md` and `project/client-build-audit.json`.

Player paths: `/portal/login`, `/portal/home`, `/portal/catalog`, `/portal/shop`. Admin additions: `/admin/reward-campaigns`, `/admin/content-registry`, `/admin/staff-grants`, `/admin/shop-offers`. These require a running evaluation runtime, not deployed public URLs. Registration, legacy AMF/game mutations and battle HTTP routes remain closed.

G8D routes under `/evaluation/v1` register ONLY with `NL_EVALUATION_GATEWAY=true` in local/testing; middleware also requires loopback Host/request IP. Default-off; production rejects even with the flag set. It retains web CSRF and existing session cookies, not bearer tokens. This is not the live native-client protocol. Lists explicitly report truncation beyond 100; client cookies/intents are in memory, not crash-safe Android persistence.

## Verification

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
python3 scripts/ci/economy_inventory.py
node --test clients/evaluation/client.test.mjs
```

Isolated runtime setup: `ops/runtime/setup-ci.sh`. Suites: `ops/rewards/` (MB02), `ops/economy/` (G8), `ops/shop/` (G8B), `ops/battles/` (G8C), `ops/gateway/` (G8D). `ops/economy/browser.sh` exercises actual staff/portal/shop UI. `ops/gateway/http.sh` uses an actual four-worker HTTP server, database sessions and persistent file cache; APP_ENV=local ensures real CSRF enforcement. It tests eight simultaneous requests for one purchase intent, SQL reconciliation, process restart and logout. No battle test double is used in that HTTP economy flow. Fresh processes verify gateway routes absent by default and in production.

Read exact revision CI results before claiming PASS. Test definitions and candidate source are not evidence of execution. The source scanner is not complete data-flow/security proof; concurrency tests are not load benchmarks. No guards or legacy tests are removed to make the diagnostic adapter pass.

Routine PR/main CI has no artifact uploads or persistent dependency caches. Standard runners and isolated synthetic MySQL only; no automatic Codespaces/paid hosting. Prior requested visual export is opt-in, <=1MiB/one day; it is not invoked for function-only batches. Existing screenshots are rendering evidence, not accepted design. No real player data/credentials exported.

## Release boundaries

Intake pins source in `project/source-lock.json`, preserves notices and excludes client binaries, game assets, credentials, SQL dumps and default seeders. Server text licensing does not establish asset/client rights. The inspected upstream launch package is SWF/AIR desktop; an Android/game-core rebuild remains undemonstrated. The Node diagnostic client does not resolve that missing build path.

Issue #8 stays OPEN. Actual client contracts, combat verification, pet/recruit/fishing/entry-cost/drop settlement, legacy sale/refund/PvP prices, gacha, mail/events and other game writers need integration before activation. G8C is not a battle engine; G8D opens no win-claim endpoint. Production auth/cache/rate limits/backups/security/capacity, catalog rights/effects, Discord linking, APK/updater and physical performance remain outstanding.

Ledger direction distinguishes credit/debit; quantities remain nonnegative. Reconcile direction and before/after values, not unsigned sums. XP uses effective capped deltas; requested XP/levels stay in battle receipts. Purchase/battle tables are terms/receipts, not separate balances. Direct SQL/PHP remains a trusted operator boundary, not tamper-proof storage.

`main` is the accepted code baseline, not accepted visual design. Changes use reviewed PRs. Rollback does not retract grants/refund purchases. Retain additive schemas, terminal results, receipts/ledger/audit and direction-aware readers. Destructive automatic down is refused. G8D adds no schema; disabling/removing its adapter retains all existing economy history.

See `docs/MB01.md`, `docs/PRIVATE_PREVIEW.md`, `docs/MB02_PORTAL_REWARDS.md`, `docs/G8_ECONOMY_INTEGRITY.md`, `docs/G8B_CONTROLLED_SHOP.md`, `docs/G8C_BATTLE_SETTLEMENT.md`, `docs/G8D_CLIENT_GATEWAY.md`, `docs/CLIENT_COMPATIBILITY.md`, `docs/BATCH_EXECUTION.md` and `docs/UI_UX_REFACTOR.md`. Earlier implementations remain in Git history.
