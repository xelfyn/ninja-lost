import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('ci_guard', Path(__file__).resolve().parents[1] / 'ops/preview/ci_guard.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class PreviewCIGuardTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.env = {'CI':'true','GITHUB_ACTIONS':'true','GITHUB_REPOSITORY':'xelfyn/ninja-lost',
                    'RUNNER_ENVIRONMENT':'github-hosted','GITHUB_WORKSPACE':str(self.root),
                    'GITHUB_RUN_ID':'123','GITHUB_RUN_ATTEMPT':'1'}
    def test_valid(self):
        self.assertEqual(module.validate_context(self.root, self.env), 'nl-mb01-ci-123-1')
    def test_requires_ci(self):
        self.env['CI']='false'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_requires_github(self):
        self.env['GITHUB_ACTIONS']='false'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_other_repo(self):
        self.env['GITHUB_REPOSITORY']='other/repo'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_self_hosted(self):
        self.env['RUNNER_ENVIRONMENT']='self-hosted'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_different_workspace(self):
        self.env['GITHUB_WORKSPACE']='/unlikely/other/workspace'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_invalid_run(self):
        self.env['GITHUB_RUN_ID']='../other'
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_missing_attempt(self):
        del self.env['GITHUB_RUN_ATTEMPT']
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_existing_env(self):
        (self.root/'.env').write_text('existing')
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_existing_state(self):
        (self.root/'.devcontainer/.runtime').mkdir(parents=True)
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
    def test_refuses_dangling_env_symlink(self):
        (self.root/'.env').symlink_to(self.root/'missing')
        with self.assertRaises(ValueError): module.validate_context(self.root, self.env)
