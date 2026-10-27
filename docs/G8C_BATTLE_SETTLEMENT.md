# G8C — Battle settlement boundary and visual review

Continuation of G8B `fb3139002766f3bc96d4c02b4930b6dc832d210c`. This is an internal, version-one settlement contract for simple mission gold/character-XP. It is NOT a battle engine, a validated win algorithm, a legacy AMF adapter, an Android build or a playable game. Issue #8 remains open.

## Finding and trust boundary

Inherited StartService caches one token per character and snapshots rewards. FinishService reads this cache, adds gold/XP/pet XP and removes recruits/drops before forgetting the token, without a single shared account-first transaction or persistent terminal receipt. Its hash accepts empty and unrecognized-length strings; even a correctly computed client hash is not authoritative proof of victory. These methods remain unreachable. No audit of other operators' live servers was performed.

A trusted adapter must supply an authenticated User; JSON may only contain start(character_id, rule_id, request_key) or finish(battle_id, proof). The internal service reloads role/ownership under account -> character -> battle/rule locks. No HTTP or AMF route is published. `BattleOutcomeVerifier` must consult authoritative combat evidence, be local/deterministic/side-effect-free, and bind the verdict to the battle and proof. The default `UnavailableBattleVerifier` returns 503 without granting anything. QA's explicit verifier lives only under ops, requires isolated CI MySQL, and does not prove real combat legality. There is no environment switch that turns arbitrary client victory flags into verified wins.

## Rules, state, retry and time

Internal operator rules refer to exact mission IDs, are inactive initially and fingerprint metadata. Minimum duration and TTL are explicit, UTC, bounded and not themselves anti-cheat proof. A metadata edit invalidates new starts. Existing starts freeze mission rewards, bonus, cumulative XP curve, rank cap, progression and equipment selection. Pausing rules stops new battles but honors already-started ones until their deadlines. One unexpired active battle per character is enforced under its owner lock. Different characters may progress independently.

Account-scoped start UUID is idempotent. Finished battle UUID is the issuance identity; repeated finish with identical serialized proof returns the durable result even after expiry. Different proof under that battle ID rejects. Proof JSON is limited to 64KiB/depth16/top-level128 entries. Exact object key ordering is part of retry identity in v1. The future wire adapter must preserve the pending battle ID/proof identity, enforce body limits, bind the authenticated game session and refresh current state separately from the historical receipt.

## Atomic reward and progression

Verified win commits cumulative XP/rank-cap progression, optional existing GrantBatch gold issuance, account revision once, existing claim/credit ledger and terminal battle result in one MySQL transaction. A verified loss is terminal and grants nothing. Gold shares the same character field and ledger as portal/staff/shop. XP ledger records the effective capped increase, plus requested XP and level before/after in the receipt. Existing credit/debit semantics are preserved; this is bookkeeping, not cryptographic tamper-proof storage. Tests inject failure after gold and after terminal result writes, requiring complete rollback and retryability.

A staff grant while a battle is in flight must survive; unrelated account revision changes do not automatically invalidate a battle. Progression fields changing out of band produce 409 rather than overwriting them. The new reward invalidates old equipment-save revision as in G8. Missing/nonmonotonic XP tables and inconsistent over-cap data reject; there is no made-up fallback XP threshold. Existing mission XP bonus is captured at start (0..1000% evaluation bound); timer policy remains to be accepted for live play.

Current unsupported modes fail explicitly: equipped pets, recruits, fishing/entry consumption, random/limited drops and special battle objectives. Pet XP, item costs, enemy action legality, PvP, boss effects and real combat replay are NOT implemented here. Session/equipment metadata alone is not sufficient evidence to build a verifier; authoritative simulation/event records and a reproducible client contract remain dependencies. No client assets or real catalog data are introduced.

## Visual review scope

User requested current application screenshots on 28 October 2026. A separate opt-in visual workflow may export at most 1 MiB of selected synthetic-fixture PNGs plus a SHA256 manifest, with one-day retention. Routine PR/main CI remains artifact-free. No budget, billing or public app settings are changed. Export failure (including quota) must be reported, not hidden or treated as a screenshot. Screenshots are never committed as source, do not show real player data or credential inputs, and are not concept art. Capture must use tested source at the recorded commit; review images before making visual claims.

## Evidence and recovery

Read exact-run status/logs; test definitions alone are not evidence. The battle suite covers no-verifier fail-closed behavior, binding, ownership, strict payloads, time windows, immutable terms, progression/bonus/cap, concurrency, late failure and revision. Synthetic race tests are not capacity measurements or live battle tests. Retain additive rule/session/claim/ledger schema and terminal receipts on rollback. No destructive down or automatic data reset. Existing preview acceptance #2 and live gate #8 remain separate. No paid hosting, Codespace, Discord or production activation is authorized by this change.
