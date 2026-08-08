# SPEC-030: Bounding the service before authentication

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Every budget this service has is spent **after** the bearer token has been
verified, and the body is parsed **before** it.

In `service/server.js` the middleware order is: correlation id (`:541`), body
parser (`:547`), body-parser error handler (`:560`), bearer auth on `/v1/*`
(`:596`), then the routes — where SPEC-015's sign limiter and SPEC-024's read
limiter live. Both limiters key on `tokenId(req.token)`, which is the right
identifier and the one SPEC-012 already derives; it simply does not exist yet at
the point where the expensive work has already happened.

### Measured against a running service, 2026-08-08

| Request | Observed |
|---|---|
| 26 MB body, **invalid** token | **413**, with the SPEC-017 oversized-body message |
| 5 MB well-formed JSON, **invalid** token | 401 |
| 60 consecutive requests, **invalid** token | 60 × 401, **zero** 429 |

The 413 is the finding. A 413 can only be produced by the parser, so an invalid
token got its body buffered and measured before anything asked who it was. And
the third row says the obvious consequence out loud: there is no budget on that
path at all — not a rate limit, not a concurrency cap, not a counter.

So anyone who can reach the port can make the service allocate and `JSON.parse`
up to `MAX_BODY_SIZE` per request, as often as they like, holding no credential.
Everything SPEC-015, SPEC-017 and SPEC-024 built bounds the work of callers who
already proved they are allowed to ask for it.

### What the earlier specs did and did not claim

Neither SPEC-015 nor SPEC-024 got this wrong; they scoped themselves to
authenticated work and said so. SPEC-024's Problem section states: "`/v1/*`
requires the bearer token, so this is not an unauthenticated exposure." That is
correct about the *read path being reached*. The gap is one layer above it, in
middleware that runs before the sentence applies.

The documentation inherits the same shape. `docs/service.md:92` says "Limits are
**on by default**", directly under the saturation discussion. SPEC-024 already
corrected that sentence once, because it implied the whole service was bounded
when half of it was. It now overstates in a different direction: every limit it
lists is a post-authentication limit.

### Scope of the risk, stated honestly

The default deployment publishes on `127.0.0.1:3000` (`docker-compose.yml`), so
on a single-host deployment the reachable set is "anything already running on
that host", and an attacker with local code execution has better targets than a
JSON parser. This matters in the deployment the README also documents: a service
on a private network, reached as `http://signer:3000` from another container.
There, "who can reach the port" is a network question rather than a
same-host one, and the answer is not always "only our application".

It is a denial-of-service exposure, not a signing one — no signature can be
produced without the token, and SPEC-011 still bounds what a valid token may ask
for. The asymmetry is what makes it worth closing: refusing an unauthenticated
request should cost a header comparison, and today it costs up to 20 MB of
allocation and a full JSON parse.

### A second thing this fixes, which is not a security property

SPEC-017 records, at `server.js:557`, that a body-parser refusal cannot be
attributed: "auth runs after the parser, so there is no verified caller to
attribute this to. Recording an unverified token would let anyone write arbitrary
token_ids into the log, so the field is simply absent." That reasoning is sound
and its premise is the ordering. Reverse the ordering and an oversized body from
a **valid** token becomes attributable — an operator can finally see *which
caller* keeps sending 25 MB assets, which is exactly the question a 413 in a log
raises today and cannot answer.

## Scope

**In scope**

- Authenticating `/v1/*` **before** the body parser, so an unauthenticated
  request is refused on headers alone and no request body is parsed for it.
- A bounded budget for failed-authentication attempts, refusing the excess in the
  shape SPEC-015 established (**429** + `Retry-After`), so an unauthenticated
  flood is limited rather than merely cheap.
- Attributing a body-parser refusal to its verified caller now that one exists,
  retiring the SPEC-017 note at `server.js:557`.
- `GET /health` reporting the new budget alongside the existing limits, and
  reporting it as disabled when set to `0`, exactly as SPEC-015 and SPEC-024 do.
- A measurement, taken before and after, of what an unauthenticated request costs
  the service — the same discipline SPEC-017 and SPEC-024 applied to their
  defaults, because the default here should follow the number rather than
  precede it.
- `docs/service.md` and `.env.example`: the new budget, and a correction to the
  "on by default" sentence so it says which side of authentication those limits
  are on.
