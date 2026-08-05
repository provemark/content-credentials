# SPEC-011: Server-side assertion limits on `/v1/sign`

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

**What this spec deliberately does not attempt.** It would be tempting to require
`digitalSourceType = trainedAlgorithmicMedia` outright, so the service could never
sign anything claiming to be a camera capture. That is a false gain: the service
can verify `trainedAlgorithmicMedia` no better than it can verify `digitalCapture`
— both are client claims. Requiring one does not make the attestation truer, it
only narrows the direction in which a caller may lie (claiming AI for authentic
material remains possible, as does lying about `softwareAgent` or submitting
someone else's asset). The cost is real, though: C2PA's value is not only "this is
AI" but also "this is authentic", and a hard requirement would permanently exclude
the second use from any deployment of this service.

The invariant this spec *does* enforce is different in kind. Two actions
assertions in one signed manifest are contradictory and their resolution is
verifier-dependent — a correctness defect, not merely an abuse vector, and
precisely the friction that motivated the divergence from upstream. "At most one"
is therefore a structural invariant and belongs in the service; "which
digitalSourceType" is deployment policy and is offered opt-in (AC8).

Attribution — knowing *who* asked for a signature — is the control that addresses
misuse rather than narrowing it, and is specified separately in SPEC-012
(audit logging).

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
- An **opt-in, default-off** deployment policy (`REQUIRE_AI_MARKING`) that
  additionally requires the single actions assertion to carry
  `digitalSourceType = trainedAlgorithmicMedia`, for deployments whose
  certificate exists only to mark AI-generated content.
- Documenting the limits and the policy flag in the README service section and
  `.env.example`.

**Out of scope** (each needs its own spec before it may be built)

- A label allowlist, or semantic validation of assertion *contents* beyond the
  optional `digitalSourceType` policy of AC8 (e.g. validating `softwareAgent`
  shape, or checking IPTC URIs against a vocabulary).
- Per-token authorisation, scopes, or distinguishing callers. Attribution of a
  signing request is **SPEC-012 (audit logging)**; per-client tokens and CAWG
  organisational identity assertions need their own spec after that.
- Rate limiting, request concurrency caps, or body-size changes (a real gap —
  see the review of 2026-08-05 — but a separate concern).
- Replacing verbatim error echo with a generic message plus correlation id
  (same review; belongs with SPEC-012, which introduces the correlation id).
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

- **AC8 — optional AI-marking policy, default off**
  - Given `REQUIRE_AI_MARKING` is unset or `false` (the default)
  - When a request carries a single actions assertion with any
    `digitalSourceType`, or none at all
  - Then it is signed — the service takes no position on which source type a
    caller asserts
  - And given `REQUIRE_AI_MARKING=true`
  - When the single actions assertion's first action does **not** carry
    `digitalSourceType = http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
  - Then HTTP **400**, no signing
  - And `GET /health` reports the effective policy, so an operator can confirm
    which mode a running service is in without reading its environment

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

// Deployment policy, NOT a structural invariant. Default off: the service takes
// no position on which digitalSourceType a caller asserts (see Problem).
const REQUIRE_AI_MARKING  = process.env.REQUIRE_AI_MARKING === 'true';

/** @returns {string|null} the violated constraint, or null when acceptable. */
function rejectAssertions(assertions) { /* ... */ }
```

Note the deliberate asymmetry in defaults: the **structural** limits are
restrictive by default, because too permissive is the risk there; the
**semantic** policy is permissive by default, because too strict is the risk
there — it would silently exclude legitimate authenticity use cases.

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
- ~~**Should `c2pa.actions` be required rather than merely limited to one?**~~
  **RESOLVED (2026-08-05): at most one, not required.** Requiring
  `trainedAlgorithmicMedia` is a false gain — the service cannot verify it any
  better than it can verify `digitalCapture`, so the requirement narrows the
  direction of a possible lie without making the attestation truer, while
  permanently excluding the authenticity use case. "At most one" stays, because
  contradictory actions assertions are a correctness defect rather than a policy
  choice. Deployments whose certificate exists solely to mark AI content can
  opt in via `REQUIRE_AI_MARKING` (AC8). Reasoning recorded in Problem.
- **Should `REQUIRE_AI_MARKING` check every action or only the first?** Claim v2
  requires the first action to be `c2pa.created`/`c2pa.opened`, and the Art.50
  marking rides on that first action. Checking only the first is simpler and
  matches the domain rule; checking all would reject a legitimate multi-action
  history (created → edited). *Non-blocker*, leaning first-action-only, as AC8
  states.
- **Does any limit break `bin/e2e.php` or the property-based suites?** Expected
  not — they drive the legitimate path — but AC1 must be proven against a live
  service, not assumed.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/AssertionLimitsTest.php` :: "still signs a manifest built by the library, unchanged" | `service/server.js` `rejectAssertions()` (no-op on the builder's output) |
| AC2 | `tests/Integration/AssertionLimitsTest.php` :: "refuses more than one actions assertion" | `service/server.js` `rejectAssertions()` actions count |
| AC3 | `tests/Integration/AssertionLimitsTest.php` :: "refuses more assertions than the limit allows" | `service/server.js` `MAX_ASSERTIONS` |
| AC4 | `tests/Integration/AssertionLimitsTest.php` :: "refuses an oversized assertion"; "refuses an assertion nested past the depth limit" | `service/server.js` `MAX_ASSERTION_BYTES`, `exceedsDepth()` |
| AC5 | `tests/Integration/AssertionLimitsTest.php` :: "refuses a malformed assertion entry" | `service/server.js` `rejectAssertions()` entry/label checks |
| AC6 | `tests/Integration/AssertionLimitsTest.php` :: "refuses a creator_name that is not a bounded string"; "still accepts a request with no creator_name at all" | `service/server.js` `MAX_CREATOR_NAME` |
| AC7 | `tests/Integration/AssertionLimitsTest.php` :: "names the constraint without leaking internals or echoing the payload" | `service/server.js` `reject()` in `POST /v1/sign` |
| AC8 | `tests/Integration/AssertionLimitsTest.php` :: "reports the AI-marking policy on /health"; "takes no position on digitalSourceType by default"; "refuses a non-AI marking when the policy requires one" | `service/server.js` `REQUIRE_AI_MARKING`, `firstActionSourceTypes()` |
