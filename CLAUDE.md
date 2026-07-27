
# CLAUDE.md — content-credentials

PHP library for C2PA Content Credentials: build manifests, sign, read, and
verify media assets. Primary use case: machine-readable marking of
AI-generated content under EU AI Act, Article 50. Ships with a minimal Node
signing service (`service/`) — the working reference implementation of the
CAI wp-plugin `/v1/sign` contract (upstream's own service does not build;
see @NOTES.md, Step 1).

This is the spec-driven REBUILD of a proven spike. The chain works; the spike
learnings live in @NOTES.md and are summarized in Domain rules below. Trust
NOTES.md over memory for anything C2PA-specific.

## Spec-driven development (non-negotiable)

1. **Spec.** Every feature starts as `specs/SPEC-###-slug.md` from
   @specs/TEMPLATE.md, status `draft`.
2. **Approval.** No implementation code while the governing spec is `draft`.
   The maintainer flips it to `approved`.
3. **Tests first.** Failing Pest tests before implementation, tagged
   `->group('SPEC-###')`. A test without a spec reference is a defect.
4. **Implement.** Only what the spec covers. Spec gap or contradiction found
   mid-implementation → STOP, amend the spec, back to step 2.
5. **Verify.** `composer check` green, then set the spec `implemented` and
   fill its Traceability section.

Scope discipline: requests outside any approved spec get a draft spec first —
never a "quick addition". Commits touching `src/` or `service/src/` carry the
spec ID: `SPEC-012: ...`.

## Commands

```bash
composer check             # format (Pint) + PHPStan level max + Pest; the
                           # single definition of green
composer test -- --group=SPEC-012
docker compose up -d --build   # signing service on :3000 (test certs)
bin/verify.sh <file>       # authoritative c2patool verify, trust ENABLED
                           # via certs/c2pa-trust.settings.json
composer deptrac           # architecture boundary check (part of check)
```

## Architecture (single repo, hard boundary)

```
src/
  Core/            # framework-agnostic: Manifest/, Signing/, Reading/, Support/
  Laravel/         # provider, facade, config, jobs, artisan commands
service/           # Node signing service (@contentauth/c2pa-node >= 0.7)
specs/  docs/  tests/Fixtures/   # certs = c2pa-rs es256 TEST certs only
```

- Deptrac enforces: `Core` MUST NOT depend on `Laravel` (or any illuminate
  package). Laravel depends on Core. illuminate/* only in require-dev +
  suggest; provider registers via package auto-discovery.
- Core signing abstraction: `SignerInterface`; v1 ships one adapter,
  `SigningServiceSigner` (PSR-18 client for `service/`). FFI is out of scope
  until a spec says otherwise.
- PHP target: ^8.3 (dev machines may run 8.5 — use no 8.4/8.5-only features;
  note curl_close() is a deprecated no-op). All files `strict_types=1`,
  public API `final` + interfaces, value objects `readonly`, PHPStan level
  max with no un-annotated ignores.

## Domain rules (verified in the spike — do not "improve" from memory)

- Manifests are **claim v2** (c2pa-rs ≥ 0.90): actions label is
  `c2pa.actions.v2`, and the FIRST action MUST be `c2pa.created` or
  `c2pa.opened`, else `assertion.action.malformed`.
- AI-generated marking = first action `c2pa.created` with `digitalSourceType`
  set to the FULL IPTC URI
  `http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
  and a `softwareAgent { name }`. This one assertion satisfies both the
  Article 50 marking and claim-v2 well-formedness. Regressions here are
  critical.
- Service contract `/v1/sign`: Bearer auth, JSON `{content: base64,
  mime_type, extra_assertions[], ...}` → `{signed_content: base64}`. Our
  service deliberately does NOT inject a hardcoded actions assertion
  (upstream divergence, documented in NOTES.md): the PHP client owns the
  actions assertion, so exactly one exists.
- In `service/`: `builder.sign(signer, source, dest)` RETURNS the JUMBF
  manifest-store bytes, NOT the signed asset — the signed asset is written
  to `dest`. Never return sign()'s value as the signed file (symptom:
  read-back fails with header `6A 75 6D 62` = "jumb").
- "Valid signature" ≠ "trusted cert": test certs always yield
  `signingCredential.untrusted` unless verifying with
  `certs/c2pa-trust.settings.json` (which embeds PEM/EKU file CONTENTS as
  strings, not paths). NEVER run `c2patool init trust` in this project — it
  fetches the production trust list, which correctly rejects test certs.
- Any post-sign mutation of an asset (re-encode, optimize, resize)
  invalidates the manifest — in code and in docs examples.
- c2pa-node auto-adds a `c2pa.thumbnail.claim` assertion; expected, harmless.
- Not yet implemented, spec required before adding: TSA timestamping
  (optional `tsaUrl` on `LocalSigner.newSigner`), non-PNG/JPEG asset types.

## Security (hard constraints)

- NEVER create, fetch, or commit real private keys or production certs.
  `tests/Fixtures/` and `certs/` hold c2pa-rs es256 TEST material only;
  `es256_private.key` stays gitignored. Trust-settings files contain only
  public test CA certs and are safe to commit.
- Service auth token (`CONTENTAUTH_API_KEY`) and cert paths come from env
  (`.env.example` documents them); never log tokens, key material, or full
  manifests at info level.
- Treat all parsed manifest/service input as untrusted: size-limit and
  validate; every parser spec includes malformed-input acceptance criteria.

## Environment gotchas

- npm on dev machines may block install scripts (allow-scripts):
  `@contentauth/c2pa-node`'s postinstall fetches the native binary. If a
  clean install can't find it: `npm approve-scripts @contentauth/c2pa-node`
  or `npm rebuild`. Non-issue inside Docker.
- The old unscoped `c2pa-node` npm package is dead (EOL at 0.5.26); the
  maintained one is `@contentauth/c2pa-node` (repo: c2pa-node-v2). Never
  "fix" package.json toward the unscoped name.

## What NOT to do

- No new runtime dependencies without a spec + ADR in `docs/adr/`.
- Do not weaken PHPStan/Deptrac, skip tests, or mark specs `implemented`
  when criteria are unmet — report the gap instead.
- Do not edit `approved` specs except their Traceability section; propose
  amendments as a diff.
- Do not invent C2PA structures beyond Domain rules; when NOTES.md and
  @docs/c2pa-primer.md are insufficient, ask instead of guessing.
