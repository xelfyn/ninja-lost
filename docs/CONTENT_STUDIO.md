# Content Studio CS01 — first authoring-to-engine path

Baseline before implementation: c2274e4e9c95e25d655d742dfb5b82818d44dd0b. This is a functional slice within the existing MB03/content roadmap, not a replacement engine or reset of the six macro-batches. Owner requested data-driven editors and deferred visual redesign. Current look is NOT canonical. Exact-revision CI determines acceptance; written test code alone is not evidence.

## Delivered scope to verify

`/admin/content-studio`: new skill draft, field-based effects, generated tooltip, saved-draft revision checks, immutable publication, explicit evaluation activation, old-version reactivation and draft duplication. Original creator fields/data format inspire this adapter; unsafe legacy creators remain quarantined. No direct overwrite of library.json, skill-effect.json or BIN files.

The shared EffectRegistry drives allowed form options and compilation. Initial direct-damage, single-target elemental skill profile supports stun, burn, heal, purify and disperse. Unsupported effect IDs/units/targets, duplicate effects, decimals, zero-damage fallback and multi-hit are rejected. Zero CP/cooldown/chance remain zero. Type, recipient, calculation unit and per-target timing are explicit, not inferred from tooltip prose. Lore is separate. Engine replacement is not introduced: compiled definitions feed the existing SkillCatalog and pinned upstream Battle/StatsCalc through a per-content version composition adapter.

This is NOT the complete mechanics library. Equipment flat/percentage stats, set thresholds, passive triggers, talent/senjutsu, pet, event template import, full skill categories and additional effects remain required by the product scope. Do not call an unavailable dropdown a working mechanic. Extend a versioned registry only together with its handler and meaningful runtime tests. Existing approved backend work is retained.

## Authoring, publication and activation

1. Save a normalized draft; no existing skills/catalog/inventory change. Stable lower-case `skill_nl_*` namespace prevents accidental legacy overwrite. Draft updates use revision compare-and-swap; stale tabs conflict.
2. Preview validates fields and derives the mechanical tooltip. It does not evaluate balance or certify animations.
3. Publish the exact saved revision/hash; unsaved changes reject. One immutable byte-preserved compiled JSON envelope per draft revision. Repeated publish replays that release. Checksum is integrity metadata, not a cryptographic signature.
4. Explicit activation atomically updates the existing skills/catalog projection, source revision, current-release pointer and operation audit. It neither grants a skill nor changes prices/wallets. Initial content creation is for operator-authored evaluation content; no community corpus/asset mass import.
5. Existing StaffGrantService/RewardService/ShopPurchase can use the resulting enabled registry record. Supply counter, opening count, inventory and economic history remain authoritative. Changing the initial supply cap through later releases is rejected.
6. PublishedLoadout reads account-owned skills and active immutable revisions under the existing transaction, then PublishedSkillCatalog validates/binds mechanics. A captured envelope stays pinned after a later activation. No native-client endpoint, durable battle reservation or combat-reward settlement is added.

One release contains both mechanics and effects, removing partial multi-file publication from this path. Activation is a separate transaction; it is not a CDN/client-deployment step. Scalar projection in existing skills is compatibility data; no second wallet/inventory exists. New content IDs reserve one draft row; global unique keys protect concurrent creation. Existing raw SQL/PHP operators remain trusted and are not made tamper-proof by a checksum.

## Animation and asset boundary

Animation key and one hit-frame index are optional references only. There is no SWF/code upload or evaluation of uploaded code. All releases explicitly say client_ready=false and reference_only_not_client_verified. Supplying an ID does not prove an asset exists, has permission, or runs on SWF/Android. Source URL is stored, not fetched by the server. Operator-declared rights evidence is not automatic rights verification. Visual support requires actual client testing later.

## Concurrency and recovery

Staff role is re-read with account lock on each write. Draft lock serializes publish/activation; expected head prevents a stale tab overwriting another activation. Catalog/definition order follows existing controlled writers. Readers hold existing ownership/catalog/definition locks while collecting version-pinned payloads.

Rollback reactivates an old immutable version, with an expected-current-head check. It MUST NOT reduce issued supply, delete inventory, modify wallet balances or reverse historical rewards. Additive schema down refuses destructive reversal. Code rollback retains release/draft/head and economic history; do not reopen raw legacy writers. Existing offers may invalidate when a skill projection changes; that fail-closed behavior is intentional.

The v1 compiler/registry contract must remain available for v1 releases. Breaking rules/schema/tooltip semantics require an explicitly versioned compiler and migration strategy, not silently modifying v1 and invalidating stored releases. Real production rollout/backups/load and multi-node deployment remain outside evaluation acceptance.

## Test flow

`ops/content-studio/StudioTest.php` covers validation, replay, roles, draft/head conflicts, release corruption, injected late-write rollback, supply/ownership preservation, actual Livewire actions and PHP-published mechanics consumed by the source engine. `catalog.test.cjs` covers immutable per-skill version composition. `race.php` uses eight ready-barrier PHP workers per case for publish replay, competing head activation, and stale draft edits.

Actual browser target: login -> field-based five-effect authoring -> preview -> save -> publish -> activate -> common registry/grant -> source-engine behavior -> edit draft without live change -> second activation -> rollback -> restart -> mobile-sized form interaction. PHP/SQL independently checks phase boundaries and receipts. Browser plugin unavailable in hosted runner; reuse Playwright1.56.1, no dependency upgrade. Synthetic actors/stats/clock/RNG/socket; not playable native game. Screenshots temporary only, no aesthetic refactor/human approval or export. No Actions artifact upload or persistent dependency cache.

Issue #8 remains open for full mechanics/native client/persistent combat/rewards; #2 remains open for accessible private preview and eventual human UI acceptance. Do not conflate this useful editor path with the full equipment-set-event system or production publication.
