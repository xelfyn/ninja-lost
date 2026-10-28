# Player portal, P2P market and deferred UI/UX

Product clarification from the owner on 28 October 2026. This document separates existing capabilities, proposed navigation and unimplemented transactions. It is not approval of a visual concept, pricing, trade policy or a deployed URL. Continue functional hosting preparation; visual refactor remains deferred under UI_UX_REFACTOR.md.

## Current truth

- `/portal/login`: player sign-in.
- `/portal/home`: existing functional character/reward dashboard, inventory, account tokens, character gold, starter/redeem and recent claims.
- `/portal/catalog`: catalog discovery; not player selling.
- `/portal/shop`: controlled server-to-player offers and purchase history. It is NOT a P2P marketplace.
- `/admin`: separate operator panel, including Content Studio, Equipment Studio and mirror-trial rules.
- `/evaluation/v1/training/client` and `/evaluation/v1/trials/client`: opt-in local browser test clients; not the native SWF/Android game, hosted URLs or final player navigation.

These paths require a running runtime. No P2P listing/escrow/settlement feature is declared implemented. The old Mysterious Market label must not be confused with P2P ownership transfer. A menu or screenshot is not evidence of functioning exchange.

## Proposed product structure, not routes implemented by this change

One website/product with a shared identity and domain services, distinct authorization and experiences:

- Public area: announcements, guide, download/status and entry to sign-in. Proposed scope, not delivered.
- Player portal: overview, characters, inventory/loadout, server shop, P2P market, events/rewards, transaction history and account/Discord connection.
- Operator area: content and effects, event/rule management, user operations, audit, marketplace moderation and platform settings.

Proposed P2P route is `/portal/market`, with Browse, My listings, Sell and Trading history. This path does not exist yet. Use clear labels 'Server Shop' and 'Player Market'. Do not create a separate wallet/database for market, website, bot or game. All write entry points must use the same authorization, revision, transaction and ledger boundaries.

## P2P is a functional delivery, not a cosmetic UI refactor

Required flow: seller selects an eligible owned asset -> server validates and locks/reserves it -> listing becomes available -> buyer pays -> ownership and currency transfer atomically -> one settlement receipt. Cancellation/expiry returns the reservation exactly once; simultaneous buyers cannot both acquire the asset. Selling must not increment catalog issuance: transfer is not minting. Prevent use/equip/upgrade/grant/sale paths from spending a reserved asset. Protect both parties with a consistent account lock order, ownership checks, idempotency, limits and independent reconciliation.

Items with distinct upgrades/rolls may require asset-instance identity rather than the existing fungible quantity representation. Do not collapse different equipment into the same listing. Preview eligibility, equipped state and account/character binding must be explicit.

A learned skill is not automatically a transferable item. Recommended first scope is explicitly tradable skill scrolls/books or unlearned entitlements; this is a proposal, not an approved conversion. Selling an already learned skill would require atomic removal, unequip, progression/prerequisite checks and client refresh rules. Never copy it to the buyer while leaving the seller's original entitlement. Bound, event-limited and account-locked content remains nontradable unless a deliberate policy permits it.

Decision dependencies before enabling trades: approved currency, fee/tax and rounding, tradability/binding rules, skill form, listing duration/cancellation, seller/buyer restrictions, instance identity and handling of refunded/revoked content. No real-money payments, fee percentage or automatic tradability are assumed. These unresolved decisions do not block hosting the existing portal, nor justify silently enabling an unsafe market.

Acceptance: actual two-player browser exchange; exactly one winner under concurrent purchase; no dupes or lost balances after disconnect/retry/restart; cancelled/expired escrow restored once; supply unchanged; audit and moderation actions traceable; game, portal and Discord cannot bypass reservations.

## UI/UX refactor plan (still deferred)

1. Confirm feature map, primary player/operator flows and navigation using working functions, not the current admin-like player layout.
2. Prototype the player dashboard and market browse/sell/detail flows before building a full component library. Test information hierarchy, labels and error/retry paths; obtain owner's design approval.
3. Establish responsive components/tokens, accessible states and Ninja Lost visual references with reviewed asset rights. Current screenshots and Filament player chrome are NOT accepted canon.
4. Replace presentation route-by-route while retaining services, account identities, transactions and receipts. Admin may continue with Filament while player presentation is customized; a new frontend framework is not decided merely for appearance.
5. Verify real desktop/mobile behavior: inventory selection, buy/sell confirmation, price/fee disclosure, pending transaction, recovery, error states, keyboard access, performance and direct user acceptance. No one-shot rewrite of both UI and economy.

Existing paths can be preserved or redirected during approved navigation migration. Never label a placeholder market or a static dashboard as implemented. UI/UX completion and functional alpha hosting are independent milestones; broad visual work starts only when the owner reopens it.
