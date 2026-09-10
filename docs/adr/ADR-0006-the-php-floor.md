# ADR-0006: The PHP floor stays `^8.3`

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-09-10                     |
| Spec     | — (support window; no spec, because nothing in `src/` changes either way) |
| Deciders | Maurice van Loon (maintainer)  |

## Context

`composer.json` has required `php: ^8.3` since the first release. ADR-0005
already noted the consequence in passing — "the `php: ^8.3` floor excludes TYPO3
sites still on 8.2" — and left it as a known exclusion. A downstream consumer
turned that from a note into a question.

`drupal/content_credentials` reads Content Credentials on Drupal sites through
this library's `ReaderInterface`. Its `content_credentials.info.yml` declares
`php: 8.3`, and says in a comment that the floor comes from this package rather
than from Drupal core. That is accurate, and it decides who can install the
module at all:

- Drupal 10.4, 10.5 and 10.6 all support **PHP 8.1, 8.2, 8.3 and 8.4**.
- Drupal 11.x and 12.x require **PHP 8.3** or higher.

So on Drupal 11 and 12 the floor excludes nobody. On Drupal 10 it excludes every
site still running 8.1 or 8.2, no matter which reading route the site picks. The
question was raised in `project/ai_disclosure` issue 32 on 2026-09-08 and left
open there.

Everything below was measured on 2026-09-10. Where a number is a count of sites
it comes from drupal.org's own usage page; where it is a resolution result it
comes from Composer.

### Lowering the floor costs nothing in `src/`

- Every `.php` file in `src/`, `tests/`, `bin/`, `config/` and `stubs/` passes
  `php -l` under **PHP 8.2.33** (`docker run --rm -v "$PWD":/app -w /app
  php:8.2-cli`). No 8.3-only syntax is used anywhere.
- PHPStan at level max with `phpVersion: 80200`, over `src/` and `tests/`,
  reports no errors.
- The suite runs on real 8.2: **390 passed, 6 skipped** in a `php:8.2-cli`
  container, with Deptrac and Pint green beside it. That total matches this
  machine exactly — 372 passed plus the 18 tests Pest 4 reports separately as
  deprecated — and there are no deprecations at all on 8.2, because the
  `illuminate/console` null-offset deprecation needs PHP 8.5.

### The cost is in the test toolchain, and it is a framework major

```
pestphp/pest[v4.0.0, ..., v4.7.8] require php ^8.3.0
```

**Pest 4 requires `^8.3`.** An 8.2 leg would have to run Pest 3, so require-dev
would become `pestphp/pest: ^3.0|^4.0` and two CI legs would exercise the suite
under a different test-framework major from the other nine (Pest 3.8.7 /
PHPUnit 11.5.56 against Pest 4.7.8 / PHPUnit 12.5.33). This is the mirror image
of the constraint that keeps Pest 5 out: there our floor is too low for a newer
Pest, here it would be too low for the current one.

`illuminate/*` 13 also requires `^8.3`, so an 8.2 leg can only test Laravel 11
and 12. The CI matrix would need its first `exclude` — `ci.yml` currently states
that none are needed — and would grow from nine cells to eleven.

### And the reach it buys closes in three months

- Week of 2026-08-30, drupal.org reports **474,292** sites. Of those, **209,588**
  run 10.x (143,560 of them 10.6) and **83,231** run 11.x or 12.x. Only the 10.x
  half can be on PHP 8.2 at all.
- **Drupal 10 reaches end of life on 9 December 2026**, the week Drupal 12.0.0
  and 11.5.0 are released.
- **PHP 8.2 loses security support on 31 December 2026** — twenty-two days later.
  PHP 8.1 is already end-of-life, so `^8.2` would not reach those sites either.

What share of the 10.x population actually runs 8.2 is **not measured**:
drupal.org publishes core versions per site, not PHP versions, and no substitute
source was found. It does not need measuring to decide this, because whatever the
share is, the window it lives in closes on both ends within three months.

## Decision

1. **The floor stays `php: ^8.3`.** No release lowers it, and the README badge,
   `docs/stability.md` and CONTRIBUTING keep saying 8.3.
2. **`pestphp/pest` stays at `^4.0`.** Widening it to `^3.0|^4.0` is only ever
   worth doing to enable a lower floor, so it is refused for the same reason.
3. **A downstream package inherits this floor and should say so**, the way
   `drupal/content_credentials` does in its `info.yml`. A consumer that hides
   the constraint moves the failure from install time to run time.
4. **Revisit only on a measured request**, not on a calendar. The trigger is a
   user who reports being unable to install *because of the floor* on a platform
   version that is itself still supported. Drupal 10 stops qualifying on
   9 December 2026; TYPO3 v14, which still permits 8.2, would qualify today if
   somebody asked.

## Consequences

- **Positive:** one test-framework major across every CI leg, one set of
  dependency versions to reason about, and no support obligation toward a PHP
  branch that is out of security support in the same quarter. The floor also
  keeps this package on versions where `readonly`, enums and the rest of the
  8.3 baseline behave as the code assumes.
- **Cost, stated plainly:** Drupal 10 sites on PHP 8.2 cannot install
  `drupal/content_credentials`, and that is a real exclusion for the next three
  months rather than a hypothetical one. The measured part of the answer is that
  the code would have worked; what would not have worked is testing it honestly.
- **The `^8.2` measurement is reproducible** — the commands are in this ADR and
  in the log — so a future revisit re-runs them rather than re-arguing them.
  Expect the Pest constraint to have moved by then; the floor question is
  really a question about the test toolchain's floor.

## Follow-ups (not part of this ADR)

- ADR-0005's note about TYPO3 sites on 8.2 stands and now has a decision behind
  it rather than an aside.
- If the floor ever does rise — to `^8.4`, once nothing supported needs 8.3 —
  that is the same kind of decision and belongs in an ADR of its own, together
  with what it unblocks. Pest 5 requires `^8.4`, so that direction has a
  concrete prize attached.
