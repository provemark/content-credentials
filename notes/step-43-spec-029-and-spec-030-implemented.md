# Step 43 — SPEC-029 and SPEC-030 implemented, and three tests that tested nothing (2026-08-08)

Both specs from Step 40 are `implemented`. What is worth keeping is not the code
— it is small — but what the tests did before they were made to work.

### SPEC-029: one accessor, not five guards

`actionsOf()` replaces five copies of `assertion.data?.actions ?? []`. That
expression is total for `?.` and not for `for…of`, which is the whole defect.
The choice worth recording is that it **replaces** the unsafe access rather than
sitting behind it: the spec's API sketch worried that a defensive helper would be
"a second guard hiding the boundary failing", and one accessor avoids that by
leaving exactly one place that knows how to read an actions array.

`server.js` now exports its helpers and guards `listen()` behind
`require.main === module`, so AC8 exercises them without HTTP.

### SPEC-030: implemented in two steps, on purpose

Four criteria gated on an `auth-limited` profile that did not exist, so they were
*skipped* rather than red — and four permanently skipped tests prove nothing.
So `/health` reporting landed on its own first, the gate flipped, and AC4 was
watched going red before the behaviour was written.

That first step also caught something the tests could not: `AUTH_FAIL_LIMIT` was
not in `docker-compose.yml`, so the override never reached the container and the
tests kept skipping while looking configured.

### ⚠️ The spec contradicted itself, and only implementing it showed that

AC8 said "bounded by the budget, not by the number of requests" **and** "every
429 is recorded". With a fixed window and unbounded attempts the 429s scale with
the requests, so the log grows with the flood — the leak the criterion exists to
close, one layer over. Amended to at most two records per window: the first
failure, and the moment the budget runs out. 15 attempts and 15 000 both produce
two.

Worth noting the process: SPEC-030 was `approved`, so this went back through an
amendment rather than being fixed quietly. CLAUDE.md's "spec contradiction found
mid-implementation → STOP, amend, back to step 2" is not ceremony; the wrong
reading was the one already written down.

### ⚠️ Three of my own tests passed while testing nothing

Ninth, tenth and eleventh in this log's collection, all in one sitting.

1. **AC8 passed on zero records.** `written (0) < attempts (15)` holds when
   nothing is written at all. Found only because amending the criterion forced a
   lower bound onto it — `records >= 1` — which is what a bound on "not too many"
   always needs.
2. **`toContain(429, 'explanation')`.** Step 21 records this exact trap:
   `toContain()` is variadic, so the second argument is a second NEEDLE. The test
   asserted the array contained both the status and the explanatory sentence, and
   reported a correct implementation as broken. It has now cost this project
   twice.
3. **A test with no assertions at all.** "writes no body-parser refusal for a
   request it never parsed" looped over the records for that request, found none,
   and performed zero assertions. **Pest caught it** — `RISKY`, not green — which
   is the second time a tool has found one of these rather than a person. Fixed
   with the control case Step 26 prescribes: the same oversized body *with* a
   valid token must produce exactly such a record.

### ⚠️ And a measurement that had to be taken twice

AC7's first run reported the post-change burst costing +0.5 MiB, which is the
Step 37 shape: too good. The baseline was a container that had been signing all
day, so its heap was already warm and the burst reused memory instead of
allocating. Re-measured on a fresh container:

| Ordering | idle | peak | burst | answer |
|---|---|---|---|---|
| parser first | 17.3 MiB | 54.3 MiB | **+37.1 MiB** | 8 × 413 |
| auth first | 17.3 MiB | 26.8 MiB | **+9.5 MiB** | 8 × 401 |

The statuses are part of the measurement, not decoration: they prove the requests
arrived. And the residual 9.5 MiB is the useful half of the result — it confirms
with a number what the spec could only assert in prose, that refusing before the
parser removes the allocation and the parse and **not the bytes arriving**.

### Verified

`composer check` green (296 passed). Integration 136 passed / 16 skipped
(defaults) and 137 / 15 (auth-limited). SPEC-029 17 passed in both the defaults
and hardened profiles; SPEC-030 10 passed / 4 skipped and 11 / 3 across its two.
`bin/e2e.php` and `bin/verify.sh` all PASS. `php bin/spec-check.php` 0 errors.
A sixth CI profile, `auth-limited`, covers the half `defaults` cannot.

---

[← Step 42](step-42-spec-030-peer-identity.md) · [index](../NOTES.md) · [Step 44 →](step-44-node-24-and-container-hardening.md)
