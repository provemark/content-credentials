# Step 38 — Reviewing 0.9.0 an hour after shipping it (2026-08-07)

Read the release back as a reviewer rather than as its author. Three defects,
two of them in the same place: what the service refuses to attest to. Fixed in
**0.9.1**, service-side only.

### ⚠️ The service put its signature on an `Invalid` manifest

A raw `/v1/sign` whose `extra_assertions` carried a `c2pa.opened` action of the
caller's own:

```
sign HTTP 200        ondertekend, 88.440 bytes
validation_state: Invalid   status: ['assertion.action.ingredientMismatch']
```

Route B works *because* c2pa-rs adds that action itself, with a hash over the
ingredient assertion it builds — which a caller cannot compute. A second
`c2pa.opened` therefore can never be linked, and the signature is spent on an
asset no verifier accepts, with our certificate on it.

**The criterion was the gap, not just the code.** AC5 was written about an
unusable *parent* and said nothing about the actions array. The PHP client
cannot produce this — its builder never emits `c2pa.opened`, and a test pins
that — which is exactly the reasoning SPEC-011 rejects: the service is a separate
HTTP surface and the client is not guaranteed to be the only caller. Added as
AC13 by amendment, which meant SPEC-028 going back to `approved` and through
tests-first again.

### ⚠️ A criterion said "accepted or refused" and only half was built

AC8's implementation added `parent_bytes` / `parent_sha256` to the success path
only. Both of its tests exercised that path, so nothing caught it — and the
traceability row read as covered. **The spec was marked `implemented` with a
criterion unmet**, which CLAUDE.md names explicitly as the thing not to do.

Deliberately scoped when fixing: the fields are recorded on every 400, and NOT
on a 429. A load-shedding refusal exists to avoid spending work, and
base64-decoding a possibly 15 MB parent to describe a request being shed is the
work it is shedding. Recorded in the spec rather than left to be rediscovered.

### The changelog shipped with a section in the wrong place

`## [0.9.0]` gained `### Added`, `### Changed` and `### Upgrading` inserted
*above* the existing SPEC-027 bullet, which left "the documentation is split
across pages" filed under **Upgrading**. Nobody reads a changelog closely enough
to catch that in review — but it was published.

### ⚠️ Two process failures while chasing a red test, both familiar

An integration run went red on `ProvenanceChainPropertyTest :: it makes the most
recent signing the active manifest`. Two mistakes followed, in order:

1. **The evidence was lost again.** Only the tail was on screen — the
   `ERIS_SEED=…` line — not the failing assertion. Step 31 asked, in writing, to
   capture output to a file *before* re-running. Re-running with the seed passed,
   so the case is gone. Third time this log records losing the same evidence.
2. **The detector was worse than the bug.** The capture loop used
   `grep -q "FAILED\|failed"`, which matched a **test title** containing the word
   — `it records a refusal that failed inside validation` — and reported a green
   run (112 passed) as red. Substring where a structure was meant: Step 20's
   `peak`/`speaks` in a new costume. Redone on the exit code.

Six consecutive green runs afterwards, 112 passed each. Not a regression from
this work: that property signs with `forAiGenerated` only — no `c2pa.opened`, no
parent — so the new guard cannot fire on it.

**Fourth sighting of the unexplained flake, and it breaks the pattern Step 31
recorded.** The first three were all under `composer check`; this one is a bare
`pest --group=integration`, and `composer check` excludes the integration group
entirely — so the earlier three must have been a *different* property suite (the
unit ones). Two flakes, not one. Worth knowing before anyone tries to explain
them with a single cause.

### Verified

`composer check` green (293 passed), integration **112 passed / 11 skipped**
(defaults, six runs) and **110 / 13** (hardened), `spec-check` 0 errors, and the
original defect re-probed: HTTP 400, nothing signed, and the refusal record
carrying `parent_bytes: 1699`.

---

[← Step 37](step-37-spec-028-implemented-article-50-2.md) · [index](../NOTES.md) · [Step 39 →](step-39-correcting-step-38-no-second-flake.md)
