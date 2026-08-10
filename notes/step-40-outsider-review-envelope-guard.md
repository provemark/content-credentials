# Step 40 — Reviewing the package as an outsider, and the guard that stops at the envelope (2026-08-08)

Read `src/`, `service/`, the Laravel layer and the deployment files as a senior
reviewer rather than as their author, one session after Step 39. Twelve findings.
Three are defects; two of those became **SPEC-029** and **SPEC-030** (both
`draft`, no implementation code). The rest are recorded below, because a finding
nobody wrote down is a finding that gets found again.

### Running the service on the host, which is faster than it looks

Every probe below was taken against `node server.js` started directly on the host
on a spare port, not against a Docker rebuild:

```bash
cd service && CONTENTAUTH_API_KEY=probe \
  SIGNING_CERT_PATH=../certs/es256_certs.pem \
  SIGNING_KEY_PATH=../certs/es256_private.key \
  PORT=3999 node server.js
```

It works because `certs/es256_private.key` is gitignored but present locally, and
it turns a two-minute rebuild loop into a two-second one. **The caveat is real
and must be stated with any result taken this way**: this host runs node 26.7.0
while the image is `node:20-slim`, and `service/node_modules` is 0.7.0 while the
container carries 0.8.1 (NOTES Step 35). So this is right for *reachability* —
does this payload reach that handler — and wrong for anything about c2pa-node's
behaviour or about memory. Those still go through the container.

### ⚠️ Defect 1 — SPEC-011 validates the envelope and never the actions array

Five helpers in `server.js` walk the actions array; four do it as
`for (const action of assertion.data?.actions ?? [])`, which assumes iterability.
Nothing checks it. Both requests below carry a valid token and pass every
SPEC-011 limit:

| `data.actions` | Response | Audit |
|---|---|---|
| `123` | **500** `{"error":"request failed"}` | `outcome:"rejected"`, `reason:"unhandled: number 123 is not iterable"` |
| `"xx"` | **500** `{"error":"signing failed"}` | `outcome:"failed"`, `reason:"could not decode assertion c2pa.actions.v2 …: invalid type: string \"xx\", expected a sequence"` |
| well-formed | 200 | `outcome:"signed"` |

The second row is the one that matters. A malformed payload passed every guard,
reached `Builder.withJson()` and was refused by c2pa-rs — so it spent a
concurrency slot and a real signing attempt. SPEC-011 exists so that "the service
will sign **any** assertion structure an authenticated caller supplies" stops
being true, and for the actions array it is still true; it just stops at the
engine instead of at the boundary.

Same shape as Step 29's depth guard: *a correct guard placed behind an unbounded
one is not a guard.* Here it is a correct guard placed **beside** the thing it
never measures.

**The detail worth keeping.** `firstActionSourceTypes()` uses
`assertion.data?.actions?.[0]` — indexing, which is total over any value — while
its four siblings use `for…of`, which is not. So whether a hostile payload
becomes a 500-with-no-reason or a 500-from-the-engine depends on which accessor
happens to touch it first. That is not a design. It is what "validate the
envelope, trust the contents" produces, and it is why SPEC-029 AC8 is written
against the helpers rather than against the route.

### ⚠️ Correcting Step 29: the catch-all handler HAS been reachable all along

Step 29 wrote, of the catch-all it had just added: "With defect 1 fixed there is
no longer a way to reach the catch-all over HTTP — which is the point of defence
in depth, and also means it cannot be tested through the normal surface." It
verified the handler with a patched `server.js` on a spare port for exactly that
reason.

The payload above reaches it with one field, over plain HTTP, with a valid token.
So the handler could have had a committed test at any point since Step 29, and
the reason recorded for not writing one was wrong.

Note what it does **not** undermine: the handler worked. It audited the request,
answered JSON, and carried a correlation id — precisely as built. The catch-all
is not the defect; being reachable by a one-field payload is. And SPEC-029 puts
it back out of reach, so the committed-test question is open again, on purpose
and this time knowingly.

### ⚠️ Defect 2 — every budget is spent after auth, and the body is parsed before it

Middleware order in `server.js`: correlation id (`:541`), body parser (`:547`),
parser error handler (`:560`), bearer auth (`:596`), then the routes where
SPEC-015's and SPEC-024's limiters live. Both limiters key on
`tokenId(req.token)`, which is the right identifier and does not exist yet where
the expensive work already happened.

| Request | Observed |
|---|---|
| 26 MB body, **invalid** token | **413** — with the SPEC-017 oversized-body message |
| 5 MB well-formed JSON, **invalid** token | 401 |
| 60 requests, **invalid** token | 60 × 401, **zero** 429 |

The 413 is the finding: only the parser can produce one, so an invalid token got
its body buffered and measured before anything asked who it was. And there is no
budget on that path at all — not a rate limit, not a cap, not a counter.

Neither SPEC-015 nor SPEC-024 got this wrong. Both scoped themselves to
authenticated work and said so; SPEC-024's Problem section states outright that
`/v1/*` requires the bearer token "so this is not an unauthenticated exposure",
which is correct about the read path *being reached*. The gap is one layer above
the sentence.

**The bycatch is worth more than the security win.** SPEC-017 records at
`server.js:557` that a body-parser refusal cannot be attributed, because auth
runs after the parser. Confirmed in the probe log — the 413 record carries no
`token_id`, exactly as designed. That reasoning is sound and its premise is the
ordering; reverse the ordering and "which caller keeps sending 25 MB assets"
becomes answerable, which is the question a 413 in a log raises today and cannot
answer.

