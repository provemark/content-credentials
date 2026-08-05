# C2PA Signing Spike — NOTES

Running log of friction points, gotchas and decisions. This file is a
first-class deliverable: it feeds the later specs and a public article.
Newest findings appended per step.

Environment (verified 2026-07-27):
- macOS (darwin 25.5.0), zsh
- Docker 29.6.2 + Docker Compose 5.3.1, daemon running
- PHP 8.5.8 CLI (task asked for 8.3; 8.5 is what's installed — fine for a spike)
- c2patool: NOT installed. cargo: absent. (Prebuilt universal-apple-darwin
  binary is available from c2pa-rs releases, currently c2patool-v0.27.3.)

---

## Step 1 — Investigate the wp-plugin signing-service (github.com/contentauth/wp-plugin)

### What the service is
A small standalone Node/Express HTTP service under `signing-service/`. It holds
the private key and performs C2PA (and optional CAWG) signing on behalf of the
WordPress plugin. It has **no WordPress runtime dependency** — it's a plain
Node service — so *architecturally* it is meant to run standalone via Docker.

Source layout (`signing-service/src/`):
- `server.js`   — Express app, routes, auth wiring
- `signer.js`   — builds the manifest + calls c2pa-node to sign
- `reader.js`   — reads back a manifest (for verification)
- `auth.js`     — Bearer-token middleware (`CONTENTAUTH_API_KEY`)
- `cawg.js`     — CAWG organisational identity assertion builder
- `key-providers/{index,local,aws-kms}.js` — pluggable key source

### The `/v1/sign` API contract (from server.js)
`POST /v1/sign`, `Authorization: Bearer <CONTENTAUTH_API_KEY>`, JSON body:

| field              | req? | meaning                                             |
|--------------------|------|-----------------------------------------------------|
| `content`          | yes  | **base64** of the raw file bytes                    |
| `mime_type`        | yes  | e.g. `image/png`                                    |
| `signature_type`   | no   | `product` (default) \| `cawg_org` \| `both`         |
| `creator_name`     | no   | prepended to the claim_generator string             |
| `org_name`/`org_url`| for cawg | required only for `cawg_org`/`both`            |
| `org_credential`   | no   | W3C VC JSON string (CAWG)                            |
| `extra_assertions` | no   | **array of raw C2PA assertion objects, appended**   |

Response: `{ signed_content: <base64>, manifest_url: null }`.
There is also `POST /v1/read` (`{content, mime_type}` → decoded manifest) and a
public `GET /health`.

### How it signs (signer.js)
- Reads cert+key via the `local` key provider (PEM cert chain + PEM private key
  from `SIGNING_CERT_PATH` / `SIGNING_KEY_PATH`).
- Algorithm from `CONTENTAUTH_SIGN_ALG` (default `ps256`); must match the key.
- **Always injects a hardcoded `c2pa.actions` assertion** with a single
  `c2pa.published` action and **no `digitalSourceType`**. Then appends whatever
  is in `extra_assertions`. There is **no first-class field to set the action
  or the digitalSourceType** — the only lever is `extra_assertions`.

### Test-cert situation (verified)
- c2pa-rs ships test certs in `cli/sample/`: `es256_certs.pem` +
  `es256_private.key` (**ES256**), plus `trust_anchors.pem` and
  `allowed_list.pem`. So for test signing: `CONTENTAUTH_SIGN_ALG=es256`, mount
  those two PEMs as `secrets/signing.crt` and `secrets/signing.key`.
- docker-compose already wires `secrets/signing.crt|key` and requires
  `CONTENTAUTH_API_KEY` to be set.
- Trust nuance to expect later: test certs give a cryptographically **valid
  signature** but are **not on any production trust list**, so a verifier will
  flag the cert as untrusted unless pointed at `trust_anchors.pem`. "Valid
  signature" (DoD) ≠ "trusted cert".

### AI-marking structure (verified, not from memory)
For EU AI Act Art. 50 / AI-generated marking the correct shape is:
```json
{
  "label": "c2pa.actions.v2",
  "data": { "actions": [
    { "action": "c2pa.created",
      "digitalSourceType": "http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia",
      "softwareAgent": { "name": "<tool>" } }
  ] }
}
```
`digitalSourceType` (added to c2pa actions in spec 1.3) also works inside a v1
`c2pa.actions`. Confirmed against ContentAuth opensource docs + C2PA guidance.

### ⛔ BLOCKERS — the service does NOT build/run as-is
1. **`c2pa-node` pinned to `^1.0.0`, which does not exist.** npm's `c2pa-node`
   tops out at **0.5.26** (`latest`). `^1.0.0` cannot resolve → install fails.
2. **`c2pa-node` is deprecated** — replaced by **`c2pa-node-v2`**. The 0.x line
   is EOL.
3. **No `package-lock.json` committed**, but the Dockerfile runs `npm ci`, which
   *requires* a lockfile → build fails even before the version issue.
4. **The code targets an unreleased API.** `signer.js` calls
   `createC2pa({ signer: { type: <SigningAlgorithm>, certificate, privateKey } })`.
   The only published (0.5.x) API expects
   `{ type: 'local', certificate, privateKey, algorithm: SigningAlgorithm.ES256 }`
   — i.e. `type` must be `'local'` and the algorithm goes in its own field.
   As written it would throw against real c2pa-node.

**Conclusion:** the `signing-service/` is *aspirational scaffolding* pinned to a
phantom `c2pa-node@1.0.0`; it has almost certainly never been run. It is **not
usable standalone without patching application logic**, not just config. Per the
spike's rules I stopped here to choose a direction rather than fork/patch it
unilaterally.

### DECISION (chosen with user): direction B
Build a **minimal Node HTTP signing service** on the *maintained* library,
mirroring the wp-plugin `/v1/sign` contract, so the PHP→HTTP→sign→verify chain is
proven on real code. The upstream `signing-service/` is kept only as reference.

### Verified c2pa-node-v2 API (the real library)
- Maintained package is **`@contentauth/c2pa-node`** (currently **0.7.0**); the
  repo is `contentauth/c2pa-node-v2`. The old unscoped `c2pa-node` is dead.
- API (verified against the repo's `Builder.spec.ts`, not from memory):
  - `LocalSigner.newSigner(certChainBuf, privateKeyBuf, "es256" [, tsaUrl])`
  - `Builder.withJson({ claim_generator_info:[{name,version}], format,
    assertions:[{label,data}], ingredients:[] , ... })`  (also
    `builder.addAssertion(label, data)`)
  - Asset source object: `{ buffer, mimeType }`; destination: `{ path }` or
    `{ buffer }`.
  - `const signedBytes = builder.sign(signer, source, dest)` — returns the
    signed bytes and writes to `dest.path`.
  - Read/verify: `const r = await Reader.fromAsset({buffer,mimeType});
    r.json(); r.getActive()`.

### Divergence from upstream contract (deliberate, documented)
My minimal service will NOT inject a hardcoded `c2pa.published` actions
assertion. The client (PHP) supplies the assertions (incl. the AI
`c2pa.actions.v2` marking) so the manifest has exactly one, correct actions
assertion — avoiding the upstream double-actions friction.

---

## Step 2/3 — Build the service + client (friction log)

### npm install-script hardening (gotcha)
This machine's npm blocks package install scripts by default
(`allow-scripts`). `@contentauth/c2pa-node` has a `postinstall` that fetches the
prebuilt native `.node` binary. npm printed a warning that the script was "not
yet covered by allowScripts" — but signing still worked, so the binary was
present. If a clean install ever yields a "cannot find native binary" error, run
`npm approve-scripts @contentauth/c2pa-node` (or `npm rebuild`) first. In Docker
this is a non-issue (scripts run during build).

### Signing works locally (real library + test certs) ✅
`LocalSigner.newSigner(cert, key, "es256")` + `Builder.withJson(...)` +
`builder.sign(...)` produced a 45 KB signed PNG. c2patool 0.27.3 confirms:
`claimSignature.validated` = **"claim signature valid"** → DoD (1) met.

### Two expected c2patool findings
- `signingCredential.untrusted` — the test certs are not on any trust list.
  This is EXPECTED and does not affect signature validity. Clearing it needs
  c2patool trust config (a `c2pa.toml` settings file with `verify_trust=true` +
  the sample `trust_anchors.pem`/`allowed_list.pem`); c2patool 0.27 moved trust
  under a `trust` sub-command / `--settings` file. Left as an OPEN item — not
  required for "valid signature".
- `assertion.action.malformed: "first action must be created or opened"` —
  appeared only because the smoke test sent *empty* assertions. C2PA claim v2
  REQUIRES an actions assertion whose first action is `c2pa.created`/`opened`.
  The spike's AI payload (`c2pa.created` + digitalSourceType) satisfies exactly
  this — so the marking we need is also what makes the manifest well-formed.

### claim_version is 2
c2pa-rs 0.90 / c2pa-node 0.7 emit **claim v2** manifests. So the actions label
is `c2pa.actions.v2` and the first action must be created/opened.

### ⚠️ Biggest gotcha: `builder.sign()` return value is NOT the signed image
`builder.sign(signer, source, dest)` **returns the C2PA manifest store bytes
(JUMBF)**, not the signed asset. The signed ASSET is written to `dest` (`{path}`
or `{buffer}`). First attempt returned the sign() value as `signed_content` →
`out/signed.png` was actually the raw manifest store, and read-back failed with
`invalid header … got [.. 6A 75 6D 62]` (`6A 75 6D 62` = ASCII "jumb"). Fix: sign
to a temp file and read that file back as the signed image. Easy to miss because
the `Builder.spec.ts` example only asserts `bytes.length > 0`.

### PHP 8.5 deprecation
`curl_close()` is deprecated (no-op since PHP 8.0). Harmless but noisy; removed
from the client. Worth knowing when the real code targets 8.3 vs 8.5.

---

## Step 4/5 — End-to-end result

**DONE.** `out/signed.png` (47 KB PNG) verified by **c2patool 0.27.3**:
- (1) `claimSignature.validated` = "claim signature valid" → **valid signature ✓**
- (2) assertion `c2pa.actions.v2` → action `c2pa.created` with
  `digitalSourceType = http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
  → **EU AI Act Art. 50 AI marking ✓**
- Signed by: `C2PA Test Signing Cert` / CN `C2PA Signer`, alg `Es256`.
- Remaining status `signingCredential.untrusted` = expected (test cert, no trust
  list). "Valid signature" ≠ "trusted cert" — both DoD points are about validity
  and the assertion, which pass.

The full chain proven: **PHP client → HTTP `/v1/sign` (Dockerized Node service on
`@contentauth/c2pa-node`) → signed PNG → read back via `/v1/read` + c2patool.**

### How to run
```bash
# 1. certs already in certs/ (es256 test cert+key from c2pa-rs sample)
# 2. .env already has a random CONTENTAUTH_API_KEY
docker-compose up -d --build           # start signer on :3000
export $(grep -v '^#' .env | xargs)    # load the API key
php bin/spike.php                       # sign fixture -> out/signed.png + report
bin/verify.sh                           # authoritative verify (trust ENABLED)
docker-compose down                     # stop when done
```

### Trust verification — WIRED ✅
By default c2patool reports the test cert as `signingCredential.untrusted`
(it's not on any trust list). To get `signingCredential.trusted` we enable trust
verification against the c2pa-rs TEST trust anchors:
- c2patool 0.27 reads a **settings file** (`--settings`, JSON or TOML; default
  `$XDG_CONFIG_HOME/c2pa/c2pa.toml`). Trust can also be passed ad-hoc via the
  `trust` sub-command flags (`c2patool <asset> trust --trust_anchors <pem>
  --trust_config <ekus>`), or env vars (`C2PATOOL_TRUST_ANCHORS`, …).
- Two things are needed together: (a) `verify.verify_trust = true`, and (b) the
  trust material — either `trust.trust_anchors` (root/intermediate CA PEM) +
  `trust.trust_config` (allowed EKU OIDs), OR `trust.allowed_list` (specific
  signing certs to implicitly trust).
- Gotcha: the settings `trust.*` fields take the PEM/EKU **file contents as
  strings**, not paths. `certs/c2pa-trust.settings.json` is generated by
  embedding `trust_anchors.pem` + `store.cfg` contents.
- EKU note: the test leaf cert's EKU is **E-mail Protection**
  (1.3.6.1.5.5.7.3.4); `store.cfg` (the trust_config) lists exactly the EKU OIDs
  c2pa permits for signing, so the chain passes.
- `bin/verify.sh` wraps `c2patool --settings certs/c2pa-trust.settings.json`
  and reports signature-valid / cert-trusted / AI-mark. Result: all PASS,
  `validation_status: []`.
- Do NOT use `c2patool init trust` here — it fetches the PRODUCTION trust list,
  which (correctly) would not trust these test certs.

The trust files (`trust_anchors.pem`, `allowed_list.pem`, `store.cfg`) and the
settings JSON contain only PUBLIC test CA certs — safe to keep. The private
signing key (`es256_private.key`) stays gitignored.

### Open items (for the spec-driven rebuild, not blockers)
- **`c2pa.actions` vs `c2pa.actions.v2`**: DoD text says "c2pa.actions"; claim-v2
  manifests use the `.v2` label. c2patool reports the digitalSourceType under
  `.v2` correctly. Confirm which label the spec wants downstream.
- **Timestamping (TSA)**: `LocalSigner.newSigner` takes an optional TSA URL; none
  used here, so no trusted timestamp. Add for production-grade provenance.
  **DONE in SPEC-007 — see Step 6 for the sync-vs-async gotcha.**
- **Thumbnail**: c2pa-node auto-adds a `c2pa.thumbnail.claim` (seen in the
  smoke test). Harmless; note it exists.

---

## Step 6 — SPEC-007 TSA timestamping (verified findings)

Implemented + verified end-to-end 2026-07-28 against a live service and DigiCert's
TSA (`http://timestamp.digicert.com`).

### ⚠️ Biggest gotcha: timestamping REQUIRES the async signing path
Passing a TSA URL to `LocalSigner.newSigner(cert, key, alg, tsaUrl)` and then
calling the **synchronous** `builder.sign(...)` fails at request time with:

```
HTTP 500: the sync http resolver is not implemented
```

Fetching the RFC 3161 timestamp token is an HTTP call, and c2pa-node's sync
`sign()` has no HTTP resolver. The timestamp only works via the **async** path:

- `CallbackSigner.newSigner({ alg, certs:[certBuf], reserveSize, tsaUrl,
  directCoseHandling:false }, async (data) => sign(data))` then
  `await builder.signAsync(signer, source, dest)`.
- The callback signs the raw bytes with the local key, mirroring the library's
  own `Builder.spec.js` TestSigner: `crypto.createSign('SHA256').update(data)
  .sign(privateKeyObject)` (es256 ⇒ SHA-256; es384/es512 map accordingly).
- `reserveSize`: the spec's callback examples use `10000` **without** a TSA; the
  timestamp token adds ~7–8 KB, so we reserve `20000`. Too small ⇒ signing
  fails; too large is just padding.
- So the service now branches: **TSA set ⇒ async CallbackSigner; no TSA ⇒ the
  original sync `LocalSigner`** (unchanged, byte-for-byte).

### Fail-closed is inherent (AC5)
An unreachable/invalid TSA makes `signAsync` reject; the existing `catch` returns
`500` with no `signed_content`. There is no untimestamped fallback. Verified:
bad TSA ⇒ `{"error":"...http resolver...error sending request..."}`, HTTP 500.

### Reading side (AC1–AC3)
The timestamp surfaces as `signature_info.time` (ISO-8601) in the manifest store
JSON (confirmed against `@contentauth/c2pa-node`'s `Reader.spec.js`).
`ManifestReport::hasTimestamp()` = present + parseable. A timestamped signed PNG
grew 47,775 → 55,478 bytes; c2patool reports signature-valid + cert-trusted + the
Art.50 mark, no failures.

### Stale bin/e2e.php namespace (fixed in passing)
`bin/e2e.php` still used the pre-v0.2.0 `ContentCredentials\...` namespace and
would fatal on run; corrected to `Provemark\ContentCredentials\...`.

---

## Step 7 — Property-based test suite (Eris) + a real service bug it caught

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

## Step 8 — Dependency bump `@contentauth/c2pa-node` 0.7.0 → 0.8.0 (verified 2026-08-02)

Routine minor bump of the service dependency; `service/` only, no `src/` change,
no spec needed for a version bump.

### Upstream context (the repo moved)
`contentauth/c2pa-node-v2` was **archived ~2026-06-08**; development moved to the
`contentauth/c2pa-js` monorepo under `packages/c2pa-node`. So GitHub
tags/releases/CHANGELOG for that old repo stop at v0.5.5, while npm keeps
publishing 0.6.x → 0.8.0 from the monorepo. The real changelog lives at
`c2pa-js/packages/c2pa-node/CHANGELOG.md`.

### 0.7.0 → 0.8.0 delta (from that CHANGELOG)
- **0.8.0** minor: "Strip archive metadata assertion when constructing builder
  from archive" (does NOT touch our path — we build fresh via `Builder.withJson`,
  never from an archive) + patch: "Fix node binary download".
- Underlying **c2pa-rs stays 0.90.0** (bumped back in 0.6.2), so claim v2 /
  `c2pa.actions.v2` / the Art.50 marking are unchanged. Non-breaking for us.

### Verified end-to-end
`docker-compose up -d --build`; container reports `c2pa-node 0.8.0`, health ok.
`php bin/e2e.php`: sign+read OK, AI mark present, timestamp present (the async
TSA `CallbackSigner`/`signAsync` path still works — the main risk point), and
`bin/verify.sh` (c2patool 0.27.3, trust on) = signature valid PASS / cert trusted
PASS / Art.50 mark PASS / no failures.

### Files
`service/package.json` + `service/package-lock.json` → `0.8.0`. NB the Dockerfile
does `COPY package.json` + `npm install` (not `npm ci`, lockfile not copied), so
the Docker build resolves off `package.json` and ignores the lockfile; the
lockfile was updated with `npm install --package-lock-only --omit=dev` (no native
binary download — sidesteps the allow-scripts postinstall) for repo hygiene only.
c2patool itself is unaffected (still the vendored 0.27.3, the latest release).

---

## Step 9 — Dependency bump `@contentauth/c2pa-node` 0.8.0 → 0.8.1 (verified 2026-08-05)

Patch bump of the service dependency; `service/` only, no `src/` change.

### 0.8.0 → 0.8.1 delta (from the c2pa-js monorepo CHANGELOG)
Two patch entries: **"incorporate c2pa builder filter_actions_and_ingredients"**
and **"Update c2pa to 0.90.4"** (c2pa-rs 0.90.0 → 0.90.4, so still the 0.90 line
— claim v2 unchanged).

The first entry is the one that mattered: a builder-side *filter* over actions
and ingredients is exactly the machinery that could silently drop or rewrite our
single `c2pa.actions.v2` assertion, which is the Art.50 marking AND what makes a
claim-v2 manifest well-formed. Verified explicitly rather than assumed — see
below. It turned out to be inert on our path (we build fresh via
`Builder.withJson`, we pass one actions assertion, nothing to filter).

### Verified end-to-end
`docker-compose up -d --build`; container reports c2pa-node **0.8.1**,
`/health` = `{"status":"ok","signing_alg":"es256","timestamping":true}`.
`php bin/e2e.php`: sign+read OK, AI mark present, `hasTimestamp` true (so the
async TSA `CallbackSigner`/`signAsync` path still works — the main risk point),
signed PNG 55,478 bytes (byte-identical in size to the 0.7.0 timestamped output
recorded in Step 6). `bin/verify.sh` (c2patool 0.27.3, trust on): signature valid
PASS / cert trusted PASS / Art.50 mark PASS / no remaining failures.

Manifest inspected directly to close out the filter question:
`claim_version: 2`, assertions = exactly **one** `c2pa.actions.v2`, first (only)
action `c2pa.created` with `softwareAgent` + the full IPTC `digitalSourceType`
URI, `validation_state: Trusted`. The auto-added `c2pa.thumbnail.claim` is still
there (reported as a manifest-level thumbnail property, not in the `assertions`
array — worth knowing, it looks absent if you only list assertion labels).

### ⚠️ npm audit: a high-severity transitive advisory, and why the lockfile did not fix it
`npm audit --omit=dev` flagged **brace-expansion ≤1.1.17** (high, DoS via
unbounded expansion — GHSA-mh99-v99m-4gvg / GHSA-rgw5-rvv9-x895), reached via
`@contentauth/c2pa-node → unzipper → fstream → rimraf → glob → minimatch`.
`npm audit fix --package-lock-only` moved it 1.1.16 → 1.1.18, 0 vulnerabilities.

But per Step 8 the Dockerfile does `COPY package.json` + `npm install`, **not**
`npm ci` with the lockfile — so that lockfile pin does not reach the container.
The container was checked directly and happens to run brace-expansion **1.1.18**
anyway, because a fresh `npm install` resolves `^1.1.7` to the newest 1.x. So we
are fine here **by luck, not by construction**: the lockfile remains decorative,
and any future advisory whose fix requires pinning *below* the newest satisfying
version would silently not apply. Fixing this means copying `package-lock.json`
and switching to `npm ci --omit=dev` — a build-behaviour change for everyone
running the service, so it is left as a deliberate open item, not smuggled into
a dependency bump. **CLOSED in Step 10.**

---

## Step 10 — Reproducible service builds: `npm install` → `npm ci` (2026-08-05)

Closes the open item from Step 9. `service/Dockerfile` now does
`COPY package.json package-lock.json ./` + `RUN npm ci --omit=dev`.

### Why the lockfile could be dropped in safely
`npm ci` refuses to run on an incomplete or out-of-sync lockfile, and this repo
generates the lockfile with `npm install --package-lock-only --omit=dev`, which
can prune dev entries — a real risk of breaking `npm ci`. Checked before
changing anything: `lockfileVersion: 3`, 131 entries, **0 marked `dev`**,
because `service/package.json` has no `devDependencies` at all (only
`@contentauth/c2pa-node` and `express`). Nothing is omitted, so the lockfile is
complete and the existing update command can stay as it is.

### Verified
`docker-compose build --no-cache` succeeds; the postinstall still fetches the
native binary under `npm ci`. Container versions are **identical** to the
`npm install` build (c2pa-node 0.8.1, express 4.22.2, brace-expansion 1.1.18) —
which is the point: today the two resolve the same, and now that sameness is
guaranteed instead of coincidental. `/health` ok, `php bin/e2e.php` sign+read OK
with the AI mark and `hasTimestamp` true, signed PNG 55,478 bytes (unchanged),
`bin/verify.sh` all PASS.

Drift detection confirmed rather than assumed: with `package.json` edited to
`0.8.0` against the 0.8.1 lockfile, `npm ci` exits `EUSAGE` with
`Invalid: lock file's @contentauth/c2pa-node@0.8.1 does not satisfy
@contentauth/c2pa-node@0.8.0`. That failure is the feature — drift becomes a
build error instead of a silent divergence.

### No tag
Service-only, no `src/` change, and functionally invisible in the running
container, so this rides in `[Unreleased]` until something else warrants a
release. Tagging every internal build improvement would erode the meaning of the
`Service (requires git pull + rebuild)` heading introduced in v0.4.3.

---

## Step 11 — c2pa-node trust settings: what actually works (verified 2026-08-05)

Experiments for SPEC-014, run inside the running container against
`out/signed.png` (es256 test cert) with `@contentauth/c2pa-node` 0.8.1. Every
line below is an observed result, not a reading of the docs — the docs are wrong
on one point and dangerous on another.

`Reader.fromAsset(asset, settings)` takes a settings object or JSON string.
Baseline with no settings: `state=Valid codes=[signingCredential.untrusted]`.

| Settings passed | Result |
|---|---|
| `trust.trust_anchors` + `trust_config`, PEM **contents** | `Trusted`, `codes=[]` |
| `trust.trust_anchors` contents only, **no** `trust_config` | `Trusted`, `codes=[]` |
| `trust.allowed_list` contents only | `Trusted`, `codes=[]` |
| `certs/c2pa-trust.settings.json` **verbatim** | `Trusted`, `codes=[]` |
| Same document as a JSON **string** | `Trusted`, `codes=[]` |
| `trust.trust_anchors` as a **file path** | **THROWS** `Invalid settings: bad parameter: could not parse configuration: TOML parse error` |

### 1. Contents, not paths — the Step 5 gotcha holds for c2pa-node too
The library README shows `trustAnchors: "path/to/anchors.pem"`. That is wrong: a
path is parsed as configuration text and throws. Trust material goes in as PEM
**contents**, exactly as `certs/c2pa-trust.settings.json` already stores it — so
that file can be handed to the service unchanged. One document now serves both
c2patool and the service.

### 2. `trust_config` (EKU OIDs) is NOT required here
Anchors alone verify to `Trusted`, and so does `allowed_list` alone. The Step 5
concern about the test leaf's E-mail Protection EKU does not bite on this path.
Keep `trust_config` in the document anyway — c2patool wants it, and one shared
document beats a minimal one.

### 3. ⚠️ The exported settings helpers are a trap
`createTrustSettings()` and `createVerifySettings()` emit **camelCase** keys
(`trustAnchors`, `verifyTrustList`, `verifyTrust`). Passing the merged object
straight to `Reader.fromAsset` **silently disables trust verification**:

```
mergeSettings(createTrustSettings(...), createVerifySettings(...))  -> state=Valid  [signingCredential.untrusted]
settingsToJson(that same object)                                    -> state=Trusted []
```

No error, no warning — just no verification, indistinguishable from passing
nothing. The camelCase form only works when routed through `settingsToJson()`,
which converts to snake_case. **Use the plain snake_case document and do not
depend on these helpers.** Also: `loadSettingsFromFile()` is broken outright in
0.8.1 — it throws `fs.readFile is not a function`.

### 4. ⚠️ Absent trust material fails silently; malformed fails loudly
```
{ verify: { verify_trust: true }, trust: {} }                 -> state=Valid  [signingCredential.untrusted]   (no error)
{ verify: { verify_trust: true } }                            -> state=Valid  [signingCredential.untrusted]   (no error)
{ trust: { trust_anchors: 'not a pem at all' }, verify: {...} }-> THROWS
```

So `verify_trust: true` with nothing to verify against is a silent no-op. An
operator who believes trust is on then gets a service that verifies nothing and
reports the same `isTrusted() === false` as a correctly configured service
reading an untrusted asset — the two are indistinguishable from outside. This is
why SPEC-014 AC5 requires the service to reject, at startup, any settings
document that could not actually verify: `verify_trust` truthy AND at least one
non-empty `trust.trust_anchors` / `trust.allowed_list`. Validating that the file
merely *parses* is not enough.

### Running these again
Module resolution follows the script's location, so a scratch script must live in
`/app` (next to `node_modules`), not `/tmp`:
`docker cp exp.js c2pa-spike-service-1:/app/ && docker exec -w /app c2pa-spike-service-1 node /app/exp.js`.

---

## Step 12 — SPEC-014 implemented: trust verification in `/v1/read` (2026-08-05)

`isTrusted()` now has something to be true about. Before this, the service read
with no settings, so every signed asset came back `Valid` +
`signingCredential.untrusted` whatever certificate signed it, and c2patool was
the only thing in the project that could say "trusted".

`CONTENTAUTH_TRUST_SETTINGS` points at a settings document; unset keeps the old
behaviour exactly. `docker-compose.yml` mounts `certs/c2pa-trust.settings.json`
read-only at `/run/secrets/c2pa-trust.settings.json` — mounted always, used only
when the env var names it.

### Verified across BOTH configurations
The suite has to be run twice, because AC1 (trust on) and AC3 (trust off)
describe service configurations that cannot coexist in one process; each test
gates on what `/health` reports.

| Run | `/health` | Result |
|---|---|---|
| no settings | `trust_verification: false` | 3 skipped, **7 passed** |
| settings on | `trust_verification: true` | 1 skipped, **9 passed** (incl. AC2) |

`bin/e2e.php` now asserts the library verdict matches the service mode, and
prints `✓ certificate trusted via the library path (SPEC-014 AC1)`. The read
report went from `isTrusted: false` to `isTrusted: true`, matching what
`bin/verify.sh` has always reported.

### AC2 needed a second service — and it was worth building
Everything above only proves trust verification can say *yes*. Proving it can
say *no* needs anchors that do not cover the signing cert:

```bash
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -days 1 -nodes \
  -subj "/CN=Foreign Test CA" -keyout foreign_ca.key -out foreign_ca.pem
# {"trust":{"trust_anchors":"<that PEM>"},"verify":{"verify_trust":true}} -> out/foreign.settings.json
docker run -d --rm --name c2pa-foreign -p 127.0.0.1:3001:3000 \
  -e CONTENTAUTH_TRUST_SETTINGS=/run/secrets/foreign.json ... c2pa-spike-service
CONTENTAUTH_SERVICE_URL_FOREIGN_ANCHORS=http://127.0.0.1:3001 vendor/bin/pest --group=SPEC-014
```

Result: same signed asset, `Valid` + `signingCredential.untrusted`,
`isSignatureValid()` true, `isTrusted()` false. Verification discriminates; it
does not rubber-stamp.

### ⚠️ Docker Desktop will not mount from the agent scratchpad
Mounting a file from `/private/tmp/claude-501/...` produced **`EISDIR`** — Docker
created a directory because that path is not in its shared set. Put throwaway
mount fixtures under the project (`out/` is gitignored). The failure surfaced as
a clean startup error rather than a mystery, which was AC4 doing its job on its
first real outing.

### Implementation notes
- `loadTrustSettings()` exits non-zero on unreadable / unparseable / non-object,
  and — the part that matters — on a document that could not verify:
  `verify.verify_trust !== true`, or no non-empty `trust.trust_anchors` /
  `trust.allowed_list`. Step 11 showed that case is a **silent** no-op.
- The settings object is passed straight to `Reader.fromAsset(asset, settings)`.
  No `createTrustSettings()` — see Step 11 for why those helpers silently
  disable verification.
- `/health` gained `trust_verification`, alongside `timestamping`.

---

## Step 13 — SPEC-013 implemented: `isTrusted()` fails closed (2026-08-05)

First `src/` change since v0.4.0, so the next release is **v0.5.0**.

```php
- return ! in_array(self::UNTRUSTED_CODE, $this->validationStatusCodes, true);
+ return $this->validationState === ValidationState::Trusted;
```

### The defect was wider than reported
The review found that an asset with no C2PA data answered `isTrusted() === true`.
Writing the tests first showed that was one instance of a shape, not the whole
of it. Because the definition named exactly ONE status code, everything the code
did not name fell through to trusted. Measured on the pre-change implementation
(11 failed, 2 passed):

| Report | Old answer |
|---|---|
| no manifest at all | `true` |
| `Valid`, no status codes reported | `true` |
| absent / unrecognised `validation_state` | `true` |
| `signingCredential.revoked` / `.expired` / `.invalid` | `true` |
| `Invalid` manifest with no untrusted code | `true` |

A **revoked certificate reading as trusted** is the one that would have hurt
most in production. Defining trust positively removes the whole class: there is
no longer a list of failures to keep complete.

### Two existing tests encoded the old rule and had to change
Not "fixed to pass" — they asserted the superseded contract (SPEC-013 amends
SPEC-003 D3), so leaving them would have pinned the defect:

- `SigningServiceReaderTest` "reports trusted when no untrusted code is present"
  → split into "does not report trusted merely because no untrusted code is
  present" plus a new case carrying the `Trusted` verdict.
- The Eris property "treats trust as exactly the absence of the untrusted code"
  → now "decides trust by the Trusted verdict alone, whatever codes accompany
  it", generating over states AND code sequences. A second property pins AC6:
  `isVerifiedAiGenerated()` is never more permissive than the two checks it
  combines.

### Eris gotcha: `Generators::elements()` cannot take a literal `null`
`Generators::elements([null, 'Valid', ...])` throws `OutOfBoundsException` — the
generator indexes its array. Use a sentinel (`'NONE'`) and map it back;
`ValidationState::tryFrom('NONE')` returns null anyway, which is exactly the
"absent or unrecognised state" case.

### Dead constant removed
`UNTRUSTED_CODE` had no remaining reference and PHPStan level max flagged it. The
spec's API sketch said it would stay, but the sketch is explicitly illustrative,
and a private constant nothing reads is dead code. `validationStatusCodes()`
stays — the codes are still useful diagnostics, they just no longer define trust.

`composer check`: 115 passed, PHPStan clean, 0 Deptrac violations.

---

## Step 14 — SPEC-011 + SPEC-012 implemented together (2026-08-05)

Done on one branch because they are coupled: SPEC-012 AC2 requires that a
SPEC-011 rejection be audited, and SPEC-012's correlation id is what makes
SPEC-011's error responses safe to make generic. Implementing them separately
would have meant writing every rejection path twice.

### SPEC-011 — what the service will attest to
`rejectAssertions()` returns the violated constraint or null; `/v1/sign` turns
that into a 400 with our own wording (never library internals, never an echo of
the payload) and signs nothing. Limits: 16 assertions, 64 KiB each, depth 16,
`creator_name` 256 — all env-tunable.

Note the deliberate asymmetry, which is the whole design in one line:
**structural limits are restrictive by default, the semantic policy is
permissive by default.** Too permissive is the risk for structure; too strict is
the risk for semantics, because requiring `trainedAlgorithmicMedia` would
exclude the authenticity use case while making nothing truer.

`exceedsDepth()` stops as soon as the limit is passed, so a hostile 10 000-level
payload costs 17 frames rather than 10 000.

### SPEC-012 — audit logging
One JSON line per `/v1/sign` to stdout, accepted and refused alike. A
correlation id per request (`X-Correlation-Id`, plus `cid` in error bodies) made
it safe to replace the verbatim error echo with `signing failed` / `read failed`.
`token_id` is a salted SHA-256 prefix; the salt is per-process unless
`CONTENTAUTH_TOKEN_ID_SALT` is set, which trades cross-restart correlation
against needing to manage another secret.

### ⚠️ `/dev/full` is how you test a failing stdout
AC9 needed an audit write that actually fails. Redirecting a second instance's
stdout to `/dev/full` (every write fails ENOSPC) inside the running container,
then driving it over HTTP from within the container using node's global `fetch`,
proves both halves at once: the sign still returns **200**, and `/health`
reports `audit_degraded: true`. Belt and braces in the implementation — a
`try/catch` around the write *and* a `process.stdout.on('error')` handler —
because a write to a file-backed stdout can fail either way.

### Test-fixture gotcha
The AC9 probe needs the fixture inside the container, and `docker compose up`
recreates the container, so anything `docker cp`-ed by hand disappears on the
next run. The test now copies it in itself. A test that depends on a manual
step is a test that will lie to you later.

### One test of mine was wrong, not the code
"caller strings are length-capped" was first asserted with a 200-character
`creator_name`, which is *within* the 256 limit — so nothing was truncated and
the test failed against correct behaviour. A `creator_name` over the limit is
refused outright (SPEC-011 AC6), so the real unbounded-input path into a record
is an **assertion label**: it rides inside the assertion size budget. Retargeted
there, where the cap genuinely bites.

Verified in both policy configurations (`REQUIRE_AI_MARKING` off and on):
SPEC-011 18 passed, SPEC-012 9 passed, SPEC-014 still 7 passed, `bin/e2e.php`
green, `composer check` green.

---

## Step 15 — SPEC-015 implemented: rate limiting and concurrency bounds (2026-08-05)

Refuse rather than queue: the PHP client bounds a request at 10s (SPEC-008), so
a queued request would time out client-side while still holding a slot here.
429 + `Retry-After`, audited through the SPEC-012 path.

### First, two measurements that shaped the design
```
one sign ~0.25s | six sequential ~1.52s | six concurrent ~0.42s
GET /health during four concurrent signs: 0.00–0.01s
```
Signing **parallelises** (~3.6x) and does **not** block the event loop. So a cap
is about bounding resource use, not restoring responsiveness — and a saturated
service answers `/health` as fast as an idle one, which is why `/health` now
reports `in_flight` and the effective `limits`. Without that an orchestrator
cannot tell the two apart.

### ⚠️ `requestTimeout` does NOT close a stalled request
This cost the most time and is the finding worth keeping. A client that sends
complete headers announcing a `Content-Length` and then never sends the body is
left open **indefinitely** — `server.requestTimeout` does not touch it.
Reproduced on node 20.20.2 in a bare `http.createServer` with no express at all:

```
requestTimeout=3000, headersTimeout=2000            -> still open after 8s
requestTimeout=3000, headersTimeout=2000, setTimeout(3000) -> closed after 3.0s
```

`server.setTimeout()` — the socket **inactivity** timeout — is what actually
closes it, to the second. All three are now set: `requestTimeout`/`headersTimeout`
bound a request still trickling in, `setTimeout` catches one that has stopped.

An earlier hypothesis — that the timeouts must be assigned before `listen()`
because node derives its connections-checking interval from
`min(headersTimeout, requestTimeout) / 2` at listen time — turned out **not** to
be the cause here; the bare repro sets them before listen and still leaves the
socket open. The server is now built with `http.createServer(app)` and configured
before `listen()` anyway, which is the correct order regardless.

### Guzzle promises do not run while you are not waiting on them
The AC4 test fired `postAsync()` calls, slept, then read `/health` and saw
`in_flight: 0`. Guzzle's cURL multi handler only progresses inside `wait()`, so
nothing had been sent. Rewritten to launch detached background `curl` processes,
which are genuinely concurrent with the PHP process.

### Defaults
`MAX_CONCURRENT_SIGNS=4`, `RATE_LIMIT_REQUESTS=60` per `60000`ms,
`REQUEST_TIMEOUT_MS=15000` (just above the client's own 10s, so in normal
operation the client gives up first and this only reclaims slots held by
something that is not our client), `HEADERS_TIMEOUT_MS=10000`. On by default; `0`
disables a limit explicitly and `/health` reports it.

Still true and documented rather than fixed: express buffers the body before any
limit is consulted, so a cap bounds signing work, not the memory spent admitting
the request it refuses. `MAX_BODY_SIZE` (50 MB) remains the biggest lever.

Verified: SPEC-015 7 passed (rate limit exercised with
`RATE_LIMIT_REQUESTS=5 RATE_LIMIT_WINDOW_MS=2000`), SPEC-011/012/014 unchanged,
`bin/e2e.php` green, `composer check` green.

---

## Step 16 — Open items (2026-08-05)

All six findings of the security review are closed, and SPEC-001..015 are
implemented. What follows is what a next session should pick up, with enough
context to act without re-deriving it.

### 1. Pending release decision: v0.5.1

`[Unreleased]` holds one Service section (SPEC-015). It does not touch `src/`,
so the Composer dist is identical to v0.5.0 — but it **is** a behaviour change
for anyone running the service: a client that fans out hard gets 429s after a
rebuild. That argues against leaving it sitting on `main` unannounced.

Either tag v0.5.1 or let it ride to the next substantive change. The entry is
written; only the version heading and the compare links need moving.

### 2. `MAX_BODY_SIZE` is still 50 MB

The single biggest remaining lever, and the multiplier under every limit
SPEC-015 introduced: the signing path holds roughly four copies of the asset at
once, so four concurrent 50 MB requests is ~800 MB. Express also buffers the
body **before** any limit is consulted, so a concurrency cap cannot protect the
memory spent admitting a request it then refuses — only a smaller body limit
can.

50 MB is far above any PNG or JPEG this service legitimately signs. Lowering it
is a behaviour change for anyone signing large assets, which is why SPEC-015 put
it out of scope rather than deciding it quietly. Currently documented in the
README as the operator's lever. Needs its own decision, and a spec if the
default changes.

### 3. Per-client tokens — the real gap behind two specs

SPEC-012 identifies **which token** signed something, not **which client**.
SPEC-015 rate-limits **per token**, not per client. With one shared credential
those are the same thing, so both specs are correct today and quietly weaker
than they look tomorrow: the moment there is a second consumer of the service,
one shared token is the actual weak point, and neither the audit trail nor the
rate limit can attribute or bound anything per consumer.

The path, in order:
1. Multiple named tokens rather than a single `CONTENTAUTH_API_KEY` — `token_id`
   and the rate-limit buckets already key on the right thing, so most of the
   machinery exists.
2. CAWG organisational identity assertions, so the *manifest* carries who
   produced it rather than only the log. The upstream contract this service
   mirrors already has `signature_type: cawg_org` (NOTES Step 1); c2pa-node
   exports `createCawgTrustSettings`, deliberately left out of SPEC-014.

This is the largest remaining piece of design, and the one that turns the
audit log from "we signed this" into "they asked us to".

---

## Step 17 — The integration suite now runs in CI (2026-08-05)

Every service-side protection built in Steps 12–15 — assertion limits, audit
logging, trust verification, rate limiting — was defended by tests **CI never
executed**. `composer check` excludes the `integration` group because it needs a
running service, and nothing else ran it. Forty-odd tests that only ever ran
when somebody remembered to.

`.github/workflows/ci.yml` gains an `integration` job with three profiles,
because several criteria describe service configurations that cannot coexist in
one process:

| Profile | Service started with | Runs |
|---|---|---|
| `defaults` | rate limit raised | `--group=integration` |
| `hardened` | trust settings + `REQUIRE_AI_MARKING=true` | `--group=integration` |
| `rate-limited` | `RATE_LIMIT_REQUESTS=5`, window 2000ms | `--group=SPEC-015` |

Verified locally against all three, **with and without a TSA configured**,
before pushing: 43 passed / 5 skipped, 44 passed / 4 skipped, 6 passed / 1
skipped. The TSA distinction matters — see below. The tests gate on what `GET /health` reports
and skip what does not apply, so the union covers the set.

### ⚠️ `--group=provenance` does NOT run the integration tests
This is the one to remember. `provenance` is the property-based chain suite —
**three tests**. The integration tests written in Steps 12–15 are tagged
`('SPEC-0NN', 'integration')`, so the documented command ran almost none of
them:

```
vendor/bin/pest --group=provenance   ->  3 tests
vendor/bin/pest --group=integration  -> 48 tests
```

Every docblock, and `composer.json`'s `scripts-descriptions`, said `provenance`.
Corrected everywhere to `integration`; only the property suite still names its
own group. The instruction had been copied forward since Step 7 without anyone
checking that it still selected what it claimed to.

### ⚠️ The concurrency tests passed for an incidental reason
The two SPEC-015 criteria about concurrency — the cap refusing an excess, and
`/health` reporting `in_flight` — passed locally and failed in CI on all three
profiles. The cause was not CI:

```
one sign WITH a TSA configured    ~250 ms   (this machine's .env)
one sign with no TSA              ~58 ms    (CI, and any deployment without one)
```

They only ever worked because the TSA round-trip made each signature slow enough
to overlap. Reproduced locally by clearing `CONTENTAUTH_TSA_URL`: same failures.

Then the obvious fix — more parallelism — turned out not to be the fix either.
Forty parallel `curl` processes against the fast service kept `in_flight` at
**0 for the entire burst** and had nothing refused: forking forty clients costs
more than the server spends answering them, so the requests never coexist.

What works is making each request **cost** more rather than making more of them.
The suite now generates a ~2.4 MB PNG (built by hand in `largePngBytes()` —
IHDR/IDAT/IEND with incompressible pixels, so no GD dependency on any runner).
A burst of 20 of those gives 5 accepted and 15 refused, and `in_flight` is
observed at the cap. Both criteria are now testable at 58 ms per signature, so
they no longer depend on anyone's TSA configuration.

`/health` is also polled **to a deadline** rather than for a fixed window, and
reduced to a peak. The first attempt sampled 20 times at 50ms — about a second —
which is a race, not an observation: the background clients take time to fork
and the burst itself lasts roughly a second, so the window can straddle it
entirely. It did exactly that in CI on a run whose predecessor had passed with
identical code, which is the signature of flakiness rather than a defect. The
loop now exits as soon as work is observed in flight and only pays the full 15s
on a genuine failure. Re-run five times against a TSA-less service to confirm,
because one green run proves nothing about a flaky test.

### ⚠️ The suite trips its own rate limit
Running `--group=integration` against a default service produces a wall of
`HTTP 429: rate limit exceeded`, and 29 failures that look like broken code and
are not. The suite makes ~50 signing requests in well under a minute — including
SPEC-015's deliberate bursts — against a default budget of 60/minute.

Hence the raised limit in the first two CI profiles. Worth knowing beyond CI:
**60 requests per minute is comfortable for interactive use and tight for batch
work.** A queue worker signing a hundred assets hits it. The default is a
starting point, not a recommendation — `RATE_LIMIT_REQUESTS` exists for this.
