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

## User-requested visual evidence, 28 October 2026

The user requested current visual design screenshots where available. A separate opt-in
`visual-review.yml` workflow may export selected real Chromium screenshots of synthetic
fixtures only: at most 1 MiB including manifest, retention one day, public repository and
standard runner only. It is not triggered on ordinary main/PR activity. No billing/budget
or repository visibility is changed. No real player data, credentials, traces or source
ZIPs are exported. Quota/export failure must be reported; never bypass limits or substitute
a generated mockup for a claimed screenshot. Download and inspect the actual images before
claiming visual QA, and retain pending user acceptance independently. Review/export metadata
must identify the exact source commit; app interaction tests remain required.

## MB01 handoff

Technical checks: source provenance, application/MySQL integration, actual Chromium
interactions, process persistence, actual Compose image/setup, HTTP assets and container
restart. The user-facing private Codespaces session and human visual acceptance are
separate acceptance items. Later isolated batches may proceed but must not pretend those
items passed. Screenshots now requested are not themselves a deployed private URL.
