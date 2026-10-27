# G8D — real HTTP diagnostic client, no UI redesign

Baseline: 14b9832484403c04e636902bc927067d4e648145. User directed function-first continuation and deferred the complete website UI/UX refactor on 28 October 2026. This batch implements an opt-in diagnostic adapter plus source-owned, dependency-free Node client. It does NOT build the inherited SWF, produce an APK, invent combat rules or replace the chosen engine.

## Architectural choice

Reuse Laravel's existing encrypted web cookie, server-side session, credential verification and CSRF middleware. No parallel authentication service, JWT secret, API bearer store, currency database or reward issuer is introduced. The diagnostic authorization stamp expires absolutely after 30 minutes and is bound to current account ID/password version. Every data operation locks and reloads the account, rejects revoked roles/password changes and uses the G8 account-first order. Only normal/premium accounts are accepted; this is not a staff API. HTTP body cannot supply the acting identity.

The code registers routes ONLY when NL_EVALUATION_GATEWAY=true and APP_ENV is local/testing. Middleware repeats those checks and also restricts Host AND request IP to loopback. Default false; production remains closed even with the flag set. This is intentionally not a private hosted URL. A future deployed API requires separate threat review, transport/origin policy, production auth/rate limiting and release approval. Do not enable legacy AMF to avoid that review.

Web group remains intact: no CSRF exemption, CORS wildcard, remember-me login or generic service execution. Login rotates session ID/CSRF; logout invalidates it. Password changes/current roles are rechecked under the account lock. Request bodies are JSON objects <=16KiB/depth12, allowlisted by operation; query strings are not accepted. Credential/actor/balance/price injection is not a feature. JSON responses use no-store and nosniff. Existing Laravel 419 responses are preserved. Unexpected application errors become generic 500 responses, not a stack trace or echoed input.

Login limits are 10/IP/minute and 5/normalized username/minute; receipt-producing writes are 60/account/minute. Real HTTP tests use persistent file cache, not request-local array cache. These are evaluation controls, NOT a claim of distributed brute-force protection, complete read-side rate limiting or production denial-of-service resistance. Simultaneous already-authenticated requests can finish before logout takes effect; logout is not a cancellation protocol for an already committed receipt.

## Wire contract (new JSON adapter, not legacy game packets)

All paths below are relative to `/evaluation/v1`. POSTs require application/json, existing session cookie and X-CSRF-TOKEN. Only bootstrap and login are anonymous; login also needs CSRF obtained from bootstrap.

| Method / path | Input / result |
|---|---|
| GET /bootstrap | Protocol evaluation-v1, CSRF token, gameplay_available=false |
| POST /login | username/password; existing session rotates; returns user ID, absolute expiry and new CSRF token |
| POST /logout | Empty JSON object; invalidates current session |
| GET /characters | Owned ID/name/level/rank only |
| GET /characters/{id}/state | G8 snapshot plus current XP/level/rank; never accept these economy fields back |
| GET /characters/{id}/inventory | Owned items/skills/pets, explicit per-list bounds |
| POST /characters/{id}/equipment | Existing revision + allowed equipment slots; stale state is 409 |
| GET /offers | Active item/skill offers from enabled rights-reviewed registry |
| GET /campaigns | Active unclaimed starter campaigns only; no redeem codes/hashes |
| POST /starter-claims | character_id, campaign_id, choice, UUID request_key |
| POST /redeem-claims | character_id, code, choice, UUID request_key |
| POST /purchases | character_id, offer_id, quantity, UUID request_key |

Lists are intentionally bounded at 100, with explicit limit/truncated fields. They are diagnostic discovery, not the future paginated large-catalog UI. Listing an offer is not a guarantee it remains purchasable: funds, supply, definition fingerprint, eligibility and activation are rechecked by existing services at purchase time. Historical receipt and fresh state are separate; replay does not promise a current balance snapshot.

Gateway delegates starter/redeem to RewardService and purchase to ShopPurchase, preserving existing request namespaces, receipts, balances, inventory, stock and ledger. RewardService's historical source label remains player_portal (shared self-service domain), not a false separate issuer. One `gateway.starter/redeem/purchase` audit entry identifies the transport for a NEW gateway receipt and commits in the same outer transaction. A receipt first created through another adapter is replayed without changing its provenance. Codes/passwords/cookies are never in that audit.

## Client and retry behavior

`clients/evaluation/client.mjs` is a source-owned technical client, NOT a graphical game, SWF compatibility shim or Android SDK. Node built-ins only, no dependencies or browser/UI. It accepts loopback origins only, refuses redirects, keeps cookies/CSRF in memory, and accepts an immutable purchase intent. Repeat an ambiguous purchase using the SAME intent/UUID; a new UUID means a new purchase. No hidden automatic retry creates a new ID. Losing this in-memory intent is NOT crash-safe storage; durable Android/mobile intent persistence remains future work. Equipment saves are revision-based and require refetch/reconciliation after an ambiguous response; they do not use purchase-style idempotency.

## Evidence required before acceptance

PHPUnit gateway tests exercise Laravel's HTTP kernel and existing domain services. Unit-testing framework may bypass CSRF; that is NOT CSRF proof. `ops/gateway/http.sh` separately launches a real four-worker PHP HTTP runtime with APP_ENV=local, database sessions and file cache. The Node client actually logs in, verifies missing-CSRF419 and foreign ownership404, discovers/claims starter and redeem, sends eight simultaneous requests with one purchase intent, checks tamper422/stale-save409, observes a staff grant, restarts the HTTP process, replays the old receipt, saves current equipment and verifies captured old-cookie logout rejection. Independent PHP/MySQL verification checks exact balances, inventory, stock, revision and continuous debit/credit chains. No combat verdict test double is used for this HTTP economy flow. It remains synthetic data, not inherited-client gameplay.

Fresh independent processes check default-disabled and production routes are absent. Existing G8/G8B/G8C races, MB02/admin/browser/container regressions remain mandatory. No old test is removed to enable the adapter. Exact-head and post-merge results must be observed; test definitions alone are not PASS. Concurrency correctness is not production capacity.

Runner fixtures require CI + isolated ninja_lost_eval MySQL. Random credentials stay in a mode0600 state file inside a mode0700 temp directory, never in args/source/artifacts. Cleanup deletes only created fixture IDs. No database reset, artifact upload, dependency upgrade, desktop access, paid hosting or deployment. Public schema unchanged; rollback removes the adapter code/flag while retaining existing transaction/audit history. Rollback is not a refund.

## Remaining release dependencies

Actual AS3/SWF client rebuild inputs and rights; reproducible Android packaging/signing/updater; authoritative combat engine/verifier; real game packet adapter; mission/assets/effect compatibility; production deployment/security/backup/load; Discord account binding/bot. G8D does not close #8 or the private-preview #2. Next batch must confront those dependencies rather than label this diagnostic client a playable Ninja Saga client.
