# SPEC-024: Bounding the read path

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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
const MAX_CONCURRENT_READS = Number(process.env.MAX_CONCURRENT_READS ?? 8);
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
    "max_concurrent_reads": 8,          // new
    "read_rate_limit_requests": 240,    // new
    "rate_limit_window_ms": 60000
  }
}
```

## Open questions

Both are for the maintainer at approval time. Neither blocks writing tests.

- **Separate budgets, or one shared budget per token?** Recommendation:
  **separate**, as sketched. One budget is simpler and one number to tune, but it
  couples two very different costs: a verification loop reading a hundred assets
  would consume the budget an application needs to sign its own output, and the
  failure would look like "signing is broken". AC3 exists to pin the separation.
  The cost is two more environment variables and a slightly longer `/health`.

- **Should a successful read be audited?** Recommendation: **no**, keep
  SPEC-012's decision. Its reasoning — reading is not an exercise of the signing
  key, so there is nothing to attest to — still holds, and a verification-heavy
  deployment would multiply its log volume for records that answer no question
  anyone asks. Refusals are different: they are about the service's own health,
  which is precisely what the operator needs to see. If the answer is instead
  "yes, audit reads too", it belongs in a spec that also says what the record
  contains, because `input_sha256` over every verified asset is a privacy
  decision, not just a volume one.

- **Defaults.** 240 reads per minute and 8 concurrent is a starting point chosen
  to be generous — roughly four times the signing budget, on the grounds that
  reading is cheaper and that a verification-heavy deployment is the normal case
  for the read path. Nobody has measured what a saturated read path costs in
  memory; SPEC-017 measured that for signing (~7× the asset) and the read path
  has no equivalent number. Measuring it before fixing the defaults would be the
  thorough version, and is the one thing here that could reasonably be demanded
  before approval.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |