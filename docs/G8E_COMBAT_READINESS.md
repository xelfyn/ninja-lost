# G8E — upstream combat readiness and turn-integrity adapter

Scope: reusable evaluation-only lifecycle adapter for the existing community PvP Battle class, plus reproducible executable source audit. This is not a new game engine, inherited client certification, PvE verifier, or live reward integration. UI/UX remains deferred and the existing design remains rejected as final.

## Dependency findings

Pinned upstream a53f074aaa0690398db947205956ffb97d5671aa contains executable Node Battle/StatsCalc server text. It is not necessary to invent a second damage calculator to test that code. Selected files are downloaded only into a fresh runner temporary directory, size-limited and verified against their Git blob identity before loading. Root MIT notice is retained alongside the text. No binaries, community asset/data packs, AIR runtime, SDK, certificates or dumps are fetched. Existing server-text provenance is not a blanket clearance for client/assets.

The selected NinjaSage-app.xml has an unresolved Moonshine `<content>` placeholder and AIR10 namespace. A separate Git tree metadata audit counts ActionScript/project/binary extensions at the exact pinned commit, refusing incomplete/truncated responses. Counts are observations of that tree, not all branches or proof that recoverable source can never exist.

Important distinction: AIR ADT can PACKAGE existing SWF plus a valid descriptor; that is not rebuilding the game core from source. Do not label an APK technically impossible solely because ActionScript source is missing. Our permitted/reproducible client input, compatible SDK/runtime, valid descriptor, endpoint configuration, rights and actual runtime behavior still have to be demonstrated. No SDK license acceptance, asset intake or client binary execution is authorized by this batch.

Official references reviewed 28 October 2026:
- https://airsdk.dev/docs/building/air-developer-tool/commands/package
- https://airsdk.dev/docs/building/application-descriptor-files/introduction
- https://airsdk.dev/docs/building/air-developer-tool/option-sets/file-and-path-options

## Source findings and treatment

1. Raw action listeners dereference null packets, and gate only running/active participant, not animation readiness. The adapter validates an exact bounded primitive envelope and admits actions only during input phase.
2. The original finished callback accepts either participant. The adapter binds acknowledgement to the actor AND turn ID. Repeated or stale messages cannot apply another action after the turn cycles.
3. Lethal outcomes wait two seconds without freezing admission in the original. The adapter freezes the pending winner immediately, rejects further actions/acks, and finalizes once. Disconnect cannot reverse a settled lethal result.
4. Original scroll handler heals any nonempty ID when enabled, without an owned-item debit. Scrolls remain unavailable; client-triggered timeout is also unavailable. Server timeout/watchdog remains functional.
5. Legacy `_saveBattle` directly inserts a PvP record and updates two characters, outside G8 issuance/revision/ledger. Its error is caught before success-shaped result packets are sent. The adapter NEVER invokes that persistence path: terminal results are explicitly evaluation-only and rewards_issued=false, with no economic values. No claim that legacy database atomicity was fixed.
6. Input retries for invalid skills could reset the timer. The adapter preserves the current deadline. Timers/listeners are disposed on terminal/cancel; unrelated socket listeners remain intact.

Protocol `nl-duel-eval-v1`: action event `Battle.action.{weapon|skill|dodge|charge|run}`, object `{battle_id,turn_id}` plus `skill_id` only for skill; `finished` needs the same original turn. It intentionally changes the input contract. Existing SWF packets do not become compatible automatically. The server, not a client payload, supplies participants, loadouts, sockets and clock. No port, authentication scheme, endpoint or loader is added to Laravel.

## Evidence interpretation

Node tests load actual pinned Battle.js and StatsCalc.js; damage/CP/cooldowns/stun are not mocked. Fixtures explicitly replace catalogue with two self-authored skills, socket transport, time and random stream, UUID, character-info query and database. VM is a dependency seam, NOT a security sandbox for arbitrary untrusted code. The DB adapter records attempts and refuses writes. The evaluation adapter must make zero calls to it even after a complete duel.

The first five tests intentionally reproduce upstream defects. Their success means the defect was observed, not that unsafe behavior is accepted. Remaining tests exercise repaired boundaries, complete damage-driven duels, timer/cleanup behavior and a real loopback HTTP transport probe. The HTTP probe's synthetic identities/time/catalogue are NOT production authentication, original client packets or real-time load/FPS evidence. All balances and claims remain untouched; no substitute victory flag is introduced.

Run: `python3 ops/combat/fetch-source.py <fresh-temp-dir>` then `NL_COMBAT_SOURCE=<dir> node --test ops/combat/combat.test.cjs`. Use Node22+ and Python3. Tests must execute on the actual head and resulting main. Network/source availability failure is a failure, not skipped coverage. No new npm dependencies or persistent cache/artifact uploads.

## Remaining gates / recovery

Whole-game play remains BLOCKED at native client provenance/runtime and packet mapping; authoritative full-content parity, owned/equipped loadout derivation and durable combat sessions are not provided here. This is a PvP duel; do not use its winner as a PvE mission verdict or issue G8C rewards from it. Stat comments claiming parity are not proof: gear/set effects, passive handling, zero-damage/support skills, effect coverage and target semantics require reference tests. Disconnect/recovery, process restart, randomness, anti-bot/abuse control, production throughput and atomic PvP rewards remain future work.

#8 and #2 remain open. Next dependency-first work must select/clear a runnable client input, validate the same protocol against real packets, and design trusted terminal-event persistence into the common economy. Do not add cosmetic panels or fake a finished game. Remove this unreferenced evaluation module/workflow to roll back; no database migration or reward reversal is involved.
