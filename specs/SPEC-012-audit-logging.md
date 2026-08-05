# SPEC-012: Audit logging for signing requests

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-05                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The signing service keeps no record of what it has signed. Today the only
output is `console.error` on failure; a successful signature leaves no trace at
all.

This is the control that matters most for a provenance system, and it is the one
that is entirely absent. If a fabricated Content Credential ever surfaces
carrying this certificate — an asset attributed to the wrong creator, or an
AI-generated image asserted as a camera capture, both of which the service will
currently sign (see SPEC-011) — the operator cannot answer the first question an
incident demands:

> Did we sign this? When? At whose request?

Without an answer, every credential ever issued under that certificate becomes
suspect, because there is no way to separate the fabricated one from the genuine
ones. The remedy is not narrowing what may be signed — SPEC-011 covers what can
usefully be constrained, and explains why semantic filtering cannot make an
attestation truer. The remedy is **attribution**: a durable, tamper-evident-enough
record binding each signature to a request, a caller and an asset.

A second, smaller need falls out of the same mechanism. The 2026-08-05 review
found that service errors are echoed verbatim to the client
(`res.status(500).json({ error: String(err.message) })`), leaking temp-file paths
and library internals into client-side exceptions and logs. Replacing that with a
generic message is only acceptable once the detail is recorded somewhere the
operator can find it, keyed by an identifier the client can quote. This spec
introduces that identifier.

Constraint from CLAUDE.md (Security): never log tokens, key material, or full
manifests at info level. The record specified here is deliberately built from
digests and summaries, never payloads.

## Scope

**In scope**

- One structured audit record per `POST /v1/sign` request, for both accepted and
  rejected requests.
- A correlation id per request, returned to the client in a response header and
  in the body of error responses.
- Replacing the verbatim error echo on `/v1/sign` and `/v1/read` with a generic
  message plus that correlation id, with full detail in the record.
- An explicit, tested denylist of things that must never appear in a record.
- Documenting the record shape and its retention implications in the README.

**Out of scope** (each needs its own spec before it may be built)

- Log shipping, rotation, retention enforcement or storage backends. Records go
  to stdout as single-line JSON; collecting them is the operator's concern and a
  container-platform responsibility.
- Cryptographic log integrity (hash chaining, signed log entries, external
  transparency log). Valuable and a natural follow-up, but a separate design.
- Per-client tokens, scopes, and CAWG organisational identity assertions —
  the record identifies *which token* was used, not *which human*. Real
  attribution needs distinct tokens per client, which needs its own spec.
- Auditing `POST /v1/read`. Reading is not an exercise of the signing key; only
  its error path is touched here, for the correlation id.
- Any change to `src/`. The PHP client already surfaces a service error message
  through `SigningFailedException`; it will now surface a generic message plus a
  correlation id, which requires no client change.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-012')`, plus service-level checks in
the integration group where a live service is required.

- **AC1 — a successful signature is recorded**
  - Given a valid `/v1/sign` request
  - When it succeeds
  - Then exactly one single-line JSON record is written to stdout containing:
    an ISO-8601 UTC timestamp; the correlation id; `outcome: "signed"`; the
    SHA-256 of the **input** asset bytes and its byte length; the SHA-256 of the
    **signed output** bytes; `mime_type`; the `creator_name` actually used; the
    labels of the assertions supplied; every `digitalSourceType` present; and
    whether a TSA timestamp was applied

- **AC2 — a rejected request is recorded**
  - Given a request refused by validation (SPEC-009 or SPEC-011) or failing
    during signing
  - When the response is returned
  - Then a record is written with `outcome: "rejected"` or `"failed"`, the same
    correlation id as the response, and a `reason` naming the violated
    constraint or the error class — and, for a rejection, no signature was
    produced

- **AC3 — the correlation id reaches the client** *(error path)*
  - Given any `/v1/sign` or `/v1/read` request
  - When the service responds
  - Then the response carries the correlation id in a header, and on a non-2xx
    response the JSON body contains it as well, so a caller can quote it in a
    bug report

- **AC4 — errors no longer leak internals** *(error path)*
  - Given a signing failure whose underlying error text contains a filesystem
    path or library internals (e.g. a TSA failure, or a c2pa-rs error naming the
    temp file)
  - When the 5xx response is returned
  - Then the body contains a generic message and the correlation id, and
    contains no filesystem path and no library error text
  - And the full error detail appears in the audit record for that correlation id

- **AC5 — the record never contains secrets or payloads** *(required denylist)*
  - Given any request, including one whose `creator_name` or assertions contain
    hostile content, and any value of `CONTENTAUTH_API_KEY`
  - When records are written
  - Then no record contains: the bearer token or any substring of it; any PEM or
    key material; the base64 `content`; the signed output bytes; the full
    assertion `data` payloads; or the full manifest store
  - And any string copied into a record is length-capped, so a caller cannot use
    `creator_name` to write unbounded data into the operator's log

