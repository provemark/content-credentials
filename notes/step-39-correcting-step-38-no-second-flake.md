# Step 39 — Correcting Step 38: there is no second flake (2026-08-07)

Step 38 concluded that the red `ProvenanceChainPropertyTest` was a *second*,
distinct flake, on the reasoning that the three earlier sightings were under
`composer check` while this one was under `--group=integration`, and that
`composer check` excludes the integration group. The reasoning was sound and the
conclusion was wrong.

**It was the rate limit, reproduced deliberately.** Started the service with
`docker-compose up -d` — no overrides, so the documented default of 60 signs per
minute — and ran the suite:

```
exit=2   4 failed, 108 passed
SigningFailedException: Signing service returned HTTP 429: rate limit exceeded
```

Four failures, all 429, three of them in the property suite. That is NOTES
Step 17 and Step 22 exactly: ~50 signs in well under a minute against a budget of
60. The property suite is simply the heaviest consumer — seven signings per test
— so it is where the budget runs out.

**The cause was mine, and it is worth naming precisely.** During the 0.9.1 fix I
rebuilt with `docker-compose up -d --build`, which does not carry
`RATE_LIMIT_REQUESTS=1000`, and then ran `--group=integration` against it. Every
green run before and after was against a service started *with* the override.
The variable was the service configuration, not the test.

### ⚠️ Eris disguises an environmental error as a property failure

The thing that sent this down the wrong path: Eris caught the
`SigningFailedException` thrown inside the property body and reported it in its
own idiom —

```
Reproduce with:
ERIS_SEED=1786125097021674 vendor/bin/phpunit --filter '…'
```

— which reads exactly like a generated input that broke an invariant, and
invites you to chase a seed. It is worth knowing that a `Reproduce with:` line
says nothing about whether the failure was *generated*. Re-running with the seed
passed, which should have been the tell: an input-dependent property failure
reproduces from its seed, an environmental one does not.

### What would have caught it in one second

Nothing in the suite checks that the service it is talking to has a budget large
enough to run it. `/health` publishes `rate_limit_requests`, and the suite knows
roughly how many signings it makes. A single skip-or-warn on that comparison
would have turned twenty minutes of seed-chasing into one line of output. Not
built here — it touches shared harness code and belongs in a spec — but it is
the cheapest available fix and this is the third time (Steps 17, 22, 39) the same
trap has cost someone an afternoon.

### Correcting the earlier count too

Step 38 said the earlier three sightings must therefore have been a different
suite. That inference goes with the conclusion: those three were under
`composer check`, which excludes `integration`, so they were the **unit**
property suites and remain genuinely unexplained. There is still exactly **one**
unexplained flake, not two, and it has never been seen in the integration group.

### ⚠️ Fifth sighting, and the evidence was lost AGAIN

Minutes after writing the paragraph above, the final verification run produced:

```
Tests:    5 deprecated, 1 failed, 6 skipped, 292 passed (6628 assertions)
```

The command was `composer check 2>&1 | grep -E "Tests:|Violations" | head -2`, so
the failing test and its assertion went into the pipe and vanished. **Fourth
time this log records losing the same evidence, and it happened immediately
after writing a step about losing it.** Steps 20, 30, 31 and 38 all asked for the
same discipline and it failed again under a routine "just check it is green".

The lesson is not "be more careful". It is that a habit which has failed four
times will fail a fifth, and the fix has to be mechanical rather than
behavioural — see below.

### Hunting it by repetition does not work, and now that is measured

| Attempt | Result |
|---|---|
| 250 × `vendor/bin/pest --exclude-group=integration` | all green |
| 80 × `composer check`, output captured per run | all green |

The 250 bare runs were also the **wrong command** — Step 31 established that
every sighting has been under `composer check`, which differs in what runs
before it (Pint rewrites files, then PHPStan, then Pest, then Deptrac). Testing
the wrong configuration, for the second time in one investigation, after the
rate-limit mistake above.

Combined with the earlier attempts (Steps 30 and 31: five `composer check` runs,
eleven targeted, roughly twenty bare), the flake is now known to occur well under
**1 in 80** `composer check` runs. Five sightings, zero reproductions on demand.

**So stop reproducing and start capturing.** Built, on the maintainer's decision,
because it changes what CLAUDE.md calls "the single definition of green":

- `bin/check.sh` runs the sequence, tees to `out/check-<stamp>.log`, and **keeps
  that file only when the exit code is non-zero**. A green run leaves nothing.
- `composer check` now calls it; the sequence itself moved unchanged to
  `composer check:run`. What green *means* is identical — only what survives a
  red run changed.
- The failure message says to read the file *before* re-running, because the
  failure this exists for has never reproduced on demand, so a second run is not
  a second chance.

Verified in both directions rather than assumed, since a capture that captures
nothing is this log's favourite failure mode:

```
green : exit=0, 293 passed, no out/check-*.log left behind
red   : exit=1, out/check-20260807-202918-…log kept, containing
        "Failed asserting that two strings are identical / -'not captured'"
```

The deliberate failure was `TemporaryCaptureProbeTest.php` — with the `Test.php`
suffix, unlike Step 18's `TemporaryAggregatorProbe.php`, which Pest never
collected and which therefore proved nothing.

**CI is unaffected.** `.github/workflows/ci.yml` does not call `composer check`;
it runs the same tools individually with Pint in `--test` mode. So this is a
local-developer instrument, which is the right scope — CI already preserves
every run's output in the job log, and it is the keyboard, not the runner, where
four of the five sightings were lost.

---

[← Step 38](step-38-reviewing-0-9-0-after-shipping.md) · [index](../NOTES.md) · [Step 40 →](step-40-outsider-review-envelope-guard.md)
