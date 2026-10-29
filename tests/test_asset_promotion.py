import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "ninja_lost_asset_promotion", ROOT / "ops" / "assets" / "promote_asset.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)

PNG = b"\x89PNG\r\n\x1a\n" + b"reviewed-image"


class AssetPromotionTest(unittest.TestCase):
    def roots(self, tmp):
        root = Path(tmp)
        extracted = root / "extracted"
        public = root / "public"
        manifest = root / "state" / "manifest.json"
        extracted.mkdir()
        public.mkdir()
        return extracted, public, manifest

    def test_promotes_reviewed_derivative_and_writes_manifest(self):
        with tempfile.TemporaryDirectory() as tmp:
            extracted, public, manifest = self.roots(tmp)
            image = extracted / "skill_1.png"
            image.write_bytes(PNG)

            result = MODULE.promote(
                extraction_root=extracted,
                derivative=image,
                public_root=public,
                manifest_path=manifest,
                kind="skill",
                source_id="skill_1",
                source_type="ninja-saga-swf",
                source_ref="swf/skills/skill_01.swf",
                visual_status="verified",
            )

            self.assertEqual(result["web_path"], "skill/skill_1.png")
            self.assertEqual(result["visual_status"], "verified")
            self.assertFalse(result["replaced"])
            self.assertEqual((public / "skill" / "skill_1.png").read_bytes(), PNG)

            data = json.loads(manifest.read_text())
            record = data["assets"]["skill"]["skill_1"]
            self.assertEqual(record["source_ref"], "swf/skills/skill_01.swf")
            self.assertEqual(record["derivative_sha256"], MODULE.sha256(image))

    def test_duplicate_identity_requires_explicit_replace(self):
        with tempfile.TemporaryDirectory() as tmp:
            extracted, public, manifest = self.roots(tmp)
            image = extracted / "skill_1.png"
            image.write_bytes(PNG)
            args = dict(
                extraction_root=extracted,
                derivative=image,
                public_root=public,
                manifest_path=manifest,
                kind="skill",
                source_id="skill_1",
                source_type="ninja-saga-swf",
                source_ref="swf/skills/skill_01.swf",
                visual_status="available",
            )
            MODULE.promote(**args)
            with self.assertRaisesRegex(ValueError, "already exists"):
                MODULE.promote(**args)

            image.write_bytes(PNG + b"-revision")
            replaced = MODULE.promote(**args, replace=True)
            self.assertTrue(replaced["replaced"])
            self.assertEqual((public / "skill" / "skill_1.png").read_bytes(), PNG + b"-revision")

    def test_disguised_swf_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            extracted, public, manifest = self.roots(tmp)
            image = extracted / "skill_1.png"
            image.write_bytes(b"FWS\x09not-a-png")
            with self.assertRaisesRegex(ValueError, "signature mismatch"):
                MODULE.promote(
                    extraction_root=extracted,
                    derivative=image,
                    public_root=public,
                    manifest_path=manifest,
                    kind="skill",
                    source_id="skill_1",
                    source_type="ninja-saga-swf",
                    source_ref="swf/skills/skill_01.swf",
                    visual_status="available",
                )

    def test_derivative_must_stay_inside_extraction_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            extracted, public, manifest = self.roots(tmp)
            outside = Path(tmp) / "outside.png"
            outside.write_bytes(PNG)
            with self.assertRaisesRegex(ValueError, "inside extraction root"):
                MODULE.promote(
                    extraction_root=extracted,
                    derivative=outside,
                    public_root=public,
                    manifest_path=manifest,
                    kind="skill",
                    source_id="skill_1",
                    source_type="ninja-saga-swf",
                    source_ref="swf/skills/skill_01.swf",
                    visual_status="available",
                )

    def test_source_reference_traversal_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            extracted, public, manifest = self.roots(tmp)
            image = extracted / "skill_1.png"
            image.write_bytes(PNG)
            with self.assertRaisesRegex(ValueError, "source reference"):
                MODULE.promote(
                    extraction_root=extracted,
                    derivative=image,
                    public_root=public,
                    manifest_path=manifest,
                    kind="skill",
                    source_id="skill_1",
                    source_type="ninja-saga-swf",
                    source_ref="../secret.swf",
                    visual_status="available",
                )


if __name__ == "__main__":
    unittest.main()
