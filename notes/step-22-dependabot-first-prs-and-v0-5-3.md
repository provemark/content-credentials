# Step 22 — Dependabot's first two PRs, and v0.5.3 (2026-08-06)

SPEC-018 merged, and Dependabot opened two PRs within minutes. Worth recording,
because the *first* run of a new automation is the one that tells you whether it
was configured correctly, and because one of the two was not routine.

### #27 — actions/checkout v4 → v7

Workflow files only. The verification is that the thirteen checks ran at all:
if checkout v7 did not work, nothing downstream of it would have. Merged.

### #28 — express 4.22.2 → 5.2.1, previously deferred

A major, and one earlier sessions deliberately left alone. What changed is that
there is now evidence: since Step 17 the three CI integration profiles build and
run the real service, so express 5 arrived already exercised by ~48 tests. That
does not remove the local step (CLAUDE.md: any `service/` change is verified by
hand), so:

- container reports `express 5.2.1`, `/health` intact including the new
  `signing_cert` block;
- full integration suite **55 passed / 5 skipped**;
- `bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true;
- `bin/verify.sh` signature valid PASS / cert trusted PASS / Art.50 mark PASS.

Then the error paths specifically, because that is where express 5 could have
changed behaviour under everything SPEC-011/012/015/017 built — the body parser,
its error types, and the middleware ordering:

```
oversized body   -> 413   (SPEC-017)
malformed JSON   -> 400 + cid  {"error":"request body is not valid JSON",...}
concurrency cap  -> 429   (SPEC-015)
missing auth     -> 401
```

All intact. Incidental finding: `/v1/nope` answers **401, not 404**, because the
auth middleware runs before routing on `/v1/*`. Pre-existing, unchanged by
express 5, and arguably the better answer — it does not enumerate routes.

**A red run that was not a regression.** The first local integration run gave
`1 failed`: `429` where `400` was expected. That is the suite tripping its own
rate limit — ~50 signs in well under a minute against a default budget of 60,
exactly as Step 17 records. Re-run with `RATE_LIMIT_REQUESTS=1000` (what the CI
profiles do): green. Worth noticing that the failing test **differed between
runs**, which is the signature of a shared budget being exhausted rather than a
defect in any one test.

### v0.5.3 released

Service and documentation: SPEC-018 plus express 5. **`src/` still has not
changed since v0.5.0.**

### ⚠️ "The dist is unchanged" was wrong, and it is the second time

I wrote that this release, like 0.5.1 and 0.5.2, leaves the installed package
identical. `.gitattributes` says otherwise: the dist is `src/`, `config/`,
`composer.json`, `LICENSE` **and `README.md`** — and SPEC-018 added two README
sections. So the package is *not* byte-identical to 0.5.2, even though no code
moved.

This is the same error as the v0.5.1 release notes claiming "byte-for-byte" when
`composer.json` differed by one help string. Both times the claim was made from
memory of what a release *usually* is rather than from the file that decides it.
The changelog and release notes now say it precisely: no change to `src/` or
`config/`, no behaviour change, but not byte-identical. **Check
`.gitattributes` before making that claim.**

### The audit workflow, verified rather than waited for

`gh workflow run audit.yml` instead of waiting until Monday, because a scan that
silently does nothing is the exact failure shape this whole session kept
producing. Both steps ran and reported real work:

```
npm audit (service): found 0 vulnerabilities
composer audit:      Lock file operations: 120 installs -> audit over 120 packages
```

So it is green because there is nothing to report, not because it scanned
nothing. The brace-expansion advisory from Step 9 would surface here now.

### Reconciling the Step 16 open items

Recorded here rather than by editing Step 16, which is a log entry and stays as
written:

1. ~~v0.5.1 decision~~ — done, Step 16 itself.
2. ~~`MAX_BODY_SIZE` at 50 MB~~ — **closed** by SPEC-017 (Step 20), now 20mb.
3. **Per-client tokens (SPEC-016) — still open, still `draft`.** Unchanged: the
   trigger is a user reporting a shared instance, not adoption. This is now the
   only remaining design gap.

---

[← Step 21](step-21-spec-018-rotation-and-scanning.md) · [index](../NOTES.md) · [Step 23 →](step-23-spec-019-ext-c2pa-reader.md)
