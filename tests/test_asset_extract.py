import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "ninja_lost_asset_export", ROOT / "ops" / "assets" / "extract_ffdec.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class AssetExportTest(unittest.TestCase):
    def test_icon_pack_commands_do_not_export_battle_frame(self):
        jar = Path("/tool/ffdec.jar")
        swf = Path("/input/icons.swf")
        output = Path("/output")
        commands = MODULE.build_commands(jar, swf, output, "icon-pack")
        joined = [" ".join(c) for c in commands]
        self.assertEqual(len(commands), 3)
        self.assertTrue(any("-export symbolClass" in c for c in joined))
        self.assertTrue(any("-export sprite" in c for c in joined))
        self.assertTrue(any("-export image" in c for c in joined))
        self.assertFalse(any("-export frame" in c for c in joined))

    def test_content_preview_exports_only_first_main_timeline_frame(self):
        commands = MODULE.build_commands(
            Path("/tool/ffdec.jar"),
            Path("/input/skill.swf"),
            Path("/output"),
            "content-preview",
        )
        frame = [c for c in commands if "frame" in c]
        self.assertEqual(len(frame), 1)
        self.assertIn("-select", frame[0])
        self.assertEqual(frame[0][frame[0].index("-select") + 1], "1")

    def test_unknown_profile_rejected(self):
        with self.assertRaisesRegex(ValueError, "unknown export profile"):
            MODULE.build_commands(
                Path("/tool/ffdec.jar"),
                Path("/input/skill.swf"),
                Path("/output"),
                "unknown",
            )

    def test_inventory_ignores_unapproved_extensions(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "preview.png").write_bytes(b"png")
            (root / "script.js").write_text("not a visual derivative")
            rows = MODULE.inventory(root)
            self.assertEqual([x["path"] for x in rows], ["preview.png"])
            self.assertEqual(rows[0]["visual_status"], "extracted-unreviewed")


if __name__ == "__main__":
    unittest.main()
