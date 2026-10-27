# MB01 client compatibility checkpoint

This is a contract inventory, not an Android build certification.
Server baseline: YoshCasaster/Ninjasage-PrivateServer-community at
`a53f074aaa0690398db947205956ffb97d5671aa`.

The intake imported server text with file provenance. Client binaries, SWF/assets,
certificates, database dumps and upstream default seeders were excluded. The current
web evaluation deliberately does not publish AMF/game mutation routes. A working
Filament panel therefore is not proof of a playable client.

## Required before game integration

| Contract | Required proof | Current status |
|---|---|---|
| Login/session | Request/response schema, session lifetime and ownership validation | Not end-to-end verified |
| Character state | Account/character IDs, inventory ownership and state version | Not end-to-end verified |
| Reward refresh | External grant cannot be overwritten by stale client saves | Scheduled with reward/client work |
| Battle | Server-validated start/finish, replay prevention and reward settlement | Not fully verified |
| Content | Data/effect/asset ID agreement and rights per component | Catalog/assets not fully imported |
| Android packaging | Reproducible input, runtime, supported ABIs, signing and distribution | NOT_BUILT |
| Updater | Version compatibility, signed manifest and interrupted-download recovery | NOT_BUILT |

Keep server adapters separate from business rules. Do not equate repository similarity
with provenance from Ninja Zero or KotaGames. Do not change engines solely to hide a
missing build path. Record and resolve the build-path decision before the Android batch.
