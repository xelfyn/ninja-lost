# G8 — Controlled staff issuance and stale-state containment

Baseline: MB02 `96b0f25f84dddd1d6551036bb0583b7173bd259b`. This batch advances release gate #8, not a public gameplay release or a replacement engine. Read actual workflow results before claiming tests passed.

## Trust boundary and writer inventory

`python3 scripts/ci/economy_inventory.py` prints candidate paths, SHA256 and nearest method/line for economy references co-occurring with mutation syntax. Initial baseline scan: 72 candidate files, run 40550155855 / job 122351389452. This is NOT 72 proven vulnerabilities or a complete data-flow analysis: configuration/read paths can match; inherited framework writes, aliases, dynamic SQL and indirect calls require separate review.

| Entry point / evidence | Disposition in this batch |
| --- | --- |
| `Services/Rewards/RewardService::starter/redeem` | Existing portal ownership, campaign quota, retry and schedule rules retained; delegates issuance to GrantBatch. |
| `Services/Rewards/StaffGrantService::grant` and `Filament/Pages/StaffGrants::grant` | New controlled staff adapter, role reloaded under lock, reason required, retry identity retained, same inventory/receipt/ledger/catalog supply. |
| `Services/AdminCommandService::execute`; old addGold/addTokens used stale model save, bulk functions used firstOrCreate | Old invocation returns a denial without writes. Bulk operations are NOT silently translated into unbounded grants. Source recoverable from Git history. |
| Users and Characters resource schemas + inherited Filament create/edit/delete pages | Not registered on the evaluation panel, not merely hidden from navigation. Their old Livewire component identities are denied on mount and hydration. |
| WorldChat, admin command/mail/giveaway/event/season management and other non-allowlisted legacy pages/resources | Quarantined at registration and component lifecycle boundaries; not claimed migrated. |
| `Console/Commands/FixCharacterItems::handle` and other application legacy commands | CommandStarting denies application command execution; only explicit existing CreateEvaluationAdmin remains allowed. Scheduler remains closed. |
| `Http/Controllers/ClanApiController`, `CrewApiController` | Routes not published. Donation, upgrade and minigame mutations remain unintegrated. |
| `Services/Amf/CharacterService/ShopService`, AccountService, DevToolsService, EquipmentService, SkillSetService | Legacy game gateway remains closed. Purchases/debits and old client contracts remain unintegrated. New ClientStateService is an internal contract, not a drop-in certified AMF adapter. |
| `Services/Amf/RewardGrantService`, MailService/ExecuteService, daily/event/gacha/hunt/exam/pet services | Not reachable through published game routes; no claim that reward settlement/battle authority was audited by regex. |
| Items/Skills/Pets/Missions resource metadata editors, GameSettings, registry/campaign management | Retained explicit metadata/configuration surface. They are not inventory grants. Catalog definition lifecycle and battle compatibility still require release review. |
| Direct PHP invocation, tinker, raw SQL, DB administrator or modified source | Trusted operator/programming boundary, NOT a sandbox or tamper-proof ledger. No claim of protecting against a database owner. |

Explicit surface is centralized in `Support/Economy/EvaluationSurface.php`. `EnforceEvaluationSurface` is registered before the pinned Livewire hook registry boots and denies old component snapshots as well as mounts. Do not restore auto-discovery or add a writer without integrating and testing it.

## Shared issuance contract

Token balance remains `users.tokens`; gold and inventory remain on existing character tables. `nl_claims` now permits a null campaign for staff receipts. Existing campaign/user uniqueness remains. Actor/key uniqueness additionally prevents a staff retry changing recipient. Staff keys reserve `staff:<actor id>:<uuid>`, forbidden by the portal key validator. Existing portal request fingerprints remain unchanged, preserving old receipt retry semantics.

Staff locks participating user rows in ascending ID order, revalidates current role, then locks the recipient character. Portal locks account, character and campaign. Both then lock sorted catalog identities through GrantBatch. The transaction includes balance/inventory, issued counter, account revision, receipt and ledger; failure at any stage rolls all back. Replay returns the original receipt without incrementing revision or supply. No network notification occurs inside the transaction.

Staff UI preserves request key and receipt after success; repeated action calls do not issue another reward. A deliberate `Grant baru` starts a distinct operation. The service also enforces idempotency independently of the UI. Amounts in tests are synthetic, not approved live economy rules. Revoked operators cannot replay through the service using a stale role model.

## Server-authoritative state contract

`ClientStateService::snapshot` reads current account/owned character under the account lock. Account-wide `nl_account_revisions` is coordination metadata, not a second balance store. Each successful approved grant or equipment save increments it once. A grant to another character of the account invalidates an older snapshot too.

`saveEquipment` accepts only `revision` and explicitly allowlisted equipment slots. It cannot accept currency, inventory, level, role or arbitrary client snapshots. Current owned/active catalog item, category, level, premium eligibility and duplicate inventory are checked before saving. Stale revision raises HTTP 409 without applying changes; caller must fetch the server state and re-evaluate intent. This is not an idempotent auto-retry of an arbitrary client snapshot.

No new HTTP/AMF game route is opened. Service-level tests prove this internal contract; existing client packets and real gameplay sessions have NOT been end-to-end integrated. Old game requests remain 404 and cannot overwrite a reward through those disabled paths.

## Verification and limits

New suite: `vendor/bin/phpunit -c ops/economy/phpunit.xml --testdox`.
Race suite: `php ops/economy/race.php` starts eight independently bootstrapped PHP processes, waits for each ready marker, then releases a shared start barrier. Scenarios cover staff retries, portal+staff additive balances, shared limited stock and equipment-save/grant ordering. This is not a production throughput benchmark.
Browser: `bash ops/economy/browser.sh`, Playwright 1.56.1. Existing player session precedes staff form submission; independent SQL verifies ledger, recipient and actor; player refresh sees the receipt; repeated staff action does not duplicate; application restart and desktop/mobile-sized viewports are exercised. Browser plugin is not available in the CI runner. Screenshots are temporary and not human visual acceptance. Original three workflows remain required regressions.

## Migration and recovery

Migration makes campaign_id nullable and adds an actor/key index and account revision table; it does not reset balances, remove inventory duplicates or rewrite old ledger entries. A duplicate/corrupt inventory is rejected for operator review, never automatically deleted. Adding the unique index can lock a large production table; no production migration is authorized or benchmarked here.

Revert code through a reviewed commit if needed; retain the additive schema and all receipts/ledger/audit records. The new migration down intentionally refuses destructive automatic reversal. Code rollback does not retract issued rewards. Reverting the containment layer reintroduces old evaluation writers, so keep the runtime stopped or restricted during recovery. Test cleanup is limited to process-owned synthetic IDs in isolated `ninja_lost_eval`.

## Open release gates

Issue #2: user-accessible private preview, actual lifecycle/quota review and human visual acceptance. Issue #8 remains a live-release gate until every game/debit/battle writer intended for activation is integrated, reconciled and tested, including real client packets. No live Discord, payments, assets, Android APK, hosting or 60 FPS claim follows from this batch. Metadata editing, production rate-limit/cache topology, backups and broader security review remain separate work.
