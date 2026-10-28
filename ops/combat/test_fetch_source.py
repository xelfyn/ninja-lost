import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('combat_fetch', Path(__file__).with_name('fetch-source.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class SourceAuthenticationTest(unittest.TestCase):
    def test_token_only_sent_to_exact_api_origin(self):
        with patch.dict('os.environ', {'NL_SOURCE_TOKEN': 'unit-test-placeholder'}, clear=True):
            api = module.source_request('https://api.github.com/repos/example/source/git/trees/pin')
            raw = module.source_request('https://raw.githubusercontent.com/example/source/pin/file')
            self.assertEqual('Bearer unit-test-placeholder', api.get_header('Authorization'))
            self.assertIsNone(raw.get_header('Authorization'))

    def test_external_lookalike_plaintext_and_credential_urls_reject(self):
        for url in ['https://api.github.com.evil.invalid/x', 'http://api.github.com/x', 'https://user@api.github.com/x', 'https://api.github.com:8443/x', 'https://example.invalid/x']:
            with self.subTest(url=url), self.assertRaises(ValueError):
                module.source_request(url)

    def test_no_token_is_optional_but_redirects_never_followed(self):
        with patch.dict('os.environ', {}, clear=True):
            request = module.source_request('https://api.github.com/repos/example/source')
            self.assertIsNone(request.get_header('Authorization'))
        with self.assertRaises(ValueError):
            module.NoRedirect().redirect_request(request, None, 302, 'redirect', {}, 'https://example.invalid')

    def test_header_injection_rejects_without_request(self):
        with patch.dict('os.environ', {'NL_SOURCE_TOKEN': 'bad\nheader'}, clear=True), self.assertRaises(ValueError):
            module.source_request('https://api.github.com/repos/example/source')

if __name__ == '__main__':
    unittest.main()
