import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


FETCH = load("ninja_lost_asset_fetch", ROOT / "ops" / "assets" / "fetch_pinned.py")
INSTALL = load("ninja_lost_ffdec_install", ROOT / "ops" / "assets" / "install_ffdec.py")


class AssetFetchTest(unittest.TestCase):
    def test_real_source_lock_is_valid_and_bounded(self):
        lock = json.loads((ROOT / "ops" / "assets" / "source-lock.json").read_text())
        FETCH.validate_lock(lock)
        self.assertGreaterEqual(len(lock["files"]), 10)
        self.assertTrue(any(x["kind"] == "skill-icon-pack" for x in lock["files"]))
        self.assertTrue(any(x["kind"] == "item-icon-pack" for x in lock["files"]))

    def test_source_lock_rejects_unsafe_and_duplicate_entries(self):
        lock = json.loads((ROOT / "ops" / "assets" / "source-lock.json").read_text())
        unsafe = copy.deepcopy(lock)
        unsafe["files"][0]["path"] = "../escape.swf"
        with self.assertRaisesRegex(ValueError, "unsafe path"):
            FETCH.validate_lock(unsafe)

        duplicate = copy.deepcopy(lock)
        duplicate["files"].append(copy.deepcopy(duplicate["files"][0]))
        with self.assertRaisesRegex(ValueError, "duplicate canonical"):
            FETCH.validate_lock(duplicate)

    def test_raw_url_is_commit_pinned_and_escaped(self):
        url = FETCH.raw_url("owner/repo", "a" * 40, "swf/items/a b.swf")
        self.assertEqual(
            url,
            "https://raw.githubusercontent.com/owner/repo/"
            + "a" * 40
            + "/swf/items/a%20b.swf",
        )

    def test_git_blob_digest_matches_git_object_rule(self):
        self.assertEqual(
            FETCH.git_blob_sha1(b"test"),
            "30d74d258442c7c65512eafab474568dd706c430",
        )

    def test_ffdec_tool_lock_is_valid(self):
        entry = INSTALL.load_lock(ROOT / "ops" / "assets" / "tool-lock.json")
        self.assertEqual(entry["version"], "26.4.0")
        self.assertEqual(
            entry["sha256"],
            "56ce960aeafc7caf034e2052ba49f878a883f7c7ca690da6eeffca9587b5e4b9",
        )

    def test_archive_paths_cannot_escape(self):
        with self.assertRaisesRegex(ValueError, "unsafe archive"):
            INSTALL.safe_member("../ffdec.jar")
        with self.assertRaisesRegex(ValueError, "unsafe archive"):
            INSTALL.safe_member("/absolute/ffdec.jar")


if __name__ == "__main__":
    unittest.main()
