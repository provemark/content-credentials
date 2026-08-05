# SPEC-016: Named client credentials

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (amends SPEC-009 auth, SPEC-012 records, SPEC-015 limits) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The service authenticates against a single `CONTENTAUTH_API_KEY`. Everything
built on top of that identity therefore collapses to one value:

- **SPEC-012** records `token_id` — which, with one token, is the same string on
  every record ever written.
- **SPEC-015** rate-limits per `token_id` — which, with one token, is one shared
  budget.

Both specs are correct today and quietly weaker the moment a second caller
appears, which is the ordinary case rather than an exotic one: a second
application, a staging environment pointed at the same service, a second team,
or a second customer if this is ever offered as a service.

What breaks then is not the code but the answers it can give.

**The audit log stops answering the question it exists for.** If a fabricated
credential surfaces under this certificate, the record says it was signed by
token `9f2a41c0b7de` — which is every request the service has ever handled. The
operator can prove *that* they signed it and not *at whose request*, and that is
the question an incident starts with (SPEC-012, Problem).

**One runaway caller starves the rest.** A misconfigured batch worker in staging
consumes the production budget, because SPEC-015's window is keyed on a value
they share.

**Rotation is all-or-nothing.** Suspecting a leak means replacing the one
credential every caller uses, so every caller stops until every caller is
updated. Rotation that expensive is rotation done late.

**One leak is every leak.** A token taken from the least protected consumer —
usually staging — signs exactly as authoritatively as production.

Constraining what may be signed (SPEC-011) and recording it (SPEC-012) both
noted that neither can tell an authorised caller from a stolen token. That
remains true. What this spec changes is the blast radius and the attribution:
a stolen credential becomes one client's problem, and the record says whose.

## Scope

**In scope**

- Multiple named client credentials, configured as a JSON document at a path in
  the environment, mirroring the SPEC-014 trust-settings pattern.
- Keeping `CONTENTAUTH_API_KEY` working unchanged as the single-client shorthand,
  so no existing deployment has to change anything.
- Failing fast at startup on a credentials document that is unreadable,
  unparseable, or could not authenticate anyone.
- Recording the **client name** alongside `token_id` in audit records
  (SPEC-012), so a rotation is visible as the same client with a new credential.
- Keying SPEC-015's rate-limit window on the **client** rather than the token,
  so one caller cannot spend another's budget.
- `GET /health` reporting how many clients are configured.
- Documenting the format, rotation and revocation in the README and
  `.env.example`.

**Out of scope** (each needs its own spec before it may be built)

- CAWG organisational identity assertions — putting the client's identity in the
  **manifest** rather than only in the operator's log, so a third party can see
  it without trusting those logs. The natural next step; `createCawgTrustSettings`
  was deliberately left out of SPEC-014 for the same reason.
- Per-client limit overrides (a client with a larger budget than another).
  Keying the existing limits per client is in scope; differentiating them is a
  policy feature.
- Reloading credentials without a restart. File watching is a different
  reliability problem; revocation is "edit and restart".
- Scopes or permissions per client (read-only, sign-only). One capability today.
- Any change to `src/`. The PHP client already sends one bearer token and needs
  no knowledge that others exist.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-016')`, with service-level
criteria in the integration group.

- **AC1 — the single-key deployment is unchanged** *(backwards compatibility)*
  - Given only `CONTENTAUTH_API_KEY` is set, as every deployment has it today
  - When a client signs and reads
  - Then behaviour is exactly what it is now, and audit records carry a client
    name identifying it as the single configured credential

- **AC2 — several clients can authenticate**
  - Given a credentials document naming two clients with different tokens
  - When each signs with its own token
  - Then both succeed, and each record carries **its own** client name and
    `token_id`

- **AC3 — an unknown token is refused, and reveals nothing** *(error path)*
  - Given a token matching no configured client
  - When it is presented
  - Then the response is **401** with the same body and timing characteristics
    as any other rejection — nothing may reveal whether a token exists, how many
    clients are configured, or how much of a token matched
  - And the comparison must not exit early on the first mismatching credential

- **AC4 — misconfiguration stops the service** *(error path)*
  - Given a credentials document that is missing, unreadable, unparseable, or
    contains no usable client (no name, empty name, missing or empty token,
    duplicate names)
  - When the service starts
  - Then it exits non-zero naming the problem, and does not start serving
  - And given **both** `CONTENTAUTH_API_KEY` and the document are set
  - Then it also exits non-zero: two sources of truth for who may sign is a
    misconfiguration, not a merge

- **AC5 — records attribute the client, and survive rotation**
  - Given a client whose token is replaced with a new one under the same name
  - When it signs before and after
  - Then both records carry the same `client`, and **different** `token_id`
    values — the name says who, the token id says which credential, and an
    operator can see a rotation happen without losing the trail

- **AC6 — one client cannot spend another's budget**
  - Given two clients and a rate limit smaller than their combined traffic
  - When one exhausts its budget and receives 429
  - Then the other is still served — SPEC-015's window is per client

- **AC7 — revoking a client takes effect**
  - Given a client removed from the credentials document, and a restart
  - When it presents its previously valid token
  - Then it receives **401**, and the remaining clients are unaffected

- **AC8 — the configuration is observable without being disclosed**
  - Given any configuration
  - When `GET /health` is called
  - Then it reports how many clients are configured
  - And it reports neither their names nor anything derived from their tokens:
    `/health` is unauthenticated

## API sketch

Illustrative only. Confined to `service/server.js`; the request and response
shapes of `/v1/sign` and `/v1/read` do not change.

```jsonc
// CONTENTAUTH_CLIENTS -> /run/secrets/clients.json
{
  "clients": [
    { "name": "web",   "token": "..." },
    { "name": "batch", "token": "..." }
  ]
}
```

```js
// service/server.js
// Digest-keyed lookup rather than comparing against each credential in turn:
// the presented token is hashed first, so no secret material is compared byte
// by byte and there is no early exit to time (AC3).
const clientsByDigest = new Map();  // sha256(salt + token) -> { name }

function authenticate(token) {
  return clientsByDigest.get(digest(token)) ?? null;
}
```

Audit records gain one field beside the existing `token_id`:

```json
{ "client": "batch", "token_id": "9f2a41c0b7de", "outcome": "signed" }
```

## Open questions

- **Document or environment list?** A JSON document mounted as a secret matches
  the SPEC-014 trust-settings pattern and keeps tokens out of `docker inspect`
  and process listings; a `name:token,name:token` env variable is easier for a
  two-client setup. *Non-blocker*, leaning the document, with
  `CONTENTAUTH_API_KEY` remaining the zero-configuration path.
- **Should `client` replace `token_id` in records, rather than joining it?**
  Keeping both is what makes AC5 possible — the name survives rotation, the
  token id distinguishes credentials. The cost is one more field carrying
  caller-supplied text, which AC5 and SPEC-012 AC5 already bound.
  *Non-blocker*, leaning keep both.
- **Is a client name personal data?** SPEC-012 AC10 already requires the README
  to say that `creator_name` may be. A client name is chosen by the operator
  rather than a caller, so it is far less likely to be — but the README should
  say so rather than leave the operator to infer it. *Non-blocker*.
- **How should rotation without downtime work?** Two valid tokens for one client
  during a handover is the obvious answer and the document format allows it
  (two entries, same name) — except AC4 rejects duplicate names. Either relax
  that to allow several tokens per client, or accept a restart. *Blocker for
  AC4/AC5*: the two criteria contradict each other as written unless this is
  decided.

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
| AC8                  | —                           | —                    |