# CS02 — equipment and cumulative set-stat publication

Base: 80ee08a228e3b5bd2fc8dcc78e12c952f724a0e2. Functional extension of MB03/Content Studio; not a replacement roadmap, game engine, or accepted visual design. Exact-head runtime evidence must be checked before acceptance.

## Functional boundary

`/admin/equipment-studio` authors a bundle of one to four distinct slots: weapon, back, clothing, accessory. A one-piece bundle is standalone equipment. Optional two/three/four-piece thresholds are cumulative. An item belongs to one bundle; inventory copies do not count as additional equipped pieces. Initial v1 membership/slots/supply caps cannot change through mechanics versions after activation; create a new bundle for different membership. This is equipment-set composition, not costume artwork creation.

Supported stats: maximum HP/CP, agility, attack, defense (nonnegative flat and additive relative percent); critical/dodge (percentage points only). Critical cap100, dodge cap95. Upstream StatsCalc is retained for base construction and damage. Composition: floor((base + sum(flat)) * (100 + sum(percent))/100), rounding once. Chance stats add points, cap explicitly and preserve one decimal. Derived maximums start a new evaluation battle at full HP/CP, not a persistent-world healing endpoint. Total output is bounded. Modifier order does not change final stats. Source contribution records are available for audit. Equipment changes rederive from fresh base; derived input is rejected to prevent double stacking.

Not delivered: accuracy mechanic, conditional weapon procs, passive trigger engine, multiset membership, mutable set membership, talent/senjutsu/pet effects, visual/SWF upload, event templates or native client. Visual keys remain unverified references with client_ready=false. No new public game endpoint or claimed Android/FPS result. Original legacy writers remain quarantined.

## Shared storage and lifecycle

Reuse CS01 draft/release/head tables with separate gear_nl namespace and new relational item mappings. Skill editor lists/loads only skill drafts. New item IDs have a slot prefix (wpn/back/set/accessory)_nl_*; no takeover of existing definitions or orphan ownership. Draft save and immutable publication do not create items or grant anything. Explicit activation creates/updates all item/catalog projections and one bundle head in one DB transaction with staff authorization and audit. It does not write JSON/BIN/CDN packages, change prices, grant inventory, or modify balances.

Initial activation establishes caps; subsequent versions and old-version reactivation preserve issued/opening counts, inventory, claims, ledger and prices. New supply is issued only through existing grant/reward/shop services. Publication retry replays one release per saved draft revision. Stale edit/head comparisons return409 rather than overriding another operator. Current locking reads follow CS01's REPEATABLE READ correction. Exact immutable payload checksum and v1 compiler parity are checked; checksums are not signatures or protection against a fully privileged DB operator. Operator-declared source/rights evidence is retained, not automatically certified or remotely fetched.

## Combat integration

The existing basic `CombatLoadout::prepare` retains its previous strict profile. `prepareEquipped` is an explicit internal extension: re-read account and character under account-first transaction, read active bundle/version, validate projection, slot, ownership, enabled catalog, level and premium requirements. Unknown legacy gear is rejected, not silently removed. Pet/talent/senjutsu remain rejected by this evaluation profile. This does NOT assert that the inherited client lacks those features.

`PublishedLoadout::prepareEquipped` returns pinned skills plus equipment release envelopes. `EquipmentStats` derives bonuses from selected pieces in that trusted snapshot and decorates only upstream `buildStats`; damage and turn functions are not replaced. A snapshot remains immutable across later activation, but a fresh admission check rejects stale release/equipment state. No battle reservation, native wire endpoint, persistent battle result or reward settlement is created here.

## Validation requirements

PHP integration covers normalization, scope/role enforcement, conflicts, replay, cap/membership immutability, collision refusal, checksum/projection tampering, late transaction rollback, owned loadout requirements, actual Livewire form actions and real source-engine behavior. Node checks composition, cumulative thresholds, caps, no duplicate stacking, input mutation, bounds and initiative using the pinned engine. Three eight-worker races cover repeat publishing, competing activation and stale edits. One existing temporary Playwright installation runs both skill and equipment browsers; no new workflow fleet, cache or Actions artifact.

Browser target: real field controls author two pieces and a two-piece bonus -> preview -> draft -> immutable publication -> activation -> shared grant/equip in fixture -> actual source engine -> edited-but-inactive draft -> new activation -> rollback -> unequip/re-equip -> application restart -> independent mobile session. Grant/equip in this test uses existing trusted services, not a native game UI. DB actors, stats, content, clock, RNG and sockets are synthetic fixtures. Browser plugin unavailable on hosted runner; existing Playwright1.56.1 reused. Screenshots are temporary hash-only functional evidence, not exported or human visual acceptance.

## Recovery and open gates

Keep additive schema/mappings, all releases and economic history. Automatic migration down refuses destructive reversal. Rollback means reviewed code recovery and/or old version reactivation, not deleting gear or refunding transactions. v1 compiler/stat-registry behavior must remain for stored v1 releases; breaking semantics require a new explicit contract. Issue8 remains open for full gameplay/client/settlement and Issue2 for accessible private preview and eventual UI acceptance. No production/paid infrastructure, real player data, credential publication or legacy reopening.
