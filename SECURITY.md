# Security Policy

## Supported versions

This project is pre-1.0. Security fixes are applied to the latest `0.x` release.

## Reporting a vulnerability

Please report security issues **privately** — do not open a public issue.

Use GitHub's private vulnerability reporting on this repository
(**Security → Report a vulnerability**). Include steps to reproduce and the
affected version. You'll get an acknowledgement as soon as possible.

## Dependency vulnerabilities

Two dependency trees reach the signing path, and both are scanned:

| Tree | Why it matters | Tools |
|---|---|---|
| `service/` (npm) | ships inside the container that holds the private key | Dependabot, `npm audit` |
| root (Composer) | runs inside the consumer's application | Dependabot, `composer audit` |
| GitHub Actions | run with this repository's credentials | Dependabot |

- **Dependabot** (`.github/dependabot.yml`) scans all three weekly and opens a
  pull request whenever a fix exists.
- **`.github/workflows/audit.yml`** runs `npm audit --omit=dev` and
  `composer audit` on a weekly schedule. This exists for the case Dependabot
  cannot act on: an advisory with **no fix available**. It is deliberately
  non-blocking, so an unfixable advisory is visible without blocking unrelated
  work on `main`.

**Remediation policy.** An advisory of **CRITICAL** or **HIGH** severity against
a dependency that reaches the signing path is fixed, or explicitly mitigated and
documented here, before the next release. Lower severities are handled in the
normal update flow.

This is the policy required by the C2PA Generator Product Security Requirements
at Assurance Level 1, requirement **O.3** — see "Conformance alignment" in the
README for what that programme is and who it applies to.

## Handling of keys and secrets

This library never handles production signing keys directly — signing is
delegated to the separate signing service. When reporting or reproducing:

- **Never** include real private keys, production certificates, or API tokens in
  reports, issues, or pull requests. The repository intentionally contains only
  the c2pa-rs **test** certificates; the private test key is gitignored.
- The `CONTENTAUTH_API_KEY` and service URL are configuration, provided via the
  environment; they must not be committed.
