# SPEC-024: Bounding the read path

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | — (extends SPEC-015)                              |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-015 bounded `/v1/sign`. `/v1/read` was never mentioned — not in scope, not
in out of scope — so it is unbounded, and nothing records that it happened.

Measured 2026-08-07 against a service configured with `RATE_LIMIT_REQUESTS=5`:

```
10× /v1/read : 200 200 200 200 200 200 200 200 200 200
10× /v1/sign : 200 200 200 200 200 429 429 429 429 429
```

The rate limiter and the concurrency cap are registered on `app.post('/v1/sign')`
only, and `inFlight` is incremented there alone.

### Why this is not "reading is cheap"

Reading decodes the same base64, holds the same decoded buffer, and then runs a
full c2pa-rs validation over it: the hash binding is verified across the whole
asset and the COSE signature is checked. It is cheaper than signing — no
temporary file, no key operation, no TSA round-trip — but it is not free, and it
is unbounded in exactly the dimension SPEC-015 cared about: how many can be in
flight at once, each holding a multi-megabyte buffer.

`MAX_BODY_SIZE` (20 MB) still applies, so a single read is bounded. Nothing
bounds the number of them.

### What a read actually costs (measured 2026-08-07)

Container memory against a 17.7 MiB idle baseline, reading a signed 11.3 MB
asset — the same method SPEC-017 used for signing:

| Concurrent reads | Peak | Per request | × asset |
|---|---|---|---|
| 1 | 76 MiB | 58 MiB | 5.2× |
| 4 | 190 MiB | 43 MiB | 3.8× |
| 8 | 278 MiB | 32.5 MiB | 2.9× |

So **roughly 3–5×** the asset, against ~7× for signing: cheaper, same order of
magnitude, and falling as concurrency amortises fixed overhead — the same shape
SPEC-017 found. Not free, and not bounded today.

The number that decides the defaults is the combined one. A fully saturated
instance holds both paths at once: four signs at 7× plus four reads at ~3.8×, of
15 MB assets, is roughly **650 MiB**. That is what an operator has to size for,
and it is why the read cap defaults to the same 4 as signing rather than to
something generous.

### Two smaller consequences, both real

- **The README overstates the protection.** It says "Limits are **on by
  default**" directly under a section about saturation. A reader takes that to
  mean the service is bounded; half of it is.
- **A verification-only deployment has an empty audit trail.** SPEC-012 records
  every `/v1/sign`, accepted and refused alike, and deliberately records nothing
  for a successful read — "reading is not an exercise of the signing key". That
  reasoning holds for *what was signed*. It does not cover *that the service was
  hammered*, which is what a refusal record is for, and there are no refusals to
  record today because there are no refusals.

### Scope of the risk, stated honestly

`/v1/*` requires the bearer token, so this is not an unauthenticated exposure. It
matters in two situations: a token that has leaked, and a legitimate caller whose
verification loop misbehaves. SPEC-015 was written for the second case as much as
the first — the concurrency cap exists so one client cannot take the instance
down by accident.

## Scope

**In scope**

- A per-token rate limit and a concurrency cap on `/v1/read`, refusing with
  **429** + `Retry-After`, in the shape SPEC-015 established.
- `GET /health` reporting the new limits and the read saturation, so the two
  paths can be told apart.
- Auditing read **refusals** through the SPEC-012 path.
- README and `.env.example`: the defaults, and a correction to the sentence that
  currently implies the whole service is bounded.
- A CHANGELOG **Upgrading** note: this is a behaviour change for anyone whose
  verification traffic exceeds the new defaults.

**Out of scope** (each needs its own spec before it may be built)

- Auditing successful reads — see Open questions; SPEC-012 decided against it
  with a reason that this spec does not overturn on its own.
- Distributed limits across instances, per-client quotas, queueing instead of
  refusal. All three inherit SPEC-015's reasoning unchanged.
- Any change to `MAX_BODY_SIZE`, or to the sign path's existing budgets.
- Any change to `src/`. The PHP client already surfaces a non-2xx from a read as
  `ReadFailedException`; it gains no retry logic here.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-024')`.

- **AC1 — reads are rate-limited per token**
  - Given a service with a read budget of N per window
  - When a token issues more than N reads inside the window
  - Then the excess is refused with **429**, a `Retry-After` header and a `cid`
  - And nothing is read

- **AC2 — reads are bounded in flight**
  - Given a service with a read concurrency cap of C
  - When more than C reads are in flight
  - Then the excess is refused with **429**
  - *(The same measurement trap as SPEC-015 AC3/AC4: a burst of small requests
    never coexists. The test must make each request cost enough to overlap, and
    must assert that the cap was actually exceeded rather than assuming it —
    NOTES Steps 17 and 19.)*

- **AC3 — the two paths do not starve each other**
  - Given a token that has exhausted the read budget
  - When it signs
  - Then the signing request is accepted, and the reverse holds too
  - *(This is the criterion that decides Open question 1, and it is why separate
    budgets are the recommendation: a verification loop must not be able to stop
    an application from marking its own output.)*

- **AC4 — a refused read is recorded** *(error path)*
  - Given a read refused for either reason
  - When the audit stream is inspected
  - Then it carries one record with the correlation id, the token id and the
    reason — the same shape SPEC-012 defines for a refused sign
  - And no record is written for a read that succeeds (see Open questions)

- **AC5 — `/health` reports what is in force**
  - Given a running service
  - When `GET /health` is called
  - Then the read limits appear beside the signing ones, and read saturation is
    reported separately from `in_flight`
  - And a limit set to `0` is reported as disabled, exactly as SPEC-015 does

