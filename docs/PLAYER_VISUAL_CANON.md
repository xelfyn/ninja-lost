# Ninja Lost Player Visual Canon

## Status

Design and asset-policy baseline for the Player Portal refactor.

This document intentionally separates **game visuals** from **web UI**:

- Web UI may be redesigned.
- Character, equipment, pet, skill, enemy and game-world visuals must come from reviewed Ninja Saga-compatible assets or future operator imports.
- AI-generated stand-ins must not be presented as real game content.
- Missing visuals use a neutral placeholder until a reviewed asset is available.

This document does not grant rights to third-party assets. Technical compatibility and rights/provenance remain separate review dimensions.

## Canonical player-facing terminology

The UI must use the player's mental model rather than legacy field names:

- Skills / Jutsu
- Talents
- Division Class
- Senjutsu
- Equipment
- Pets
- Inventory
- Missions
- Battle Record

Legacy names such as bloodline and bloodline_id remain client-compatibility terminology. They map to **Talent** at the product/domain boundary and are not a standalone primary Player Portal navigation item.

Passive is a mechanic/node type, not a top-level module.

## Visual source policy

Allowed visual states:

1. **verified** — imported asset is matched to the intended content and reviewed.
2. **available** — asset exists and is structurally usable but has not completed full visual/game-client verification.
3. **missing** — no reviewed web visual exists.
4. **unsupported** — source exists but cannot be safely exposed by the current web renderer.

The website must never silently replace missing or unsupported content with generated game art.

## Asset families

The registry is designed to support:

- character composition/render captures;
- weapon;
- clothing/body set;
- back item;
- accessory;
- hair/face where needed;
- skill/jutsu;
- Talent active/passive skill;
- Division Class skill;
- Senjutsu skill;
- pet and pet skill;
- item/material;
- enemy/NPC;
- event/mission artwork.

The original client may assemble a character from multiple modular assets. Therefore a full-body character image must be treated as a **rendered composition** or verified capture, not inferred from one equipment icon.

## Import pipeline

Source pack -> inventory scan -> metadata extraction -> visual extraction/render -> content-ID matching -> conflict/duplicate report -> operator review -> asset manifest -> private Player Portal preview -> verification -> activation.

SWF is an import format, not a browser-delivery format. The web layer should consume reviewed PNG/WebP/JPEG derivatives where practical.

A visual file never authorizes gameplay mechanics. Damage, effects, targeting, cooldown, price, rarity and progression data must come from verified metadata or operator decisions.

## Manifest v1

Local/private imported assets are described by a schema with: schema_version, source identity, asset family, source_id, source_type, source_ref, web_path and visual_status.

The manifest contains identifiers and provenance metadata. Binary source packs and generated derivatives remain outside Git unless their rights and repository policy explicitly permit inclusion.

## UI direction

Canonical color foundation:

- Ninja Orange: #FF4F00
- Chakra Yellow: #FFC400
- Pure White: #FFFFFF
- Ink: #101C1C

No decorative gradients.

The Player Portal should feel like a lightweight in-game companion rather than a SaaS dashboard:

- quiet global navigation;
- flat surfaces and whitespace;
- restrained separators;
- game art carries the visual identity;
- English copy is concise and natural;
- IDs are secondary metadata, not the main interaction;
- the same content record feeds Encyclopedia, inventory, loadout, shop/market and admin selectors.

## First implementation slice

Do not redesign every route at once.

The first visual slice is:

1. Player character overview;
2. Inventory/equipment lookup;
3. Encyclopedia lookup;
4. original asset rendering via the shared registry.

Acceptance requires real content data and reviewed assets. Mockup-only screenshots do not count as completion.
