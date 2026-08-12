# ADR-0005: Framework integrations that need their own Composer type ship as separate packages

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-08-12                     |
| Spec     | — (architecture; informs a future TYPO3 integration spec, which lives in the package it governs) |
| Deciders | Maurice van Loon (maintainer)  |

## Context

This package is `provemark/content-credentials`, Composer `type: library`,
autoloading `Provemark\ContentCredentials\` from `src/`. It carries its Laravel
integration **inside** it, at `src/Laravel/`, and that has never caused a
problem: Laravel discovers the service provider through
`extra.laravel.providers`, which is a key in an existing file. It asks for no
Composer type and no files in the package root. Deptrac keeps `Core` from
depending on it, and `illuminate/*` stays in `require-dev`, so a consumer who
does not use Laravel is constrained by nothing.

TYPO3 was investigated on 2026-08-12 as a second integration target, on the
reasoning that CMS platforms are where media is processed and where existing
Content Credentials get destroyed by re-encoding. The code side turned out to
fit well — the Core is framework-agnostic and TYPO3's own constraints are
compatible:

- TYPO3 v14.3.6 requires `psr/http-client ^1.0.3`, `psr/http-factory ^1.1.0`
  and `psr/http-message ^1.1 || ^2.0`, all satisfied by this package, and ships
  Guzzle 7, which `php-http/discovery` finds.
- Its file layer dispatches PSR-14 events that carry exactly what an integration
  would need, including the original and the derivative in the same call.

The obstacle is not code. It is packaging:

- A TYPO3 extension must declare `type: typo3-cms-extension` (core system
  extensions use `typo3-cms-framework`) together with
  `extra['typo3/cms']['extension-key']`, so that `typo3/cms-composer-installers`
  registers it. Verified against `typo3/cms-filemetadata`.
- **Composer's `type` is a single value.** A package cannot be both a library
  and a TYPO3 extension.
- TYPO3 further expects `ext_emconf.php`, `Configuration/` and `ext_tables.sql`
  in the **package root** — where `src/`, `config/` and the `.gitattributes`
  dist rules already live.

So the question this ADR settles is not whether to support TYPO3. It is where
an integration lives when the platform makes demands on the package itself.

## Decision

1. **A framework integration that requires its own Composer `type`, or its own
   convention files in the package root, ships as a separate package** that
   depends on this one. The first such case would be
   `provemark/content-credentials-typo3`.
2. **`src/Laravel/` stays where it is, and is recorded as the exception rather
   than the precedent.** It qualifies precisely because Laravel needs only a key
   in `composer.json`. Read the other way round — "integrations live inside" —
   it would be a rule this package cannot keep.
3. **The seam an integration builds against is `ReaderInterface`,
   `SignerInterface` and the Core value objects.** An integration package
   depends on those and on nothing in `src/Laravel/`. This is the boundary
   Deptrac already enforces internally; the decision extends it outward.
4. **The feature spec for an integration lives in the package that implements
   it**, not here. This ADR is the only record this repository keeps of it.

## Consequences

- **Positive:** this package keeps one Composer type, one dist shape and one
  release cadence. An integration can track a platform's majors on its own
  schedule, which matters for TYPO3, where backend form structures shift between
  major versions. And the architectural work is already done — the Core was made
  framework-agnostic for exactly this, so the decision costs no refactoring.
- **Cost, and it is the real one:** a second repository means a second CI, a
  second release cadence, and the spec discipline set up a second time. For a
  project whose value rests on being carefully verified, that is not overhead to
  absorb lightly. It is the reason not to start an integration package
  speculatively — one should begin when a user asks, not when a platform looks
  promising.
- **Consumers install two packages,** so version compatibility between them
  becomes something to maintain: the integration package pins a range of this
  library, and a breaking change here is a release there.
- **The line generalises.** Drupal, Contao, TYPO3 and any other platform with
  its own installer type fall on the same side of it. The decision is about
  Composer packaging, not about TYPO3.

## Follow-ups (not part of this ADR)

- If a TYPO3 integration is built, its first spec decides two things this ADR
  deliberately leaves open: which TYPO3 major it targets, and whether version one
  reads only. Reading needs no signing key, no second process and no queue, and
  it does not touch the render path — which is the argument for starting there.
- The `php: ^8.3` floor excludes TYPO3 sites still on 8.2, which TYPO3 v14 still
  permits. That is a known exclusion, not a reason to lower the floor here.
- `psr/http-message: ^2.0` narrows TYPO3's permitted `^1.1 || ^2.0` to the 2.x
  line. A site running an extension pinned to `^1.1` would hit a resolution
  conflict; worth naming in an integration package's install notes.
