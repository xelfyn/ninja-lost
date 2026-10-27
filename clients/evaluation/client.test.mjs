import test from 'node:test';
import assert from 'node:assert/strict';
import { EvaluationClient } from './client.mjs';

test('probe rejects external destinations, credentials, query strings and paths', () => {
  for (const value of ['https://example.com', 'http://127.0.0.1.example.com', 'http://user:pass@localhost', 'http://localhost/other', 'http://localhost/?token=x', 'file:///tmp/client']) {
    assert.throws(() => new EvaluationClient(value));
  }
  for (const value of ['http://127.0.0.1:8000', 'http://localhost:8000', 'http://[::1]:8000']) assert.doesNotThrow(() => new EvaluationClient(value));
});
test('purchase intent is immutable and explicit rather than silently recreated on retry', () => {
  const a = EvaluationClient.purchaseIntent(1, 2, 3);
  const payload = JSON.stringify(a);
  assert.equal(JSON.stringify(a), payload); assert.equal(Object.isFrozen(a), true);
  assert.throws(() => { a.quantity = 4; }, TypeError);
  assert.notEqual(EvaluationClient.purchaseIntent(1, 2, 3).request_key, a.request_key);
  for (const quantity of [0, -1, 1.5, 1001, '1']) assert.throws(() => EvaluationClient.purchaseIntent(1, 2, quantity));
});
test('unsafe paths fail before any network request', async () => {
  const client = new EvaluationClient();
  for (const path of ['//example.com', '/../../admin', '/characters?token=x', 'https://example.com']) await assert.rejects(client.exchange('GET', path));
});
