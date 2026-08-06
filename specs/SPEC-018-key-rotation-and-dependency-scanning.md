# SPEC-018: Key rotation and dependency scanning (Assurance Level 1 alignment)

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-06                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The C2PA Conformance Program publishes the *Generator Product Security
Requirements* that any product must meet to appear on the Conforming Products
List at Assurance Level 1. Read on 2026-08-06 from
`c2pa-org/conformance-public`, `docs/v0.2/`.

This package cannot itself be listed — a Generator Product is "the set of
software, hardware, and platform configurations […] that work together as a
system to produce digital Assets", and it "is always the Signer" and the entity
named on the list. That is our *users'* deployment, not our library. But two
Level 1 requirements land squarely on code and process we own, and today we meet
neither. Closing them costs little and is the difference between a user being
able to reuse our architecture in their submission and having to work around it.

### O.2 — the signing key: rotation, and knowing which key is live

> "GP TOE SHALL be capable of rotating the claim signing key."

The service reads `SIGNING_CERT_PATH` and `SIGNING_KEY_PATH` **once at startup**
into module-level constants (`service/server.js:70-71`). There is no reload path.
Rotation is therefore "replace the mounted files and restart the container" —
which does satisfy *capable of rotating*, but it is undocumented, and nothing
anywhere reports which key is actually in use.

That second half is the real defect, and it has a shape this project has now met
three times (SPEC-013's `isTrusted()`, SPEC-014's silent trust settings, the
vacuous tests in NOTES Step 20): **an operator who believes they have rotated has
no way to discover that they have not.** A mount that did not take, a restart
that reused a cached layer, a path typo that resolved to the old file — every one
of those produces a service that keeps signing with the superseded key and looks
identical from outside.

Note what the requirement does *not* ask for, and what this spec therefore does
not build: hot reload. Restart-based rotation is conformant. What is missing is
that it be **documented and observable**.

The rest of O.2 we already meet, and it is worth recording where, because a user
assembling a GPSA document will need exactly this mapping:

| O.2 requirement | Where |
|---|---|
| key stored encrypted at rest, or held by a discrete key-management component "with an unrelated attack surface" | The isolated signing service *is* that discrete component (ADR-0003); the key never enters the PHP process |
| access controlled by least privilege | Read-only mount, loopback-only publication |
| capable of rotation | **Gap — this spec** |

### O.3 — dependency vulnerability scanning

> "Applicant SHALL ensure a Software Composition Analysis (SCA) or Software Bill
> of Materials (SBOM) analysis is performed to detect vulnerabilities […]"
> "Applicant SHALL ensure that applicable fixes or other mitigations are applied
> to any Claim Generator security vulnerabilities detected with a CRITICAL or
> HIGH severity […]"
> Static evidence: "Applicant SHALL document […] the SCA/SBOM dependency
> vulnerability scanning tools used during the Claim Generator build or
> integration process."

There is no automated scanning in this repository. `.github/` contains
`workflows/ci.yml`, an issue template and a PR template — no `dependabot.yml`,
and no advisory step in CI. The `npm audit` finding in NOTES Step 9 was found by
hand, in passing, during an unrelated version bump. That is not a process.

This matters more than the generic case, because of the specific finding in
NOTES Step 9: a transitive advisory whose fix reached the container **by luck**
rather than by construction. Step 10 fixed the mechanism (`npm ci` against the
lockfile) but not the detection.

