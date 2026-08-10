# Step 29 — A review of the whole codebase, and the two defects it found (2026-08-07)

Read the service, Core, the Laravel layer, config and deployment as a reviewer
rather than an author. Eight findings; two fixed here, one turned into SPEC-024
(draft), the rest recorded below for whoever picks them up.

### ⚠️ Defect 1: SPEC-011's depth guard was defeated by the check in front of it

`rejectAssertions()` ran the SIZE check before the DEPTH check:

```js
if (JSON.stringify(assertion).length > MAX_ASSERTION_BYTES) ...   // line 250
if (exceedsDepth(assertion, MAX_ASSERTION_DEPTH)) ...             // line 253
```

`exceedsDepth()` is careful — it stops at frame 17 whatever it is handed, which
is what SPEC-011 describes. `JSON.stringify()` is not: it recurses over the whole
structure and throws `RangeError` at about 10 000 levels. Measured in the
container:

```
depth  1000  JSON.stringify=ok                              exceedsDepth=ok
depth 10000  JSON.stringify=RangeError: Maximum call stack  exceedsDepth=ok
depth 60000  JSON.stringify=RangeError: Maximum call stack  exceedsDepth=ok
```

So a 60 000-level payload answered **500 with an HTML body and wrote no audit
record**: SPEC-011's bound and SPEC-012's "every request is recorded, accepted
and refused alike" both held only for payloads small enough not to need them.

The fix is the two lines swapped. What makes this worth writing down is the
shape: **a correct guard placed behind an unbounded one is not a guard.** The
existing test nested 64 levels and passed throughout.

### ⚠️ Defect 2: no catch-all error handler

The only error middleware returns `next(err)` for anything without an `err.type`,
so every unanticipated throw reached express's default handler: an HTML page, no
correlation id in the body, no audit record. Defect 1 was one instance; this was
the class. A catch-all now audits and answers `{error, cid}` in JSON.

`NODE_ENV=production` is set in the Dockerfile, so no stack ever reached a
client. That is the one thing that kept this from being worse.

### Verifying a handler with no reachable trigger

With defect 1 fixed there is no longer a way to reach the catch-all over HTTP —
which is the point of defence in depth, and also means it cannot be tested
through the normal surface. Verified the way SPEC-018 AC2 verified a second
certificate: a patched copy of `server.js` with one deliberately throwing route,
run inside the container on a spare port.

```
status = 500   content-type = application/json   cid header present
body   = {"error":"request failed","cid":"6a3c7c60-…"}
audit  = "reason":"unhandled: probe: deliberate"
```

Two container gotchas cost time, both already in this log and both re-learned:
`node /tmp/probe.js` cannot resolve `express` because module resolution follows
the **script's** directory, not the cwd (Step 11) — the script must live in
`/app`. And the image has no `pkill` and no `ps`, so a stray probe holding a port
is invisible; it surfaced as a puzzling 404 from a stale process, and the way out
was a different port, not diagnosis.

**This handler is not covered by a committed test.** If it should be, that needs
an acceptance criterion in a spec, because a test that patches `server.js` is
too fragile to add on its own authority.

### The other six findings

Recorded rather than fixed, in rough order of how much they matter.

1. **`/v1/read` is unbounded** — no rate limit, no concurrency cap, no record.
   Measured with `RATE_LIMIT_REQUESTS=5`: ten reads all answered 200 while the
   sixth sign was refused. SPEC-015 scoped itself to `/v1/sign` and never
   mentioned read, so this is a gap rather than a decision. **SPEC-024 (draft).**
2. **`maxResponseBytes` defaults to 96 MiB**, documented as "headroom over the
   service's 50 MB request cap" — a cap SPEC-017 lowered to 20 MB. The guard that
   exists to stop a hostile response exhausting PHP memory allows ~3.5× more than
   the service can return, and sits far above the common `memory_limit=128M`, so
   the OOM arrives before the guard does. Both the constant and the config
   comment are stale.
3. **No client-side request bound.** The client bounds the response but not the
   request: `SignAssetJob` reads a file of any size, and signing then holds
   bytes + base64 + JSON ≈ 3.7× before the request leaves. A caller meets the
   20 MB limit as a 413 after paying that, or OOMs first. `/health` publishes
   `max_body_bytes`, so a pre-flight check is cheap.
4. **Plain HTTP by default, with no warning when the host is remote.** Loopback
   is fine and documented, but pointing `base_url` at a remote host over `http://`
   sends the bearer token in clear and nothing objects.
5. **The extension reader parses untrusted input in the web process.** ADR-0003
   isolates the *key*; `ExtC2paReader` moves parsing of hostile assets from a
   disposable container into the PHP worker through a native Rust extension.
   Nothing in the README, SPEC-019 or the ADRs mentions this, and SPEC-020's
   `auto` makes it near-default for anyone who installs the extension.
6. **Nits.** `extractError()` embeds a service error string in an exception with
   no cap (bounded only by `maxResponseBytes`); `file_put_contents` in the job
   and command is not atomic, so a crash mid-write leaves a truncated "signed"
   file; `rateLimited()` runs before the concurrency check, so a 429-for-load has
   already spent rate budget; an empty `CONTENTAUTH_API_KEY` starts a service
   that 401s everything without saying so at startup.

### What the review found in good order

Worth recording too, so the list above is calibrated. Constant-time token
comparison over SHA-256 digests. No key material anywhere in git history.
`composer audit` and `npm audit --omit=dev` both clean today.
`ManifestStoreParser` treats every field as untrusted and degrades instead of
throwing, with a bounded `json_decode` depth. No token, key or manifest is logged
anywhere in `src/`. Startup fails closed on an unparseable certificate and on
trust settings that would verify nothing. And SVG — the newest format — neither
expands XML entities nor resolves external ones, checked with an entity bomb and
an XXE probe against the running service.

---

[← Step 28](step-28-spec-023-thirteen-media-types.md) · [index](../NOTES.md) · [Step 30 →](step-30-spec-024-bounding-the-read-path.md)
