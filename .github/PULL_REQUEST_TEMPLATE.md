## What & why

<!-- Summary of the change and the motivation. -->

## Spec reference

<!-- This project is spec-driven. Non-trivial / src changes reference the
     governing spec; a src change without a spec is a defect (CONTRIBUTING.md). -->

- Spec: SPEC-###  <!-- or "n/a — docs/tooling only" -->

## Checklist

- [ ] `composer check` is green (Pint + PHPStan level max + Pest + Deptrac)
- [ ] Tests added/updated and tagged `->group('SPEC-###')` (tests-first)
- [ ] PHPStan stays at level max; Deptrac boundary intact (Core must not depend on Laravel/Illuminate)
- [ ] Targets PHP 8.3 (no 8.4/8.5-only features)
- [ ] No real keys, certificates, or `.env` committed
- [ ] CHANGELOG updated if the change is user-facing
