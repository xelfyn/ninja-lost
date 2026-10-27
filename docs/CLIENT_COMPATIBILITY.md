# Ninja Lost client compatibility checkpoint

Contract/dependency inventory, NOT Android or inherited-client certification.
Inherited server source: YoshCasaster/Ninjasage-PrivateServer-community at
`a53f074aaa0690398db947205956ffb97d5671aa`. Client binaries, SWF/assets,
certificates, dumps and default seeders were excluded. Web evaluation does not
publish legacy AMF/game routes. A panel or diagnostic JSON client is not a playable
Ninja Saga client; internal/new JSON contracts do not establish old wire compatibility.

| Contract | Current implementation / remaining proof |
|---|---|
| Login/session | Web login plus G8D loopback JSON login uses Laravel credential checks/encrypted cookie/database session/CSRF, rotation/logout and an absolute 30-minute diagnostic stamp. Real HTTP tests required. Native/AMF session lifetime/refresh protocol is still unverified. |
| Character state | G8 revisions and allowlisted equipment saves; G8D adapter exposes owned state/inventory and revision-based saves. Inherited packets and conflict UX remain unverified. |
| Reward refresh | Portal/staff/shop and G8D share balances/inventory/receipts. Real HTTP reads observe staff grants and restart persistence; not a push channel or native real-time sync. |
| Retry recovery | Shared receipt keys are reused across adapters/reconnect. Diagnostic client stores cookie/intent in memory only; device crash-safe intent storage/reconciliation remains open. |
| Battle settlement | G8C freezes terms/commits verified settlement. Default verifier refuses unverified outcomes. No public battle adapter or real combat verifier. |
| Combat verification | Requires authoritative simulation or authenticated server events and reproducible combat/client contract. Win flags/hashes/time/token alone are insufficient. |
| Content | Registry/ID checks exist; complete catalog/effects/enemies/assets and per-component rights remain unverified. |
| Inherited client build | Inspected upstream desktop package includes SWF/AIR runtime, descriptor and launcher. Complete game-core/Android rebuild not demonstrated; see project/client-build-audit.json. |
| Android packaging | Runtime, ABIs, signing, reproducible package/distribution: NOT_BUILT. |
| Updater | Signed compatibility/version metadata and interrupted-update recovery: NOT_BUILT. |

## G8C trust boundary

Trusted session adapter supplies User. Start accepts character_id/rule_id/UUID request_key;
finish accepts battle_id/bounded proof JSON only. No client reward or verified verdict.
Default refuses finish until a trusted local deterministic side-effect-free verifier exists.
Only simple mission gold/character XP contract; pets/recruits/fishing/entry costs/special
modes/PvP/loot require more integration. Legacy methods are not silently forwarded.

## G8D diagnostic adapter boundary

Explicitly enabled only in local/testing with loopback Host and request IP. Existing
Laravel web group/CSRF remains. Normal/premium player only; no admin grant, generic
execute or battle route. Existing domain services keep account-first transaction order.
Current role/password version/expiry are checked under account lock. New JSON contract
is for functional backend integration and later presentation decoupling, NOT a change
of engine or a claim that original SWF packets now work. Do not expose it publicly or
retitle it as the Android game. See docs/G8D_CLIENT_GATEWAY.md for methods/limitations.

Tests must identify actual HTTP versus internal calls, synthetic records versus actual
client packets, and fixture verifiers versus authoritative combat. Current diagnostic
HTTP flow uses real application auth/database/economy, synthetic accounts/content and
NO combat verifier; G8C battle tests still use explicit verdict doubles. No actual
native gameplay or production load/device performance evidence is inferred.

Keep adapters separate from business rules. Repository similarity is not evidence of
Ninja Zero/KotaGames provenance. Do not change engines merely to conceal a missing
client build. Resolve reproducible client inputs, permissions and combat evidence
before game endpoints. #8 remains live integration gate. #2 remains private preview;
owner has deferred full UI/UX refactor and rejected current visual design as final.
