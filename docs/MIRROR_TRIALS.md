# G8H — bound mirror trials and persistent gold/XP

Continues G8G main f6cc027e5367c6488b4db5df7103bd1624c6fa39. This is an explicit new evaluation mode, not certification of arbitrary inherited PvE missions. It closes a specific path: admin-authored terms -> DB-owned loadout -> existing pinned combat -> server replay verification -> existing G8C settlement/GrantBatch/ledger -> persistent result and retry.

## Mode and access

Ordinary training remains reward-free. A mirror trial must be created through TrialRules and started through TrialService; this atomically creates a NEW G8C ticket, a NEW persistent training-engine run, and a one-to-one binding. An old standalone training run cannot be attached later. The opponent is still the G8G synthetic mirror using captured player stats/equipment and automatic weapon attacks. No legacy enemy/mission parity, multiplayer or client animation is implied.

Requires explicit NL_EVALUATION_GATEWAY=true, NL_TRAINING=true, NL_MIRROR_TRIALS=true and local/testing. Default and production remain closed. Player routes retain loopback host/IP checks, server session authorization, CSRF and the shared training request throttle. Staff authoring uses existing Filament authorization plus current database role checks. No credentials, hosting, Codespace, paid capacity, public deployment or external assets are provisioned.

Admin: /admin/mirror-trials. Player: /evaluation/v1/trials/client. Login/select equipment and skills through /evaluation/v1/training/client, then open the trial page in the same session. These are local runtime paths, NOT published URLs. Function-only screens; visual redesign stays deferred.

## Configuration

Operator supplies slug/title, minimum level, gold, base XP, and an account-wide maximum number of rewarded wins. Creation is inactive; activation is explicit. There are no default rules or live reward values. Terms are immutable; a change requires a new rule. Pause closes new starts, not already-promised rewards. This first template has no calendar schedule, tickets, gacha, token/item reward, enemy editor or arbitrary uploaded code.

Each rule creates a fresh namespaced mission metadata row and a G8C rule. Operators cannot associate an existing unrelated mission with mirror outcomes. G8C's fingerprint invalidates a new start/activation if generic mission metadata has changed. Bonus XP, rank cap, curve and reward amounts are captured by existing G8C start semantics. All XP progression guards remain, including rejection if character XP/level/rank changed during the battle.

Only one unexpired unsettled trial is allowed per account (including across characters). Win quota is checked under the account lock at start and finish. Loss or surrender is recorded with no reward and does not consume wins. Limits are cumulative for the rule, not daily resets. Paused rules remain available for in-progress completion; receipts remain replayable after settlement/expiry. New trials need a fresh request key.

## Verification and transaction boundary

The player sends commands through the existing TrainingService. Finish accepts no client winner, damage, XP, price, proof or seed: only the battle UUID in its authenticated URL. TrialVerifier is instantiated only by TrialService, NOT registered as a replacement for the default unavailable mission verifier.

The binding covers account/character, G8C terms hash, engine runtime hash, owned-content bundle hash and the unique run ID. Final verification checks terminal status, contiguous bounded command history, encrypted server seed and source version; then replays the complete transcript with the existing TrainingRuntime/Battle/StatsCalc. The replay must exactly reproduce the stored public state. Rewardable victory additionally requires player HP >0 and opponent HP <=0. Stored/client win flags alone are insufficient. Runtime drift or corrupt state refuses settlement rather than guessing.

G8C applies gold/XP, level transitions, account revision, receipt, ledger and final result in its existing transaction. Training has no economic writer. Eight retries must share one original receipt; failures after the ledger write roll back balances/progression/receipt/result. External grants use the same account lock and are not overwritten. No new balance store or second inventory is introduced.

The last combat action and the separate settlement request are intentionally distinct durable steps. Losing the network response after settlement cannot cause a second credit: the canonical battle ID identifies completion. Client sessionStorage retains only pending intent IDs/commands in the same tab, never credentials or server seeds. Retry paths are built from fixed operation kinds, not a restored arbitrary URL. Full Android crash recovery is not claimed.

## Schema and recovery

Additive tables nl_trial_rules and nl_trial_runs contain configuration bindings only. Existing G8C/training/claims/ledger/account tables remain authoritative. Trial runs use a monotonic row ID for latest-run ordering; UUID ordering is not chronology. Do not delete these records or reset quota to recover code. Disable NL_MIRROR_TRIALS to close the mode; retain schema, original application encryption key, snapshots and receipts. Code rollback is not a refund. Raw SQL/database-owner access stays a trusted operator boundary; hashes are not a tamper-proof audit log.

The existing pre-G8C upgrade test now selects migrations chronologically BEFORE its target, instead of all migrations except the target. Later child schemas must not be installed before their missing parent. Its existing receipt/revision/retry assertions are retained. A new disposable-schema test applies this migration to populated G8G training and reward history.

## Evidence requirements

Test definitions are not execution evidence. Record final exact-head and actual-main CI runs separately before claiming completion.

The new PHP suite covers actual engine KO, loss, unbound/default-verifier rejection, payload/terms/transcript/state/runtime tampering, role/ownership, quotas, external grants, XP progression conflict, fault rollback, paused terms, expiry, Livewire authoring and populated upgrade. Browser tests exercise real staff form creation/activation and player loadout selection, combat commands, simultaneous finishes, a lost response AFTER commit, retry, restart, mobile-sized view and independent SQL reconciliation. QA uses synthetic owned accounts/content, a documented fixed engine seed and UTC not-before setup, NOT a verdict test double. Production starts still generate random server seeds.

Hosted CI reuses one pinned temporary Playwright installation. Browser plugin is not available on the runner. No new artifacts or persistent dependency caches; no new framework/runtime dependency. Full tests run against disposable MySQL only. Existing training/Studio/economy/combat regressions remain. Screenshot export and visual acceptance are not part of this batch.

## Remaining scope

Replay remains O(actions) inside a bounded subprocess/account transaction: suitable for isolated evaluation, not a production worker architecture or capacity guarantee. Native SWF/Android wiring, genuine legacy mission enemies, broad skill/talent/senjutsu/pet compatibility, item rewards, event templates/import, Discord, updater, backups/retention/observability, accessible private preview and performance remain separate gates. #8 and #2 stay open. Do not call this a full live game or silently start another batch.
