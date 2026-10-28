import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "ninja_lost_asset_scan", ROOT / "ops" / "assets" / "scan_pack.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class AssetScanTest(unittest.TestCase):
    def test_classifies_known_original_asset_families(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for name in [
                "skill_42.swf",
                "wpn1498.swf",
                "set_2248.swf",
                "set_01_0.swf",
                "hair_01_1.swf",
                "skill_icon_1-10.swf",
                "item_icon_before_801.swf",
                "back_557.swf",
                "acsy_9.swf",
                "pet_71.swf",
                "ene_338.swf",
                "data_library_en.swf",
            ]:
                (root / name).write_bytes(b"FWS\x09" + name.encode())

            report = MODULE.scan(root)
            self.assertEqual(report["files_recognized"], 12)
            self.assertEqual(report["duplicate_identities"], 0)
            self.assertEqual(report["counts"]["skill"], 1)
            self.assertEqual(report["counts"]["weapon"], 1)
            self.assertEqual(report["counts"]["clothing"], 2)
            self.assertEqual(report["counts"]["hair"], 1)
            self.assertEqual(report["counts"]["skill-icon-pack"], 1)
            self.assertEqual(report["counts"]["item-icon-pack"], 1)
            ids = {x["canonical_id"] for x in report["assets"]}
            self.assertIn("set_1_variant_0", ids)
            self.assertIn("hair_1_variant_1", ids)
            self.assertIn("skill_icon_1_to_10", ids)
            self.assertIn("item_icon_before_801", ids)
            self.assertEqual(report["counts"]["pet"], 1)
            self.assertEqual(report["counts"]["enemy"], 1)
            self.assertEqual(report["counts"]["library"], 1)
            self.assertTrue(all(x["visual_status"] == "unprocessed" for x in report["assets"]))

    def test_unknown_files_are_ignored_not_promoted_to_content(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "mystery.swf").write_bytes(b"FWS\x09mystery")
            (root / "notes.txt").write_text("not an asset")
            report = MODULE.scan(root)
            self.assertEqual(report["files_recognized"], 0)
            self.assertEqual(report["files_ignored"], 2)

    def test_duplicate_canonical_identity_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "a").mkdir()
            (root / "b").mkdir()
            (root / "a" / "skill_42.swf").write_bytes(b"FWS\x09a")
            (root / "b" / "skill42.swf").write_bytes(b"FWS\x09b")
            report = MODULE.scan(root)
            self.assertEqual(report["files_recognized"], 2)
            self.assertEqual(report["duplicate_identities"], 1)

    def test_symlink_is_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "skill_1.swf"
            target.write_bytes(b"FWS\x09data")
            link = root / "skill_2.swf"
            try:
                link.symlink_to(target)
            except (OSError, NotImplementedError):
                self.skipTest("symlink unavailable on this platform")
            with self.assertRaisesRegex(ValueError, "symlink refused"):
                MODULE.scan(root)


if __name__ == "__main__":
    unittest.main()