**What reordering does not buy, and SPEC-030 AC7 must measure rather than assume:**
refusing before the parser stops the allocation and the parse, not the bytes
arriving. node still reads from the socket, and a body that is never consumed is
either drained or the connection is reset — which a client may see as a reset
rather than a clean 401.

### Defect 3 — the hardening went into one of two identical methods

`SigningServiceSigner::extractError()` caps the service's error text at 256
characters, with SPEC-025 AC4's reasoning attached: whatever answers on that URL
controls this string and it ends up in somebody's logs.
`SigningServiceReader::extractError()` is the same method minus the cap, bounded
only by `maxResponseBytes` — 32 MiB into one exception message. The two are
otherwise byte-identical.

That is the finding: not "a missing cap" but *two copies, hardened once*. It
belongs in a shared helper, which is the move `ManifestStoreParser` already made
for the decoder and for the same stated reason. Not spec'd yet — it is a
one-symbol change against `src/` and needs a spec before it may be built.

### The other findings, recorded rather than fixed

1. **`node:20-slim` is end-of-life.** Node 20 left maintenance in April 2026. The
   image holding the signing key is on an unpatched runtime, and `npm audit`
   cannot see it — that audits packages, not the runtime. This is the one finding
   that touches the O.3 claim in `SECURITY.md`. Bumping it means re-running the
   full manual ritual, and the async TSA path is the regression risk.
2. **No container hardening.** No `USER node` — the process holding the private
   key runs as root. No `HEALTHCHECK`. `docker-compose.yml` sets no `mem_limit`,
   `read_only`, `cap_drop` or `no-new-privileges`. The memory one stings: this
   log is full of measured multipliers and a published "size a container against
   ~650 MiB", and the compose file that would encode it sets no limit.
3. **`SignAssetJob` retries what cannot succeed.** `$tries = 3`,
   `backoff() = [10, 60, 300]`. `AssetTooLargeException`,
   `MediaTypeMismatchException`, `MissingParentAssetException` and every 400 from
   the service are deterministic, so the job sleeps up to six minutes to fail
   identically. Only transport, 429 and 5xx are worth a retry — and the 429
   carries `Retry-After`, which nothing reads.
4. **Two Guzzle clients where one would do.** `ContentCredentialsServiceProvider`
   calls `resolveClient()` in both the signer and the reader closure; with no
   application-bound client that is two `new Client(...)`, two connection pools
   to one host.
5. **`ExtC2paReader` never confirms the anchors took.** It calls
   `withTrustAnchors()` (declared `void`, so discarding the return is correct) and
   never calls `hasTrustAnchors()`, which our own stub declares. Given Steps 11,
   14 and 21 — three separate records of trust configuration silently verifying
   nothing — this is the last trust surface where "configured" and "effective"
   are not distinguished. One assert-and-throw is the SPEC-014 AC5 move.
6. **⚠️ The fifth stale enumeration.** `SignCommand`'s signature says
   `{input : Path to the source image (.png/.jpg/.jpeg)}` and its description
   says "Sign an image", while `InfersMediaType` accepts fifteen extensions
   including `.mp4` and `.wav`. Step 37 counted four of these and drew the
   general lesson; this is the fifth, in the text `artisan list` prints, and
   `EXTENSIONS` sits in the same trait to interpolate from. Separately: the CLI
   can only produce `forAiGenerated`, so it now describes a smaller package than
   the library.
7. **`ManifestBuilder`'s `with*` methods re-list all five constructor arguments**,
   three times over. A sixth field means three edits and a wrong positional order
   fails silently; `clone` plus one assignment is immune.
8. **Bytes versus characters, twice.** `MAX_ASSERTION_BYTES` is compared against
   `JSON.stringify(...).length` — UTF-16 code units, so astral-plane text reaches
   up to 2× the published limit in bytes (`Buffer.byteLength()` says what the
   message claims). And `SigningServiceSigner::extractError()` truncates with
   `substr()`, which can cut mid-codepoint and put a broken byte sequence into a
   log line.
9. **`AtomicWrite` uses `0644 & ~umask()`** where the conventional form is
   `0666 & ~umask()`; group-write can never be produced regardless of umask,
   while the comment says the umask is inherited. Cosmetic.

### What the review found in good order

Recorded so the list above is calibrated, as Step 29 did. Constant-time token
comparison over digests. Trust defined positively rather than as the absence of a
code. One decoder, shared. Fail-closed at startup on both an unparseable
certificate and trust settings that would verify nothing. The correlation id
ahead of the parser. Depth before size, in that order, with the reason attached.
`ManifestStoreParser` degrading on every field instead of throwing. PHPStan level
max with no un-annotated ignores, plus Deptrac. And `bin/check.sh` as a
mechanical rather than behavioural answer to losing evidence — which is what made
this session's probe output survive long enough to be written down here.

`vendor/bin/pest --exclude-group=integration`: 293 passed, 6 skipped.
`php bin/spec-check.php`: 0 errors, unchanged with both drafts added.

---

[← Step 39](step-39-correcting-step-38-no-second-flake.md) · [index](../NOTES.md) · [Step 41 →](step-41-measuring-spec-029-blocking-question.md)
