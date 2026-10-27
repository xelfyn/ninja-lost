"""MB01 replaces the eight-file B00 guard; no application behavior is mocked here."""
import importlib.util, pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,ROOT/path);mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
guard=load('guard','scripts/ci/check_repository.py')
intake=load('intake','scripts/ci/import_server.py')
class IntakeTest(unittest.TestCase):
 def test_server(self):self.assertTrue(intake.selected('app/Models/User.php'))
 def test_migration(self):self.assertTrue(intake.selected('database/migrations/test.php'))
 def test_client(self):self.assertFalse(intake.selected('Client/Game.swf'))
 def test_seeders(self):self.assertFalse(intake.selected('database/seeders/AdminUserSeeder.php'))
 def test_dotenv(self):self.assertFalse(intake.selected('.env'))
 def test_traversal(self):self.assertFalse(intake.selected('app/../secret.php'))
 def test_normal(self):self.assertEqual(guard.inspect('app/Example.php',b'<?php'),[])
 def test_env(self):self.assertTrue(guard.inspect('.env',b'APP_KEY=x'))
 def test_binary(self):self.assertTrue(guard.inspect('a.php',b'abc\0def'))
 def test_archive(self):self.assertTrue(guard.inspect('game.zip',b'x'))
 def test_vendor(self):self.assertTrue(guard.inspect('vendor/a.php',b'x'))
 def test_token(self):self.assertTrue(guard.inspect('x.txt',b'gh' + b'p_' + b'A'*36))
 def test_validation(self):
  with self.assertRaises(ValueError):intake.validate_payload('x.php',b'x\0')
 def test_utf8(self):self.assertTrue(guard.inspect('x.php',b'\xff'))
if __name__=='__main__':unittest.main()
