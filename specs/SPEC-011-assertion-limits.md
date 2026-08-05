# SPEC-011: Server-side assertion limits on `/v1/sign`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | claude (proposed), maurice (to approve)           |
| Approved   | — while draft                                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`POST /v1/sign` passes `extra_assertions` straight into
`Builder.withJson({ ..., assertions })` with no validation beyond "is it an
array". The service will therefore sign **any** assertion structure an
authenticated caller supplies.

Demonstrated against the running service with a valid token (2026-08-05): an
AI-generated PNG was signed with `digitalSourceType = digitalCapture`,
`softwareAgent = "Canon EOS R5"`, `creator_name = "Reuters Photo Desk"` and a
fabricated `stds.schema-org.CreativeWork` author. `c2patool` reports
`validation_state: Trusted`. The system's central claim — that a Content
Credential says something reliable about how an asset came to be — is inverted
by a caller doing nothing more than using the API as designed.

This is a consequence of the deliberate divergence documented in
`docs/c2pa-primer.md` §3: unlike the upstream wp-plugin service, ours injects no
actions assertion, because the PHP client owns it. That divergence is right — it
is what keeps exactly one, correct actions assertion in the manifest — but it
left the service with no opinion at all about what it will attest to.

The security consequence is that `CONTENTAUTH_API_KEY` carries the full
authority of the signing key. The architecture's key-isolation property (CLAUDE.md,
Architecture) protects against key *theft* from the web application; it does not
currently constrain key *misuse* through the API. Narrowing what the service will
sign converts a general signing oracle into a bounded attestation service, and
costs the legitimate client nothing: `ManifestBuilder` emits exactly one
`c2pa.actions.v2` assertion (SPEC-001).

Related domain rules that this spec must not break: the first action MUST be
`c2pa.created` or `c2pa.opened` (claim v2), and the Article 50 marking is that
same single assertion (CLAUDE.md, Domain rules).

## Scope

**In scope**

- Rejecting a `/v1/sign` request whose `extra_assertions` exceed structural
  limits: total count, per-assertion serialised size, nesting depth.
- Rejecting more than one assertion whose label starts `c2pa.actions`.
- Requiring every assertion to be an object carrying a non-empty string `label`.
- Bounding `creator_name` length and rejecting non-string values.
- Returning HTTP 400 with a specific, non-leaking message for each rejection,
  consistent with the SPEC-009 client-error convention.
- Documenting the limits in the README service section and `.env.example` if
  they are configurable.

**Out of scope** (each needs its own spec before it may be built)

- A label allowlist, or semantic validation of assertion *contents* (e.g.
  requiring `digitalSourceType` to be an IPTC URI). Structural limits only.
- Per-token authorisation, scopes, or distinguishing callers.
- Rate limiting, request concurrency caps, or body-size changes (a real gap —
  see the review of 2026-08-05 — but a separate concern).
- Any change to `src/`. The PHP client already sends one actions assertion, so
  it should need no modification; if a limit turns out to break it, that is a
  spec contradiction → stop and amend.
- `isTrusted()` failing closed on a manifest-less asset, and the README
  read-verification guidance. Separate spec against `src/`.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-011')`, plus service-level checks in
the integration group where a live service is required.

- **AC1 — the legitimate client is unaffected**
  - Given a manifest built by `ManifestBuilder::forAiGeneratedImage()`, i.e. one
    `c2pa.actions.v2` assertion with `c2pa.created` and the IPTC
    `digitalSourceType`
  - When it is signed via `/v1/sign`
  - Then the request succeeds and the resulting manifest is byte-for-byte what
    it was before this spec — same single actions assertion, signature valid,
    Art.50 mark present, timestamp behaviour unchanged

- **AC2 — more than one actions assertion is refused** *(error path)*
  - Given `extra_assertions` containing two entries whose labels start
    `c2pa.actions` (e.g. `c2pa.actions` and `c2pa.actions.v2`)
  - When `/v1/sign` is called
  - Then HTTP **400** with an error naming the constraint, and **no signing
    takes place** — no temp file is written, no signature is produced

- **AC3 — assertion count is bounded** *(error path)*
  - Given `extra_assertions` with more than `MAX_ASSERTIONS` entries
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing

- **AC4 — assertion size and depth are bounded** *(error path)*
  - Given an assertion whose serialised size exceeds `MAX_ASSERTION_BYTES`, or
    whose nesting depth exceeds `MAX_ASSERTION_DEPTH`
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing, and the service does not attempt to walk the
    structure unboundedly while deciding (no stack exhaustion on a hostile
    payload)

- **AC5 — malformed assertion entries are refused** *(error path)*
  - Given `extra_assertions` containing a non-object entry (string, number,
    null, array) or an object with a missing, empty or non-string `label`
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing

- **AC6 — `creator_name` is bounded**
  - Given a `creator_name` that is not a string, or longer than
    `MAX_CREATOR_NAME` characters
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing. An absent `creator_name` keeps today's
    default (`c2pa-spike-signer`)

- **AC7 — rejection leaks nothing**
  - Given any of AC2–AC6
  - When the 400 response is returned
  - Then the message names the violated constraint and its limit, and contains
    no file path, no library internals, and no echo of the submitted payload

## API sketch

Illustrative only. The change is confined to `service/server.js`; the request
and response shapes of `/v1/sign` do not change, only which requests are
accepted.

```js
// service/server.js
const MAX_ASSERTIONS      = Number(process.env.MAX_ASSERTIONS ?? 16);
const MAX_ASSERTION_BYTES = Number(process.env.MAX_ASSERTION_BYTES ?? 64 * 1024);
const MAX_ASSERTION_DEPTH = Number(process.env.MAX_ASSERTION_DEPTH ?? 16);
const MAX_CREATOR_NAME    = Number(process.env.MAX_CREATOR_NAME ?? 256);

/** @returns {string|null} the violated constraint, or null when acceptable. */
function rejectAssertions(assertions) { /* ... */ }
```

The PHP client needs no change: `SigningServiceSigner` already surfaces a
non-2xx body through `SigningFailedException` with the service message
(SPEC-002 / SPEC-009).

## Open questions

- **Are the defaults right?** 16 assertions / 64 KiB / depth 16 are proposed as
  comfortably above any legitimate use and far below anything abusive. The
  thumbnail c2pa-node adds is not part of `extra_assertions`, so it does not
  count. *Non-blocker* — numbers can be settled at approval.
- **Configurable or fixed?** Env-configurable limits are flexible but let an
  operator widen them back to today's behaviour without noticing. Fixed limits
  are safer but need a release to change. *Non-blocker*, leaning env with the
  defaults documented.
- **Should `c2pa.actions` be required rather than merely limited to one?**
  Requiring it would make a manifest without the Art.50 marking impossible to
  produce — attractive for this project's purpose, but it changes the service
  from "signs what you ask" to "signs only AI markings", which may be wrong for
  a general-purpose service. *Blocker for scope*: needs a decision before
  approval.
- **Does any limit break `bin/e2e.php` or the property-based suites?** Expected
  not — they drive the legitimate path — but AC1 must be proven against a live
  service, not assumed.

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