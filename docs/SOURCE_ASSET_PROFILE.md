# Audited Ninja Saga asset-source profile

Read-only profile of the public reference snapshot:

- repository: shenjai47/NinjaSaga
- commit: 39bcd9a2ee4bd653e87ba1cbf6697de21f3a2107
- purpose: file-family and naming discovery only

This profile does not import, redistribute, activate, or establish rights to any binary asset.

## Observed SWF families

The `swf` tree contains dedicated folders for actions, enemies, icons, items, language, library, maps, mission, NPC, panels, pets, skills and SNS assets.

### Skills

The `swf/skills` tree contains **1,070 SWF files** in the audited snapshot. All 1,070 inspected names match the `skill_<number>.swf` / `skill<number>.swf` identity family.

This count is a file count for this snapshot, not a claim of 1,070 unique playable mechanics and not a claim that the snapshot matches another server's catalog.

### Items and equipment

The `swf/items` tree contains **5,550 files**. Filename-family counts in the audited tree:

| family | files |
| --- | ---: |
| set | 2,621 |
| weapon (`wpn`) | 1,583 |
| back | 675 |
| hair | 575 |
| accessory (`acsy`) | 93 |
| face | 2 |
| other | 1 |

The remaining non-pattern item observed in the tree is `ChakraScroll.swf`.

These are source-file families, not normalized Ninja Lost content counts. Variant suffixes must be preserved until their client meaning is verified.

### Pets

The `swf/pets` tree contains **185 files**. Only part of the tree uses `pet_<number>.swf`; many original files use species/event names such as `cat_1.swf`, `fox_2.swf`, `snake_*.swf`, `tail_*.swf`, etc.

Therefore the Ninja Lost scanner uses parent-folder context for pet-family recognition and preserves the source filename instead of inventing a numeric pet ID.

### Libraries and icon packs

The library tree includes client/code/network/library SWFs plus nested icon and item-icon packs. The audited item-icon subtree contains grouped files such as `item_icon_801-850.swf` and `item_icon_before_801.swf`.

Core game definitions in the reference implementation are read from client libraries (including data-library style sources), so visual files alone must never be treated as authoritative mechanics.

## Product implications

1. **Character visuals are composite.** A Player Portal full-body character cannot be represented by one random image. It must be rendered from verified character appearance/equipment state or a verified capture produced from that state.
2. **Equipment visuals are source-linked.** The web layer should resolve the same canonical content identity used by inventory/loadout and then render a reviewed web derivative of the source asset.
3. **Pet classification is path-aware.** Filename-only scanning loses valid original pet files.
4. **Visual availability is separate from mechanics.** An SWF can exist while its effect/stat definition remains unknown or unsupported.
5. **Counts are snapshot-specific.** Do not display these source counts as Ninja Lost catalog totals unless the corresponding records are actually imported, normalized and accepted.

## Next technical slice

The private operator workflow should be:

```text
asset pack
  -> read-only scan
  -> source inventory report
  -> extract/render selected web previews
  -> match canonical content IDs
  -> operator review
  -> manifest
  -> Player Portal / Encyclopedia
```

No binary source asset is committed to Git by this workflow.