- A CHANGELOG **Upgrading** note: this is a behaviour change for anyone whose
  monitoring or health-checking hits `/v1/*` without a token, and for anyone
  relying on a 413 rather than a 401 for a bad-token oversized request.

**Out of scope** (each needs its own spec before it may be built)

- Bounding `/health`. It is unauthenticated by design and SPEC-024 AC6 states it
  is never itself rate limited, so that an orchestrator can always reach it. It
  does publish the certificate fingerprint and expiry, the media types, the
  limits and the live saturation to anyone who can reach the port — see Open
  questions, but not here.
- TLS, mutual TLS, or any transport-level control. SPEC-025 settled that plain
  HTTP to a non-loopback host is reported, not refused, and that decision stands.
- Per-client tokens. SPEC-016 stays `draft` and its trigger is unchanged (a user
  reporting a shared instance); nothing here brings it forward.
- Reducing `MAX_BODY_SIZE`. SPEC-017 measured and set it; a smaller pre-auth
  limit is a different lever and is not proposed.
- Validating the actions structure inside an accepted body. That is the companion
  finding and is **SPEC-029**; the two are independent and neither blocks the
  other.
- Any change to `src/`. The PHP client always sends a token; it gains no retry or
  backoff behaviour here.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-030')`, in the integration group
where a live service is required.

- **AC1 — the authenticated path is unchanged**
  - Given a valid token
  - When an asset is signed and read back
  - Then both succeed unchanged, and the existing SPEC-011/012/015/017/024
    behaviour is intact: the same limits, the same audit records, the same
    correlation id on every response including a refusal
  - *(The correlation-id middleware must stay first. SPEC-017 moved it ahead of
    the parser deliberately, so that a request failing to parse still carries an
    id; nothing in this spec may push it behind authentication.)*

