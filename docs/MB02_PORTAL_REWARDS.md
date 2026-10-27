# MB02 — Player portal, catalog registry and transactional rewards

## Scope and entry points

Additive evaluation functionality on the existing Laravel 12 / Filament 4 backend; no engine rewrite or separate balance database. `/portal/login`, `/portal/home`, `/portal/catalog` use the existing users and web guard. `/admin/reward-campaigns` and `/admin/content-registry` extend the existing design system. Portal access and reward services are refused outside local/testing. Registration, AMF, legacy game routes, Discord live, payments and Android builds remain outside this batch.

No persistent accounts or campaigns are provisioned by migrations. No asset or community content is imported by MB02. CI fixtures are explicitly synthetic, temporary and never production seeders. A running private user preview still depends on MB01 issue #2; CI URLs are not a deployed game.

## Audit conclusions and decisions

- Tokens belong to `users.tokens`; gold belongs to `characters.gold`; both are signed INT, so grants check overflow at 2,147,483,647 rather than promising infinite balances.
- Character ownership is resolved on the server from authenticated account ID. Portal payloads never mass-assign balances, account types, inventories or catalog definitions.
- Existing character inventory tables have no compound unique key. The new service locks the account and character, rejects pre-existing duplicates, and never repairs/deletes old inventory automatically. Legacy game/admin grant paths have not been certified for this protocol. Do not enable them concurrently or treat the new ledger as a global guarantee for unrestricted legacy administrative edits.
- Every campaign has one claim per ACCOUNT, even when the account has multiple characters. The operator can offer up to eight choices; only the chosen server-defined pack is granted. New starter campaigns are separate entitlements, not an unstated once-per-lifetime rule.
- Starter/redeem campaign definitions and quotas are immutable through the new panel. Create a new version for different rules. Only activation/pause is mutable. New definitions are draft, catalog entries are disabled. No live token amount, supply quota or gacha economy has been decided by fixtures.

## Transaction and idempotency

Lock order is account, character, campaign, then sorted catalog identities. Database transactions include inventory/balance updates, catalog issuance, campaign count, claim receipt and per-line ledger. Unique constraints on `(campaign_id,user_id)` and `(user_id,idempotency_key)` are backstops. Same-key/same-payload retries return the original receipt, including after a campaign is paused or expired. Same-key/different-payload retries fail. Different keys do not bypass the account entitlement.

Limited supply is shared by all MB02 campaigns referencing a registry entry. Opening issuance is measured from existing inventory during registration, not assumed zero. This is not a migration/concurrency protocol for an already operating legacy game. Notification is sent only after the service transaction returns; notification delivery does not authorize or reverse rewards. No network calls are made inside reward transactions. A database administrator can still change records; this is an application audit log, not a tamper-proof external ledger.

Redeem codes are trimmed and uppercased, then SHA-256 hashed; plaintext is never stored in the campaign, receipt or audit log. Codes are campaign-level codes with per-account redemption and global quota, not individually generated one-use vouchers. Claim attempts are rate limited per account. Filament login supplies normal session regeneration, CSRF handling and login rate limiting. Keep a persistent/shared cache when moving beyond one evaluation process; the preview uses file cache. Multi-instance production needs a separate operational review.

## Registry and licensing boundaries

Registry records kind, backend ID, source URL/revision, operator-attested rights basis/evidence, compatibility note, optional effect metadata, enabled status and supply. Existing item/skill/pet definitions must exist first. The registry is NOT an automatic license validator, upstream synchronization tool, client asset importer or battle effect engine. `effect` entries are metadata only and cannot be granted as inventory. Compatibility notes are operator declarations, not proof of tested Android support. Actual community catalog additions in this batch: ZERO.

## Tests and limits

`vendor/bin/phpunit -c ops/rewards/phpunit.xml --testdox`: authorization, real player login/logout, login rate limiting, ownership, signed-int limits, normal claims, replay/conflict, account scope, code validation, time/status restrictions, registry enforcement, shared inventory, fault rollback and Livewire integration.

`php ops/rewards/race.php`: four scenarios with eight concurrent PHP processes against real MySQL: identical retries, account claim across characters, campaign capacity and shared limited stock across campaigns. Workers have a start barrier, bounded deadline and receipt/inventory/counter reconciliation. This is a race-condition test, NOT a throughput benchmark or distributed load test.

`bash ops/rewards/browser.sh`: Chromium 1440x1000 and 390x844, actual login, starter/redeem form controls, independent SQL verification, process restart/session persistence, catalog filters, CSRF rejection and logout. Screenshots are ephemeral; no human visual acceptance is implied. No artifact uploads or persistent CI cache.

Read actual workflow results for the exact tested revision. Written tests are not themselves a PASS claim.

## Rollback and next boundary

Revert the MB02 code commit to close its new UI. Do not run migration rollback against useful data without exporting the new campaign/claim/ledger/audit tables and verifying the balance consequences. Code rollback does not revoke already issued rewards. CI cleanup only removes the fixture IDs created by its own process in `ninja_lost_eval`.

Before a public/live release: actual private preview acceptance, legacy grant-path migration/lock discipline, retention/backups, catalog rights/content intake, role separation, production cache/rate limits, performance measurements and security review remain necessary. Discord integration must reuse the audited transaction boundary with explicit account binding and a trusted actor/source contract; do not expose the private claim method or let Discord write balances directly.

Technical references used for this implementation: Laravel 12 query builder pessimistic locking and database transactions; Filament 4 multi-panel configuration and schemas. Keep the locked dependency versions; no framework upgrade is included.
