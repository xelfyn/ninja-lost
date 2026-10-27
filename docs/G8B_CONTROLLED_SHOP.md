# G8B — Controlled item/skill purchases

Continues G8 baseline `20c739bd3e846364da95cbb5663df6d16ca565a1` within release gate #8. Evaluation only. This is NOT activation or certification of legacy AMF shop/game packets. Read actual exact-revision CI before claiming PASS.

## Available evaluation flow

`/admin/shop-offers` creates an inactive offer against one enabled, rights-approved registry item or skill. Operator reviews fixed unit token/gold prices and maximum quantity, then explicitly activates. `/portal/shop` selects an owned character, offer and quantity. Both currencies are charged when both prices are positive. These are existing in-game balances, not real-money payments. No default offer, community content, seed prices or assets are imported. Prices entered by operators are evaluation configuration, not approved live economy policy.

Offers are immutable apart from active status. Definition fingerprint includes the stored definition except timestamps. New purchases and activation fail closed if its metadata changed; operator must review and create a new inactive offer. Definition shared locks stop metadata edits while a purchase is in progress. Existing receipts remain replayable after pause or definition changes. This is conservative invalidation, not a full content lifecycle or battle-effect audit.

Player UI displays at most 100 recent active offers/characters; admin offers paginate 20, purchase history shows the latest 20. Broader catalog search and high-volume operations are not certified. Browser screenshots remain temporary and unreviewed; user-facing private preview/human acceptance stays in #2.

## Transaction and trust boundary

Trusted adapter supplies the authenticated actor; only character_id, offer_id, quantity and UUID request_key are accepted as purchase input. Client price, reward, actor, balance and arbitrary state keys are rejected. Account role and character ownership are reloaded under lock. Account-scoped key `shop:<uuid>` cannot collide with portal/staff namespaces. Same request returns original issuance and charged costs; altered offer/character/quantity under the same key rejects. UI retains key and receipt after success and requires deliberate `Pembelian baru`.

Lock order: account -> character -> offer -> catalog -> definition -> inventory, compatible with G8 account-first grants. Price multiplication is bounded before debit. The same transaction covers both currency debits, optional skill element assignment, GrantBatch/RewardWriter issuance, supply, account revision, claim, signed ledger and purchase terms receipt. Any exception rolls all back. GrantBatch increments revision once; this also invalidates old equipment snapshots. Purchase is an intent against immutable server terms, not replacement of a client balance snapshot.

Existing positive-only reward ledger gains direction=credit by default for old/new grant rows; purchases append debit currency rows and credit content rows. Quantity stays nonnegative. Reconciliation MUST use `after = before + (credit ? quantity : -quantity)`; blindly summing quantity now overstates money issuance. `nl_shop_purchases` only stores the receipt's costs/terms, not a second balance or inventory. Existing portal/staff fingerprints and receipt JSON remain unchanged. Portal's existing issuance history shows the acquired content; shop history separately shows costs.

Skill purchases retain the inherited two-element normal / three-element premium-or-admin acquisition rule for elements 1..5, but compute it without writes until funds pass and commit it atomically with issuance. Duplicate skills reject without net charge. Pet purchases remain excluded: inherited pet shop allows duplicates while controlled G8 grants reject them; no silent policy change is made. Inventory duplicates/corruption are rejected, not deleted.

## Source findings and excluded paths

- Legacy CharacterService/ShopService::buySkill locks character before account, unlike G8. It may persist a newly assigned element and subsequently return insufficient funds inside the transaction, allowing that partial mutation to commit. The new service uses account-first locks and exception-based rejection; an insufficient-funds skill regression checks unchanged elements.
- Legacy buyItem/sellItem and PetService::buyPet are not rewritten or reopened. Their client contracts lack the new retry identity. Legacy AMF routes stay 404.
- This controlled offer path does not automatically import legacy price fields, PvP/library prices, sales/refunds, pet purchases, packs, gacha, mailbox, battle rewards or updater behavior. No claim that all gameplay writers migrated.
- Direct PHP/SQL and DB ownership remain trusted operator boundaries; this is not a tamper-proof ledger. No real player data, live Discord, public hosting, APK or 60 FPS claim.

## Verification

`vendor/bin/phpunit -c ops/shop/phpunit.xml --testdox` tests dual/single currency, replay, tampering, ownership/roles, funds, multiplication limits, catalog/definition changes, level/premium/element constraints, duplicate inventory/skills, rollback faults, revision conflicts, grant/debit reconciliation, Livewire adapters and nonempty G8 schema upgrade.

`php ops/shop/race.php` uses eight independently bootstrapped PHP workers with ready/start barriers per case: identical retries, insufficient funds across distinct requests, mixed staff credits/shop debits, shared limited stock, and old equipment-save ordering. Exact ledger continuity and physical inventory are checked. Not a production throughput benchmark.

`ops/economy/browser.sh` reuses one temporary pinned Playwright install for old G8 and new shop flows. Admin creates and explicitly activates an offer; an already-open player session sees it after refresh, purchases via UI, retries without double charge, gets a visible insufficient-funds rejection, and retains balances/session across PHP restart. Independent PHP/SQL validates each transition. Desktop/mobile-sized viewports are not physical Android or human visual approval. Browser plugin not available on runner; no dependency upgrade, artifact upload or persistent cache.

## Recovery

Additive migration only; no resets or seeders. Populated G8 credits receive default direction=credit and remain replayable. Automatic down refuses to destroy purchase/debit history. Revert code through reviewed PR while retaining added schema, all claim/ledger/purchase terms and audit records; keep runtime restricted until every reader handles direction correctly. Code rollback does not refund purchases. Large-table ALTER impact and production rollout/backup/recovery remain unmeasured and unauthorized.

Issue #8 remains OPEN for real client contracts and all other intended gameplay writers. Issue #2 remains OPEN for usable private preview and human acceptance. Continue the accepted roadmap, not a new engine or replacement balance store.
