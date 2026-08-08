# SPEC-030: Bounding the service before authentication

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-08                     |
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
- A **single global** budget for failed-authentication attempts — not keyed on
  the client address, and never on a header — refusing the excess in the shape
  SPEC-015 established (**429** + `Retry-After`). Its purpose is visibility of
  credential guessing rather than load: the reordering above is what removes the
  load. See Open questions for the measurement that settled this.
- A running count of failed authentications, so repeated guessing is visible
  without a log record per attempt.
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

- **AC4 — failed authentication is bounded, globally** *(error path)*
  - Given a service with a failed-authentication budget of N per window
  - When more than N requests with an invalid or absent token arrive inside the
    window
  - Then the excess is refused with **429** and a `Retry-After` header
  - And the refusal carries a correlation id
  - And the budget is **one counter for the whole service**: two different
    sources exhausting it together reach the refusal at N in total, not at N
    each
  - *(That last clause is the decision from Open question 1 made testable, and it
    carries an accepted cost: during a flood, a caller with a merely wrong token
    gets 429 where it would otherwise get 401. It holds no valid credential
    either way. A test asserting only "eventually 429" would pass against a
    per-address implementation too, which is why the criterion names the
    aggregate.)*
  - *(Measured today: 60 invalid-token requests produce 60 × 401 and zero 429.
    The test must assert the 429 is reached, not merely that some requests were
    refused — a criterion about a budget that never observes the budget being
    exceeded is the trap NOTES Steps 17 and 19 record twice.)*

- **AC5 — the failed-authentication budget never touches the authenticated ones** *(error path)*
  - Given a service whose failed-authentication budget is fully exhausted
  - When a **valid** token signs
  - Then the signing request is accepted, and its own SPEC-015 budget is
    unaffected
  - *(This holds by construction — the budget is spent only on failure, and a
    valid token does not fail — which is exactly why it needs a test. A
    criterion that is true by construction is one an implementation can quietly
    stop satisfying: spending the budget on every *attempt* rather than on every
    *failure* would look like a one-word simplification and would hand any
    unauthenticated caller a lever to stop all signing. That is the failure this
    criterion exists to catch, not a starvation the design allows.)*

- **AC6 — `/health` reports what is in force, and what has been tried**
  - Given a running service
  - When `GET /health` is called
  - Then the failed-authentication budget appears beside the signing and read
    limits, and a budget of `0` is reported as disabled
  - And a running count of failed authentications is reported
  - And `/health` itself remains reachable without a token and without spending
    that budget, per SPEC-024 AC6
  - *(The counter is what makes a global budget acceptable. Without per-source
    detail there is no record worth writing per attempt, so the count is the
    only thing that turns "somebody is guessing our token" from invisible into
    observable — the same argument SPEC-018 made for publishing the certificate
    identity rather than assuming a rotation took.)*

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
  - Then the number of records written is bounded by the **window**, not by the
    number of requests: at most two per window — the first failure, and the
    moment the budget runs out — and nothing in between
  - And that holds for 15 attempts and for 15 000 alike
  - And the total remains visible through the `/health` counter of AC6, so
    bounding the records does not bound what an operator can see
  - *(Amended 2026-08-08, during implementation. The criterion first said "every
    429 is recorded", which contradicts its own headline: with a fixed window and
    unbounded attempts the 429s scale with the requests, so the log grows with
    the flood — the leak this criterion exists to close, one layer over. A test
    must assert records ≥ 1 as well as ≤ 2: with nothing written at all the
    upper bound holds vacuously, which is how this was found.)*
  - *(The mirror of SPEC-017's reasoning about `token_id`. If every 401 writes a
    line, an unauthenticated caller controls how much an operator's log grows,
    which is the same denial of service one layer over.)*

## API sketch

Illustrative only. The change is confined to `service/server.js`; no request or
response shape changes, only the order in which a request meets them.

```js
// service/server.js — the ordering is the change.

app.use(correlationId);              // SPEC-012/017: first, always.

app.use('/v1', authenticate);        // moved ABOVE the parser: headers only.
app.use(express.json({ limit: MAX_BODY }));
app.use(bodyParserErrors);           // can now record req.token (SPEC-017 note retires)

// Inside authenticate(), AFTER the constant-time comparison has failed — never
// before it, so a valid token cannot spend this budget (AC5):
//
//   if (!tokenMatches(token, API_KEY)) {
//     authFailures += 1;
//     const wait = rateLimited(authBuckets, AUTH_FAIL_LIMIT, 'global', Date.now());
//     return wait !== null ? refuse429(wait) : res.status(401).json({ … });
//   }

const AUTH_FAIL_LIMIT = Number(process.env.AUTH_FAIL_LIMIT ?? 30);
let authFailures = 0;               // reported on /health (AC6)
```

`rateLimited()` already takes its store and limit as parameters (SPEC-024), so a
third budget is a third `Map` and no new mechanism. The literal `'global'` key is
the decision from Open question 1 in one token: the map holds exactly one entry,
by design and not by coincidence, so there is no cardinality for a caller to
grow. A helper that took no key at all would be tidier and would hide that the
same mechanism is being used three times with three policies.

