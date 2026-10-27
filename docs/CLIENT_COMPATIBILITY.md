# Ninja Lost client compatibility checkpoint

This is a contract and dependency inventory, NOT Android/client certification.
Inherited server baseline: YoshCasaster/Ninjasage-PrivateServer-community
`a53f074aaa0690398db947205956ffb97d5671aa`. Intake imported server text with provenance;
client binaries, SWF/assets, certificates, dumps and default seeders were excluded.
Web evaluation does not publish legacy AMF/game routes. A functioning panel is not a
playable client, and newly tested internal contracts are not proof of wire compatibility.

| Contract | Current implementation / remaining proof |
|---|---|
| Login/session | Web login and ownership tests exist. Actual game session protocol, lifetime and refresh NOT end-to-end verified. |
| Character state | G8 account revisions and allowlisted equipment saves reject stale snapshots. Actual legacy client packet adaptation and conflict UX remain unverified. |
| External reward refresh | Portal/staff/shop share persistent balances, inventory and receipts. Browser refresh is tested, not an Android push channel or full game save. |
| Battle settlement | G8C freezes simple mission reward/XP terms and commits verified settlement atomically. Default verifier refuses all unverified outcomes. No real combat verifier or public battle route is shipped. Test fixture verdicts do not establish legitimate wins. |
| Combat verification | Requires authoritative simulation or authenticated server event evidence and a reproducible combat/client contract. A client win flag, hash, elapsed time or token alone is NOT proof. |
| Content | IDs/metadata registry exist. Catalog/assets, effects, enemies and resource rights not fully imported or certified. |
| Android packaging | Reproducible input, runtime, ABIs, signing and distribution: NOT_BUILT. |
| Updater | Signed compatibility/version metadata and interrupted-update recovery: NOT_BUILT. |

## G8C internal start/finish contract

Trusted session adapter supplies User. Start input accepts character_id, rule_id and UUID
request_key. Finish input accepts battle_id and bounded proof JSON only. No client-provided
reward, price, XP, actor or verified verdict. Preserve start/finish identities across retries;
read current state separately from historical receipts. Default service declines finish
until a trusted, local, deterministic, side-effect-free verifier is implemented and wired.
Ordinary mission gold/character XP only; pets, recruits, fishing/entry costs, special modes,
PvP and loot require additional integration. Existing legacy methods are NOT silently
forwarded to this incompatible contract.

All application tests must state whether they exercise real client packets, generated
contract input, or an explicit verifier test double. This checkpoint only introduces the
latter internal contract. Consult exact revision CI; code existence is not execution proof.

Keep adapters separate from business rules. Do not equate repository similarity with
Ninja Zero/KotaGames source provenance or change engines to hide a missing build path.
Resolve reproducible client inputs, permissions and authoritative combat evidence before
opening game endpoints. Issue #8 remains the live integration gate; #2 tracks usable
private preview and user visual acceptance.
