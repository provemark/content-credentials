# SPEC-015: Rate limiting and concurrency bounds on `/v1/sign`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-05                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The signing service accepts unbounded concurrent work. There is no rate limit,
no cap on requests in flight, and no request timeout. `express.json({limit:
'50mb'})` means a single request may buffer 50 MB, and each signing request
holds roughly **four copies** of the asset at once: the base64 string parsed
from the body, the decoded `Buffer`, the signed file read back from disk, and
its base64 encoding in the response.

This is the last unaddressed finding of the 2026-08-05 security review. It is
the cheapest denial of service available against the one process that holds the
signing key: whoever holds `CONTENTAUTH_API_KEY` — or has stolen it, which
SPEC-012 records but cannot prevent — can exhaust the container's memory, CPU
and disk with a handful of concurrent requests. A misconfigured queue worker
fanning out does the same thing by accident.

### Measured, not assumed (2026-08-05)

Two assumptions had to be checked before designing anything, because each would
have pointed at a different remedy.

| Measurement | Result |
|---|---|
| One sign (small fixture, TSA enabled) | ~0.25 s |
| Six sequential | ~1.52 s |
| Six concurrent | **~0.42 s** |
| `GET /health` during four concurrent signs | 0.00–0.01 s |

So signing **parallelises** (≈3.6× on six) and does **not** block the event
loop — the native work and the TSA round-trip both leave it free. Two
consequences:

1. A concurrency cap is not about restoring responsiveness. The service stays
   responsive. It is about bounding the resources concurrent work consumes.
2. **A saturated service still reports healthy.** `/health` answers in
   milliseconds no matter how much signing is in flight, so an orchestrator
   keeps routing traffic to an instance that is about to run out of memory.
   That is its own defect, and this spec fixes it alongside the limits.

### The limitation this spec must state honestly

Express parses and buffers the request body **before** any handler runs. By the
time we can answer 429, the allocation has already happened. A concurrency cap
therefore bounds signing work, not the memory spent admitting the request that
gets refused. The levers that do bound that are `MAX_BODY_SIZE` and
connection-level limits — see Open questions.

## Scope

**In scope**

- A per-token rate limit on `/v1/sign`, keyed on the `token_id` SPEC-012 already
  derives, answering **429** with `Retry-After`.
- A cap on signing requests in flight, answering **429** when exceeded.
- Server-level `requestTimeout` and `headersTimeout`, so a slow or stalled
  client cannot hold a slot indefinitely.
- `GET /health` reporting saturation, and never being rate-limited itself.
- Recording every refusal through the SPEC-012 audit path.
- Documenting the defaults and their reasoning in the README and `.env.example`.

**Out of scope** (each needs its own spec before it may be built)

- Distributed limits across multiple service instances. State is per-process;
  two instances behind a load balancer each enforce their own budget. Shared
  state is a different design with a different dependency.
- Per-client quotas or tiers. The service has one credential
  (SPEC-012 identifies *which token*, not *which human*); real per-client limits
  need per-client tokens, which is already noted as a follow-up there.
- Queueing with backpressure instead of refusal. Decided against, with reasons,
  in Open questions; revisiting it needs a spec that also addresses the
  client-side timeout that makes queueing lie to the caller.
- Lowering `MAX_BODY_SIZE`, which is a behaviour change for existing callers.
- Any change to `src/`. The PHP client surfaces a non-2xx through
  `SigningFailedException` already; it gains no retry logic here.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-015')`, with the load-dependent
criteria in the integration group.

- **AC1 — the legitimate path is unaffected**
  - Given a client signing at a normal rate, well inside the configured budget
  - When it signs a sequence of assets
  - Then every request succeeds, and the manifests are what they were before
    this spec — no added latency beyond the accounting itself

- **AC2 — a token exceeding its rate is refused** *(error path)*
  - Given more requests from one token within the window than the limit allows
  - When the limit is passed
  - Then the service answers **429** with a `Retry-After` header and signs
    nothing
  - And the budget recovers: after the window, the same token is served again

- **AC3 — requests in flight are capped** *(error path)*
  - Given more concurrent signing requests than `MAX_CONCURRENT_SIGNS`
  - When the cap is exceeded
  - Then the excess is answered **429**, and the requests within the cap still
    complete normally — a burst must degrade by refusing the excess, not by
    failing everything

- **AC4 — a saturated service says so**
  - Given signing requests occupying the concurrency cap
  - When `GET /health` is called
  - Then it answers promptly (it is never rate-limited) and reports saturation —
    at minimum the number in flight and the cap
  - Rationale: verified 2026-08-05 that `/health` answers in ~0.01 s under
    load, so without this an orchestrator cannot tell a saturated instance from
    an idle one and keeps routing to it

