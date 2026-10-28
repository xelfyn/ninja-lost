# G8G — playable training client, not a native game release

Continuation from CS02 main 8bd7230259767c39fc61297d127e7a782af52527. This batch connects actual player browser controls to published owned skills/equipment, the pinned community Battle/StatsCalc and durable MySQL training state. No new admin panel or replacement damage engine. Visual redesign remains deferred.

## Scope and access

Explicit opt-in only: both NL_EVALUATION_GATEWAY=true and NL_TRAINING=true, APP_ENV local/testing, loopback Host AND request IP. Default/production remain closed, including cached routes. Existing Laravel login/session/password-version/30-minute authorization stamp and CSRF remain. Functional client: /evaluation/v1/training/client. This is a path on a running evaluation runtime, NOT a hosted private/public URL. No provider or desktop was provisioned.

NL_COMBAT_SOURCE must be a trusted absolute directory holding the exact Battle.js and StatsCalc.js from ops/combat/upstream-lock.json. Existing intake downloads and verifies these text files temporarily in CI. No runtime download from player input, client binaries, SDK or SWF/assets. The PHP process invokes a fixed Node script with an argument array, stdin JSON, a five-second timeout and bounded input/output. App/DB/CI secret environment variables are not forwarded. VM dependency isolation is not a sandbox for untrusted code; hash-pinned server source and host operators remain trusted.

## Player flow

Login -> select owned character -> choose existing owned Studio equipment and up to eight Studio skills -> save through existing character storage and equipment service -> start training -> weapon/skill/charge/dodge/run -> server-computed terminal result -> reload/resume -> logout.

Only validated published Studio mechanics are admitted. Level, premium, element, slot, ownership, active catalog and unsupported pet/talent/senjutsu checks stay in the existing PublishedLoadout/CombatLoadout path. Old/unpublished skills are not silently reinterpreted. Lists are bounded at100 with a visible truncation flag. Skill/equipment changes are atomic and optimistic account-revision checked; no new inventory, wallet or supply writer.

The opponent is an explicitly synthetic engine-local mirror of the captured player stats/equipment, with automatic weapon-only actions. Its integer handle is not a DB identity or second account. This is paused single-player training derived from the existing two-participant evaluation combat adapter, not enemy/mission parity, real PvP or an approved live balancing rule. Virtual time advances internal animation/status transitions, then pauses for player input. A run expires after30 wall-clock minutes and is bounded at128 player actions. There is no real-time PvP timeout claim or graphical animation playback.

## Authoritative persistence and retries

Start captures the equipped DB-owned snapshot and immutable skill/equipment release bytes, plus a server-generated random seed. Seed is encrypted with the existing app key and never sent to the browser. HMAC-derived deterministic draws enable exact replay; not a client RNG or fixed favorable fixture outcome. Stored v1 engine-contract file hashes reject continuation after incompatible runtime drift rather than silently reinterpreting history.

The trusted PHP service serializes on the existing account lock, then owned character/run. Each accepted action replays the bounded stored transcript using the real pinned engine, compares the previous public state with the stored state, and atomically commits the command receipt plus new state/revision. The browser supplies only command identity, optimistic revision, request UUID and equipped skill ID where applicable. Damage, winner, stats, animation acknowledgement, timeout, seed and snapshot submissions are rejected. The inherited terminal DB writer is inaccessible.

Identical action retry returns the accepted revision and CURRENT run state, not a stale response that rewinds the UI. Changed payload with a reused key conflicts. Two different actions using the same revision cannot both commit. Start is also idempotent, with at most one unexpired active run per character under the shared account lock. Account grants/equipment changes do not rewrite an already captured battle; the next run reads current data. Starting training does not heal persistent-world HP, award gold/XP/items, change PvP rankings or certify a PvE outcome. G8C's unverified mission-result rejection remains intact.

The client retains an unresolved command/revision/request UUID in per-account sessionStorage, never a password, cookie or seed. It reuses that intent after a lost response/reload. This is same-tab browser recovery, not crash-safe Android storage. Refresh recovers server progress; authorization still comes from the server session, not storage. Engine failure or a failed final database write leaves no partial action.

## Resource and operations limits

Replay is O(number of stored actions) per command and holds an account transaction during a bounded child process. That is deliberate for isolated low-concurrency evaluation and restart correctness, NOT a production-scalable worker architecture. Before live operation, use versioned worker/session ownership, queues/backpressure, production rate limits, retention and measured load behavior. No claim of production capacity,60FPS, physical Android support or independently approved UX.

New additive tables: nl_training_runs and nl_training_actions. They store snapshots/results/receipts, not balances. Keep history on code rollback; destructive down refuses. Disabling NL_TRAINING closes new routes without deleting data. Application key loss prevents seed decryption; preserve the existing key in operator-managed backups. Direct SQL remains a trusted boundary: checksums and encryption are not a tamper-proof ledger. Expired records are retained; no automatic deletion job is introduced.

## Verification plan, not automatic proof of execution

ops/training/engine.test.cjs exercises actual pinned source, deterministic replay, complete terminal combat, invalid commands and source drift. TrainingTest.php covers MySQL persistence, role/ownership, CP/cooldown, CAS/idempotency, immutable snapshots, no economic writes, expiry, engine failure and injected late-write rollback. Fresh CLI processes verify independent flags and production rejection. Existing content/equipment/economy suites remain.

ops/training/browser.cjs uses actual Chromium, four-worker loopback HTTP, database sessions and real CSRF (APP_ENV=local). Actual player controls choose/equip skills and equipment; no fixture calls perform those UI actions. Fixture setup only creates synthetic accounts/published content and grants ownership through existing services. It tests eight identical HTTP retries, eight competing revisions, a deliberately dropped response AFTER server commit, retained intent retry/reload, process restart, independent mobile-sized session, complete battle and logout. SQL checks reconcile commands and prove original ownership/receipts/wallet/XP unchanged. Screenshot files are temporary/hash-only, not exported or human visual acceptance. Routine CI retains no Actions artifact or dependency cache and adds no new workflow fleet.

Record exact-head and post-merge workflow results separately. #8 stays OPEN for mission/PvP/economic/native integration; #2 stays OPEN for accessible private preview. No Discord/APK/updater, native wire adaptation or asset rights clearance is implied. Do not silently start another batch.
