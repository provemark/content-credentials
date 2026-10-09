# ADR-0007: provemark/c2pa-verifier as an optional reader, never a requirement

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-10-07                     |
| Spec     | SPEC-042                       |
| Deciders | Maurice van Loon (maintainer)  |

## Context

SPEC-042 adds `C2paVerifierReader`, a third `ReaderInterface` implementation
that reads through `provemark/c2pa-verifier`, a pure-PHP C2PA verifier
(MIT, `php ^8.3`, `ext-openssl`, `ext-mbstring`, no runtime dependencies). This
repository's rule is that a new dependency needs a spec and an ADR. The
question is what kind of dependency it is.

Three facts decide it:

- **Most installs do not need it.** The default reader is the signing service,
  and a host that signs runs that service anyway. The verifier is for hosts
  that can run neither the service nor `ext-c2pa`.
- **It requires `ext-mbstring`, and this package deliberately does not.**
  `ServiceError::cap()` uses a `/u` regex rather than `mb_substr()` for that
  reason. A hard requirement would add an extension to every install.
- **The verifier's README fixes the direction:** "The dependency, if any,
  points from the library to the verifier, never the other way." Its own
  suite has this package in `require-dev`, to feed its report through
  `ManifestStoreParser`. That is a test dependency in the other direction,
  not a cycle in what either package installs.

## Decision

- `provemark/c2pa-verifier` goes in **`suggest`** (there since v0.15.2) and in
  **`require-dev`** at `^0.5`, never in `require`. The verifier's own promise
  is that a change breaking its recorded API will be `0.6.0`.
- `C2paVerifierReader::isAvailable()` checks for the verifier's class. Selecting
  the reader without the package fails at construction with a named exception
  that says what to install. It never falls back to another reader, for the
  reason SPEC-019 AC5 gives for the extension.
- `src/Core` may reference `Provemark\C2paVerifier`, as it already references
  `Automattic\VIP\C2PA` for `ExtC2paReader`. Deptrac's layers cover `src/`
  only, so neither shows up there; the boundary that matters, Core not
  depending on Laravel, is unchanged.
- Only `C2paVerifierReader` touches the verifier, and only through the
  classes the verifier lists as its contract (`Verifier`,
  `VerificationReport`, `TrustSettings`, `TrustException`). Nothing reads
  `$report->store`, which it marks `@internal`.

## Consequences

- An install that never selects `verifier` gains nothing and loses nothing:
  no new extension, no new package.
- `require-dev` means every CI leg has it, so the SPEC-042 comparison against
  the service runs in blocking profiles. It is the first reader comparison
  that can gate `main`; the SPEC-019 one depends on a download and cannot.
- A host that installs the verifier picks its version through its own
  lockfile, and `^0.5` in our `suggest` text is advice rather than a
  constraint. A verifier minor that reads a new format changes nothing here
  until SPEC-042 AC3's refused set is amended.
- Two packages by the same maintainer now test against each other. A
  breaking change on either side shows up in the other's suite, which is
  the point, but releases have to be ordered: the verifier first, then this
  package's constraint.

## Addendum (2026-10-08): the tested range is enforced, not advised

`suggest` carries no constraint, so a host following VerifierMissingException's
`composer require provemark/c2pa-verifier` would take 0.6 the day it appears,
and VerifierOutcome maps v0.5's report shape. A changed shape can turn a
signed file into an empty report. So `composer.json` gains
`"conflict": {"provemark/c2pa-verifier": "<0.5 || >=0.6"}`: Composer refuses
an untested minor instead of installing it. Raising the range is a release of
this package, after re-running the SPEC-042 measurements. A test pins that
the `conflict` range and the `require-dev` constraint describe the same range.

The cost, accepted: when the verifier ships 0.6.0, a host can use it only
after this package releases a widened constraint. The two releases are
ordered, which the Consequences above already said for the other direction.

That happened on 2026-10-09: the verifier released 0.6.0, the SPEC-042
measurements were re-run against it, and the range moved to `^0.6`
(`conflict` `<0.6 || >=0.7`, SPEC-042 Amendment 4).