- **AC2 — an oversized body with an invalid token is refused on the token** *(error path)*
  - Given a request body larger than `MAX_BODY_SIZE` and an invalid or absent
    bearer token
  - When `/v1/sign` is called
  - Then the response is **401**, not 413
  - And no body-parser refusal is audited for it, because the body was never
    parsed
  - *(This is the criterion that proves the reordering happened. Asserting only
    "401 somewhere" would pass against today's service for a small body.)*

- **AC3 — an oversized body with a valid token is still refused, and now attributable**
  - Given a request body larger than `MAX_BODY_SIZE` and a **valid** token
  - When `/v1/sign` is called
  - Then HTTP **413** with the SPEC-017/021/023 message, unchanged
  - And the audit record carries a `token_id`, which it does not today

- **AC4 — failed authentication is bounded** *(error path)*
  - Given a service with an unauthenticated budget of N per window
  - When more than N requests with an invalid or absent token arrive inside the
    window
  - Then the excess is refused with **429** and a `Retry-After` header
  - And the refusal carries a correlation id
  - *(Measured today: 60 invalid-token requests produce 60 × 401 and zero 429.
    The test must assert the 429 is reached, not merely that some requests were
    refused — a criterion about a budget that never observes the budget being
    exceeded is the trap NOTES Steps 17 and 19 record twice.)*

- **AC5 — the unauthenticated budget does not touch the authenticated ones** *(error path)*
  - Given a client that has exhausted the unauthenticated budget
  - When a **valid** token signs from the same source
  - Then the signing request is accepted
  - *(SPEC-024 AC3 in a new place, and for the same reason: a limiter that lets
    an unauthenticated flood starve a legitimate caller has moved the denial of
    service rather than removed it. This is what decides Open question 1.)*

- **AC6 — `/health` reports what is in force**
  - Given a running service
  - When `GET /health` is called
  - Then the unauthenticated budget appears beside the signing and read limits
  - And a budget of `0` is reported as disabled
  - And `/health` itself remains reachable without a token and without spending
    that budget, per SPEC-024 AC6

- **AC7 — the cost of an unauthenticated request is measured, not assumed**
  - Given a burst of unauthenticated requests carrying a near-maximum body
  - When container memory is sampled to a deadline across the burst
  - Then a figure is recorded before and after this change, and published in
    `docs/service.md` beside the existing multipliers
  - And the measurement asserts every request's HTTP status, so a number taken
    over work that never arrived cannot read as a small number
  - *(NOTES Step 37: an AC9 measurement first reported 0.8× because the requests
    were being refused rather than served. A low number is not good news, it is
    an unverified measurement.)*

- **AC8 — an unauthenticated flood cannot flood the audit log** *(error path)*
  - Given more unauthenticated requests than the budget allows
  - When the audit stream is inspected
  - Then the number of records written is bounded by the budget, not by the
    number of requests
  - *(The mirror of SPEC-017's reasoning about `token_id`. If every 401 writes a
    line, an unauthenticated caller controls how much an operator's log grows,
    which is the same denial of service one layer over. See Open question 2.)*

## API sketch

Illustrative only. The change is confined to `service/server.js`; no request or
response shape changes, only the order in which a request meets them.

```js
// service/server.js — the ordering is the change.

app.use(correlationId);              // SPEC-012/017: first, always.

app.use('/v1', authenticate);        // moved ABOVE the parser: headers only.
app.use(express.json({ limit: MAX_BODY }));
app.use(bodyParserErrors);           // can now record req.token (SPEC-017 note retires)

// Inside authenticate(), before the constant-time comparison:
//   const wait = rateLimited(authBuckets, AUTH_FAIL_LIMIT, clientId(req), Date.now());
// spent only on FAILURE, so a valid caller never touches this budget (AC5).

const AUTH_FAIL_LIMIT = Number(process.env.AUTH_FAIL_LIMIT ?? 30);
```

`rateLimited()` already takes its store and limit as parameters (SPEC-024), so a
third budget is a third `Map` and no new mechanism.

**What this does not buy, stated precisely.** Refusing before the parser stops the
*allocation and the parse*. It does not stop the bytes arriving: node still reads
from the socket, and a request whose body is never consumed is either drained or
the connection is reset — which the client may see as a connection reset rather
than a clean 401. Which of the two node does here, and what it costs, is AC7's
job to measure. The existing `server.setTimeout()` (SPEC-015) already bounds a
socket that stops talking; nothing here replaces it.

## Open questions

- **What identifies an unauthenticated caller?** There is no token, so the only
  candidate is the client address. Three problems, and they interact:
  `X-Forwarded-For` is caller-controlled and must never be trusted here; behind a
  reverse proxy every request has the proxy's address, so one bucket becomes a
  global one and AC5 is at risk; and a raw address in memory is a mild
  personal-data question even unlogged. A **single global budget** for failed
  auth avoids all three and is simpler — at the cost of one noisy source being
  able to spend everyone's budget, which is the exact failure AC5 forbids for the
  authenticated path. **Blocker**: AC4 and AC5 cannot both be written until this
  is settled. Leaning per-address on `req.socket.remoteAddress`, never a header,
  with the proxy caveat documented rather than solved.
- **Should a failed authentication be audited at all?** SPEC-012 records every
  `/v1/sign`, accepted and refused alike, and repeated failed auth is exactly the
  event an operator wants. But an unauthenticated caller then controls log
  volume — AC8. Options: record only the *first* failure per bucket per window,
  record only the 429s, or record nothing and let `/health` carry a counter.
  *Non-blocker*, leaning first-failure-plus-refusals, with a counter on `/health`
  so the total is visible without a record per attempt.
- **Does `/health` publish too much to an unauthenticated caller?** It reports the
  signing certificate's fingerprint and expiry (SPEC-018, deliberately — a
  fingerprint is public by construction), the media types, the limits and live
  saturation. None of it is secret; together it is a fairly complete description
  of the instance, including how busy it is, to anyone who can reach the port.
  Explicitly **out of scope** here, recorded so the decision is a decision.
  Bounding it would break the orchestrator case SPEC-024 AC6 protects.
- **Does moving auth first break anything that expects a 400?** A malformed-JSON
  body with a bad token answers 400 today and 401 after. `bin/e2e.php`, the
  integration suite and any monitoring that probes `/v1/*` without a token are
  the places to check. *Non-blocker*, but it must be checked before
  implementation rather than discovered during it — and it is what the CHANGELOG
  **Upgrading** note is for.
- **Does this need its own CI profile?** AC4 needs a small `AUTH_FAIL_LIMIT` and
  AC5 needs a large signing budget in the same process, which the `read-limited`
  profile's shape already demonstrates is expressible. Whether that is a sixth
  profile or an extension of an existing one is an implementation choice.
  *Non-blocker*.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | — | — |
| AC2 | — | — |
| AC3 | — | — |
| AC4 | — | — |
| AC5 | — | — |
| AC6 | — | — |
| AC7 | — | — |
| AC8 | — | — |