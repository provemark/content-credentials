# Contributing

Thanks for your interest in improving this library.

## Spec-driven development

This project is **spec-driven**: every feature starts as a spec in
[`specs/`](specs/) (from [`specs/TEMPLATE.md`](specs/TEMPLATE.md)) and is
approved before any implementation. Please open or reference a spec for
non-trivial changes rather than sending unscoped code. The rationale and
domain rules live in [`docs/`](docs/) and [`NOTES.md`](NOTES.md).

## Getting set up

```bash
composer install
composer check   # Pint (style) + PHPStan (level max) + Pest + Deptrac
```

`composer check` is the single definition of green — it must pass before a PR is
merged (CI runs it on PHP 8.3, 8.4 and 8.5).

## Guidelines

- **Tests first.** Add failing Pest tests tagged `->group('SPEC-###')` before the
  implementation; keep PHPStan at level max and Deptrac green (the `Core` layer
  must not depend on Laravel/Illuminate).
- Match the surrounding style; Pint enforces it (`composer format`).
- Target **PHP 8.3** — avoid 8.4/8.5-only features.
- Never commit real keys, production certificates, `.env`, or `vendor/`.

## Running the full chain locally

```bash
docker compose up -d --build   # signing service (test certs); older setups: docker-compose
php bin/e2e.php                 # library -> service -> verify with c2patool
```

## Cutting a release (maintainer)

Releases follow [SemVer](https://semver.org/). Only tag when a *consumer* gains
something — a feature, a bug fix, or security. Docs-only, CI-only or test-only
changes just land on `main` and stay under `[Unreleased]`; they do not get their
own tag (a tag notifies every downstream via Dependabot/Renovate).

When there is something worth releasing, do it in this order so the tagged dist
is self-consistent:

1. **Feature complete.** Every governing spec is `implemented` with its
   Traceability filled; anything with a service/e2e part is verified against a
   live run (`bin/e2e.php`), not just the unit suite.
2. **`composer check` is green** on the release commit.
3. **Update the docs in the same commit, *before* tagging** — README (and any
   `docs/`) for the new capability, and rename the CHANGELOG `[Unreleased]`
   section to `[X.Y.Z] - <date>` with fresh compare links. The tag must bundle
   the docs it describes; adding them *after* the tag leaves the dist README
   behind (and a published tag must never be moved to fix that).
4. **Tag and release.** `git tag -a vX.Y.Z`, push `main` + the tag, then create
   the GitHub Release from the CHANGELOG section.
5. **After the release.** Bump the `dev-main` branch-alias in `composer.json` to
   the next `X.Y-dev`; add a fresh empty `[Unreleased]` to the CHANGELOG; confirm
   Packagist picked up the tag and CI is green.
