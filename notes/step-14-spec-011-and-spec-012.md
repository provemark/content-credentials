# Step 14 — SPEC-011 + SPEC-012 implemented together (2026-08-05)

Done on one branch because they are coupled: SPEC-012 AC2 requires that a
SPEC-011 rejection be audited, and SPEC-012's correlation id is what makes
SPEC-011's error responses safe to make generic. Implementing them separately
would have meant writing every rejection path twice.

### SPEC-011 — what the service will attest to
`rejectAssertions()` returns the violated constraint or null; `/v1/sign` turns
that into a 400 with our own wording (never library internals, never an echo of
the payload) and signs nothing. Limits: 16 assertions, 64 KiB each, depth 16,
`creator_name` 256 — all env-tunable.

Note the deliberate asymmetry, which is the whole design in one line:
**structural limits are restrictive by default, the semantic policy is
permissive by default.** Too permissive is the risk for structure; too strict is
the risk for semantics, because requiring `trainedAlgorithmicMedia` would
exclude the authenticity use case while making nothing truer.

`exceedsDepth()` stops as soon as the limit is passed, so a hostile 10 000-level
payload costs 17 frames rather than 10 000.

### SPEC-012 — audit logging
One JSON line per `/v1/sign` to stdout, accepted and refused alike. A
correlation id per request (`X-Correlation-Id`, plus `cid` in error bodies) made
it safe to replace the verbatim error echo with `signing failed` / `read failed`.
`token_id` is a salted SHA-256 prefix; the salt is per-process unless
`CONTENTAUTH_TOKEN_ID_SALT` is set, which trades cross-restart correlation
against needing to manage another secret.

### ⚠️ `/dev/full` is how you test a failing stdout
AC9 needed an audit write that actually fails. Redirecting a second instance's
stdout to `/dev/full` (every write fails ENOSPC) inside the running container,
then driving it over HTTP from within the container using node's global `fetch`,
proves both halves at once: the sign still returns **200**, and `/health`
reports `audit_degraded: true`. Belt and braces in the implementation — a
`try/catch` around the write *and* a `process.stdout.on('error')` handler —
because a write to a file-backed stdout can fail either way.

### Test-fixture gotcha
The AC9 probe needs the fixture inside the container, and `docker compose up`
recreates the container, so anything `docker cp`-ed by hand disappears on the
next run. The test now copies it in itself. A test that depends on a manual
step is a test that will lie to you later.

### One test of mine was wrong, not the code
"caller strings are length-capped" was first asserted with a 200-character
`creator_name`, which is *within* the 256 limit — so nothing was truncated and
the test failed against correct behaviour. A `creator_name` over the limit is
refused outright (SPEC-011 AC6), so the real unbounded-input path into a record
is an **assertion label**: it rides inside the assertion size budget. Retargeted
there, where the cap genuinely bites.

Verified in both policy configurations (`REQUIRE_AI_MARKING` off and on):
SPEC-011 18 passed, SPEC-012 9 passed, SPEC-014 still 7 passed, `bin/e2e.php`
green, `composer check` green.

---

[← Step 13](step-13-spec-013-istrusted-fails-closed.md) · [index](../NOTES.md) · [Step 15 →](step-15-spec-015-rate-limiting-and-concurrency.md)
