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
merged (CI runs it on PHP 8.3 and 8.4).

## Guidelines

- **Tests first.** Add failing Pest tests tagged `->group('SPEC-###')` before the
  implementation; keep PHPStan at level max and Deptrac green (the `Core` layer
  must not depend on Laravel/Illuminate).
- Match the surrounding style; Pint enforces it (`composer format`).
- Target **PHP 8.3** — avoid 8.4/8.5-only features.
- Never commit real keys, production certificates, `.env`, or `vendor/`.

## Running the full chain locally

```bash
docker compose up -d --build   # signing service (test certs)
php bin/e2e.php                 # library -> service -> verify with c2patool
```