**What this does not buy, stated precisely.** Refusing before the parser stops the
*allocation and the parse*. It does not stop the bytes arriving: node still reads
from the socket, and a request whose body is never consumed is either drained or
the connection is reset — which the client may see as a connection reset rather
than a clean 401. Which of the two node does here, and what it costs, is AC7's
job to measure. The existing `server.setTimeout()` (SPEC-015) already bounds a
socket that stops talking; nothing here replaces it.

## Open questions

- ~~**What identifies an unauthenticated caller?**~~
  **RESOLVED (2026-08-08): nothing — a single global budget, never keyed on
  address, never reading `X-Forwarded-For`.** The draft leaned the other way; the
  measurement removed the reason for the complexity.

  **What `req.socket.remoteAddress` actually reports**, measured against the two
  deployments this project documents (a probe on the compose network, 2026-08-08):

  | Deployment | Peer as the container sees it |
  |---|---|
  | host → published port, `127.0.0.1:3000:3000` (docker-compose default) | `172.19.0.1` for **every** request — the bridge gateway |
  | container → container, `http://signer:3000` (README) | the calling container's own address, distinct |

  So in the deployment this project ships and recommends, per-address keying
  discriminates **nothing**: every host-side caller, legitimate or not,
  collapses into the gateway address. It is a global bucket wearing a costume,
  and only the container-network deployment would see distinct peers.

  Three further reasons, in order of weight:

  1. **An address-keyed map has attacker-controlled cardinality.** SPEC-015's
     comment records why its own map is safe — "only authenticated requests
     reach here, so the map is bounded by the number of valid tokens". That
     sentence is exactly what an unauthenticated bucket cannot say. Adding an
     unbounded map inside the spec written to close a resource exhaustion would
     be a poor trade.
  2. **AC5 is satisfied by construction, so the argument for per-address does not
     apply.** The budget is spent only on *failure*, and a valid token never
     fails, so it never touches this budget however exhausted it is. The draft
     worried that a global bucket would let one noisy source starve a legitimate
     caller; it cannot, because the two budgets never meet.
  3. `X-Forwarded-For` is caller-controlled, and a raw address held in memory is
     a small personal-data question that a global counter does not raise at all.

  **The accepted cost, stated plainly**: during a flood, a caller presenting a
  *wrong* token gets 429 rather than 401. They hold no valid credential either
  way, so this degrades their diagnostics and nothing else. It is recorded in AC4
  so it is a decision rather than a surprise.

- ~~**Should a failed authentication be audited at all?**~~
  **RESOLVED (2026-08-08), as a consequence of the above:** the first failure per
  window is audited, the first 429 of a window is audited, and `/health` carries
  a running count of failed authentications. (Amended 2026-08-08: "every 429"
  was the original wording and contradicted AC8's own bound — see AC8.) With a global bucket there is no per-source detail
  worth a record per attempt, and AC8's bound comes free — the number of records
  is bounded by the budget rather than by the number of requests.

- **Is the budget still needed once the reordering is in?** Worth asking, because
  after authentication moves ahead of the parser an unauthenticated request costs
  a header parse, one SHA-256 and a 401 — about what `GET /health` costs, and
  SPEC-024 AC6 already decided `/health` is not worth bounding. The honest answer
  is that **the reordering is the fix and the budget is not a load control**. It
  is kept for a different purpose: repeated authentication failure is a
  credential-guessing signal, and today nothing anywhere reports it. That purpose
  is served by a counter and a bounded record, which is precisely what the
  resolution above specifies. *Non-blocker*, and recorded so nobody later reads
  AC4 as a performance measure and sizes it like one.
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
| AC1 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "still signs and reads back with a valid token, carrying a correlation id" | `service/server.js` middleware order (correlation id stays first) |
| AC2 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "answers 401 rather than 413 when the token is invalid"; "writes no body-parser refusal for a request it never parsed" | `service/server.js` `app.use('/v1', …)` ahead of `express.json` |
| AC3 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "still refuses an oversized body from a valid token, and attributes it" | `service/server.js` body-parser error handler `token_id` |
| AC4 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "refuses failed authentication past the budget, with Retry-After"; "spends one budget for the whole service rather than one per source" | `service/server.js` `AUTH_FAIL_LIMIT`, `authBuckets` keyed `'global'` |
| AC5 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "still signs with a valid token while the failed-authentication budget is exhausted" | `service/server.js` budget spent only on failure |
| AC6 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "reports the failed-authentication budget and a running count on /health"; "counts a failed authentication without needing a token to see it"; "never rate limits /health itself" | `service/server.js` `/health` `auth_failures`, `limits.auth_fail_limit` |
| AC7 | `tests/Unit/UnauthenticatedCostGuidanceTest.php` :: "says which side of authentication the limits are on"; "publishes what an unauthenticated request costs"; "documents the failed-authentication budget and its counter" | `docs/service.md` "Before authentication"; `.env.example` |
| AC8 | `tests/Integration/UnauthenticatedBoundsTest.php` :: "bounds audit records by the budget rather than by the number of requests" | `service/server.js` `auditedFailureWindow`, `auditedRefusalWindow` |