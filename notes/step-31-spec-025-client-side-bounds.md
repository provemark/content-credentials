# Step 31 — SPEC-025: the client keeps its own bounds (2026-08-07)

Four findings from the review (Step 29), plus the two smaller ones, implemented
together because they are one idea: the service has been hardened six times and
the client once.

### The response bound was five times too generous, in the dangerous direction

`maxResponseBytes` defaulted to 96 MiB, documented in two places as "headroom
over the service's 50 MB request cap" — a cap SPEC-017 replaced with 20 MB. The
service cannot return more than ~20 MiB. So the guard against a hostile response
exhausting PHP memory sat above the `memory_limit = 128M` many deployments still
run: the process dies before the guard fires, which is the exact outcome it
exists to prevent. Now 32 MiB, and both comments corrected.

### The request was not bounded at all

The client bounded the response and not the request — and the request is where
the memory goes: raw bytes, base64, JSON body, roughly 3.7× the file. A caller
signing something too large met the limit as a 413 *after* paying that.
`AssetTooLargeException` is thrown before encoding.

The number is duplicated (client and service), and that is acceptable here for a
specific reason worth stating: the service enforces its own limit regardless, so
drift costs a worse error message and never a wrong outcome. That is what makes a
configured value tolerable where it would not be for a security control.

### Insecure transport: warn, do not break the documented deployment

The strict reading of SPEC-015's "a protection that ships off is one nobody turns
on" would say throw. It is wrong here: `http://signer:3000` between two
containers on one private network is what this project's own `docker-compose.yml`
produces, and it is not a leak. A default that breaks the deployment the README
recommends would be switched off by everyone within a day — worse than a warning
nobody disables.

So: `usesInsecureTransport()` in Core states the fact, the provider decides what
it is worth. Core has no logger by design, and the severity difference is a
framework concern. Note the consequence, which the Core test pins: Core reports
`http://signer:3000` as insecure, because it cannot know that host is private.

**The warning must survive a missing logger.** A bare container has no `log`
binding, and a protection that crashes when it cannot warn is worse than absent.

### Atomic writes: the temporary file must share the destination's filesystem

`tempnam()` in the destination's own directory, not `sys_get_temp_dir()`. A
rename across filesystems degrades to a copy, which is precisely the non-atomic
write being replaced. Also `chmod` after creation: `tempnam()` makes 0600, and a
signed asset is an output file rather than a secret.

The tests assert observable consequences — no leftover temporary file, no
destination file after a failure, wholesale replacement — because true atomicity
rests on `rename()` semantics and cannot be observed in-process without a race.

### ⚠️ AC5 was implemented before its test

Recorded rather than quietly fixed: for AC5 the code went in first and the tests
followed, so they were never watched going red. For AC6 the same risk was closed
differently — the phrases were checked against `git show origin/main:README.md`
and confirmed absent, which is the same evidence by another route. Worth doing
that routinely for documentation criteria; it costs one command.

### The unexplained flake, third sighting — and a pattern

Step 20 saw it once, Step 30 twice. Today it appeared a third time:
`1 failed, 237 passed`, output not captured again, and not reproducible in four
subsequent `composer check` runs or roughly twenty bare `pest` runs.

The pattern now visible, and the reason to keep counting: **all three sightings
were under `composer check`, never under a bare `pest` run.** That may be
coincidence — `composer check` is what gets run most — but it is the only
correlation there is, and `check` differs from `test` only in what runs before
it (Pint rewrites files, then PHPStan, then Pest, then Deptrac).

For next time, concretely: run `composer check > /tmp/out.txt 2>&1` in a loop
and inspect the file, rather than re-running afterwards. Twice now the evidence
has been lost by re-running to confirm.

---

[← Step 30](step-30-spec-024-bounding-the-read-path.md) · [index](../NOTES.md) · [Step 32 →](step-32-digitalsourcetype-research.md)
