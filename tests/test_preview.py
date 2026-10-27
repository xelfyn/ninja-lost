import importlib.util
import tempfile
import unittest
from pathlib import Path
SPEC = importlib.util.spec_from_file_location('preview', Path(__file__).resolve().parents[1] / 'ops/preview/environment.py')
preview = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(preview)

class PreviewTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
    def tearDown(self): self.tmp.cleanup()
    def test_local_origin(self): self.assertEqual(preview.origin({}), 'http://127.0.0.1:8000')
    def test_cloud_origin(self): self.assertEqual(preview.origin({'CODESPACES':'true','CODESPACE_NAME':'kind-ninja-123'}), 'https://kind-ninja-123-8000.app.github.dev')
    def test_untrusted_domain(self):
        with self.assertRaises(ValueError): preview.origin({'CODESPACES':'true','CODESPACE_NAME':'safe','GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN':'attacker.example'})
    def test_bad_name(self):
        with self.assertRaises(ValueError): preview.origin({'CODESPACES':'true','CODESPACE_NAME':'safe/../../'})
    def test_idempotent_secrets(self):
        preview.prepare(self.root,{})
        first=(self.root/'.devcontainer/.runtime/credentials.json').read_bytes()
        preview.prepare(self.root,{})
        self.assertEqual(first,(self.root/'.devcontainer/.runtime/credentials.json').read_bytes())
    def test_preserve_key_on_origin_change(self):
        preview.prepare(self.root,{})
        before=(self.root/'.env').read_text()
        preview.prepare(self.root,{'CODESPACES':'true','CODESPACE_NAME':'new-space-123'})
        after=(self.root/'.env').read_text()
        self.assertEqual(next(x for x in before.splitlines() if x.startswith('APP_KEY=')),next(x for x in after.splitlines() if x.startswith('APP_KEY=')))
        self.assertIn('SESSION_SECURE_COOKIE=true',after)
    def test_foreign_env_not_overwritten(self):
        (self.root/'.env').write_text('REAL=production\n')
        with self.assertRaises(ValueError): preview.prepare(self.root,{})
        self.assertEqual((self.root/'.env').read_text(),'REAL=production\n')
    def test_modified_managed_env_rejected(self):
        preview.prepare(self.root,{})
        with (self.root/'.env').open('a') as f:f.write('UNKNOWN=1\n')
        with self.assertRaises(ValueError):preview.prepare(self.root,{})
    def test_incomplete_state_rejected(self):
        preview.prepare(self.root,{})
        (self.root/'.devcontainer/.runtime/credentials.json').unlink()
        with self.assertRaises(ValueError):preview.prepare(self.root,{})
    def test_mismatch_db_state_rejected(self):
        preview.prepare(self.root,{})
        (self.root/'.devcontainer/.runtime/mysql.env').write_text('MYSQL_DATABASE=other\n')
        with self.assertRaises(ValueError):preview.prepare(self.root,{})
    def test_env_permissions(self):
        preview.prepare(self.root,{})
        self.assertEqual((self.root/'.env').stat().st_mode & 0o777,0o600)
    def test_symlink_rejected(self):
        target=self.root/'other';target.write_text('leave alone')
        (self.root/'.env').symlink_to(target)
        with self.assertRaises(ValueError):preview.prepare(self.root,{})
        self.assertEqual(target.read_text(),'leave alone')

if __name__ == '__main__': unittest.main()
