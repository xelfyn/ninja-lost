"""Unit tests use synthetic bytes, not real credentials or player data."""
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('bootstrap', ROOT / 'scripts/ci/check_bootstrap.py')
GUARD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUARD)


class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.files = {name: (ROOT / name).read_bytes() for name in GUARD.ALLOWED}

    def test_valid_bootstrap(self):
        self.assertEqual(GUARD.validate_files(self.files), [])

    def test_missing_file_fails(self):
        self.files.pop('README.md')
        self.assertTrue(GUARD.validate_files(self.files))

    def test_environment_file_fails(self):
        self.files['.env'] = b'example'
        self.assertTrue(GUARD.validate_files(self.files))

    def test_binary_fails(self):
        self.files['README.md'] = b'\x00\xff'
        self.assertTrue(GUARD.validate_files(self.files))

    def test_large_file_fails(self):
        self.files['README.md'] = b'a' * (GUARD.MAX_FILE + 1)
        self.assertTrue(GUARD.validate_files(self.files))

    def test_invalid_json_fails(self):
        self.files['project/source-lock.json'] = b'{'
        self.assertTrue(GUARD.validate_files(self.files))

    def test_invalid_json_shape_fails(self):
        self.files['project/source-lock.json'] = b'[]'
        self.assertTrue(GUARD.validate_files(self.files))

    def test_unpinned_source_fails(self):
        lock = json.loads(self.files['project/source-lock.json'])
        lock['candidate']['commit'] = 'main'
        self.files['project/source-lock.json'] = json.dumps(lock).encode()
        self.assertTrue(GUARD.validate_files(self.files))

    def test_artifact_policy_fails(self):
        lock = json.loads(self.files['project/source-lock.json'])
        lock['ci_policy']['artifact_upload'] = True
        self.files['project/source-lock.json'] = json.dumps(lock).encode()
        self.assertTrue(GUARD.validate_files(self.files))

    def test_artifact_step_fails(self):
        self.files['.github/workflows/bootstrap.yml'] += b'\n# actions/upload-artifact\n'
        self.assertTrue(GUARD.validate_files(self.files))

    def test_unbounded_job_fails(self):
        name = '.github/workflows/bootstrap.yml'
        self.files[name] = self.files[name].replace(b'timeout-minutes: 3', b'timeout-minutes: 60')
        self.assertTrue(GUARD.validate_files(self.files))

    def test_key_marker_fails(self):
        self.files['README.md'] += ('\n-----BEGIN ' + 'PRIVATE KEY-----\n').encode()
        self.assertTrue(GUARD.validate_files(self.files))


if __name__ == '__main__':
    unittest.main()