Two dependency trees are in scope and both feed the signing path: `service/`
(npm, ships in the container that holds the key) and the root `composer.json`
(PHP, runs in the consumer's application). GitHub Actions versions are a third,
smaller surface.

## Scope

**In scope**

- Reporting the identity of the live signing certificate on `GET /health`, so a
  rotation is verifiable from outside the container.
- A documented rotation procedure in the README, stating what it costs
  (in-flight requests are lost) and how to confirm it took effect.
- Automated dependency scanning for `service/` (npm), the root package
  (Composer) and GitHub Actions, with a stated remediation policy for
  CRITICAL/HIGH.
- A short README section mapping this service onto the O.2 and O.3 Level 1
  requirements, for a user assembling a GPSA document.
- A CHANGELOG entry under `Service`.

**Out of scope** (each needs its own spec before it may be built)

- **Hot reload of the key** (SIGHUP, file watching, or a rotation endpoint). Not
  required by Level 1, and every mechanism has failure modes — a partially
  written PEM, a reload mid-signature — that need their own criteria. Restart is
  conformant and this spec says so explicitly rather than leaving it implied.
- Automated certificate enrollment with a Certification Authority (O.1). Not
  applicable while certificates are supplied as mounted files.
- Assurance Level 2, which requires hardware-backed key storage and attestation.
- Publishing a machine-readable SBOM artifact (CycloneDX/SPDX). O.3 is satisfied
  by SCA *or* SBOM analysis; a published artifact is a separate deliverable.
- Actually applying to the Conformance Program.
- Any change to `src/`. Nothing here reaches the PHP client.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-018')`, with the service-facing
criteria in the integration group.

- **AC1 — the live signing certificate is identifiable**
  - Given a running service with a configured `SIGNING_CERT_PATH`
  - When `GET /health` is called
  - Then the response carries a stable, non-secret identifier of the certificate
    actually loaded — the SHA-256 fingerprint of its DER encoding, and its
    `notAfter` — such that two services with different certificates report
    different values

- **AC2 — the identifier discriminates** *(the criterion that gives AC1 meaning)*
  - Given two services started against two different signing certificates
  - When both are asked for `/health`
  - Then the fingerprints differ
  - And a service restarted against the *same* certificate reports the *same*
    fingerprint, so the value tracks the key and not the process

- **AC3 — nothing secret is exposed** *(error path)*
  - Given the reported certificate identity
  - When the `/health` response is inspected, by an unauthenticated caller
  - Then it contains no private-key material, no PEM body, and no filesystem
    path; the fingerprint is derived from the *certificate*, which is public by
    construction — it is embedded in every manifest this service signs

- **AC4 — rotation is documented and confirmable**
  - Given an operator following the README's rotation procedure
  - When they have completed it
  - Then the documented steps state that in-flight requests are lost, and end
    with a check against `/health` that distinguishes a successful rotation from
    one that silently did not take
  - And the README states that restart-based rotation satisfies the Level 1
    requirement, so a reader does not go looking for a reload endpoint

- **AC5 — dependency scanning runs without anyone remembering**
  - Given the repository
  - When a vulnerable dependency is published in `service/`'s npm tree, the root
    Composer tree, or a pinned GitHub Action
  - Then an automated scan raises it without a maintainer initiating anything,
    covering all three ecosystems

- **AC6 — the remediation policy is stated**
  - Given a CRITICAL or HIGH advisory against a dependency that reaches the
    signing path
  - When a maintainer reads the contributing/security documentation
  - Then it states the O.3 obligation — fixes or mitigations applied — and names
    the scanning tools in use, which is the static evidence O.3 requires

- **AC7 — the Level 1 mapping is documented**
  - Given a user assembling a Generator Product Security Architecture document
  - When they read the README
  - Then it states plainly that the *library* cannot be listed and their
    deployment is the Generator Product, and maps this service's key handling
    onto the O.2 requirements it satisfies

## API sketch

Illustrative only. Confined to `service/server.js`, `.github/`, `README.md`.

```js
// service/server.js — computed once, next to the existing PEM validation.
// crypto.X509Certificate is node built-in; no new dependency.
const leaf = new crypto.X509Certificate(certificate);
const certFingerprint = leaf.fingerprint256.replace(/:/g, '').toLowerCase();
const certNotAfter = leaf.validTo;
```

```json
// GET /health gains one block, alongside `limits` from SPEC-015
{
  "status": "ok",
  "signing_alg": "es256",
  "signing_cert": {
    "fingerprint_sha256": "a1b2…",
    "not_after": "Jan 1 00:00:00 2027 GMT"
  }
}
```

```yaml
# .github/dependabot.yml
version: 2
updates:
  - package-ecosystem: npm
    directory: /service
    schedule: { interval: weekly }
  - package-ecosystem: composer
    directory: /
    schedule: { interval: weekly }
  - package-ecosystem: github-actions
    directory: /
    schedule: { interval: weekly }
```

## Open questions

All four were settled at approval, before any test was written. Recorded as
decisions rather than deleted, because the reasoning is the useful part.

- **Does `notAfter` belong on an unauthenticated endpoint?** **Yes.** It is
  public information — the whole certificate travels inside every signed asset —
  and it is the field an operator most needs *before* a rotation becomes urgent.
- **Dependabot, or `npm audit` / `composer audit` as a CI step?** **Both, with
  different jobs.** Dependabot opens PRs across all three ecosystems with no
  maintenance. A scheduled audit job additionally reports advisories that have no
  fix available, which Dependabot cannot act on and which would otherwise go
  unseen. That job is **non-blocking**: an unfixable advisory must be visible
  without turning `main` red and blocking unrelated work.
- **Where does the remediation policy live?** **`SECURITY.md`**, which is where a
  reader looks and where GitHub links, with a pointer from `CONTRIBUTING.md`.
- **Should the fingerprint cover the whole chain or the leaf?** **The leaf.** It
  is what signs and what rotates. A chain digest also changes when an
  intermediate is renewed without the signing key changing, which is a different
  event and would report as a rotation that did not happen.

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