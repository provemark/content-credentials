# Step 7 — Property-based test suite (Eris) + a real service bug it caught

Added a property-based (PBT) suite with `giorgiosironi/eris` (^1.1, dev-only):
`tests/Unit/Property/` (stateless + a model-based builder suite) and
`tests/Integration/Property/` (stateful chain over the real service). Grouped
`pbt` / `stateful` / `provenance` / `integration`.

### Eris ↔ PHPStan: excluded, not weakened
The Eris DSL is untypeable under PHPStan level max: `Eris\Generators::*`
factories are not typed to return `Eris\Generator` (inferred `mixed`),
`Eris\Generator` is a bare `@template T`, and Pest's `uses(TestTrait::class)`
hides `forAll()`/`then()` from `$this`. So `tests/{Unit,Integration}/Property/*`
are in `phpstan.neon` `excludePaths` — `src/` and every other test path stay
strict. The properties are guarded by Pest itself.

### Eris 1.1 gotcha: `limitTo()` is on the TestTrait, not the ForAll chain
`$this->forAll(...)->limitTo(8)` throws `BadMethodCallException: Method
Eris\Quantifier\ForAll::limitTo does not exist`. In 1.1, `limitTo($int)` is a
`protected` method on `TestTrait` that sets `$this->iterations`, which
`forAll()` then reads. Correct usage: `$this->limitTo(8);` **before**
`$this->forAll(...)`. Default is 100 iterations.

### Integration group is opt-in (kept out of `composer check`)
The `integration` tests need `docker compose up` and do real HTTP round-trips.
They are excluded from the default suite via the composer script
(`"test": "pest --exclude-group=integration"`), so `composer check` stays fast
and deterministic. Run them explicitly: `vendor/bin/pest --group=provenance`.
NB: a `<groups><exclude>` in `phpunit.xml` does NOT work here — in this
PHPUnit/Pest version it also suppresses an explicit CLI `--group=provenance`
(exclude wins), so the opt-in command would find no tests. The composer-script
`--exclude-group` leaves a direct `vendor/bin/pest --group=...` unaffected.

### ⚠️ Real bug found: `/v1/read` returns 500 on a manifest-less asset
The stateful provenance property drives `read` on the *unsigned* fixture (a
generated sequence can start with `read`). Against the live service this fails:

```
HTTP 500  {"error":"Cannot read properties of null (reading 'json')"}
```

Root cause in `service/server.js` `POST /v1/read`:
`const reader = await Reader.fromAsset(...); const json = reader.json();` —
`Reader.fromAsset()` returns **null** for an asset with no C2PA manifest, so
`reader.json()` throws. This VIOLATES the SPEC-003 read contract: absent C2PA
data must be an empty report (HTTP 200, empty store), which the PHP client and
its unit test (`SigningServiceReaderTest.php:142`, mocking `readStoreResponse([])`)
already implement. The unit test missed it because the mock was more forgiving
than the real service — exactly the divergence integration PBT exists to catch.

Written up and fixed under **SPEC-010** (implemented). The fix is a null-guard in
`POST /v1/read`: `if (!reader) return res.json({})` — confirmed that
`Reader.fromAsset()` resolves to **null** (not a throw) for a manifest-less
asset, so `.json()` was the crash. `{}` decodes client-side to an empty
`ManifestReport` (`hasManifest() === false`), matching the SPEC-003 contract and
the `readStoreResponse([])` unit mock. Verified against the rebuilt service:
`/v1/read` on the unsigned fixture now returns `{}` / HTTP 200, the provenance
property is green, and `tests/Integration/ReadEmptyManifestTest.php` (AC1–AC3)
passes. Requires a service rebuild to take effect (`docker-compose up -d --build`;
note this machine uses the `docker-compose` v1 binary, not `docker compose`).

---

[← Step 6](step-06-spec-007-tsa-timestamping.md) · [index](../NOTES.md) · [Step 8 →](step-08-c2pa-node-0-8-0.md)
