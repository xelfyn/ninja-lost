# Original Game Asset Intake

This tooling supports the private Ninja Lost development environment. It is intentionally separate from runtime gameplay and from Git-tracked application assets.

## Rules

- Do not use AI-generated character/equipment/pet/skill art as real game content.
- Do not infer damage, effect, price, target, rarity, progression, or ownership from a SWF visual.
- Do not commit imported SWFs or extracted derivatives by default.
- Technical availability does not establish distribution rights.
- Missing visuals remain missing until reviewed.

## Pinned starter intake

`source-lock.json` pins a small representative pack from the audited source revision:

- skill animation SWFs 1-5;
- the original skill icon pack for IDs 1-10;
- one weapon;
- modular set/hair variants;
- back item;
- accessory;
- pet;
- the early item-icon library pack;
- `data_library_en.swf`.

This is a compatibility sample, not a claim that these records are the final Player Portal collection.

## Private Codespaces flow

After this branch is reviewed and merged:

1. Fetch locked source files into ignored private storage:

   `python3 ops/assets/fetch_pinned.py storage/app/ninja-lost/assets/source-pack-v1`

2. Inventory them without execution:

   `python3 ops/assets/scan_pack.py storage/app/ninja-lost/assets/source-pack-v1 --output storage/app/ninja-lost/assets/scan-v1.json`

3. Install the pinned FFDec tool into ignored private storage:

   `python3 ops/assets/install_ffdec.py storage/app/ninja-lost/tools/ffdec-26.4.0`

4. Export an icon pack for review:

   `python3 ops/assets/extract_ffdec.py --ffdec-jar storage/app/ninja-lost/tools/ffdec-26.4.0/ffdec.jar --profile icon-pack storage/app/ninja-lost/assets/source-pack-v1/swf/library/icons/skill_icon_1-10.swf storage/app/ninja-lost/assets/extracted/skill-icon-1-10`

5. Export a first-frame/content inspection bundle when needed:

   `python3 ops/assets/extract_ffdec.py --ffdec-jar storage/app/ninja-lost/tools/ffdec-26.4.0/ffdec.jar --profile content-preview storage/app/ninja-lost/assets/source-pack-v1/swf/skills/skill_01.swf storage/app/ninja-lost/assets/extracted/skill-1`

Nothing above promotes a derivative to the web. The output remains `extracted-unreviewed`.

## Promotion boundary

A later promotion command will require:

- exact source identity;
- matched content ID;
- selected derivative;
- provenance;
- operator approval;
- visual status;
- destination path.

Only then may `GameAssetRegistry` expose a reviewed web derivative.

Character visuals are a separate composition problem: original appearance pieces must be resolved and rendered together. A single equipment icon or skill animation is never treated as a character render.

## Promote one reviewed derivative

After you have inspected an extracted image and confirmed that it represents the intended original game content, promote it explicitly:

```bash
python3 ops/assets/promote_asset.py \
  --extraction-root storage/app/ninja-lost/assets/extracted/skill-icon-1-10 \
  --derivative storage/app/ninja-lost/assets/extracted/skill-icon-1-10/<reviewed-file>.png \
  --public-root public/game-assets \
  --manifest storage/app/ninja-lost/assets/manifest.json \
  --kind skill \
  --source-id skill_1 \
  --source-type ninja-saga-swf \
  --source-ref swf/library/icons/skill_icon_1-10.swf \
  --status verified
```

The destination filename is generated from the content identity. Existing identities are refused unless `--replace` is supplied deliberately after review. Promotion records the derivative hash and does not modify inventory, mechanics, balances, ownership, or active game content.

Do not mark an asset `verified` merely because extraction succeeded. Verification means a human confirmed that the selected derivative matches the intended content identity.