- **AC5 — a stalled client cannot hold a slot** *(error path)*
  - Given a client that opens a request and then sends its body slowly or not at
    all
  - When the configured `requestTimeout` / `headersTimeout` elapses
  - Then the connection is closed and the slot is released

- **AC6 — refusals are recorded and leak nothing** *(error path)*
  - Given any 429 from AC2 or AC3
  - When the response is returned
  - Then an audit record is written with `outcome: "rejected"` and a reason
    naming the limit (SPEC-012 AC2), the response carries the correlation id,
    and the body contains no path, library internals or echo of the payload
  - And a flood of refusals must not itself become the denial of service: the
    record for a refused request stays bounded in size

- **AC7 — limits are configurable, and off is a deliberate choice**
  - Given `MAX_CONCURRENT_SIGNS` / `RATE_LIMIT_*` are unset
  - When the service starts
  - Then documented defaults apply — limits are **on** by default, because a
    default-off protection is one nobody turns on
  - And setting a limit to `0` disables it explicitly, which `GET /health`
    reports, so an operator can see that an instance is unprotected

## API sketch

Illustrative only. Confined to `service/server.js`; the `/v1/sign` request and
success-response shapes do not change.

```js
// service/server.js
const MAX_CONCURRENT_SIGNS = Number(process.env.MAX_CONCURRENT_SIGNS ?? 4);
const RATE_LIMIT_REQUESTS  = Number(process.env.RATE_LIMIT_REQUESTS ?? 60);
const RATE_LIMIT_WINDOW_MS = Number(process.env.RATE_LIMIT_WINDOW_MS ?? 60_000);

let inFlight = 0;

// Token bucket per token_id — the identifier SPEC-012 already derives, so no
// new way of naming a caller is introduced here.
const buckets = new Map();
```

No new runtime dependency: a token bucket and a counter are a few lines, and
`express-rate-limit` would add a dependency to the one process holding the
signing key for something this small (see ADR requirement in CLAUDE.md).

## Open questions

- ~~**Refuse or queue?**~~ **RESOLVED (2026-08-05): refuse, with
  `Retry-After`.** Queueing looks friendlier to a legitimate burst, but against
  this client it is not: the PHP client bounds a request at 10 s (SPEC-008), so
  a queued request can time out client-side while still occupying a slot
  server-side — the caller has given up and the service is still paying for it.
  Refusal keeps the two ends in agreement about what happened, and `Retry-After`
  tells the caller what to do about it. The cost is honest and bounded: a burst
  the service could have absorbed is refused instead, which is the trade being
  made deliberately.
- **What is the right default concurrency?** Measured ≈3.6× speedup at six
  concurrent on this machine, so the ceiling is not one. But the memory cost is
  ~4× the asset per request, and `MAX_BODY_SIZE` is 50 MB — four concurrent
  50 MB assets is ~800 MB. A default of 4 is a guess that needs measuring
  against a container memory limit. *Non-blocker*, but the default must be
  justified by measurement before AC7 is marked met.
- **Should `MAX_BODY_SIZE` drop?** 50 MB is far above any PNG or JPEG this
  service will legitimately sign, and it is the multiplier on every other
  number here. Lowering it is the single most effective change — and a
  behaviour change for anyone signing large assets, which is why it is out of
  scope above. *Non-blocker*, but worth its own decision.
- **Does the rate limiter need to survive a restart?** No, and it must not
  pretend to: an in-process bucket resets on restart, which is a documented
  property rather than a defect at this scale.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/RateLimitTest.php` :: "signs a normal sequence of requests without interference" | `service/server.js` `/v1/sign` guard (no-op within budget) |
| AC2 | `tests/Integration/RateLimitTest.php` :: "refuses a token that exceeds its rate, and serves it again after the window" | `service/server.js` `rateLimited()`, `buckets` |
| AC3 | `tests/Integration/RateLimitTest.php` :: "refuses the excess when more signs arrive than the cap allows" | `service/server.js` `inFlight`, `MAX_CONCURRENT_SIGNS` |
| AC4 | `tests/Integration/RateLimitTest.php` :: "answers /health while signing is saturating the cap"; "reports its configured limits and how many signs are in flight" | `service/server.js` `GET /health` `in_flight` + `limits` |
| AC5 | `tests/Integration/RateLimitTest.php` :: "closes a connection whose body never arrives" | `service/server.js` `server.setTimeout()`, `requestTimeout`, `headersTimeout` |
| AC6 | `tests/Integration/RateLimitTest.php` :: "refuses the excess when more signs arrive than the cap allows" (Retry-After + correlation id) | `service/server.js` `refuse()` in the `/v1/sign` guard |
| AC7 | `tests/Integration/RateLimitTest.php` :: "has its limits switched on by default" | `service/server.js` limit constants, `GET /health` `limits` |
