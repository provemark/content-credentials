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
