import { randomUUID } from 'node:crypto';

/** Dependency-free diagnostic client. This is NOT the SWF/Android game client or a public SDK. */
export class EvaluationClient {
  #cookies = new Map();
  #csrf = '';
  #origin;

  constructor(origin = 'http://127.0.0.1:8000') {
    const url = new URL(origin);
    if (!['http:', 'https:'].includes(url.protocol) || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
      || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
      throw new Error('Evaluation client accepts a loopback origin only.');
    }
    this.#origin = url.origin;
  }

  fork() {
    const client = new EvaluationClient(this.#origin);
    client.#cookies = new Map(this.#cookies);
    client.#csrf = this.#csrf;
    return client;
  }

  static purchaseIntent(characterId, offerId, quantity = 1) {
    if (![characterId, offerId, quantity].every(Number.isSafeInteger) || characterId < 1 || offerId < 1 || quantity < 1 || quantity > 1000) {
      throw new TypeError('Invalid purchase intent.');
    }
    return Object.freeze({ character_id: characterId, offer_id: offerId, quantity, request_key: randomUUID() });
  }

  async exchange(method, path, body, { csrf = true } = {}) {
    if (!['GET', 'POST'].includes(method) || !/^\/[a-z0-9\/-]+$/.test(path) || path.includes('//') || path.includes('..')) {
      throw new Error('Unsupported evaluation request path/method.');
    }
    const headers = { Accept: 'application/json' };
    if (this.#cookies.size) headers.Cookie = [...this.#cookies].map(([name, value]) => `${name}=${value}`).join('; ');
    if (method === 'POST') {
      headers['Content-Type'] = 'application/json';
      if (csrf && this.#csrf) headers['X-CSRF-TOKEN'] = this.#csrf;
    }
    const response = await fetch(`${this.#origin}/evaluation/v1${path}`, {
      method, headers, body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
      redirect: 'manual', signal: AbortSignal.timeout(10000),
    });
    if (response.status >= 300 && response.status < 400) throw new Error('Evaluation client refuses redirects.');
    for (const cookie of response.headers.getSetCookie()) {
      const first = cookie.split(';', 1)[0]; const at = first.indexOf('=');
      if (at > 0) this.#cookies.set(first.slice(0, at), first.slice(at + 1));
    }
    if (!response.headers.get('content-type')?.includes('application/json')) throw new Error(`Non-JSON evaluation response (${response.status}).`);
    const text = await response.text();
    if (Buffer.byteLength(text) > 1048576) throw new Error('Evaluation response exceeded 1 MiB.');
    let data;
    try { data = JSON.parse(text); } catch { throw new Error('Invalid evaluation response JSON.'); }
    if (response.ok && typeof data.csrf_token === 'string') this.#csrf = data.csrf_token;
    return { status: response.status, data };
  }

  async login(username, password) {
    const bootstrap = await this.exchange('GET', '/bootstrap');
    if (bootstrap.status !== 200) throw new Error(`Bootstrap denied (${bootstrap.status}).`);
    return this.exchange('POST', '/login', { username, password });
  }

  // Caller owns the intent. Retry MUST reuse it; no automatic retry with a new UUID.
  purchase(intent) { return this.exchange('POST', '/purchases', intent); }
}
