# Ninja Lost — one prompt per batch

Authorized working pattern: execute the requested batch through inspection, implementation,
repair, tests, PR publication, review, merge of passing code, and post-merge verification
within the active session. Do not request a new “continue” for each internal step.

## Evidence and stopping rules

GitHub is the baseline. Read current HEAD before editing; do not reapply an old patch
blindly. Use a working branch. A test failure triggers diagnosis, a targeted correction,
and a rerun, not deletion of the failing assertion or relaxed authorization.
Publish a terminal outcome per batch: COMPLETE, CODE_READY_ACCESS_PENDING, or BLOCKED,
with the exact acceptance items still open. Do not call generated tests executed tests.
A merge does not mean a live deployment. Do not silently roll into the next batch.

Routine edits, migrations in disposable test databases, standard public-repository CI,
PR publication and merge of verified in-scope code do not need repeated confirmation.
Charges, paid capacity, production access, repository-visibility changes, destructive
operations on persistent data, credential handling and unresolved asset rights retain
separate authorization boundaries. Never ask for passwords or tokens in chat.

Routine CI remains artifact-free. Do not publish screenshots or credentials in source.
No background development is promised. If a provider account requires an action unavailable
through current tools, record that external gate precisely; do not manufacture a URL or
mark the provider provisioned.

## Current owner decision: function first, visual refactor deferred

On 28 October 2026, after inspecting actual screenshots, the owner rejected the current
website design/layout as final and directed continuation of system functionality instead.
Comprehensive UI/UX refactor is DEFERRED; current portal look is NOT canonical. Do not
silently treat screenshot review or passed browser tests as visual approval. No new
cosmetic work/screenshots unless required for a functional defect or explicitly reopened.
See docs/UI_UX_REFACTOR.md. Private preview access and eventual visual acceptance in #2
remain distinct. G8D makes no views/CSS/branding changes.

## Prior user-requested visual evidence

The prior request allowed a separate opt-in visual-review.yml workflow to export selected
real Chromium screenshots of synthetic fixtures only: at most 1MiB including manifest,
retention one day, public repository/standard runner only. It is not invoked for ordinary
main/PR activity or function-only batches. No billing/budget/visibility change. No real
player data, credentials, traces or source ZIP export. Report quota/export failures;
never bypass limits or substitute generated mockups for claimed screenshots. Actual
image inspection and user acceptance are separate. Preserve exact source identity.

## Runtime/client boundaries

MB01 checks cover isolated application/MySQL/Filament/browser/container behavior, not a
user-accessible private URL. G8D adds a loopback diagnostic JSON client with real HTTP
session/CSRF/retry/restart checks; it is not the inherited SWF/Android client, a native
build, real combat, a public API or a replacement engine. Keep default/prod route gates,
legacy quarantine and domain tests. Do not add a fake victory bypass to demonstrate play.
Later isolated batches may proceed without falsely closing preview, rights or native-client gates.
