# Hosting preparation — runtime parity and access gates

Baseline inspected: de111cee18d2bfe0e9242a456dbcba58167f8bd8 (G8H). This change closes a concrete runtime mismatch and documents remaining hosting gates. It does not provision a provider, buy capacity, activate a domain, create a user or deploy publicly. Read exact-head and post-merge CI before claiming this implementation passed.

## Runtime work in this change

The existing preview Dockerfile supplied PHP/Composer but not Node, despite TrainingRuntime launching Node. The preview image now includes the official Node24 Bookworm runtime in the same Debian family, without npm/yarn or new npm dependencies. Node24's major is checked during image build. The application remains non-root; MySQL remains unexposed with the existing scoped DB credentials. Source/API feature restrictions are unchanged.

The actual existing Docker Compose CI now fetches the same hash-pinned combat TEXT into a new isolated container path, executes the engine and PHP training/trial suites inside that image against its MySQL service, then repeats these probes after app+DB restart. The schema-upgrade suite that requires CREATE DATABASE remains in the separate root-owned disposable job; the preview DB account is not elevated for it. Existing session/key/sentinel/HTTP probes remain. No artifact upload, image push, Codespace, new workflow fleet, client binary or asset intake.

Passing means the tested preview image can execute PHP -> Node -> the pinned engine and scoped settlement, including after normal container restart. It does not establish native-client parity, long-lived worker scalability, accessible private URL, browser gateway support for a provider hostname, backup/restore or image reproducibility by digest. Family-tag base images are still a development setup.

## Remaining gates before the owner can use a hosted alpha

1. Resolve provider/project, allowed spend/quotas and deployment identity. Use connected account permissions; never request passwords/tokens in chat. Do not create billable services from a broad continue prompt.
2. Configure an explicitly private access boundary for that provider. Current GatewayBoundary requires loopback host/IP; a forwarded domain will not automatically work for training/trials. Do not remove this guard globally, trust wildcard proxies or set production to local merely to expose the app. Verify exact origin, trusted proxy handling, session/CSRF and approved test-account access in the intended environment.
3. Prepare the verified combat source as a controlled runtime dependency with provenance, integrity and licensing notices, not an untrusted player upload. The container CI's temporary intake is evidence, not an automatic provider installation step.
4. Provision isolated evaluation data and a non-default operator/test account through approved interactive or secret-managed tools. No public sign-up or default password is introduced here.
5. Test the real private URL from the owner's browser: admin, portal, content configuration, owned loadout, trial, gold/XP, retry/logout, restart and actual backup/restore. Inspect provider storage lifecycle separately from normal container restart.
6. Record the exact source/image/config versions and disable/delete only disposable resources through reviewed cleanup. Preserve APP_KEY, receipts/ledger and volumes; source rollback is not a transaction refund.

The first hosting target is a functional private alpha with the existing player dashboard and server shop. P2P market and deferred visual refactoring are separate deliverables; see PLAYER_PORTAL_AND_MARKET.md. No provider selection is implied by retaining the existing Codespaces-compatible preview scaffolding. Broader multi-user staging needs its own measured architecture, not a dev-server scalability claim.

## Runtime documentation sources

Node release status: https://nodejs.org/en/about/previous-releases
Official image variants: https://github.com/nodejs/docker-node
Node24 was listed as LTS when checked on 2026-10-28. Use reviewed security updates; do not describe a floating image tag as an immutable release.
