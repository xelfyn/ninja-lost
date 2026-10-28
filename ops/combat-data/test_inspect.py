import importlib.util
import json
from pathlib import Path
import unittest
import zlib

spec = importlib.util.spec_from_file_location('combat_data_inspect', Path(__file__).with_name('inspect.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class DataIntakeTest(unittest.TestCase):
    def test_plain_and_zlib_preserve_zero(self):
        value = [{'id': 'skill_self', 'cp_cost': 0, 'cooldown': 0, 'damage': 0}]
        raw = json.dumps(value).encode()
        self.assertEqual(module.decode(raw)[0], value)
        self.assertEqual(module.decode(zlib.compress(raw))[0], value)
    def test_duplicate_keys_fail(self):
        with self.assertRaises(ValueError): module.decode(b'[{"id":"a","id":"b"}]')
    def test_nonfinite_fails(self):
        for text in [b'[{"chance":NaN}]', b'[{"chance":Infinity}]', b'[{"chance":-Infinity}]']:
            with self.assertRaises(ValueError): module.decode(text)
    def test_truncation_and_trailing_data_fail(self):
        raw = zlib.compress(b'[{}]')
        for data in [raw[:-1], raw + b'X', raw + zlib.compress(b'[]')]:
            with self.assertRaises(ValueError): module.decode(data)
    def test_compressed_expansion_is_bounded(self):
        with self.assertRaises(ValueError): module.decode(zlib.compress(b'["' + b'x' * 10000 + b'"]'), limit=1024)
    def test_wrong_top_level_fails(self):
        for text in [b'{}', b'[1]', b'[null]', b'[[]]']:
            with self.assertRaises(ValueError): module.decode(text)
    def test_empty_invalid_utf8_and_oversize_fail(self):
        for data, limit in [(b'', 8), (b'[\xff]', 10), (b'[{}]', 3)]:
            with self.assertRaises((ValueError, UnicodeError)): module.decode(data, limit)
    def test_summary_distinguishes_duplicate_ids_from_counts(self):
        value = module.summary([{'id': 'skill_a'}, {'id': 'skill_a'}], [{'skill_id': 'skill_x', 'skill_effect': []}])
        self.assertEqual(value['skill_records'], 2)
        self.assertEqual(value['unique_skill_ids'], 1)
        self.assertEqual(value['duplicate_skill_ids'], 1)
        self.assertEqual(value['orphan_effect_records'], 1)
    def test_boolean_is_not_numeric_zero(self):
        value = module.summary([{'id': 'skill_a', 'cp_cost': False, 'cooldown': False}], [])
        self.assertEqual(value['zero_cp_records'], 0)
        self.assertEqual(value['zero_cooldown_records'], 0)
    def test_redirect_is_not_followed(self):
        with self.assertRaises(ValueError): module.NoRedirect().redirect_request(None, None, 302, None, None, 'https://example.invalid')

if __name__ == '__main__': unittest.main()
