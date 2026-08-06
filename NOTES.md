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

### 1. ~~Pending release decision: v0.5.1~~ — DONE (2026-08-05)

Released as v0.5.1, with the reasoning that mattered: the dist is unchanged, but
it *is* a behaviour change for anyone running the service — a client that fans
out now gets 429s, and a stalled connection is closed. Leaving that unannounced
on `main` is how someone discovers it from a support ticket.

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

**The trigger is not adoption.** Worth stating precisely, because it is easy to
get wrong: every Composer install is a *separate* deployment running its own
service with its own token, so a hundred installs still means a hundred
one-caller setups and SPEC-016 helps none of them. What matters is topology
inside a single deployment — more than one caller on the same instance. The
realistic first case is a user pointing staging and production at one service,
because certificates are not cheap enough to duplicate.

So this is a feature for *users*, and the signal to build it is a user saying
they share an instance — asking how to tell environments apart in the audit
log, or why staging is spending production's rate limit. Until then the design
has no real deployment to shape it. The README now states the limitation and
invites exactly that report, which costs nothing and produces the signal.

### 4. Where the PHP users actually are (researched 2026-08-06)

Not a task, a finding to keep. Looked into who could realistically use this, and
what else exists.

**Competition is one package.** [`jrglasgow/c2patool`](https://packagist.org/packages/jrglasgow/c2patool)
— 0.5.2, **1,775 installs**, 0 dependents, 0 stars, last published Feb 2026. It
wraps the `c2patool` binary through `symfony/process`, so the private key sits
on the web server and the PHP process shells out. That is the exact trade
ADR-0003 rejected. There is no official CAI library for PHP; they maintain Rust,
JavaScript and Python.

**The PHP mass is WordPress, not Laravel.** AI Engine alone has 80,000+ active
installs and generates images; AI Power / AI Puffer 10,000+; AIOSEO 3M+ with
image generation as a feature. On the credential side only a *viewer* plugin
exists — reading and displaying, not signing.

**Laravel orchestrates, it does not generate.** Prism handles images as *input*;
generation goes through `openai-php/laravel` or the Laravel AI SDK against
DALL·E/Gemini. So the target is not "a product that generates in PHP" — it is a
PHP product whose feature is generation, implemented against an API.

Worth knowing: OpenAI already attaches C2PA credentials to its image output, and
a PHP app that thumbnails or re-encodes that image **destroys** it. That is both
the risk and the argument — such an app must either preserve the upstream
credential (hard in a normal image pipeline) or re-sign under its own identity,
which is what this package is for.

**A WordPress plugin: viewer yes, signer probably not.** Typical WordPress
hosting cannot run a second process, so a signing plugin would have to either
shell out to a binary (the key back on the web server, ADR-0003 again) or call a
remote service (which filters out most of that 80,000). A **viewer** plugin has
none of those problems: no keys, no service, no liability, and it reuses
`SigningServiceReader` almost unchanged. That is the cheap way into the
WordPress ecosystem without compromising the architecture — the signing side
stays where it belongs, with people who can run a service.

Not a decision, just the map. The Core is framework-agnostic and needs only
PSR-18, so nothing is lost by waiting.

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

---

## Step 18 — Branch protection on `main` (2026-08-05)

A ruleset (`main`, id 20475597) rather than classic branch protection: rulesets
are inspectable and manageable through the API, and can be versioned.

| Rule | Setting |
|---|---|
| Pull request required | yes, **0 required approvals** |
| Required status checks | **`all checks passed`** only |
| Strict (branch up to date) | no |
| Force pushes | blocked |
| Deletion | blocked |

Zero required approvals is not a weakness for a solo maintainer: GitHub does not
let you approve your own pull request, so requiring one would lock the only
person with commit rights out of their own repository. The value here is the
enforced PR plus a green run, not a second pair of eyes that does not exist yet.
Raise it when there is a second committer.

### Why one aggregate check rather than the real ones
The twelve jobs are matrix jobs, so their names carry their parameters —
`composer check (PHP 8.3, Laravel 11)` and so on. A required-checks list built
from those goes stale the moment the matrix changes, and it fails in the
dangerous direction: add Laravel 14 and its job is simply *not required*, so a
red run merges. `all-green` depends on every job and is the only name the
ruleset knows.

`if: always()` on that job is load-bearing. Without it the job is **skipped**
when anything upstream fails, and a skipped required check does not block a
merge — the protection would silently permit exactly what it exists to stop.

### Verified in both directions before trusting it
A required check that cannot fail is worse than none. The aggregator was pushed
together with a deliberately failing test (PR #20): `composer check` went red,
`integration (defaults)` stayed green, and `all checks passed` reported **red** —
so it reports the run, not one job. Then the probe was removed and everything
went green.

The first attempt at that probe was named `TemporaryAggregatorProbe.php`, which
Pest does not collect — it wants a `Test.php` suffix. So the deliberate failure
never ran, and `all checks passed` went green, which looked exactly like the
aggregator working. Same failure shape as SPEC-014's silent trust settings and
the old `isTrusted()`: something reports success while testing nothing.

### The bypass actor, and how to get it right
`bypass_actors` decides whether the maintainer can still push straight to
`main` — which matters here, because the release commits in Steps 12–17 were
direct pushes. Set it wrong and the release flow breaks the next time it is
used, not when the ruleset is created.

`{"actor_type": "RepositoryRole", "actor_id": 5}` did **not** work: this
account's admin rights come from owning the organisation, not from an explicit
repository role. `{"actor_type": "OrganizationAdmin", "actor_id": 1}` does.

⚠️ `GET /repos/{owner}/{repo}/rules/branches/main` cannot confirm this. It lists
the rules on the branch regardless of whether the caller may bypass them, so it
reported all four rules as applying in both configurations — the working one and
the broken one. The only reliable test is to attempt the push: it succeeds with
the bypass in place, printing `Required status check "all checks passed" is
expected` as a warning rather than an error.

Remove the bypass to make the ruleset absolute, at the cost of routing release
commits through a pull request too.

---

## Step 19 — Verified against the OFFICIAL C2PA trust list (2026-08-06)

`c2pa-org/conformance-public` publishes the real thing:
`trust-list/C2PA-TRUST-LIST.pem` — 29 certificates, 36 KB, and a separate TSA
trust list beside it. That file is what decides whether the outside world
trusts a signature, as opposed to `certs/trust_anchors.pem`, which is our own
test material trusting our own test certificate.

Pointed the service at it (`CONTENTAUTH_TRUST_SETTINGS` → a settings document
with the real PEM as `trust_anchors`). Same asset, same test certificate:

| Trust list | `validation_state` | `isTrusted()` |
|---|---|---|
| our test anchors | `Trusted` | true |
| **official C2PA list** | `Valid` + `signingCredential.untrusted` | **false** |

`isSignatureValid()` stays true in both, and the Art.50 marking reads back in
both. So the pipeline works end to end against the real list — the only thing
missing for production is a certificate that is actually on it. SPEC-014's
startup validation accepts the official document without change.

### ⚠️ It also caught a bad assertion in bin/e2e.php
The SPEC-014 AC1 check was written as `isTrusted() === $trustEnabled`, which
assumes trust verification being ON implies *this* asset is trusted. That only
holds when the configured anchors cover the signing certificate. Against the
official list it reported

```
✗ trust mismatch: service trust_verification=true but isTrusted()=false
```

for a completely healthy configuration, where `false` is the correct answer.

Rewritten to assert what actually must hold in every configuration:

- trust off → `isTrusted()` false, by design;
- trust on and trusted → state is `Trusted` **and** no untrusted code;
- trust on and not trusted → there **is** a `signingCredential.untrusted` code
  explaining it, rather than silence.

Verified across all three. Same failure shape as the rest of this session — a
check going red for the wrong reason — except this one failed loudly on a
correct setup rather than passing quietly on a broken one, which is the
direction you want to be wrong in.

### And a flaky concurrency test, again
SPEC-015 AC3 failed on the `hardened` CI profile for a change that touched only
`bin/e2e.php`. Same cause as AC4 before it: a single burst is a race. Whether
the cap is reached depends on how fast the clients start relative to how fast
the service drains, and on a quick enough runner 20 requests arrive spread out
enough that nothing exceeds it.

Fixed the same way AC4 was — retry the burst to a deadline and stop on the
first one showing both outcomes. That does not weaken the criterion; it
establishes the precondition the criterion needs, namely that the cap was
actually exceeded. Burst also raised to `max(30, cap * 8)`.

The lesson, twice over now: a test that asserts something about concurrency
cannot assume it achieved concurrency. Assert it, or retry until you observe
it.

---

## Step 20 — SPEC-017: a body-size default matched to what we sign (2026-08-06)

`MAX_BODY_SIZE` 50mb → **20mb**, plus `max_body_bytes` on `/health`, a proper
413, and the refusal in the audit trail.

### The measurement that drove it
Container memory at the concurrency cap of 4, idle baseline 17.6 MiB:

| Asset | Peak | Per request | × asset |
|---|---|---|---|
| 1.0 MB | 66 MiB | 12.1 MiB | 12.1× |
| 4.1 MB | 161 MiB | 35.9 MiB | 8.7× |
| 11.4 MB | 332 MiB | 78.6 MiB | 6.9× |

So **~7×**, not the "roughly four copies" SPEC-015 and the v0.5.1 changelog
claim. Neither is edited — an approved spec is frozen outside Traceability, and
a published changelog records what shipped. The correction lives in SPEC-017 and
in the README, which is where someone sizing a container actually looks.

At 50mb a body carried a ~37 MB asset; four in flight peaked near 1 GB, in a
container many people give 512 MB. 20mb carries ~15 MB and peaks around 420 MB.

### Two latent bugs surfaced while implementing
**The correlation id was assigned after the body parser.** So any request that
failed to parse — oversized, malformed JSON — was answered with no correlation
id at all, which is exactly when a caller most needs one. Moved ahead of
`express.json`.

**Body-parser failures were unhandled.** They fell through to express's default
error page, and nothing was recorded. Now caught explicitly: 413 for
`entity.too.large`, 400 for malformed JSON, both audited. Note what the record
cannot say — auth runs *after* the parser, so there is no verified caller to
attribute it to, and recording an unverified token would let anyone write
arbitrary `token_id` values into the log. The field is simply absent.

### ⚠️ Three vacuous tests in one sitting
All three of my own, all green while testing nothing:

- the aggregator probe Pest never collected, because the filename lacked `Test`
- an assertion that the README does not say "four copies" — it never did
- a check for `peak` in the README that matched **`speaks`** in "speaks plain HTTP"

A substring check on a short word is not a check. Phrases now (`peak memory`,
`concurrency cap`). The recurring lesson of this session, in its purest form:
**green is not evidence unless you have seen it go red.**

### One unexplained failure, recorded rather than dismissed
A single `composer check` run reported `1 failed, 117 passed`, and the output
was not captured. Eleven consecutive runs since have all been green, so it could
not be reproduced or identified. Most likely candidate is one of the Eris
property suites, which generate random input — meaning it may be a real case
rather than noise. Noted here so that if it recurs there is a record; do not
assume it was nothing.

---

## Step 21 — SPEC-018: rotation you can confirm, and scanning that runs itself (2026-08-06)

Came out of reading the C2PA Conformance Program documents in
`c2pa-org/conformance-public` (`docs/v0.2/`). Two Level 1 requirements land on
code and process we own; we met neither.

### The finding that reframes the whole programme question

> "Generator Product: the set of software, hardware, and platform configurations
> […] that work together as a system to produce digital Assets […] the Generator
> Product is **always the Signer**, and is always the entity listed on the
> Conforming Products List."

**A library cannot be on that list. Neither can any library.** The thing that
gets listed is the deployed system that signs. The programme explicitly permits a
Generator Product to rely on a claim-generator service "created by the Applicant
**or by a different entity**" — that is the role this package plays. Our users
could apply; we cannot, unless we ever run signing as a service ourselves.

Also confirmed, since it changes the calculus: **there is no fee.** No
application fee, no fee to be added to the Conforming Products List. The cost is
a Security Architecture document, sample assets per media type, and a legal
agreement signed on behalf of a registered company. No external audit at Level 1
— it is self-declaration plus document review.

So the useful move was not to apply. It was to make the architecture *describable*
by a user who does apply, and to close the two gaps while we were in there.

### O.2 — the gap was not rotation, it was knowing

The requirement is only "SHALL be capable of rotating the claim signing key".
Replace the mounted files and restart, and you have rotated; that already
conformed. What was missing is that **nothing reported which certificate was
live**. A mount that did not take, a stale image layer, a path typo — every one
leaves the service signing with the superseded key and looking, from outside,
exactly like a successful rotation.

That is the fourth time this session's shape has appeared (`isTrusted()` failing
open, trust settings verifying nothing silently, three vacuous tests). `/health`
now carries `signing_cert: {fingerprint_sha256, not_after}`, and the README's
rotation procedure ends with the comparison rather than the restart.

Leaf, not chain: a chain digest also changes when an intermediate is renewed
without the signing key changing, which would report a rotation that did not
happen.

Deliberately NOT built: hot reload. Not required, and a reload has failure modes
(a half-written PEM, a reload mid-signature) that need their own criteria. The
README says restart-based rotation satisfies the requirement, so nobody goes
looking for an endpoint that is absent on purpose.

### A latent bug the work surfaced

The startup check accepted **any file containing the word `CERTIFICATE`**. A
truncated or corrupt PEM therefore started a service that could not sign. Now
parsed with `crypto.X509Certificate` and fatal on failure — which the identity
work forced, because there is no fingerprint to report for a file you cannot
parse.

### O.3 — the scan that only ever ran by hand

No `dependabot.yml`, no advisory step in CI. The brace-expansion finding in
Step 9 was found *by accident* during an unrelated version bump, and its fix
reached the container by luck. Step 10 fixed the mechanism; nothing fixed the
detection.

Now Dependabot over three ecosystems (`service/` npm, root Composer, Actions)
plus a weekly `audit` workflow. The split matters: **Dependabot cannot open a PR
for an advisory that has no fix**, and those are exactly the ones worth knowing
about. That job is `continue-on-error` and outside the `all checks passed`
aggregate — an unfixable advisory must be visible without blocking unrelated work
on `main`.

### ⚠️ Two test defects, same family as Step 20

- **`toContain()` is variadic.** `expect($x)->toContain('needle', 'explanation')`
  does not attach a message — it asserts the haystack contains BOTH strings. The
  test failed against a correct README, and would have been trivially "fixed" by
  editing the README to contain the explanation. Messages belong to `toBe()`,
  `toBeArray()` and friends; `toContain()` takes needles only.
- **Phrase matching breaks on hard-wrapped prose.** "Generator Product Security
  Requirements" carries a newline in the README, so the substring was never
  there. The helper now collapses whitespace before matching. This is the
  counterpart to Step 20's `peak`/`speaks`: phrases are right, but only against
  normalised text.

### ⚠️ The service image has no curl, no wget, and no openssl

AC2 needs a second service on a second certificate, which meant starting one
inside the container and asking it for `/health`. None of the usual tools exist
in the image. The poll is node's own global `fetch` via `node -e`, and the
throwaway certificate is generated on the host and `docker cp`-ed in. Port
override is essential — without it the probe hits EADDRINUSE against the live
service and dies for the wrong reason, which is the SPEC-014 startup trap again.

### Verified

`composer check` green (126 passed). Full integration suite 55 passed / 5
skipped. `bin/e2e.php` sign+read OK with the AI mark and `hasTimestamp` true;
`bin/verify.sh` signature valid PASS / cert trusted PASS / Art.50 mark PASS.
The `/health` fingerprint matches `openssl x509 -fingerprint -sha256` on
`certs/es256_certs.pem` exactly.

---

## Step 22 — Dependabot's first two PRs, and v0.5.3 (2026-08-06)

SPEC-018 merged, and Dependabot opened two PRs within minutes. Worth recording,
because the *first* run of a new automation is the one that tells you whether it
was configured correctly, and because one of the two was not routine.

### #27 — actions/checkout v4 → v7

Workflow files only. The verification is that the thirteen checks ran at all:
if checkout v7 did not work, nothing downstream of it would have. Merged.

### #28 — express 4.22.2 → 5.2.1, previously deferred

A major, and one earlier sessions deliberately left alone. What changed is that
there is now evidence: since Step 17 the three CI integration profiles build and
run the real service, so express 5 arrived already exercised by ~48 tests. That
does not remove the local step (CLAUDE.md: any `service/` change is verified by
hand), so:

- container reports `express 5.2.1`, `/health` intact including the new
  `signing_cert` block;
- full integration suite **55 passed / 5 skipped**;
- `bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true;
- `bin/verify.sh` signature valid PASS / cert trusted PASS / Art.50 mark PASS.

Then the error paths specifically, because that is where express 5 could have
changed behaviour under everything SPEC-011/012/015/017 built — the body parser,
its error types, and the middleware ordering:

```
oversized body   -> 413   (SPEC-017)
malformed JSON   -> 400 + cid  {"error":"request body is not valid JSON",...}
concurrency cap  -> 429   (SPEC-015)
missing auth     -> 401
```

All intact. Incidental finding: `/v1/nope` answers **401, not 404**, because the
auth middleware runs before routing on `/v1/*`. Pre-existing, unchanged by
express 5, and arguably the better answer — it does not enumerate routes.

**A red run that was not a regression.** The first local integration run gave
`1 failed`: `429` where `400` was expected. That is the suite tripping its own
rate limit — ~50 signs in well under a minute against a default budget of 60,
exactly as Step 17 records. Re-run with `RATE_LIMIT_REQUESTS=1000` (what the CI
profiles do): green. Worth noticing that the failing test **differed between
runs**, which is the signature of a shared budget being exhausted rather than a
defect in any one test.

### v0.5.3 released

Service and documentation: SPEC-018 plus express 5. **`src/` still has not
changed since v0.5.0.**

### ⚠️ "The dist is unchanged" was wrong, and it is the second time

I wrote that this release, like 0.5.1 and 0.5.2, leaves the installed package
identical. `.gitattributes` says otherwise: the dist is `src/`, `config/`,
`composer.json`, `LICENSE` **and `README.md`** — and SPEC-018 added two README
sections. So the package is *not* byte-identical to 0.5.2, even though no code
moved.

This is the same error as the v0.5.1 release notes claiming "byte-for-byte" when
`composer.json` differed by one help string. Both times the claim was made from
memory of what a release *usually* is rather than from the file that decides it.
The changelog and release notes now say it precisely: no change to `src/` or
`config/`, no behaviour change, but not byte-identical. **Check
`.gitattributes` before making that claim.**

### The audit workflow, verified rather than waited for

`gh workflow run audit.yml` instead of waiting until Monday, because a scan that
silently does nothing is the exact failure shape this whole session kept
producing. Both steps ran and reported real work:

```
npm audit (service): found 0 vulnerabilities
composer audit:      Lock file operations: 120 installs -> audit over 120 packages
```

So it is green because there is nothing to report, not because it scanned
nothing. The brace-expansion advisory from Step 9 would surface here now.

### Reconciling the Step 16 open items

Recorded here rather than by editing Step 16, which is a log entry and stays as
written:

1. ~~v0.5.1 decision~~ — done, Step 16 itself.
2. ~~`MAX_BODY_SIZE` at 50 MB~~ — **closed** by SPEC-017 (Step 20), now 20mb.
3. **Per-client tokens (SPEC-016) — still open, still `draft`.** Unchanged: the
   trigger is a user reporting a shared instance, not adoption. This is now the
   only remaining design gap.

---

## Step 23 — SPEC-019: reading without the service, and what the extension really does (2026-08-06)

Came out of an adoption question — how could this become the PHP norm — and the
answer turned out to be architectural rather than promotional.

### The finding that reframed it

`ReaderInterface` had one implementation, which POSTs to the Node service. So
**verifying a credential required running a signing service**: a second process,
Docker, a certificate mount, a token. For signing that is key isolation working
as designed. For reading it is a cost with no matching benefit — verification
needs no key, no certificate and no service. It needed one only because reading
and signing shared a transport.

That is backwards in a specific way. Marking is what a generator does; *checking*
is what everyone downstream does, including applications that never sign
anything. And the deployments most likely to meet somebody else's credential —
WordPress on shared hosting, 80,000+ installs for AI Engine alone (Step 16) — are
exactly the ones that cannot run a second process.

### ericmann/ext-c2pa: what is true, versus what its own README says

- **It is on Packagist** as `ericmann/ext-c2pa`, `type: php-ext`, `v0.1.0`, and
  `RELEASE.md` documents a worked PIE procedure with a platform × PHP-minor build
  matrix. Its README says "listing on Packagist/PIE is still deferred" — that
  line is stale. I believed the README first and told the user it was not
  installable; Packagist said otherwise. **Check the registry, not the prose.**
- `PLAN.md` still reads `Scaffold | rendered` with an empty release table while
  `src/` holds a real implementation, so the project's own planning lags its
  code. Treat the API as movable.
- It is an **Automattic VIP product** (namespace `Automattic\VIP\C2PA`) built to
  serve the `wp-c2pa` plugin. We are not its audience.
- Built sensibly for a web process: `c2pa` with `default-features = false` (no
  `file_io`, no remote-manifest fetch) and `rust_native_crypto` (no system
  OpenSSL link).

### Verified before implementing, and it behaved better than assumed

Probed inside the running PHP, against v0.1.0:

| Probe | Result |
|---|---|
| `withTrustAnchors(certs/trust_anchors.pem)` | `Trusted` — PEM **contents**, our existing file, no extraction |
| unsigned asset | Reader with `hasManifest() === false` — **not null** |
| garbage bytes | catchable `C2paException`, no fatal |
| `json()` | `active_manifest`, `manifests`, `validation_status`, `validation_state` |

The null case matters most: that is the exact shape of the SPEC-010 bug on the
service side, and it simply does not exist here. And the JSON keys are the ones
our decoder already reads, which is what made the adapter thin.

### ⚠️ The declared media type is advisory, in BOTH engines

A signed PNG offered as `image/jpeg` is read fine — by the extension *and* by the
service (200, one manifest, `Valid`). c2pa-rs recognises the format from the
bytes. The 400 our service returns for `image/gif` comes from SPEC-009's own
allow-list, not from c2pa.

I had written an acceptance criterion asserting that case must throw. That was
**my assumption, not the spec's requirement**, and it was wrong. It did not get
deleted: it moved to AC2, because shared behaviour between two engines is
precisely what an equivalence test should pin. Deleting would have left the
agreement untested; asserting would have failed against correct code.

### One decoder, deliberately

`SigningServiceReader::parse()` moved verbatim into `ManifestStoreParser`. Two
decoders would be two places for the definition of "trusted" to drift, and
SPEC-013 is the record of how expensive that definition is to get wrong. It also
makes the equivalence criterion mean something: when the readers disagree, the
difference is in c2pa-rs, not in our parsing.

This required reading SPEC-019's "no change to `SigningServiceReader`" as *no
change to its behaviour or contract*, since the decoder lived inside it as a
private method while "reuse the existing decoder" was explicitly in scope. Both
could not hold. Recorded in the spec's implementation notes rather than decided
quietly.

### ⚠️ Signing through the extension is blocked by more than principle

`Signer::fromPem()` and `Builder` exist, so in-process signing is possible — and
it would put the private key in the web process, which ADR-0003 rejected. But
there is a concrete blocker too, from `signer.rs`:

```
tsa_url is None, so no timestamp authority is contacted
```

**The extension cannot timestamp.** SPEC-007 implements RFC 3161 timestamping and
fails closed. In-process signing would silently produce untimestamped manifests —
a capability regression invisible in the output unless someone checks
`hasTimestamp()`. Reading a wrong answer is bad; *producing* a permanently wrong
manifest is worse.

### ⚠️ A green Pest run is not a run

The CI profile for this needed two guards, both added after watching the
unguarded version pass while testing nothing:

```
# with a deliberately bad extension_dir:
Tests:    9 skipped, 8 passed        <- exit code 0
```

Every criterion skipped, and the job reports green. So the profile now asserts
`extension_loaded('c2pa')` after the PIE install — PIE reports success on paths
that do not end in a loaded extension — and greps the output for the equivalence
test having actually **passed**, not merely being present. Confirmed red against
that skipped run before trusting it.

The extension is installed in **one** profile only. AC5 is about the extension
being ABSENT; installing it everywhere would delete the only place that criterion
is exercised.

### PHPStan caught a vacuous test, again

An assertion that `ExtC2paReader implements ReaderInterface` was rejected as
`function.alreadyNarrowedType` — always true. It was: the `implements` clause is
enforced by the type system, so the test exercised the compiler. Removed rather
than silenced. That is now the sixth documented case in this repository, and the
first one a tool caught instead of a person.

### Verified

`composer check` green (131 passed), integration 64 passed / 5 skipped locally
with the extension installed, and CI's new `integration (ext-c2pa)` profile shows
`Install complete: /usr/lib/php/20230831/c2pa.so`, `c2pa Version => 0.1.0`, and
the three equivalence tests passing. The two engines — c2pa-rs 0.89.0 and
0.90.4 — agree on every public accessor today, and that comparison now runs on
every push instead of only where somebody had installed the extension by hand.

---

## Step 24 — Correcting Step 23: what ExtC2paReader actually unlocks (2026-08-06)

Same day, prompted by one question: *why does his extension work with WordPress?*
The answer undoes a claim Step 23 and SPEC-019 both rest on, so it is recorded
here rather than left to be rediscovered.

### The claim that was wrong

SPEC-019's Problem section argues: typical WordPress hosting cannot run a second
process, so the signing-service requirement puts verification out of reach of the
80,000+ installs identified in Step 16 — and the extension removes that.

**The first half holds. The second does not.** Cheap shared hosting cannot
install a native PHP extension either. One barrier was swapped for another and
reported as the barrier being gone.

### Why it works for Automattic and not for wordpress.org

`ext-c2pa` is the extension half of **wp-c2pa, a VIP product**. Automattic VIP
*is* the host: they build the PHP runtime, so they can ship `c2pa.so` in the
image and a plugin may simply assume it is present. No plugin distributed through
wordpress.org can assume anything of the sort. That is why an extension-based
design is viable there and nowhere near the mass market.

Which also re-reads the "it is a VIP product, not neutral infrastructure" note
from Step 23 more sharply: it is not only a governance caveat about the API
moving, it explains the deployment model the whole extension presupposes.

### What ExtC2paReader really buys, stated honestly

Not reach. **Operating cost.** The people who gain are those who control their own
PHP — VPS, containers, Forge/Ploi-style platforms, CI pipelines:

- no second process to run, secure, monitor and update
- no network hop, no token, no `.env` entry for something that needs no secret
- works offline, and is markedly faster

The uncomfortable part, and the reason to write it down: **anyone with enough
control to install an extension can usually also run a service.** So this does not
open a new audience; it makes the existing one much cheaper to operate. That is a
real improvement and it is not the one that was claimed.

### What reach would actually require

A third route: a reader in **pure PHP** — no extension, no service. JUMBF, CBOR,
COSE signature verification and the hash binding, using `openssl`/`sodium`.
That was called "the real work" before ext-c2pa was found, and finding the
extension was read as making it unnecessary. It made it *cheap*, for a different
problem. The mass-market problem is still open, and still needs that.

Not proposed as work — it is a large spec and there is no user asking for it. But
the next time the adoption question comes up, this is the honest map.

### The specs are not edited

SPEC-019 is `implemented` and frozen outside Traceability, and its Problem
section is what was believed when it was approved. The correction lives here,
where NOTES.md is authoritative per CLAUDE.md. The README never made the claim,
so nothing shipped to users needs fixing — which is the one piece of luck in
this.
