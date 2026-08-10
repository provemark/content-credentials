# Step 17 — The integration suite now runs in CI (2026-08-05)

Every service-side protection built in Steps 12–15 — assertion limits, audit
logging, trust verification, rate limiting — was defended by tests **CI never
executed**. `composer check` excludes the `integration` group because it needs a
running service, and nothing else ran it. Forty-odd tests that only ever ran
when somebody remembered to.

`.github/workflows/ci.yml` gains an `integration` job with three profiles,
because several criteria describe service configurations that cannot coexist in
one process:

| Profile | Service started with | Runs |
|---|---|---|
| `defaults` | rate limit raised | `--group=integration` |
| `hardened` | trust settings + `REQUIRE_AI_MARKING=true` | `--group=integration` |
| `rate-limited` | `RATE_LIMIT_REQUESTS=5`, window 2000ms | `--group=SPEC-015` |

Verified locally against all three, **with and without a TSA configured**,
before pushing: 43 passed / 5 skipped, 44 passed / 4 skipped, 6 passed / 1
skipped. The TSA distinction matters — see below. The tests gate on what `GET /health` reports
and skip what does not apply, so the union covers the set.

### ⚠️ `--group=provenance` does NOT run the integration tests
This is the one to remember. `provenance` is the property-based chain suite —
**three tests**. The integration tests written in Steps 12–15 are tagged
`('SPEC-0NN', 'integration')`, so the documented command ran almost none of
them:

```
vendor/bin/pest --group=provenance   ->  3 tests
vendor/bin/pest --group=integration  -> 48 tests
```

Every docblock, and `composer.json`'s `scripts-descriptions`, said `provenance`.
Corrected everywhere to `integration`; only the property suite still names its
own group. The instruction had been copied forward since Step 7 without anyone
checking that it still selected what it claimed to.

### ⚠️ The concurrency tests passed for an incidental reason
The two SPEC-015 criteria about concurrency — the cap refusing an excess, and
`/health` reporting `in_flight` — passed locally and failed in CI on all three
profiles. The cause was not CI:

```
one sign WITH a TSA configured    ~250 ms   (this machine's .env)
one sign with no TSA              ~58 ms    (CI, and any deployment without one)
```

They only ever worked because the TSA round-trip made each signature slow enough
to overlap. Reproduced locally by clearing `CONTENTAUTH_TSA_URL`: same failures.

Then the obvious fix — more parallelism — turned out not to be the fix either.
Forty parallel `curl` processes against the fast service kept `in_flight` at
**0 for the entire burst** and had nothing refused: forking forty clients costs
more than the server spends answering them, so the requests never coexist.

What works is making each request **cost** more rather than making more of them.
The suite now generates a ~2.4 MB PNG (built by hand in `largePngBytes()` —
IHDR/IDAT/IEND with incompressible pixels, so no GD dependency on any runner).
A burst of 20 of those gives 5 accepted and 15 refused, and `in_flight` is
observed at the cap. Both criteria are now testable at 58 ms per signature, so
they no longer depend on anyone's TSA configuration.

`/health` is also polled **to a deadline** rather than for a fixed window, and
reduced to a peak. The first attempt sampled 20 times at 50ms — about a second —
which is a race, not an observation: the background clients take time to fork
and the burst itself lasts roughly a second, so the window can straddle it
entirely. It did exactly that in CI on a run whose predecessor had passed with
identical code, which is the signature of flakiness rather than a defect. The
loop now exits as soon as work is observed in flight and only pays the full 15s
on a genuine failure. Re-run five times against a TSA-less service to confirm,
because one green run proves nothing about a flaky test.

### ⚠️ The suite trips its own rate limit
Running `--group=integration` against a default service produces a wall of
`HTTP 429: rate limit exceeded`, and 29 failures that look like broken code and
are not. The suite makes ~50 signing requests in well under a minute — including
SPEC-015's deliberate bursts — against a default budget of 60/minute.

Hence the raised limit in the first two CI profiles. Worth knowing beyond CI:
**60 requests per minute is comfortable for interactive use and tight for batch
work.** A queue worker signing a hundred assets hits it. The default is a
starting point, not a recommendation — `RATE_LIMIT_REQUESTS` exists for this.

---

[← Step 16](step-16-open-items.md) · [index](../NOTES.md) · [Step 18 →](step-18-branch-protection-on-main.md)
