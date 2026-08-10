# Step 30 — SPEC-024 implemented: the read path is bounded (2026-08-07)

`/v1/read` now has its own concurrency cap and its own rate budget, separate
from signing's, reported on `/health` as `max_concurrent_reads`,
`read_rate_limit_requests` and `reads_in_flight`.

### The measurement changed the defaults, which is why it was worth taking

The draft proposed 8 concurrent reads "on the grounds that reading is cheaper",
with an explicit note that nobody had measured it. Measured, against a 17.7 MiB
idle baseline, reading a signed 11.3 MB asset:

| Concurrent | Peak | Per request | × asset |
|---|---|---|---|
| 1 | 76 MiB | 58 MiB | 5.2× |
| 4 | 190 MiB | 43 MiB | 3.8× |
| 8 | 278 MiB | 32.5 MiB | 2.9× |

So ~3–5×, against signing's ~7×: cheaper, same order of magnitude, same falling
shape as SPEC-017 found. Eight concurrent reads of a maximum-size asset is
~350 MiB *on top of* signing, so the cap became **4**, matching signing, and a
fully saturated instance is ~650 MiB. The rate budget stayed generous at 240/min
— sustained rate is about fair use, the concurrency cap is what bounds memory.

Worth recording as a case where a draft's honest "unmeasured" note did its job:
the number that arrived halved the proposed default.

### ⚠️ A sign-then-verify round-trip spends from BOTH budgets

Found the moment the limiter landed: SPEC-015's "signs a normal sequence of
requests without interference" started failing with

```
ReadFailedException: Read service returned HTTP 429: read rate limit exceeded
```

— because that test signs *and reads back*, and the read-limited profile gives a
budget of 5. The 429 is correct behaviour; what it exposed is that reading back
what you just signed is a very common pattern, including in `bin/e2e.php` and in
most of this suite.

Two consequences. The README now says it, because a deployment that verifies
everything it signs needs its read budget at least as large as its sign budget
(the defaults, 240 against 60, satisfy that comfortably). And the dense CI
profiles now raise `READ_RATE_LIMIT_REQUESTS` exactly as they already raise
`RATE_LIMIT_REQUESTS` — without that, CI would have gone flaky in a way that
looks like a defect in the limiter.

It is also an argument *for* the separation rather than against it: with one
shared budget the same round-trip would spend double from a single bucket.

### Two CI profiles, for opposite reasons

`read-limited` gives a small read budget and a large sign budget, which is what
AC3 needs — it asserts that exhausting one does not spend the other, and it can
only tell them apart when the numbers are far enough apart to rule out
coincidence. The read *concurrency* cap needs the opposite (a rate budget high
enough that 429 does not arrive from the rate limiter first) and is covered by
`defaults`. Two criteria about the same subsystem that cannot be tested in one
configuration, the same shape as SPEC-014's trust-on/trust-off split.

### AC6 is a weak test and is kept as one

`/health` sits outside `/v1`, so it is structurally impossible for it to be rate
limited: the test cannot fail against any plausible defect. Kept as a smoke
check, recorded here as not being evidence of anything on its own. The
alternative — deleting it — would leave the criterion untested, which is worse
only in the sense that it would be invisible.

### The unexplained `composer check` flake, second sighting

Step 20 recorded one run reporting `1 failed, 117 passed` that could never be
reproduced, and asked that a recurrence be recorded rather than assumed to be
nothing. It recurred today: `1 failed, 213 passed`, output not captured, and not
reproducible in **five** subsequent full runs or **eleven** targeted runs of the
property suite.

What is now visible that was not before: the assertion count varies run to run
(6155–6691 across five runs), which confirms the Eris suites are generating
different input each time. So the most likely candidate remains a rare generated
case rather than noise — meaning it is a real property failure nobody has seen
the input for. If it recurs, capture the output before doing anything else.

---

[← Step 29](step-29-codebase-review-two-defects.md) · [index](../NOTES.md) · [Step 31 →](step-31-spec-025-client-side-bounds.md)