- **AC6 — the caller is identified without revealing the credential**
  - Given a request authenticated with a bearer token
  - When the record is written
  - Then it carries a stable `token_id` derived from the token by a one-way
    function and truncated, such that two requests with the same token share a
    `token_id` and the token itself cannot be recovered from it

- **AC7 — a record is emitted even when signing throws**
  - Given a request that raises inside the signing path after validation passed
  - When the exception is handled
  - Then a `failed` record is still written before the response is sent, and the
    temp file cleanup of the existing `finally` still runs

- **AC8 — records are machine-readable and one per line**
  - Given multiple concurrent requests
  - When records are written
  - Then each record is a single line of valid JSON on stdout with no
    interleaving, so a collector can parse them line by line

- **AC9 — audit loss is visible, and never blocks signing** *(error path)*
  - Given the audit write fails (stdout closed, downstream pipe broken)
  - When a `/v1/sign` request is processed
  - Then the request still completes normally — a logging outage must not become
    a signing outage, and must not hand a caller who can break the write a lever
    to stop all signing
  - And a minimal fallback record is attempted on stderr
  - And the service sets a persistent degraded flag reported by `GET /health`,
    so monitoring can see that records are incomplete
  - And the flag is not cleared by a subsequent successful write: once records
    have been lost, that fact stays visible until the process restarts

- **AC10 — the personal-data implication is stated**
  - Given `creator_name` is caller-supplied and reproduced in records
  - When an operator reads the README section on audit logging
  - Then it states that records are written to stdout, that `creator_name` is
    reproduced verbatim (truncated per AC5), that a deployment whose
    `creator_name` carries a person's name is therefore processing personal data
    in its logs, and that retention is the operator's responsibility
  - And it states what is deliberately *not* logged, so the boundary is
    auditable rather than assumed

## API sketch

Illustrative only. Confined to `service/server.js`; the `/v1/sign` request and
success-response shapes do not change.

```js
// service/server.js
const crypto = require('crypto');

const tokenId = (token) =>
  crypto.createHash('sha256').update(String(token)).digest('hex').slice(0, 12);

const sha256 = (buf) => crypto.createHash('sha256').update(buf).digest('hex');

/** Single-line JSON to stdout. Never called with payloads — see AC5. */
function audit(record) {
  process.stdout.write(JSON.stringify(record) + '\n');
}
```

A record, illustratively:

```json
{
  "ts": "2026-08-05T09:14:22.104Z",
  "cid": "01J...",
  "event": "sign",
  "outcome": "signed",
  "token_id": "9f2a41c0b7de",
  "mime_type": "image/png",
  "input_sha256": "3b1f...",
  "input_bytes": 1699,
  "output_sha256": "c07e...",
  "creator_name": "ACME GenAI Image Model",
  "assertion_labels": ["c2pa.actions.v2"],
  "digital_source_types": ["http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"],
  "timestamped": true
}
```

The error response shape gains the correlation id:

```json
{ "error": "signing failed", "cid": "01J..." }
```

## Open questions

- ~~**Fail-open or fail-closed if the record cannot be written?**~~
  **RESOLVED (2026-08-05): fail-open, but audit loss is itself an event
  (AC9).** A binary choice is the wrong frame. Fail-closed converts a logging
  outage into a signing outage, and worse, hands an attacker a denial-of-service
  lever: whoever can make writes fail can stop all signing. Fail-open alone,
  though, is exactly the hole the log exists to close — signatures issued with
  no account of them, and nobody the wiser.

  So: the request proceeds, and the failure is escalated rather than swallowed.
  A minimal fallback record goes to stderr; if that also fails, the service
  raises a persistent degraded flag surfaced on `GET /health`. Audit loss then
  becomes visible to monitoring instead of silent, which is the property that
  actually matters — the risk was never "one write failed", it was "we cannot
  tell that our records are incomplete".
- ~~**Is `creator_name` personal data?**~~ **RESOLVED (2026-08-05): log it,
  truncated, and say so.** In this library's intended use `creator_name` is
  application metadata — a tool or model name such as `ACME GenAI Image Model`
  (SPEC-001, README) — not a person. Hashing it would destroy the investigative
  value that justifies the record at all, and omitting it removes the only field
  linking a signature to the software that requested it. It is nonetheless
  caller-supplied and *may* carry a personal name in some deployment, so the
  README must state plainly that operators whose `creator_name` contains
  personal data are processing personal data in their logs and must set
  retention accordingly (AC10). The asset itself is never logged, and its
  SHA-256 is not personal data.
- **Should `token_id` be salted?** An unsalted SHA-256 prefix is brute-forceable
  if a deployment uses a weak token. `.env.example` prescribes 32 random bytes,
  which is not brute-forceable — but a salt (from env, or derived at startup)
  would make the property hold regardless of token quality. *Non-blocker*,
  leaning salted with the salt never logged.
- **Correlation id format.** A UUIDv4 or ULID from `crypto.randomUUID()` needs no
  dependency; ULID would sort chronologically but needs code. *Non-blocker*,
  leaning `crypto.randomUUID()`.

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
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
