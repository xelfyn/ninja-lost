# G8F — combat data and owned loadout bridge

Continues c56b36f / PR16. Current UI design remains NOT_ACCEPTED; full UI/UX refactor DEFERRED. This batch addresses a concrete prerequisite for connected gameplay, not another visual panel or a native-client claim. Read exact-head/post-merge Actions evidence before interpreting this authored document as PASS.

## Why input mapping precedes settlement activation

Laravel `skills` stores identity/requirements/prices but not the complete combat mechanics. The upstream `SkillData.js` independently reads zlib-compressed JSON `bindata/skills.bin` and `skill-effect.bin`. `parseInt(x)||50/3` loses valid zero CP/cooldown; `parseInt(chance)??100` leaves NaN, and an effect probability comparison against NaN can apply unexpectedly. It also overwrites duplicate effect IDs and silently ignores or approximates unsupported mechanics. Adding a reward bridge over unverified input would not establish correct battles.

Pinned intake at upstream a53f074aaa0690398db947205956ffb97d5671aa is read-only temporary metadata. No assets/SWF/client binary or executable is downloaded by this new intake, no decryption/access bypass, DB import or live activation. Source-lock records exact blob IDs and sizes. JSON decoding rejects duplicate keys, nonfinite constants, trailing/truncated zlib and excessive expansion. Original metadata is not rewritten or replaced with inferred numbers. Counts are specific to this snapshot, not the latest ecosystem or content of Ninja Zero.

## Catalogue behavior

`combat/data/SkillCatalog.cjs` separates preserved source metadata from admission to a deliberately restricted engine profile. Missing/ambiguous effect records, fractional formulas, unsupported/conditional/passive effects, unknown types, missing chance and required fields fail closed. Unknown skill IDs never get a fabricated default skill. Legitimate numeric zero CP/cooldown is preserved. Zero direct damage remains preserved but quarantined because inherited StatsCalc falls back to an attack-based hit; this batch does not invent semantics for effect-only skills.

The admitted effect set is intentionally small and explicit. Some amountless statuses normalize missing amount to0 and blank calculation to `number`, since those inherited handlers do not use either numeric term. This normalization is documented, not blanket validation of other blank fields. Optional absent passive/no_disperse mean false; present values must be booleans. Structural admission is NOT proof of all gameplay/effect parity, rights or approval for production. Registry activation remains separate.

Report counts overlap by reason. No duplicate record is silently selected for execution. Normalized mechanics/effects and metadata are immutable. `bindLoadout` matches IDs, catalogue revision, level/element/premium requirements and owned snapshot shape; it does not turn an untrusted JSON object into authenticated authority.

## Account-bound preparation

`app/Services/Combat/CombatLoadout.php` accepts a trusted authenticated User object plus a character ID, re-reads the current ordinary/premium account, verifies ownership, locks existing account/character/catalogue/definition/ownership rows, and returns existing stats, equipped skill IDs, requirements/provenance and account revision. No player-supplied stats or balances. Missing/ambiguous ownership, disabled or unapproved registry, skill level/premium/element mismatch and unsupported gear/pet/talent/senjutsu reject.

The restricted basic-stat profile rejects unsupported loadout effects instead of silently removing them. This does NOT mean normal gameplay should permanently ban equipment or talents. Their authoritative parity needs separate implementation before wider activation. Profile limit8 is an evaluation bound, not certification of native client slots.

`assertCurrent` re-reads and compares before trusted use; it catches metadata/point changes even when an unsafe legacy path did not bump revision. It does not reserve a battle, mint a bearer ticket, prevent future changes, or authorize arbitrary client-provided snapshots. It is an internal service with local/testing gate and no new route/schema. Read-only admission does not issue rewards or mutate wallets.

## Execution proof and limits

CI first tests safe source decoding and immutable catalogue behavior. It then inspects the complete two-file real corpus and executes one eligible real-corpus skill through the actual pinned Battle/StatsCalc using synthetic character/socket/clock/RNG adapters. This is not all1158 skills executed or asset permission.

Separately, disposable MySQL creates self-authored skill mechanics and ordinary accounts. Real StaffGrantService creates ownership/receipt/revision, then CombatLoadout reads those characters and validates current state. A Node subprocess receives the server-produced snapshots, matches mechanics revision, and drives a full damage-based duel through the actual upstream engine. Both zero CP and zero cooldown remain exact. Accounts are synthetic but their IDs/stats/ownership come from actual MySQL rather than hard-coded engine identities. DB queries after combat confirm currency/claim/ledger/revision were not changed. Clock, RNG, sockets and skill mechanics in this paired integration remain explicit fixtures; no real account login/new HTTP action endpoint/native rendering or persistent terminal/reward is claimed.

This deliberately does not connect a PvP duel result to a PvE mission verifier. G8E combat execution and G8C settlement still need a mode-correct durable, authenticated bridge. #8 stays OPEN; #2 preview/human visual acceptance stays OPEN. Native client/input rights/build/packet mappings remain unresolved. No production gameplay, Discord live, paid hosting, Android/updater or FPS claim.

## Recovery

No schema/migration/economy changes. Remove the new internal service/catalogue/intake/workflow through reviewed revert if needed. Existing receipts/ledger/revisions and original runtime quarantine stay intact. The fixture extension adds optional inputs only; default G8E tests remain unchanged. Routine CI retains zero artifacts and no persistent dependency caches. Temporary upstream metadata is removed at job end and not committed, exported or marked redistributable.