- **AC6 — `/health` is never itself limited**
  - Given a service at both caps
  - When `GET /health` is called
  - Then it answers normally
  - *(SPEC-015 AC4's reasoning: an orchestrator that cannot reach `/health`
    cannot tell a saturated instance from a dead one.)*

- **AC7 — the sign path is unchanged**
  - Given the SPEC-015 integration suite
  - When it runs against this version
  - Then every criterion still passes, with the same budgets and the same
    refusals

## API sketch

Illustrative only.

```js
// service/server.js — mirroring the sign path rather than generalising it.
// A shared helper would have to take four parameters to express two policies;
// two small blocks are easier to read and to change independently.
const MAX_CONCURRENT_READS = Number(process.env.MAX_CONCURRENT_READS ?? 4);
const READ_RATE_LIMIT_REQUESTS = Number(process.env.READ_RATE_LIMIT_REQUESTS ?? 240);
// Window shared with the sign limiter: two windows would be a third knob for no
// benefit anybody has asked for.
```

```jsonc
// GET /health
{
  "in_flight": 0,           // signing, unchanged
  "reads_in_flight": 0,     // new
  "limits": {
    "max_concurrent_signs": 4,
    "rate_limit_requests": 60,
    "max_concurrent_reads": 4,          // new
    "read_rate_limit_requests": 240,    // new
    "rate_limit_window_ms": 60000
  }
}
```

## Open questions

All three settled before approval, 2026-08-07. Recorded rather than deleted,
because the reasoning is the useful part.

- ~~**Separate budgets, or one shared budget per token?**~~ **Separate.** One
  budget is simpler and one number to tune, but it couples two very different
  costs: a verification loop reading a hundred assets would consume the budget an
  application needs to sign its own output, and that failure presents as "signing
  is broken". AC3 pins the separation. The cost is two more environment variables
  and a slightly longer `/health`.

- ~~**Should a successful read be audited?**~~ **No** — SPEC-012's decision
  stands. Reading is not an exercise of the signing key, so there is nothing to
  attest to, and a verification-heavy deployment would multiply its log volume
  for records answering no question anyone asks. Refusals are different: they are
  about the service's own health, which is exactly what an operator needs. Note
  what this deliberately avoids: `input_sha256` over every asset a deployment
  verifies is a privacy decision, not just a volume one, and it belongs in a spec
  that says so rather than arriving as a side effect of a rate limit.

- ~~**Defaults.**~~ **Measured before choosing, and the sketch above is
  revised.** The draft proposed 240 reads per minute and 8 concurrent "on the
  grounds that reading is cheaper", with the honest note that nobody had measured
  it. Measured (see Problem), a read costs ~3–5× the asset, so eight concurrent
  reads of a maximum-size asset is ~350 MiB on top of whatever signing is doing.
  **`MAX_CONCURRENT_READS` therefore defaults to 4, matching signing**, which
  keeps a fully saturated instance near 650 MiB rather than 800.
  `READ_RATE_LIMIT_REQUESTS` stays generous at **240/minute**: sustained rate is
  about fair use over time, while the concurrency cap is what bounds peak memory,
  and conflating them would make the service refuse bursts it can comfortably
  serve.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/ReadLimitTest.php` :: "refuses a token that exceeds its read rate, and serves it again after the window" | `service/server.js` `READ_RATE_LIMIT_REQUESTS`, `readBuckets`, `rateLimited()` |
| AC2 | `tests/Integration/ReadLimitTest.php` :: "refuses the excess when more reads arrive than the cap allows" | `service/server.js` `MAX_CONCURRENT_READS`, `readsInFlight` |
| AC3 | `tests/Integration/ReadLimitTest.php` :: "still signs for a token that has exhausted its read budget" | `service/server.js` `readBuckets` (separate from `buckets`) |
| AC4 | `tests/Integration/AuditLoggingTest.php` :: "records a refused read but not a successful one" | `service/server.js` `/v1/read` limiter, `audit()` |
| AC5 | `tests/Integration/ReadLimitTest.php` :: "reports its read limits and how many reads are in flight", "has its read limits switched on by default" | `service/server.js` `/health` |
| AC6 | `tests/Integration/ReadLimitTest.php` :: "answers /health while the read path is saturated" | `service/server.js` `/health` (outside `/v1`, so never limited) |
| AC7 | `tests/Integration/RateLimitTest.php` :: the whole SPEC-015 group, unchanged | `service/server.js` sign limiter |

## Implementation notes (2026-08-07)

- **The defaults changed between draft and approval, because the measurement
  arrived in between.** The draft proposed 8 concurrent reads "on the grounds
  that reading is cheaper", with an explicit note that nobody had measured it.
  Measured, eight concurrent reads of a maximum-size asset is ~350 MiB on top of
  signing, so the cap became 4. Worth recording as a case where the honest
  "unmeasured" note in a draft did its job.
- **Two limiter blocks, not one helper.** `rateLimited()` gained a store and a
  limit parameter, which is shared, but the two middlewares stay separate: a
  single generalised one would take four parameters to express two policies
  whose numbers, budgets and reasons differ.
- **AC6 is a weak test and stays weak.** `/health` sits outside `/v1`, so it is
  structurally impossible for it to be rate-limited, and the test therefore
  cannot fail against any plausible defect. It is kept as a smoke check rather
  than deleted, but it is not evidence of anything on its own.
- **Two CI profiles, for opposite reasons.** `read-limited` gives a small read
  budget and a large sign budget, which is what AC3 needs to tell the two apart;
  the read *concurrency* cap needs the opposite (a rate budget high enough not to
  answer 429 first) and is covered by `defaults`.
