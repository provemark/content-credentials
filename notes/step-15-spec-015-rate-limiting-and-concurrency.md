# Step 15 — SPEC-015 implemented: rate limiting and concurrency bounds (2026-08-05)

Refuse rather than queue: the PHP client bounds a request at 10s (SPEC-008), so
a queued request would time out client-side while still holding a slot here.
429 + `Retry-After`, audited through the SPEC-012 path.

### First, two measurements that shaped the design
```
one sign ~0.25s | six sequential ~1.52s | six concurrent ~0.42s
GET /health during four concurrent signs: 0.00–0.01s
```
Signing **parallelises** (~3.6x) and does **not** block the event loop. So a cap
is about bounding resource use, not restoring responsiveness — and a saturated
service answers `/health` as fast as an idle one, which is why `/health` now
reports `in_flight` and the effective `limits`. Without that an orchestrator
cannot tell the two apart.

### ⚠️ `requestTimeout` does NOT close a stalled request
This cost the most time and is the finding worth keeping. A client that sends
complete headers announcing a `Content-Length` and then never sends the body is
left open **indefinitely** — `server.requestTimeout` does not touch it.
Reproduced on node 20.20.2 in a bare `http.createServer` with no express at all:

```
requestTimeout=3000, headersTimeout=2000            -> still open after 8s
requestTimeout=3000, headersTimeout=2000, setTimeout(3000) -> closed after 3.0s
```

`server.setTimeout()` — the socket **inactivity** timeout — is what actually
closes it, to the second. All three are now set: `requestTimeout`/`headersTimeout`
bound a request still trickling in, `setTimeout` catches one that has stopped.

An earlier hypothesis — that the timeouts must be assigned before `listen()`
because node derives its connections-checking interval from
`min(headersTimeout, requestTimeout) / 2` at listen time — turned out **not** to
be the cause here; the bare repro sets them before listen and still leaves the
socket open. The server is now built with `http.createServer(app)` and configured
before `listen()` anyway, which is the correct order regardless.

### Guzzle promises do not run while you are not waiting on them
The AC4 test fired `postAsync()` calls, slept, then read `/health` and saw
`in_flight: 0`. Guzzle's cURL multi handler only progresses inside `wait()`, so
nothing had been sent. Rewritten to launch detached background `curl` processes,
which are genuinely concurrent with the PHP process.

### Defaults
`MAX_CONCURRENT_SIGNS=4`, `RATE_LIMIT_REQUESTS=60` per `60000`ms,
`REQUEST_TIMEOUT_MS=15000` (just above the client's own 10s, so in normal
operation the client gives up first and this only reclaims slots held by
something that is not our client), `HEADERS_TIMEOUT_MS=10000`. On by default; `0`
disables a limit explicitly and `/health` reports it.

Still true and documented rather than fixed: express buffers the body before any
limit is consulted, so a cap bounds signing work, not the memory spent admitting
the request it refuses. `MAX_BODY_SIZE` (50 MB) remains the biggest lever.

Verified: SPEC-015 7 passed (rate limit exercised with
`RATE_LIMIT_REQUESTS=5 RATE_LIMIT_WINDOW_MS=2000`), SPEC-011/012/014 unchanged,
`bin/e2e.php` green, `composer check` green.

---

[← Step 14](step-14-spec-011-and-spec-012.md) · [index](../NOTES.md) · [Step 16 →](step-16-open-items.md)
