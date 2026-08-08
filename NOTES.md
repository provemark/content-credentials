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

---

## Step 25 — SPEC-021: seven more media types, and a third allow-list (2026-08-07)

Implemented the spec drafted the previous day. Nine media types now sign and
read: PNG, JPEG, WEBP, AVIF, GIF, TIFF, WAV, MP3 and MP4. Nothing about the
manifest changed — same single `c2pa.actions.v2` assertion, different container.

### The third list

The spec's Problem section names two hand-written allow-lists (`MediaType`,
`SUPPORTED_MIME`). There are **three**: `Laravel\Console\InfersMediaType` maps a
file *extension* for the artisan commands. Widening the enum without it would
have shipped an enum accepting `image/webp` and a command refusing `photo.webp`.

Added as AC8 while the spec was still `draft`, rather than smuggled in as an
obvious consequence. All three now derive their error messages from the list
itself instead of restating it — the enum's refusal names every supported type,
the extension map names every extension, and the service interpolates
`SUPPORTED_MIME`. The old messages said "supported: image/png, image/jpeg" in
three places, which is how they go stale.

### ⚠️ Four existing tests used a now-supported type as their counter-example

`image/gif` was the stock "unsupported" MIME in SPEC-001, SPEC-010 and SPEC-012,
and `.gif` in SPEC-006. Making it supported turned four unrelated tests red —
including `/v1/read` returning **500** where 400 was asserted, because garbage
bytes declared as a *supported* type reach c2pa-rs and throw, which is
pre-existing behaviour that the old test never touched.

Retargeted at `image/bmp` / `.bmp`. The criteria did not change; the example
had to. Worth noting for next time: a test whose counter-example is drawn from
"things we happen not to support" is coupled to the scope, not to the rule.

`Gen::mediaType()` now derives from `MediaType::cases()`, so the property suite
widens with the enum rather than lagging it.

### AC7: the 413 cannot know it is a video

The criterion asks that an oversized MP4 be refused with a message about video
being size-bounded rather than a bare byte count. It cannot be conditional: the
body parser refuses **before any route**, so the request is never decoded and
there is no `mime_type` to branch on — the same ordering SPEC-017 found for the
correlation id. So the message names video unconditionally. That is the honest
reading: the person who needs the sentence is exactly the one whose body never
got parsed.

### Verified

`composer check` green (178 passed). Full integration suite **80 passed / 5
skipped** against a rebuilt service (`RATE_LIMIT_REQUESTS=1000` — the suite still
trips a default 60/minute budget, NOTES Step 17). `bin/e2e.php` sign+read OK with
the Art.50 mark and `hasTimestamp` true. `php bin/spec-check.php`: 0 errors.

And then, because our own reader agreeing with our own service proves less than
it looks like: a signed **WEBP, WAV and MP4** put through `bin/verify.sh`
(c2patool 0.27.3, trust on) — signature valid PASS / cert trusted PASS / Art.50
mark PASS / no remaining failures on all three.

### Two small findings from the fixtures

- **The manifest dominates a small asset.** A 312-byte WEBP signs to 108 KB; a
  2.8 KB MP4 to 24 KB. c2pa-node's auto-added `c2pa.thumbnail.claim` is most of
  it. Irrelevant at realistic sizes, confusing at fixture sizes.
- **`$http_response_header` is deprecated on PHP 8.4+**, and its replacement
  (`http_get_last_response_headers()`) does not exist on the 8.3 this package
  targets — so there is no version-portable way to read a status code off
  `file_get_contents`. The raw-POST helper uses Guzzle instead. Caught because
  Pest reports deprecations; it would otherwise have been noise in CI on 8.4/8.5.

### What this does NOT do

`video/mp4` is a **container**, not video support. `MAX_BODY_SIZE` (20 MB) and
the ~7× multiplier apply to every media type, and the transport is base64 in one
HTTP body. Streaming or path-based signing is the change that would matter, and
it is a separate project with no spec. The README, the CHANGELOG and the 413 all
say so; three places, because this is the claim most likely to be misread.

---

## Step 26 — SPEC-022: the name SPEC-021 broke (2026-08-07)

Same day, and a direct consequence: `ManifestBuilder::forAiGeneratedImage()` was
the only entry point, and after SPEC-021 the call a user writes is
`forAiGeneratedImage(MediaType::Mp4)`. `forAiGenerated()` is now canonical; the
old name delegates to it and **stays indefinitely** (settled at approval).

### Why it had to be the same release

Nothing was broken by the name — that is exactly why it needed doing now rather
than later. SPEC-021 is on `main` and unreleased, so fixing it in the same
release means "MP4 through a method called forAiGeneratedImage" is never a state
we published. Afterwards it is the same correction against an API users have
already copied into their code, at a higher price for no extra benefit.

### The alias raises no runtime deprecation, deliberately

`@deprecated` in the docblock only. An alias with no removal date must not
shout: applications that promote notices to exceptions — and PHPUnit does that
for deprecations by default — would break on a purely cosmetic change. AC4
asserts the silence, so nobody adds a `trigger_error` later without revisiting
the decision.

Note the wording problem this creates: a bare `@deprecated` reads as "will be
removed". The docblock has to say "kept indefinitely" in the sentence after the
tag, and AC5 tests for that phrase, or people migrate under a deadline that does
not exist.

### ⚠️ A no-op assertion needs a control case

AC4 installs an error handler and asserts nothing was raised. That test passes
just as happily if the handler was never installed — the seventh instance of the
shape this log keeps recording (Steps 18, 20, 21, 23). So a second test fires
`E_USER_DEPRECATED` through the same mechanism and asserts it *is* caught.
Without it, AC4 cannot fail.

The general form, worth stating once: **an assertion that nothing happened is
only meaningful next to a demonstration that something could have.**

### ⚠️ The bulk rename renamed the alias

`perl -0pi -e 's/forAiGeneratedImage\(/forAiGenerated(/g'` across `src/`,
`tests/`, `bin/` and the docs also rewrote the *declaration* of the alias, so
the method called itself under a duplicate name. The IDE flagged it within
seconds, but the lesson holds: a rename whose entire point is that one
occurrence must survive cannot be a blanket substitution. The BC test was
excluded by hand for the same reason — a suite that no longer calls the alias
cannot detect it breaking.

### Verified

`composer check` green (187 passed), integration 80 passed / 5 skipped,
`bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true,
`bin/verify.sh` all PASS, `php bin/spec-check.php` 0 errors.

### Open question 2, settled the same day: the family shape (A)

`forAiGenerated()` is one of a family, not a shortcut. The next spec adds
siblings rather than a general constructor:

```php
ManifestBuilder::forAiGenerated(MediaType::Png)     // trainedAlgorithmicMedia
ManifestBuilder::forAiManipulated(MediaType::Png)   // the Art. 50(4) case
ManifestBuilder::forCaptured(MediaType::Png)        // digitalCapture
```

and **not** `ManifestBuilder::for(DigitalSourceType $source, MediaType $type)`.

So what SPEC-022 shipped is final: `forAiGenerated()` stays *the* canonical entry
point for the AI-generated case rather than being demoted to a convenience
wrapper in the next release. That was the whole point of asking — the cost of
getting it wrong is telling users in two consecutive releases what the canonical
call is.

What it commits us to: every additional source type is a new public method.
Additive and cheap, but it means each name is a decision that ships, and the
IPTC vocabulary is long — the next spec must pick which cases we actually
support rather than mirroring the whole list.

**Still to verify before that spec, not assumed here:** whether the manipulated
case differs only in `digitalSourceType`, or also in the action sequence. Claim
v2 requires the first action to be `c2pa.created` or `c2pa.opened`, and "edited
with AI" is plausibly `c2pa.opened` plus an edit action — which would make it a
different assertion shape, not a different constant. If so, form A was the right
call for a second reason: one parameter would have implied a symmetry that does
not exist. Do not write that spec from memory; check it against the C2PA spec
first (CLAUDE.md: ask rather than guess).

### Also recorded: an upgrade note SPEC-021 needed and did not have

Adding seven enum cases is additive for Composer, and not free for consumers:
an exhaustive `match ($mediaType)` with no `default` arm now throws
`UnhandledMatchError` the first time it meets a WEBP. That is in the CHANGELOG
under **Upgrading** for 0.8.0. It is the kind of break that does not show up in
any of our own tests, because our own code has the new cases.

---

## Step 27 — Measuring the remaining formats, and what PDF really costs (2026-08-07)

SPEC-021 listed six formats as "unmeasured, therefore undeclared": SVG, PDF,
DNG, AVI, MOV, WEBM. Measured them, plus one that turned up on the way.

### Results

Signed inside the running container with `@contentauth/c2pa-node` 0.8.1
(c2pa-rs 0.90.4), bypassing `SUPPORTED_MIME`, then read back, verified with
`c2patool` (trust on) and re-read through `ExtC2paReader` (c2pa-rs 0.89.0).

| Format | MIME | Sign | Read back | Art. 50 | c2patool | ext-c2pa 0.89 |
|---|---|---|---|---|---|---|
| SVG | `image/svg+xml` | ok | `Valid` | present | PASS | agrees |
| MOV | `video/quicktime` | ok | `Valid` | present | PASS | agrees |
| AVI | `video/x-msvideo` | ok | `Valid` | present | PASS | agrees |
| **FLAC** | `audio/flac` | ok | `Valid` | present | PASS | agrees |
| PDF | `application/pdf` | **fails** | (reads) | — | — | **throws** |
| WEBM | `video/webm` | **fails** | **fails** | — | — | throws |
| DNG | `image/x-adobe-dng` | *not measured — see below* | | | | |

FLAC was not on the list. It surfaced from the binary's parser names and turned
out to work end to end, so it is a candidate alongside the other three.

MOV carries an extra `c2pa.hash.bmff.v3` assertion — the BMFF hash binding,
same family as MP4. Expected, not a defect.

### ⚠️ DNG was NOT measured, though it looked like it was

I could not produce a real DNG, so the probe was a plain TIFF named `.dng`. It
signed, read back `Valid`, kept the marking and passed c2patool — and all of
that is worthless as evidence. Reading the signed file back as `image/tiff`,
as `image/x-adobe-dng` and as `image/png` gave the same answer three times, and
its first bytes are `49492a00` (TIFF magic). The declared type is advisory, so
c2pa-rs simply saw TIFF, which we already support.

Recorded because the wrong conclusion was one step away: "DNG works" would have
shipped on the strength of a green result that measured a format we already had.
A real DNG — raw sensor data, DNG tags — is needed before that claim.

### ⚠️ And a probe that produced nothing while looking like a result

The first attempt to inspect the native binary ran `strings` **inside** the
container. That image has no `strings`, so every candidate came back "absent" —
a clean table of negatives, produced by a command that never ran. Copy the
binary to the host (`docker cp`) and inspect it there. Same shape as the vacuous
tests this log keeps collecting: absence of output is not evidence of absence.

### Why PDF fails, precisely

Not an allow-list of ours, and not a missing parser. c2pa-rs keeps **two
registers**, both visible in the binary:

```
c2pa::jumbf_io::get_cailoader_handler    <- readers
c2pa::jumbf_io::get_caiwriter_handler    <- writers
c2pa::asset_handlers::pdf_io::SUPPORTED_TYPES
c2pa::asset_handlers::pdf::Pdf::from_reader
```

PDF is registered as a reader only. Signing looks for a *writer*, finds none,
and that surfaces as `type is unsupported`. Confirmed at the source: in
`sdk/src/asset_handlers/pdf_io.rs` all three write methods return
`Err(NotImplemented(WRITE_NOT_IMPLEMENTED))` — "PDF write functionality will be
added in a future release" — and `get_writer()` returns `None`.

So it is a **not-yet**, not a refusal, and not a specification gap either: the
C2PA spec defines PDF embedding (§3.4 lists PDF 1.7 and PDF 2.0; Appendix A.4
carries the normative procedure, including how a C2PA manifest relates to a
native PDF signature). The spec exists; the Rust implementation lags. Worth
re-checking on every `@contentauth/c2pa-node` bump, like the TSA path.

WEBM is a different answer. c2pa-rs's `asset_handlers/` holds bmff, c2pa, flac,
gif, jpeg, **jpegxl**, mp3, pdf, png, riff, svg, tiff — no Matroska, in either
register. That is why WEBM fails on reading too. Nothing suggests it is coming.
(JPEG XL is there and is now the one remaining unmeasured candidate.)

### The real cost of PDF is not the enum — it is that our engines disagree

| | service (0.90.4) | extension (0.89.0) |
|---|---|---|
| PDF read | works (returns "no manifest") | **throws `type is unsupported`** |

PDF would be the first media type where the two readers give different answers.
SPEC-019 and SPEC-020 both rest on them being interchangeable, and SPEC-020's
`auto` mode picks the extension whenever it is loaded, **without knowing the
format** — so the same application code would read a PDF or throw, depending on
whether an extension happens to be installed on that host.

On top of that, `MediaType` serves both directions: `Asset` carries it, and the
service checks one `SUPPORTED_MIME` on `/v1/sign` *and* `/v1/read`. A read-only
type has nowhere to live. Adding `application/pdf` would make `sign()` compile
and then fail with a 400 from the service, where the type system gives certainty
today.

### What would have to happen in the package

**If the engine gains PDF writing** and both engines carry it, PDF is ordinary:
an enum case, `.pdf` in the extension map, `SUPPORTED_MIME`, a fixture, an AC
that signs and reads back, README and CHANGELOG. Half a day, no structural
change. Four things to verify rather than assume:

1. PDF's own digital signatures — what adding a manifest does to an
   already-signed PDF. Appendix A.4 covers it; read it, do not reason it out.
2. Embedding goes through annotations and the associated-files array (per the
   binary's own error strings). Ordinary PDF tooling — linearise, merge,
   compress, "save as" — rewrites the file, so the immutability rule bites far
   harder here than for a JPEG.
3. Size. Scanned PDFs are large, and the 20 MB body limit with its ~7×
   multiplier is not theoretical for them.
4. Both engines, not one. The extension lags the service by a minor version
   today; a format is only supported for us when both carry it, or we accept
   per-deployment variation deliberately.

**If we ever want asymmetric capability at all** — read-only PDF, or a format
only one engine knows — the package needs a capability model, not a longer
list:

- `MediaType` carrying what can be signed and what can be read, rather than one
  flat set;
- `ReaderInterface::supports(MediaType): bool`, with SPEC-020's `auto` binding
  taking it into account instead of picking blind;
- the service splitting `SUPPORTED_MIME` into sign and read lists, publishing
  both on `/health` — SPEC-021 AC2 then compares two pairs;
- a distinct exception so "this cannot be signed" fails early in PHP rather than
  as a 400 from the service.

**Deliberately not built now.** The design should be shaped by what actually
arrives: if PDF writing lands and the extension catches up, symmetry returns and
the whole capability layer is unnecessary. Building it first would add
complexity for a situation that may never occur.

### Consequence for the next spec

Four shippable formats — SVG, MOV, AVI, FLAC — all working in both engines and
confirmed by c2patool. That is a SPEC-021-shaped spec and nothing more. PDF,
WEBM, DNG and JPEG XL stay out, each for a different and now-documented reason,
which is worth more than the four types.

---

## Step 28 — SPEC-023 implemented: thirteen media types (2026-08-07)

SVG, MOV, AVI and FLAC shipped. Straightforward, because SPEC-021 built the
machinery — two criteria there derive their expectation from
`MediaType::cases()`, so they widened by themselves. What is worth recording is
what broke, and what the SVG measurement changed.

### The morning's upgrade note came true before lunch

SPEC-022 added a CHANGELOG note warning consumers that adding enum cases makes
an exhaustive `match ($mediaType)` throw `UnhandledMatchError`. SPEC-021's own
`cc21Fixture()` helper is exactly such a match, and it was the first thing to
fail. Our own suite was the first consumer bitten by our own upgrade note —
which is the cheapest possible confirmation that the note was worth writing.

### ⚠️ A fourth counter-example overtaken by scope

`image/svg+xml` sat in two places as an example of "unsupported": the SPEC-021
unit dataset and the Eris property pool. Both went red. That pool has now lost
members twice — gif/webp/tiff in SPEC-021, svg here.

It is refilled with types measured as genuinely out of reach (`application/pdf`,
`video/webm`, `image/jxl`) **plus malformed input** (`imagepng`, `image/`, `''`).
The malformed half is the part that cannot go stale, and in hindsight that is
what a negative pool should have been built on from the start: a rule about
shape, not a list of things we happen not to support yet.

### The 413 message is now derived

SPEC-021 hand-wrote the oversized-body refusal around `video/mp4`. With three
video types that sentence was quietly wrong — someone sending a MOV would read
about MP4 and reasonably conclude it did not apply. `VIDEO_MIME_LIST` is now
filtered out of `SUPPORTED_MIME`, so a fourth video type cannot repeat it.

Same move as the enum-derived error messages in SPEC-021/022: every place that
lists what we support is derived from the one list, or it will drift.

### SVG shipped because of a measurement, not despite one

The open question was whether to ship SVG at all, given that build tooling
destroys the credential. Answered by measuring rather than reasoning:

| Operation | Result | What a verifier sees |
|---|---|---|
| SVGO, default preset | 17.7 KiB → 0.1 KiB | `hasManifest() === false`, **silently** |
| XML re-serialisation | payload intact | `error parsing SVG: invalid file` |

`preset-default` includes `removeMetadata`, and every common bundler runs SVGO
with defaults. The decision was to ship with the README carrying the measurement
verbatim: refusing would protect nobody — someone wanting a signed SVG reaches
for another route and gets no warning at all — while it would exclude the
legitimate case, a generated diagram delivered as a file.

The second failure mode was found by accident and is the more insidious one: the
namespace prefix (`c2pa:` → `ns1:`) changes on any XML rewrite, so the bytes
survive and the file becomes unparseable. Any XML tool does this, SVGO or not.

### Verified

`composer check` green (214 passed). Integration **88 passed / 5 skipped**.
`bin/e2e.php` green. All four new formats signed through `SigningServiceSigner`
and checked with `bin/verify.sh` (c2patool, trust on): signature valid / cert
trusted / Art.50 mark PASS on each. `php bin/spec-check.php` 0 errors.

### What is left, and it is not much

`0.8.0` now carries SPEC-021, 022 and 023 — thirteen media types, a builder
entry point that matches them, and four documented refusals. The next real piece
of work is the `digitalSourceType` family (form A, NOTES Step 26), and it needs
the action-sequence question answered against the C2PA spec first.

---

## Step 29 — A review of the whole codebase, and the two defects it found (2026-08-07)

Read the service, Core, the Laravel layer, config and deployment as a reviewer
rather than an author. Eight findings; two fixed here, one turned into SPEC-024
(draft), the rest recorded below for whoever picks them up.

### ⚠️ Defect 1: SPEC-011's depth guard was defeated by the check in front of it

`rejectAssertions()` ran the SIZE check before the DEPTH check:

```js
if (JSON.stringify(assertion).length > MAX_ASSERTION_BYTES) ...   // line 250
if (exceedsDepth(assertion, MAX_ASSERTION_DEPTH)) ...             // line 253
```

`exceedsDepth()` is careful — it stops at frame 17 whatever it is handed, which
is what SPEC-011 describes. `JSON.stringify()` is not: it recurses over the whole
structure and throws `RangeError` at about 10 000 levels. Measured in the
container:

```
depth  1000  JSON.stringify=ok                              exceedsDepth=ok
depth 10000  JSON.stringify=RangeError: Maximum call stack  exceedsDepth=ok
depth 60000  JSON.stringify=RangeError: Maximum call stack  exceedsDepth=ok
```

So a 60 000-level payload answered **500 with an HTML body and wrote no audit
record**: SPEC-011's bound and SPEC-012's "every request is recorded, accepted
and refused alike" both held only for payloads small enough not to need them.

The fix is the two lines swapped. What makes this worth writing down is the
shape: **a correct guard placed behind an unbounded one is not a guard.** The
existing test nested 64 levels and passed throughout.

### ⚠️ Defect 2: no catch-all error handler

The only error middleware returns `next(err)` for anything without an `err.type`,
so every unanticipated throw reached express's default handler: an HTML page, no
correlation id in the body, no audit record. Defect 1 was one instance; this was
the class. A catch-all now audits and answers `{error, cid}` in JSON.

`NODE_ENV=production` is set in the Dockerfile, so no stack ever reached a
client. That is the one thing that kept this from being worse.

### Verifying a handler with no reachable trigger

With defect 1 fixed there is no longer a way to reach the catch-all over HTTP —
which is the point of defence in depth, and also means it cannot be tested
through the normal surface. Verified the way SPEC-018 AC2 verified a second
certificate: a patched copy of `server.js` with one deliberately throwing route,
run inside the container on a spare port.

```
status = 500   content-type = application/json   cid header present
body   = {"error":"request failed","cid":"6a3c7c60-…"}
audit  = "reason":"unhandled: probe: deliberate"
```

Two container gotchas cost time, both already in this log and both re-learned:
`node /tmp/probe.js` cannot resolve `express` because module resolution follows
the **script's** directory, not the cwd (Step 11) — the script must live in
`/app`. And the image has no `pkill` and no `ps`, so a stray probe holding a port
is invisible; it surfaced as a puzzling 404 from a stale process, and the way out
was a different port, not diagnosis.

**This handler is not covered by a committed test.** If it should be, that needs
an acceptance criterion in a spec, because a test that patches `server.js` is
too fragile to add on its own authority.

### The other six findings

Recorded rather than fixed, in rough order of how much they matter.

1. **`/v1/read` is unbounded** — no rate limit, no concurrency cap, no record.
   Measured with `RATE_LIMIT_REQUESTS=5`: ten reads all answered 200 while the
   sixth sign was refused. SPEC-015 scoped itself to `/v1/sign` and never
   mentioned read, so this is a gap rather than a decision. **SPEC-024 (draft).**
2. **`maxResponseBytes` defaults to 96 MiB**, documented as "headroom over the
   service's 50 MB request cap" — a cap SPEC-017 lowered to 20 MB. The guard that
   exists to stop a hostile response exhausting PHP memory allows ~3.5× more than
   the service can return, and sits far above the common `memory_limit=128M`, so
   the OOM arrives before the guard does. Both the constant and the config
   comment are stale.
3. **No client-side request bound.** The client bounds the response but not the
   request: `SignAssetJob` reads a file of any size, and signing then holds
   bytes + base64 + JSON ≈ 3.7× before the request leaves. A caller meets the
   20 MB limit as a 413 after paying that, or OOMs first. `/health` publishes
   `max_body_bytes`, so a pre-flight check is cheap.
4. **Plain HTTP by default, with no warning when the host is remote.** Loopback
   is fine and documented, but pointing `base_url` at a remote host over `http://`
   sends the bearer token in clear and nothing objects.
5. **The extension reader parses untrusted input in the web process.** ADR-0003
   isolates the *key*; `ExtC2paReader` moves parsing of hostile assets from a
   disposable container into the PHP worker through a native Rust extension.
   Nothing in the README, SPEC-019 or the ADRs mentions this, and SPEC-020's
   `auto` makes it near-default for anyone who installs the extension.
6. **Nits.** `extractError()` embeds a service error string in an exception with
   no cap (bounded only by `maxResponseBytes`); `file_put_contents` in the job
   and command is not atomic, so a crash mid-write leaves a truncated "signed"
   file; `rateLimited()` runs before the concurrency check, so a 429-for-load has
   already spent rate budget; an empty `CONTENTAUTH_API_KEY` starts a service
   that 401s everything without saying so at startup.

### What the review found in good order

Worth recording too, so the list above is calibrated. Constant-time token
comparison over SHA-256 digests. No key material anywhere in git history.
`composer audit` and `npm audit --omit=dev` both clean today.
`ManifestStoreParser` treats every field as untrusted and degrades instead of
throwing, with a bounded `json_decode` depth. No token, key or manifest is logged
anywhere in `src/`. Startup fails closed on an unparseable certificate and on
trust settings that would verify nothing. And SVG — the newest format — neither
expands XML entities nor resolves external ones, checked with an entity bomb and
an XXE probe against the running service.

---

## Step 30 — SPEC-024 implemented: the read path is bounded (2026-08-07)

`/v1/read` now has its own concurrency cap and its own rate budget, separate
from signing's, reported on `/health` as `max_concurrent_reads`,
`read_rate_limit_requests` and `reads_in_flight`.

### The measurement changed the defaults, which is why it was worth taking

The draft proposed 8 concurrent reads "on the grounds that reading is cheaper",
with an explicit note that nobody had measured it. Measured, against a 17.7 MiB
idle baseline, reading a signed 11.3 MB asset:

| Concurrent | Peak | Per request | × asset |
|---|---|---|---|
| 1 | 76 MiB | 58 MiB | 5.2× |
| 4 | 190 MiB | 43 MiB | 3.8× |
| 8 | 278 MiB | 32.5 MiB | 2.9× |

So ~3–5×, against signing's ~7×: cheaper, same order of magnitude, same falling
shape as SPEC-017 found. Eight concurrent reads of a maximum-size asset is
~350 MiB *on top of* signing, so the cap became **4**, matching signing, and a
fully saturated instance is ~650 MiB. The rate budget stayed generous at 240/min
— sustained rate is about fair use, the concurrency cap is what bounds memory.

Worth recording as a case where a draft's honest "unmeasured" note did its job:
the number that arrived halved the proposed default.

### ⚠️ A sign-then-verify round-trip spends from BOTH budgets

Found the moment the limiter landed: SPEC-015's "signs a normal sequence of
requests without interference" started failing with

```
ReadFailedException: Read service returned HTTP 429: read rate limit exceeded
```

— because that test signs *and reads back*, and the read-limited profile gives a
budget of 5. The 429 is correct behaviour; what it exposed is that reading back
what you just signed is a very common pattern, including in `bin/e2e.php` and in
most of this suite.

Two consequences. The README now says it, because a deployment that verifies
everything it signs needs its read budget at least as large as its sign budget
(the defaults, 240 against 60, satisfy that comfortably). And the dense CI
profiles now raise `READ_RATE_LIMIT_REQUESTS` exactly as they already raise
`RATE_LIMIT_REQUESTS` — without that, CI would have gone flaky in a way that
looks like a defect in the limiter.

It is also an argument *for* the separation rather than against it: with one
shared budget the same round-trip would spend double from a single bucket.

### Two CI profiles, for opposite reasons

`read-limited` gives a small read budget and a large sign budget, which is what
AC3 needs — it asserts that exhausting one does not spend the other, and it can
only tell them apart when the numbers are far enough apart to rule out
coincidence. The read *concurrency* cap needs the opposite (a rate budget high
enough that 429 does not arrive from the rate limiter first) and is covered by
`defaults`. Two criteria about the same subsystem that cannot be tested in one
configuration, the same shape as SPEC-014's trust-on/trust-off split.

### AC6 is a weak test and is kept as one

`/health` sits outside `/v1`, so it is structurally impossible for it to be rate
limited: the test cannot fail against any plausible defect. Kept as a smoke
check, recorded here as not being evidence of anything on its own. The
alternative — deleting it — would leave the criterion untested, which is worse
only in the sense that it would be invisible.

### The unexplained `composer check` flake, second sighting

Step 20 recorded one run reporting `1 failed, 117 passed` that could never be
reproduced, and asked that a recurrence be recorded rather than assumed to be
nothing. It recurred today: `1 failed, 213 passed`, output not captured, and not
reproducible in **five** subsequent full runs or **eleven** targeted runs of the
property suite.

What is now visible that was not before: the assertion count varies run to run
(6155–6691 across five runs), which confirms the Eris suites are generating
different input each time. So the most likely candidate remains a rare generated
case rather than noise — meaning it is a real property failure nobody has seen
the input for. If it recurs, capture the output before doing anything else.

---

## Step 31 — SPEC-025: the client keeps its own bounds (2026-08-07)

Four findings from the review (Step 29), plus the two smaller ones, implemented
together because they are one idea: the service has been hardened six times and
the client once.

### The response bound was five times too generous, in the dangerous direction

`maxResponseBytes` defaulted to 96 MiB, documented in two places as "headroom
over the service's 50 MB request cap" — a cap SPEC-017 replaced with 20 MB. The
service cannot return more than ~20 MiB. So the guard against a hostile response
exhausting PHP memory sat above the `memory_limit = 128M` many deployments still
run: the process dies before the guard fires, which is the exact outcome it
exists to prevent. Now 32 MiB, and both comments corrected.

### The request was not bounded at all

The client bounded the response and not the request — and the request is where
the memory goes: raw bytes, base64, JSON body, roughly 3.7× the file. A caller
signing something too large met the limit as a 413 *after* paying that.
`AssetTooLargeException` is thrown before encoding.

The number is duplicated (client and service), and that is acceptable here for a
specific reason worth stating: the service enforces its own limit regardless, so
drift costs a worse error message and never a wrong outcome. That is what makes a
configured value tolerable where it would not be for a security control.

### Insecure transport: warn, do not break the documented deployment

The strict reading of SPEC-015's "a protection that ships off is one nobody turns
on" would say throw. It is wrong here: `http://signer:3000` between two
containers on one private network is what this project's own `docker-compose.yml`
produces, and it is not a leak. A default that breaks the deployment the README
recommends would be switched off by everyone within a day — worse than a warning
nobody disables.

So: `usesInsecureTransport()` in Core states the fact, the provider decides what
it is worth. Core has no logger by design, and the severity difference is a
framework concern. Note the consequence, which the Core test pins: Core reports
`http://signer:3000` as insecure, because it cannot know that host is private.

**The warning must survive a missing logger.** A bare container has no `log`
binding, and a protection that crashes when it cannot warn is worse than absent.

### Atomic writes: the temporary file must share the destination's filesystem

`tempnam()` in the destination's own directory, not `sys_get_temp_dir()`. A
rename across filesystems degrades to a copy, which is precisely the non-atomic
write being replaced. Also `chmod` after creation: `tempnam()` makes 0600, and a
signed asset is an output file rather than a secret.

The tests assert observable consequences — no leftover temporary file, no
destination file after a failure, wholesale replacement — because true atomicity
rests on `rename()` semantics and cannot be observed in-process without a race.

### ⚠️ AC5 was implemented before its test

Recorded rather than quietly fixed: for AC5 the code went in first and the tests
followed, so they were never watched going red. For AC6 the same risk was closed
differently — the phrases were checked against `git show origin/main:README.md`
and confirmed absent, which is the same evidence by another route. Worth doing
that routinely for documentation criteria; it costs one command.

### The unexplained flake, third sighting — and a pattern

Step 20 saw it once, Step 30 twice. Today it appeared a third time:
`1 failed, 237 passed`, output not captured again, and not reproducible in four
subsequent `composer check` runs or roughly twenty bare `pest` runs.

The pattern now visible, and the reason to keep counting: **all three sightings
were under `composer check`, never under a bare `pest` run.** That may be
coincidence — `composer check` is what gets run most — but it is the only
correlation there is, and `check` differs from `test` only in what runs before
it (Pint rewrites files, then PHPStan, then Pest, then Deptrac).

For next time, concretely: run `composer check > /tmp/out.txt 2>&1` in a loop
and inspect the file, rather than re-running afterwards. Twice now the evidence
has been lost by re-running to confirm.

---

## Step 32 — The digitalSourceType research, and what it changed (2026-08-07)

Step 26 settled the shape of the builder family (form A) and left one thing to
verify before writing the spec: whether the manipulated case differs only in
`digitalSourceType`, or also in the action sequence. Verified against the
sources. **It differs, and much more than expected.**

### The manipulated case needs ingredients, which this package does not have

C2PA Implementation Guidance 2.4 is explicit: AI editing is recorded as a
`c2pa.edited` or `c2pa.placed` action carrying the source type, with a
`c2pa.opened` action first, "pointing to an ingredient assertion for the
original photo, where a `parentOf` relationship is indicated".

So Article 50(4)'s case is three things, not one constant:

1. `c2pa.opened` first, referencing an ingredient;
2. an ingredient assertion for the original, `relationship: parentOf`;
3. `c2pa.edited` carrying the AI `digitalSourceType`.

`ManifestBuilder` emits one assertion. `service/server.js` passes
`ingredients: []`. Supporting this means the caller supplies the *original*
asset so a hash can be computed over it — a new input to the entire signing
path. That is a bigger piece of work than the whole source-type spec, which is
why SPEC-026 (draft) ships the **created-time** terms and puts the edited ones
out of scope behind an explicit exception.

Had this not been checked first, the obvious implementation — one more enum case
— would have produced a well-formed manifest making a **false claim**: that the
asset was *created* by an operation which by definition acts on one that already
existed.

### ⚠️ The guidance misspells the IPTC term

The guidance writes `compositedWithTrainedAlgorithmicMedia`. IPTC has no such
concept. The registered term is `compositeWithTrainedAlgorithmicMedia` — no "d".
Implementing from the prose would emit a URI resolving to nothing, inside the
assertion whose entire purpose is machine readability.

Rule for this project, now written down: **source-type URIs come from
`cv.iptc.org`, never from a document quoting it.**

### ⚠️ And the CAI docs describe that term more loosely than IPTC defines it

CAI: "assets containing elements created by generative AI". IPTC: "Augmentation,
correction or enhancement **using** a Generative AI model, such as with
inpainting or outpainting". Those are different claims. For "a new asset mixing
AI and non-AI elements" the registered term is `compositeSynthetic`.

Reaching for `compositeWithTrainedAlgorithmicMedia` because its name sounds like
"composite" would assert that an original existed and was edited. That is the
kind of error nobody notices, because the manifest is valid and the assertion is
present — it is simply about something that did not happen.

### The vocabulary as it stands (fetched 2026-08-07)

Active and relevant: `trainedAlgorithmicMedia`,
`compositeWithTrainedAlgorithmicMedia`, `compositeSynthetic`, `composite`,
`compositeCapture`, `algorithmicMedia`, `digitalCapture`, `computationalCapture`,
`algorithmicallyEnhanced`, `humanEdits`, `digitalCreation`, `dataDrivenMedia`,
`virtualRecording`, `screenCapture`, `negativeFilm`, `positiveFilm`, `print`.

**Retired, never to be emitted:** `minorHumanEdits` and `digitalArt` (both
2024-09-17), `softwareImage` (2022-06-14). Worth knowing because older examples
on the web still use them.

### What the draft asks the maintainer

Three open questions, and the third is the one worth sleeping on: **does the
authenticity case belong in this package at all?** Everything here is built
around Article 50 and AI marking. `digitalCapture` is the opposite claim, made by
cameras — and a PHP web application asserting that it captured a photograph is
asserting something it cannot know. Cheap to support, and it may invite a use
this package is not positioned for.

### Open question 3, settled the same day: no authenticity claims here

Answered: **not in this package, not now** — and the consequence is wider than
the one term that prompted it. `digitalCapture` and `computationalCapture` claim
a device recorded something; `digitalCreation` claims a human made it without
generative tools; the film and print terms claim a physical original. **A PHP web
application cannot know any of them.** It receives bytes. Whatever it asserts
about their origin it is repeating something it was told — and a C2PA assertion
is signed with a certificate, which turns hearsay into attestation.

Not an argument against ever supporting them, but against supporting them as
"an enum case you can pass". The caller would have to be the capture device or
vouch for it, and the package would have to say so loudly. That is a different
product decision, and nothing is asking for it.

What remains is coherent, and sharper than the draft was: this package marks
**synthetic** media. `trainedAlgorithmicMedia` (exists), `compositeSynthetic`
(a mix containing generative AI), and `algorithmicMedia` (purely algorithmic,
not trained on sampled data). The last is worth having precisely because it is a
*negative* claim about AI — it distinguishes procedural output from generative
output instead of leaving both unmarked.

The draft narrowed accordingly: two new cases instead of five, `forCaptured()`
dropped before it existed, and SPEC-026 AC5 now tests `REQUIRE_AI_MARKING`
against `algorithmicMedia` rather than `digitalCapture` — synthetic but
explicitly not trained, which is the sharpest test of a policy that names
`trainedAlgorithmicMedia`.

Sources: C2PA Implementation Guidance 2.4; IPTC Digital Source Type NewsCodes;
CAI open-source documentation on writing assertions and actions.

---

## Step 33 — SPEC-026 implemented: three claims instead of one (2026-08-07)

`compositeSynthetic` and `algorithmicMedia` ship; the three editing terms are
declared and refused; the capture terms are absent by decision.

### Two amendments, both found by trying to write the code

The draft was internally inconsistent and the implementation exposed it within
minutes. **AC4 required the builder to refuse three source types that the Scope
never let into the enum** — a criterion that cannot be expressed. Resolved by
splitting **vocabulary (the enum) from policy (the builder)**, which is better
than either original reading: the refusal can now say *why*, where an absent
constant only says "no such thing". Someone reaching for the editing term learns
it needs ingredients, which is the fact worth conveying.

The second was **AC7**, added before implementation: shipping the writing side
without deciding the reading side would have left a caller who marks
`compositeSynthetic` unable to detect it except by string-matching
`digitalSourceTypes()`. That is the asymmetry the Step 29 review found elsewhere,
about to be created deliberately.

### isAiGenerated() was deliberately not widened

A `compositeSynthetic` asset contains generative AI, so widening the predicate
was tempting. It gates Article 50 decisions in code already written against it,
and SPEC-013 is this project's record of what a predicate that quietly answers
more than it says costs. `involvesGenerativeAi()` is additive and explicit —
true for `trainedAlgorithmicMedia` and `compositeSynthetic`, **false** for
`algorithmicMedia`, which is the entire point of that term.

### AC1 and AC5 cannot be tested in one configuration

A service with `REQUIRE_AI_MARKING=true` refuses the non-AI terms **by design** —
that is AC5. So the round-trip criteria describe a configuration AC5 excludes,
and the two skip past each other. Same shape as SPEC-014's trust-on/trust-off,
and no new CI profile was needed: `defaults` and `hardened` cover one half each.

Worth noticing how it surfaced: the AC1 tests simply went red when the hardened
service was started, which read as a defect for about a minute. The skip
condition is not a workaround — it is the criterion admitting what it needs.

### Verified beyond our own reader

A signed `compositeSynthetic` and `algorithmicMedia` asset, each inspected with
`c2patool` under trust settings:

```
action            : c2pa.created
digitalSourceType : compositeSynthetic / algorithmicMedia
validation_state  : Trusted    status: none
```

So the service passes an unfamiliar source type straight through, and c2pa-rs
neither rewrites nor objects to it. That was a real question — the service
composes its own claim generator and could have had opinions.

### What is still not buildable, and why that is the useful part

The Article 50(4) case — content *manipulated* with AI — remains out of reach,
and now says so in a way a caller meets at the point of asking rather than
discovering later. Building it means ingredients: a second asset as input, its
hash, a `parentOf` relationship, and a `c2pa.opened` action this package has
never emitted. That is the next real piece of work in this direction, and it is
larger than everything SPEC-026 did.

---

## Step 34 — The anchors SPEC-027 broke, and the test that let it (2026-08-07)

Reported within the hour of merging: the anchor links in the README did not work.
Three of them, all created by the split:

```
README.md   -> #signing-service        (section left for docs/service.md)
README.md   -> #going-to-production    (left for docs/production.md)
marking.md  -> #sizing-the-container   (left for docs/service.md)
```

### The test was written to catch exactly this, and skipped it

SPEC-027 AC2 exists because "link rot is the failure this reorganisation is most
likely to introduce". Its implementation opened with:

```php
// External links and in-page anchors are somebody else's problem.
if (str_starts_with($target, 'http') || str_starts_with($target, '#') ...) continue;
```

In-page anchors *were* somebody else's problem — until the move made every one of
them a cross-file link that had not been rewritten. The criterion was right and
the implementation excluded the case it was written for, which is worse than
having no test: the suite reported the link check green.

The check now resolves the anchor too, by generating the GitHub-style slug for
every heading in the target file. Verified against the broken state before
trusting it: three failures, named individually.

### Two duplicated headings, from moving blocks whole

The script that did the split moved each heading block intact, which is what kept
AC3 honest — and it left `# Running the signing service` immediately followed by
`## Signing service`, and `# Going to production` with a `## Going to production`
further down. Faithful, and silly to read.

Fixed by dropping the two headings that duplicated their page title and promoting
the orphaned `###` levels. Worth noting as the cost of the automated approach:
it cannot know that a section title has become a page title. Cheaper than
retyping 900 lines, and the review that catches it is a human reading the result
once.

### The lesson, in one line

**A test that skips a case "for now" has to be re-read when the ground moves.**
That exclusion was correct when it was written and wrong an hour later, and
nothing about the test itself changed in between.

---

## Step 35 — SPEC-028 drafted, and the two questions it could not be written without (2026-08-07)

Article 50(2) requires marking content that is "generated **or manipulated**".
This package does the first half. SPEC-028 (draft) is the second half, and two of
its open questions were blocking enough that the spec was written around
measurements rather than around reasoning. Both were measured against the running
container: `@contentauth/c2pa-node` **0.8.1**, c2pa-rs 0.90.4.

No implementation code exists — the spec is `draft`. What follows is probe work.

### ⚠️ The local `service/node_modules` is 0.7.0; the service runs 0.8.1

Nearly read the wrong API surface. `service/package.json` and the lockfile say
0.8.1 and the Docker build honours them (Step 10), but the checkout's own
`node_modules` had never been refreshed. Any claim about "what the library
offers" has to come from inside the container:

```
docker exec c2pa-spike-service-1 node -e "…require('/app/node_modules/…')"
-> version: 0.8.1   addIngredient: function   setIntent: function   addAction: function
```

Same family as Step 23's "check the registry, not the prose", one layer down:
**check the artefact that runs, not the one that happens to be on disk.**

Also worth keeping: `@contentauth/c2pa-node`'s own `Builder.spec.js` ships inside
the image, and it is the authoritative source for API shapes —
`setIntent('edit')` takes a plain string, `addIngredient(parentJsonString,
{buffer, mimeType})`. That is how Step 1 verified the 0.5.x API too, and it beats
the README every time.

### OQ1 — who builds the `c2pa.opened` → ingredient linkage

Three shapes built and signed, then the signed manifests inspected.

| Route | What we supply | `validation_state` |
|---|---|---|
| A | `c2pa.opened` + `c2pa.edited`, no intent | **`Invalid`** |
| B | `setIntent('edit')`, only `c2pa.edited` | `Valid` |
| B2 | `setIntent('edit')`, action via `addAction()` | `Valid` |

**Route A is not a worse option — it is not an available one.** The failure is
`assertion.action.ingredientMismatch`, and the reason is visible in what a
correct `c2pa.opened` actually carries:

```json
"parameters": { "ingredients": [{
  "url": "self#jumbf=c2pa.assertions/c2pa.ingredient.v3",
  "hash": "nP3uvWkY9FColHEVkiXwzC/E90OQapMiYGge/AesTwg=" }] }
```

That hash is over the **ingredient assertion**, which the service constructs.
PHP would have to reproduce c2pa-rs's own assertion serialisation and hashing to
emit it. So the linkage is c2pa-rs's to build, and the only question was what it
costs us.

**It costs nothing.** All three routes produced exactly **one**
`c2pa.actions.v2` assertion: c2pa-rs inserts `c2pa.opened` *into our assertion*
rather than adding a second one, and our `c2pa.edited` survives with its
`digitalSourceType` and `softwareAgent` intact. The double-actions problem that
NOTES Step 1's divergence exists to prevent does not appear, so the invariant
"the client owns the actions assertion" holds under route B.

B over B2 because B leaves `extra_assertions` flowing through the service
unchanged; the whole service delta is `setIntent('edit')` + `addIngredient()`,
both conditional on a parent being present.

### ⚠️ Three things the library will not do for us

1. **`setIntent('edit')` with no ingredient signs anyway** — `Valid`, no error,
   despite `BuilderInterface` documenting that "Edit requires a parent
   ingredient". There is no enforcement underneath us. SPEC-028's AC3/AC5 are
   the only guards that exist.
2. **The contradictory shape signs clean.** Given `c2pa.created` + a `parentOf`
   ingredient + the AI *edit* source type together, c2pa-rs returned `Valid`,
   added no `c2pa.opened`, and warned about nothing — actions stayed
   `[c2pa.created]`. That is exactly the well-formed-but-false manifest SPEC-026
   was written to prevent, and **nothing outside our own builder catches it**.
   Retroactive justification for SPEC-026's split of vocabulary (the enum) from
   policy (the builder): the policy is not a nicety, it is the only check.
3. **The existing created path is untouched.** No intent set, one actions
   assertion, `c2pa.created`, `Valid` — measured alongside, so the regression
   claim is evidence rather than an assumption. Written down as AC11.

### OQ4 — a parent that is already signed

Signed an original the ordinary way, then used that signed file as the
`parentOf` ingredient of a route-B edit, with an unsigned parent as baseline.

Provenance is preserved automatically: the store gains a **second manifest**
(`manifestCount` 1 → 2), the ingredient gains `active_manifest`, `manifest_data`
and `validation_results`, and `validation_state` stays `Valid`. Nothing needs
building for it.

**The check that mattered was our own reader, and it was run rather than
reasoned.** `ManifestStoreParser` resolves `active_manifest` and reads only that
manifest's assertions, so the parent's `trainedAlgorithmicMedia` must not leak
into what we report about the child. Confirmed on the real file through
`ExtC2paReader` (c2pa-rs **0.89.0**, the older engine):

```
isAiGenerated        : false   <- correct: edited, not created
involvesGenerativeAi : true
digitalSourceTypes   : ["compositeWithTrainedAlgorithmicMedia"]
```

A whole-store scan would have reported **both** terms and made `isAiGenerated()`
wrongly true. One accessor away from a bug, in the same predicate SPEC-013 spent
a whole step repairing.

### ⚠️ Provenance accumulates in the bytes

From a 1.7 KB fixture:

| | bytes |
|---|---|
| signed original | 47,748 |
| derived, unsigned parent | 80,840 |
| derived, **signed** parent | **128,448** |

The extra ~47.6 KB is the parent's entire manifest store, carried inside the
child. A second edit carries two, a third all three.

This lands twice: the output is larger, and when it is edited again it is also
the larger *input* to the next request. So a deployment can approach
`MAX_BODY_SIZE` through **edit-chain depth rather than asset size** — a path
neither SPEC-017 nor SPEC-025 was sized for. SPEC-028 AC9 therefore requires
measurement across at least three generations (one before/after pair cannot show
whether a cost compounds), and AC10 requires the README to publish the number
rather than an adjective.

### Where SPEC-028 stands

OQ1 and OQ4 are answered by measurement. OQ2 (do `algorithmicallyEnhanced` and
`humanEdits` unlock too), OQ3 (may the parent's media type differ) and OQ5 (one
size budget for the pair, or two) are maintainer decisions with recommendations
attached, not open investigations. Status stays `draft`.

---

## Step 36 — ADR-0004, and the same link check failing the same way twice (2026-08-07)

### The ADR was holding a decision that had been reversed

Went looking for where to record why WebAssembly, browser-held keys and
in-process signing all get declined, and found that **ADR-0003 decision 3 still
says "plan an `ExtC2paSigner` adapter"**. NOTES Step 23 found the extension
cannot timestamp (`tsa_url = None`), Step 24 corrected the reach argument, and
CLAUDE.md says the adapter stays unbuilt — but the artefact whose entire job is
to hold architectural decisions held the superseded one.

That is worth noticing as a class: this repository keeps its reasoning in four
places (specs, ADRs, NOTES, CLAUDE.md), and only specs have a lifecycle that
forces them to be revisited. An ADR can quietly go stale because nothing ever
reads it back.

**ADR-0004** (`proposed`, amending ADR-0003 §3) now carries all four answers in
one place: no `ExtC2paSigner`, no WASM runtime inside PHP, no per-user or
browser-held signing keys, and HSM/KMS through SPEC-007's existing
`CallbackSigner` as the one sanctioned upgrade — unbuilt, with the trigger and
the three things to measure written down. Opened as `proposed` rather than
`accepted`, because it reverses a decision the maintainer had accepted and that
ratification is not an assistant's to make. **Accepted the same day.**

### ⚠️ SPEC-027 AC2 did not check `docs/adr/` — eighth instance

The new ADR link went green immediately, which by now is a reason for suspicion
rather than comfort. Broke it deliberately:

```
sed 's#ADR-0004-where-the-signing-key-lives.md#ADR-0004-does-not-exist.md#'
-> ✓ it resolves every relative link in the documentation   (1 passed)
```

The check globbed `docs/*.md` — one level — so every ADR was outside it, and the
ADRs link to each other. Fixed with a recursive walk (`spec027DocPages()`) and
confirmed red before trusting green, with the failure naming the file by its
root-relative path rather than its basename, since basenames stop being unique
once subdirectories are in scope.

**This is the second defect in the same criterion in one day.** Step 34 fixed it
skipping in-page anchors; this fixed it skipping a whole directory. Both times
the criterion was right and the implementation was narrower than the sentence it
implemented — and both times it reported green over the exact failure it was
written to catch.

The generalisation, which is not "write better tests": a check that enumerates
*where* to look is a check with a scope that silently ages. `spec027Pages()`
lists the five pages SPEC-027 created, and that is correct because the criterion
is about those five. AC2's sentence says "the documentation", so it must
discover, not enumerate.

`composer check` green (273 passed), SPEC-027 group 10 passed.

---

## Step 37 — SPEC-028 implemented: the second half of Article 50(2) (2026-08-07)

Content *manipulated* with AI can now be marked. Route B throughout, as Step 35
measured: the client emits one `c2pa.edited` action, the service sets
`setIntent('edit')` and calls `addIngredient()`, and c2pa-rs writes the
`c2pa.opened` action and its linkage into our own actions assertion.

Verified end to end rather than through our own reader alone — `c2patool` with
trust settings on a signed manipulated PNG:

```
c2pa.opened  -> parameters.ingredients[0] = {url: self#jumbf=…/c2pa.ingredient.v3, hash: …}
c2pa.edited  -> softwareAgent + compositeWithTrainedAlgorithmicMedia
ingredients  : [('parent', 'parentOf')]     validation_state: Trusted     status: []
```

### ⚠️ The measurement that was wrong looked like good news

AC9's first run reported a memory multiplier of **0.8×**, against SPEC-017's ~7×
for a single asset. A number that low is not a pleasant surprise, it is a broken
measurement — and this one was broken twice over: the baseline was 133 MiB
because the container had just run the full integration suite, and the asset
pair was sized so close to the body limit that the requests were plausibly
refused rather than signed.

Restarted the container and made the script **assert the HTTP statuses**:

```
HTTP statuses : 200 200 200 200
idle baseline : 24.4 MiB   peak with 4 in flight : 244.1 MiB
per request   : 54.9 MiB   multiplier vs the PAIR : 4.6x
```

The lesson is the sibling of everything in Steps 20, 21 and 34: **a measurement
taken over work that did not happen reads as a small number, not as an error.**
Any load measurement has to prove the load arrived. The script now prints a
warning when a status is not 200.

The answer AC9 wanted: **`MAX_BODY_SIZE` needs no change.** A manipulation
request is bounded by the same limit, and the largest admissible *pair* is
smaller than the largest admissible single asset, so the peak (≈245 MiB) sits
below SPEC-017's ≈420 MiB. The parent is hashed, not signed.

### Generational growth is linear, and that was worth measuring

```
gen 1 (created)  55,455    gen 2  144,301 (+88,846)
gen 3  233,924 (+89,623)   gen 4  323,547 (+89,623)
```

Constant ~89.6 KB per generation. Step 35 established that a signed parent's
manifest is carried into the child and worried in the spec that it "compounds";
four generations show it does not. Had the child embedded the parent's whole
accumulated store, gen 3 would have been ≈288 KB rather than 234 KB. The
mechanism was deliberately not chased — the measured shape is what the README
publishes, and a mechanism nobody verified is how this log fills with things
that turn out to be wrong.

### ⚠️ `bin/verify.sh` gave a wrong answer, and it is the authoritative check

CLAUDE.md names it as the authoritative verification. It reported a correctly
marked manipulated asset as `AI Art.50 mark : FAIL`, because it tested for
`trainedAlgorithmicMedia` alone — while Article 50(2) covers generated **or**
manipulated. So the one tool the project trusts to arbitrate would have said no
to exactly the content this spec added. Now recognises both and names which it
found; checked in both directions.

Worth generalising: this is the fourth place in the repo where a list of
"what counts as supported" had gone stale (SPEC-021's three allow-lists, SPEC-023's
413 wording, SPEC-027 AC2's directory glob, now this). Every one of them was a
hand-written enumeration of something that grew.

### ⚠️ A documented example that did not compile

SPEC-028's API sketch and `docs/marking.md` both show
`ContentCredentials::sign($asset, $manifest, parent: ...)` — the Laravel facade.
`ContentCredentialsManager::sign()` still took two parameters, and **no
acceptance criterion covered the Laravel layer at all**, so nothing would have
caught it: the Core signer's tests bypass the manager entirely.

Added, plus a test asserting the third parameter exists by reflection rather
than trusting the prose. The spec gap is recorded in its implementation notes
rather than papered over — the criteria were written about Core and the service,
and the sketch quietly assumed a third layer.

### ⚠️ `?? 'missing'` cannot test for null

AC8 asserts the audit record's `parent_bytes` is null when nothing was derived.
Written as `expect($record['parent_bytes'] ?? 'missing')->toBeNull()`, which
**cannot pass**: null coalescing returns the fallback for a real `null` exactly
as it does for an absent key. It reported correct behaviour as broken. Presence
and nullness are now asserted separately, which is also the stricter contract —
the field must be *there* and null, so a reader can tell lineage was considered.

Same family as Step 14's over-long `creator_name`: a test of mine that was wrong
about the code rather than the other way round.

### SPEC-026's AC4 tests were rewritten, not deleted

They asserted the refusal SPEC-028 removes. Deleting them would have lost the
criterion; leaving them would have pinned behaviour that no longer exists. What
AC4 actually guarded survives intact — an editing term must never ride on
`c2pa.created` — so they now assert that, and SPEC-026's traceability row was
updated (the only section of an `approved` spec that may change). Same move as
Step 13 when SPEC-013 amended SPEC-003 D3.

### Two smaller findings

- **PHPStan caught a vacuous test again**, the eighth in this log and the second
  by a tool: `is_subclass_of(MissingParentAssetException::class,
  ContentCredentialsException::class)` is provably always true once the class
  exists, because `implements` is enforced by the type system. Removed rather
  than silenced, with a comment where it stood so nobody restores it.
- **`bin/spec-check.php` needs the bolded AC title on ONE line.** Its criteria
  regex is `/m` without `/s`, so a title wrapped across two lines is invisible
  and the traceability row is reported as stale. It reported that clearly —
  `AC12 has a traceability row but no entry in Behavior` — which is the tool
  working, not failing.

### Verified

`composer check` green (293 passed), integration **109 passed / 11 skipped**
(defaults) and **107 / 13** (hardened, `REQUIRE_AI_MARKING=true`),
`php bin/spec-check.php` 0 errors, `bin/e2e.php` green, `bin/verify.sh` PASS on
both a generated and a manipulated asset.

---

## Step 38 — Reviewing 0.9.0 an hour after shipping it (2026-08-07)

Read the release back as a reviewer rather than as its author. Three defects,
two of them in the same place: what the service refuses to attest to. Fixed in
**0.9.1**, service-side only.

### ⚠️ The service put its signature on an `Invalid` manifest

A raw `/v1/sign` whose `extra_assertions` carried a `c2pa.opened` action of the
caller's own:

```
sign HTTP 200        ondertekend, 88.440 bytes
validation_state: Invalid   status: ['assertion.action.ingredientMismatch']
```

Route B works *because* c2pa-rs adds that action itself, with a hash over the
ingredient assertion it builds — which a caller cannot compute. A second
`c2pa.opened` therefore can never be linked, and the signature is spent on an
asset no verifier accepts, with our certificate on it.

**The criterion was the gap, not just the code.** AC5 was written about an
unusable *parent* and said nothing about the actions array. The PHP client
cannot produce this — its builder never emits `c2pa.opened`, and a test pins
that — which is exactly the reasoning SPEC-011 rejects: the service is a separate
HTTP surface and the client is not guaranteed to be the only caller. Added as
AC13 by amendment, which meant SPEC-028 going back to `approved` and through
tests-first again.

### ⚠️ A criterion said "accepted or refused" and only half was built

AC8's implementation added `parent_bytes` / `parent_sha256` to the success path
only. Both of its tests exercised that path, so nothing caught it — and the
traceability row read as covered. **The spec was marked `implemented` with a
criterion unmet**, which CLAUDE.md names explicitly as the thing not to do.

Deliberately scoped when fixing: the fields are recorded on every 400, and NOT
on a 429. A load-shedding refusal exists to avoid spending work, and
base64-decoding a possibly 15 MB parent to describe a request being shed is the
work it is shedding. Recorded in the spec rather than left to be rediscovered.

### The changelog shipped with a section in the wrong place

`## [0.9.0]` gained `### Added`, `### Changed` and `### Upgrading` inserted
*above* the existing SPEC-027 bullet, which left "the documentation is split
across pages" filed under **Upgrading**. Nobody reads a changelog closely enough
to catch that in review — but it was published.

### ⚠️ Two process failures while chasing a red test, both familiar

An integration run went red on `ProvenanceChainPropertyTest :: it makes the most
recent signing the active manifest`. Two mistakes followed, in order:

1. **The evidence was lost again.** Only the tail was on screen — the
   `ERIS_SEED=…` line — not the failing assertion. Step 31 asked, in writing, to
   capture output to a file *before* re-running. Re-running with the seed passed,
   so the case is gone. Third time this log records losing the same evidence.
2. **The detector was worse than the bug.** The capture loop used
   `grep -q "FAILED\|failed"`, which matched a **test title** containing the word
   — `it records a refusal that failed inside validation` — and reported a green
   run (112 passed) as red. Substring where a structure was meant: Step 20's
   `peak`/`speaks` in a new costume. Redone on the exit code.

Six consecutive green runs afterwards, 112 passed each. Not a regression from
this work: that property signs with `forAiGenerated` only — no `c2pa.opened`, no
parent — so the new guard cannot fire on it.

**Fourth sighting of the unexplained flake, and it breaks the pattern Step 31
recorded.** The first three were all under `composer check`; this one is a bare
`pest --group=integration`, and `composer check` excludes the integration group
entirely — so the earlier three must have been a *different* property suite (the
unit ones). Two flakes, not one. Worth knowing before anyone tries to explain
them with a single cause.

### Verified

`composer check` green (293 passed), integration **112 passed / 11 skipped**
(defaults, six runs) and **110 / 13** (hardened), `spec-check` 0 errors, and the
original defect re-probed: HTTP 400, nothing signed, and the refusal record
carrying `parent_bytes: 1699`.

---

## Step 39 — Correcting Step 38: there is no second flake (2026-08-07)

Step 38 concluded that the red `ProvenanceChainPropertyTest` was a *second*,
distinct flake, on the reasoning that the three earlier sightings were under
`composer check` while this one was under `--group=integration`, and that
`composer check` excludes the integration group. The reasoning was sound and the
conclusion was wrong.

**It was the rate limit, reproduced deliberately.** Started the service with
`docker-compose up -d` — no overrides, so the documented default of 60 signs per
minute — and ran the suite:

```
exit=2   4 failed, 108 passed
SigningFailedException: Signing service returned HTTP 429: rate limit exceeded
```

Four failures, all 429, three of them in the property suite. That is NOTES
Step 17 and Step 22 exactly: ~50 signs in well under a minute against a budget of
60. The property suite is simply the heaviest consumer — seven signings per test
— so it is where the budget runs out.

**The cause was mine, and it is worth naming precisely.** During the 0.9.1 fix I
rebuilt with `docker-compose up -d --build`, which does not carry
`RATE_LIMIT_REQUESTS=1000`, and then ran `--group=integration` against it. Every
green run before and after was against a service started *with* the override.
The variable was the service configuration, not the test.

### ⚠️ Eris disguises an environmental error as a property failure

The thing that sent this down the wrong path: Eris caught the
`SigningFailedException` thrown inside the property body and reported it in its
own idiom —

```
Reproduce with:
ERIS_SEED=1786125097021674 vendor/bin/phpunit --filter '…'
```

— which reads exactly like a generated input that broke an invariant, and
invites you to chase a seed. It is worth knowing that a `Reproduce with:` line
says nothing about whether the failure was *generated*. Re-running with the seed
passed, which should have been the tell: an input-dependent property failure
reproduces from its seed, an environmental one does not.

### What would have caught it in one second

Nothing in the suite checks that the service it is talking to has a budget large
enough to run it. `/health` publishes `rate_limit_requests`, and the suite knows
roughly how many signings it makes. A single skip-or-warn on that comparison
would have turned twenty minutes of seed-chasing into one line of output. Not
built here — it touches shared harness code and belongs in a spec — but it is
the cheapest available fix and this is the third time (Steps 17, 22, 39) the same
trap has cost someone an afternoon.

### Correcting the earlier count too

Step 38 said the earlier three sightings must therefore have been a different
suite. That inference goes with the conclusion: those three were under
`composer check`, which excludes `integration`, so they were the **unit**
property suites and remain genuinely unexplained. There is still exactly **one**
unexplained flake, not two, and it has never been seen in the integration group.

### ⚠️ Fifth sighting, and the evidence was lost AGAIN

Minutes after writing the paragraph above, the final verification run produced:

```
Tests:    5 deprecated, 1 failed, 6 skipped, 292 passed (6628 assertions)
```

The command was `composer check 2>&1 | grep -E "Tests:|Violations" | head -2`, so
the failing test and its assertion went into the pipe and vanished. **Fourth
time this log records losing the same evidence, and it happened immediately
after writing a step about losing it.** Steps 20, 30, 31 and 38 all asked for the
same discipline and it failed again under a routine "just check it is green".

The lesson is not "be more careful". It is that a habit which has failed four
times will fail a fifth, and the fix has to be mechanical rather than
behavioural — see below.

### Hunting it by repetition does not work, and now that is measured

| Attempt | Result |
|---|---|
| 250 × `vendor/bin/pest --exclude-group=integration` | all green |
| 80 × `composer check`, output captured per run | all green |

The 250 bare runs were also the **wrong command** — Step 31 established that
every sighting has been under `composer check`, which differs in what runs
before it (Pint rewrites files, then PHPStan, then Pest, then Deptrac). Testing
the wrong configuration, for the second time in one investigation, after the
rate-limit mistake above.

Combined with the earlier attempts (Steps 30 and 31: five `composer check` runs,
eleven targeted, roughly twenty bare), the flake is now known to occur well under
**1 in 80** `composer check` runs. Five sightings, zero reproductions on demand.

**So stop reproducing and start capturing.** Built, on the maintainer's decision,
because it changes what CLAUDE.md calls "the single definition of green":

- `bin/check.sh` runs the sequence, tees to `out/check-<stamp>.log`, and **keeps
  that file only when the exit code is non-zero**. A green run leaves nothing.
- `composer check` now calls it; the sequence itself moved unchanged to
  `composer check:run`. What green *means* is identical — only what survives a
  red run changed.
- The failure message says to read the file *before* re-running, because the
  failure this exists for has never reproduced on demand, so a second run is not
  a second chance.

Verified in both directions rather than assumed, since a capture that captures
nothing is this log's favourite failure mode:

```
green : exit=0, 293 passed, no out/check-*.log left behind
red   : exit=1, out/check-20260807-202918-…log kept, containing
        "Failed asserting that two strings are identical / -'not captured'"
```

The deliberate failure was `TemporaryCaptureProbeTest.php` — with the `Test.php`
suffix, unlike Step 18's `TemporaryAggregatorProbe.php`, which Pest never
collected and which therefore proved nothing.

**CI is unaffected.** `.github/workflows/ci.yml` does not call `composer check`;
it runs the same tools individually with Pint in `--test` mode. So this is a
local-developer instrument, which is the right scope — CI already preserves
every run's output in the job log, and it is the keyboard, not the runner, where
four of the five sightings were lost.

---

## Step 40 — Reviewing the package as an outsider, and the guard that stops at the envelope (2026-08-08)

Read `src/`, `service/`, the Laravel layer and the deployment files as a senior
reviewer rather than as their author, one session after Step 39. Twelve findings.
Three are defects; two of those became **SPEC-029** and **SPEC-030** (both
`draft`, no implementation code). The rest are recorded below, because a finding
nobody wrote down is a finding that gets found again.

### Running the service on the host, which is faster than it looks

Every probe below was taken against `node server.js` started directly on the host
on a spare port, not against a Docker rebuild:

```bash
cd service && CONTENTAUTH_API_KEY=probe \
  SIGNING_CERT_PATH=../certs/es256_certs.pem \
  SIGNING_KEY_PATH=../certs/es256_private.key \
  PORT=3999 node server.js
```

It works because `certs/es256_private.key` is gitignored but present locally, and
it turns a two-minute rebuild loop into a two-second one. **The caveat is real
and must be stated with any result taken this way**: this host runs node 26.7.0
while the image is `node:20-slim`, and `service/node_modules` is 0.7.0 while the
container carries 0.8.1 (NOTES Step 35). So this is right for *reachability* —
does this payload reach that handler — and wrong for anything about c2pa-node's
behaviour or about memory. Those still go through the container.

### ⚠️ Defect 1 — SPEC-011 validates the envelope and never the actions array

Five helpers in `server.js` walk the actions array; four do it as
`for (const action of assertion.data?.actions ?? [])`, which assumes iterability.
Nothing checks it. Both requests below carry a valid token and pass every
SPEC-011 limit:

| `data.actions` | Response | Audit |
|---|---|---|
| `123` | **500** `{"error":"request failed"}` | `outcome:"rejected"`, `reason:"unhandled: number 123 is not iterable"` |
| `"xx"` | **500** `{"error":"signing failed"}` | `outcome:"failed"`, `reason:"could not decode assertion c2pa.actions.v2 …: invalid type: string \"xx\", expected a sequence"` |
| well-formed | 200 | `outcome:"signed"` |

The second row is the one that matters. A malformed payload passed every guard,
reached `Builder.withJson()` and was refused by c2pa-rs — so it spent a
concurrency slot and a real signing attempt. SPEC-011 exists so that "the service
will sign **any** assertion structure an authenticated caller supplies" stops
being true, and for the actions array it is still true; it just stops at the
engine instead of at the boundary.

Same shape as Step 29's depth guard: *a correct guard placed behind an unbounded
one is not a guard.* Here it is a correct guard placed **beside** the thing it
never measures.

**The detail worth keeping.** `firstActionSourceTypes()` uses
`assertion.data?.actions?.[0]` — indexing, which is total over any value — while
its four siblings use `for…of`, which is not. So whether a hostile payload
becomes a 500-with-no-reason or a 500-from-the-engine depends on which accessor
happens to touch it first. That is not a design. It is what "validate the
envelope, trust the contents" produces, and it is why SPEC-029 AC8 is written
against the helpers rather than against the route.

### ⚠️ Correcting Step 29: the catch-all handler HAS been reachable all along

Step 29 wrote, of the catch-all it had just added: "With defect 1 fixed there is
no longer a way to reach the catch-all over HTTP — which is the point of defence
in depth, and also means it cannot be tested through the normal surface." It
verified the handler with a patched `server.js` on a spare port for exactly that
reason.

The payload above reaches it with one field, over plain HTTP, with a valid token.
So the handler could have had a committed test at any point since Step 29, and
the reason recorded for not writing one was wrong.

Note what it does **not** undermine: the handler worked. It audited the request,
answered JSON, and carried a correlation id — precisely as built. The catch-all
is not the defect; being reachable by a one-field payload is. And SPEC-029 puts
it back out of reach, so the committed-test question is open again, on purpose
and this time knowingly.

### ⚠️ Defect 2 — every budget is spent after auth, and the body is parsed before it

Middleware order in `server.js`: correlation id (`:541`), body parser (`:547`),
parser error handler (`:560`), bearer auth (`:596`), then the routes where
SPEC-015's and SPEC-024's limiters live. Both limiters key on
`tokenId(req.token)`, which is the right identifier and does not exist yet where
the expensive work already happened.

| Request | Observed |
|---|---|
| 26 MB body, **invalid** token | **413** — with the SPEC-017 oversized-body message |
| 5 MB well-formed JSON, **invalid** token | 401 |
| 60 requests, **invalid** token | 60 × 401, **zero** 429 |

The 413 is the finding: only the parser can produce one, so an invalid token got
its body buffered and measured before anything asked who it was. And there is no
budget on that path at all — not a rate limit, not a cap, not a counter.

Neither SPEC-015 nor SPEC-024 got this wrong. Both scoped themselves to
authenticated work and said so; SPEC-024's Problem section states outright that
`/v1/*` requires the bearer token "so this is not an unauthenticated exposure",
which is correct about the read path *being reached*. The gap is one layer above
the sentence.

**The bycatch is worth more than the security win.** SPEC-017 records at
`server.js:557` that a body-parser refusal cannot be attributed, because auth
runs after the parser. Confirmed in the probe log — the 413 record carries no
`token_id`, exactly as designed. That reasoning is sound and its premise is the
ordering; reverse the ordering and "which caller keeps sending 25 MB assets"
becomes answerable, which is the question a 413 in a log raises today and cannot
answer.

**What reordering does not buy, and SPEC-030 AC7 must measure rather than assume:**
refusing before the parser stops the allocation and the parse, not the bytes
arriving. node still reads from the socket, and a body that is never consumed is
either drained or the connection is reset — which a client may see as a reset
rather than a clean 401.

### Defect 3 — the hardening went into one of two identical methods

`SigningServiceSigner::extractError()` caps the service's error text at 256
characters, with SPEC-025 AC4's reasoning attached: whatever answers on that URL
controls this string and it ends up in somebody's logs.
`SigningServiceReader::extractError()` is the same method minus the cap, bounded
only by `maxResponseBytes` — 32 MiB into one exception message. The two are
otherwise byte-identical.

That is the finding: not "a missing cap" but *two copies, hardened once*. It
belongs in a shared helper, which is the move `ManifestStoreParser` already made
for the decoder and for the same stated reason. Not spec'd yet — it is a
one-symbol change against `src/` and needs a spec before it may be built.

### The other findings, recorded rather than fixed

1. **`node:20-slim` is end-of-life.** Node 20 left maintenance in April 2026. The
   image holding the signing key is on an unpatched runtime, and `npm audit`
   cannot see it — that audits packages, not the runtime. This is the one finding
   that touches the O.3 claim in `SECURITY.md`. Bumping it means re-running the
   full manual ritual, and the async TSA path is the regression risk.
2. **No container hardening.** No `USER node` — the process holding the private
   key runs as root. No `HEALTHCHECK`. `docker-compose.yml` sets no `mem_limit`,
   `read_only`, `cap_drop` or `no-new-privileges`. The memory one stings: this
   log is full of measured multipliers and a published "size a container against
   ~650 MiB", and the compose file that would encode it sets no limit.
3. **`SignAssetJob` retries what cannot succeed.** `$tries = 3`,
   `backoff() = [10, 60, 300]`. `AssetTooLargeException`,
   `MediaTypeMismatchException`, `MissingParentAssetException` and every 400 from
   the service are deterministic, so the job sleeps up to six minutes to fail
   identically. Only transport, 429 and 5xx are worth a retry — and the 429
   carries `Retry-After`, which nothing reads.
4. **Two Guzzle clients where one would do.** `ContentCredentialsServiceProvider`
   calls `resolveClient()` in both the signer and the reader closure; with no
   application-bound client that is two `new Client(...)`, two connection pools
   to one host.
5. **`ExtC2paReader` never confirms the anchors took.** It calls
   `withTrustAnchors()` (declared `void`, so discarding the return is correct) and
   never calls `hasTrustAnchors()`, which our own stub declares. Given Steps 11,
   14 and 21 — three separate records of trust configuration silently verifying
   nothing — this is the last trust surface where "configured" and "effective"
   are not distinguished. One assert-and-throw is the SPEC-014 AC5 move.
6. **⚠️ The fifth stale enumeration.** `SignCommand`'s signature says
   `{input : Path to the source image (.png/.jpg/.jpeg)}` and its description
   says "Sign an image", while `InfersMediaType` accepts fifteen extensions
   including `.mp4` and `.wav`. Step 37 counted four of these and drew the
   general lesson; this is the fifth, in the text `artisan list` prints, and
   `EXTENSIONS` sits in the same trait to interpolate from. Separately: the CLI
   can only produce `forAiGenerated`, so it now describes a smaller package than
   the library.
7. **`ManifestBuilder`'s `with*` methods re-list all five constructor arguments**,
   three times over. A sixth field means three edits and a wrong positional order
   fails silently; `clone` plus one assignment is immune.
8. **Bytes versus characters, twice.** `MAX_ASSERTION_BYTES` is compared against
   `JSON.stringify(...).length` — UTF-16 code units, so astral-plane text reaches
   up to 2× the published limit in bytes (`Buffer.byteLength()` says what the
   message claims). And `SigningServiceSigner::extractError()` truncates with
   `substr()`, which can cut mid-codepoint and put a broken byte sequence into a
   log line.
9. **`AtomicWrite` uses `0644 & ~umask()`** where the conventional form is
   `0666 & ~umask()`; group-write can never be produced regardless of umask,
   while the comment says the umask is inherited. Cosmetic.

### What the review found in good order

Recorded so the list above is calibrated, as Step 29 did. Constant-time token
comparison over digests. Trust defined positively rather than as the absence of a
code. One decoder, shared. Fail-closed at startup on both an unparseable
certificate and trust settings that would verify nothing. The correlation id
ahead of the parser. Depth before size, in that order, with the reason attached.
`ManifestStoreParser` degrading on every field instead of throwing. PHPStan level
max with no un-annotated ignores, plus Deptrac. And `bin/check.sh` as a
mechanical rather than behavioural answer to losing evidence — which is what made
this session's probe output survive long enough to be written down here.

`vendor/bin/pest --exclude-group=integration`: 293 passed, 6 skipped.
`php bin/spec-check.php`: 0 errors, unchanged with both drafts added.

---

## Step 41 — Measuring SPEC-029's blocking question, which reversed its own sketch (2026-08-08)

SPEC-029 shipped as `draft` with one blocking open question: is an actions
assertion with no `data.actions` acceptable? Its API sketch had answered yes,
reasoning that SPEC-011 settled "an actions assertion is not required", so an
empty one is the degenerate case of the same permission. Measured against the
container (`@contentauth/c2pa-node` 0.8.1, c2pa-rs 0.90.4, TSA on) rather than
reasoned, and the reasoning was wrong twice over. The full table lives in the
spec; what follows is what it cost and what it taught.

Two shapes, nothing alike:

- `data: {}` — c2pa-rs refuses at **build** time, `missing field 'actions'`. So
  permitting it buys a 500 instead of a 400 and nothing else.
- `data: {actions: []}` — **signs**. HTTP 200, a 55 KB signed PNG comes back.

### ⚠️ The one shape that signs is the one nothing can read

| Engine | Reading the signed asset |
|---|---|
| the signing service, c2pa-rs 0.90.4 | HTTP 500, `validation rule was violated: No Action array in Actions` |
| `c2patool` 0.27.3 | `Error: validation rule was violated: No Action array in Actions` |
| `ExtC2paReader`, c2pa-rs 0.89.0 | `ReadFailedException: … No Action array in Actions` |

Three engines, two c2pa-rs minors, one answer — so it is not a version quirk and
not our decoder. This is SPEC-028 AC13's situation reached from the other
direction: a signature spent on a manifest no verifier accepts, with our
certificate on it. Worse than AC13's case in one respect, which is worth stating
precisely: a caller-supplied `c2pa.opened` produced an *Invalid* manifest that a
verifier could still parse and explain. This one throws.

**Absent is a worse claim; empty is a worse artefact.** Sending no actions
assertion at all still signs and still reads back `Invalid` with
`assertion.action.malformed` — a verifier correctly reporting a claim-v2
violation, which is a caller's claim to make badly. SPEC-011's permission is
untouched; the new constraint is conditional on there being an actions assertion
at all, and SPEC-029 AC6 tests all three outcomes together so an implementation
cannot quietly refuse the absent case too.

### What is worth keeping beyond the answer

**The draft argued from a permission and the permission did not transfer.**
"Not required" is about a manifest lacking a claim. "Empty array" is about a
manifest whose claim is structurally broken. They look adjacent in a scope
sentence and behave nothing alike in an engine, and no amount of re-reading
SPEC-011 would have surfaced that — only signing it did. This is the fourth time
in this log that an open question marked *blocker, measure it* returned an answer
opposite to the leaning written beside it (Steps 27, 30, 32, now this).

**It is also the first time the two readers agreed about something that was not
a test.** SPEC-019 AC2 exists to catch the engines drifting apart; here they were
used as three independent witnesses to rule out "our decoder is wrong", which is
a use the equivalence work paid for without being written for.

### Method note

The container, not the host — deliberately, per Step 40's own caveat. The two
defect-1 rows from Step 40 were re-measured here too, and came back identical to
the host run, which retires the caveat for those two specific results without
retiring it in general.

---

## Step 42 — What the container sees as the peer, and why SPEC-030 keeps no addresses (2026-08-08)

SPEC-030's blocking question was what identifies an unauthenticated caller, since
there is no token to key a budget on. The draft leaned per-address on
`req.socket.remoteAddress`, with the proxy caveat "documented rather than
solved". Measured first, and the measurement removed the reason for the
complexity entirely.

A probe container on the compose network, reporting `req.socket.remoteAddress`:

| Deployment | Peer as the container sees it |
|---|---|
| host → published port, `127.0.0.1:3000:3000` (docker-compose default) | **`172.19.0.1` for every request** — the bridge gateway |
| container → container, `http://signer:3000` (README) | the calling container's own address, distinct |

**In the deployment this project ships and recommends, per-address keying
discriminates nothing.** Every host-side caller collapses into the gateway
address, so the "per-address" bucket is a global bucket wearing a costume. Only
the container-network deployment would ever see distinct peers — and that is the
deployment with the smallest set of possible callers.

### The reason that mattered more than the measurement

An address-keyed map has **attacker-controlled cardinality**. SPEC-015's own
comment records why its map is safe: "only authenticated requests reach here, so
the map is bounded by the number of valid tokens". That sentence is exactly what
an unauthenticated bucket cannot say. Adding an unbounded map inside the spec
written to close a resource exhaustion is a poor trade, and it is the kind of
thing that gets added because the alternative looked less thorough.

### ⚠️ The argument that pushed toward per-address did not survive reading it back

The draft worried that a global bucket lets one noisy source starve a legitimate
caller — SPEC-024 AC3's failure in a new place. It cannot: the budget is spent
only on authentication *failure*, and a valid token does not fail, so the two
budgets never meet. AC5 is therefore true **by construction**.

Which is why AC5 is kept rather than deleted as vacuous. A criterion true by
construction is one an implementation can quietly stop satisfying — spending the
budget on every *attempt* instead of every *failure* is a one-word change that
looks like a simplification and hands any unauthenticated caller a lever to stop
all signing. Same family as Step 26's rule: an assertion that nothing happened
needs a demonstration that something could have.

### And the honest consequence: the budget is not a load control

Worth writing down because someone will size it wrong otherwise. Once
authentication runs ahead of the parser, an unauthenticated request costs a
header parse, one SHA-256 and a 401 — about what `GET /health` costs, and
SPEC-024 AC6 already settled that `/health` is not worth bounding. **The
reordering is the fix.** The budget survives for a different purpose: repeated
authentication failure is a credential-guessing signal and nothing anywhere
reports it today. Hence a global counter on `/health` plus a bounded record,
rather than a per-source breakdown nobody can act on.

Accepted cost, recorded in AC4 so it is a decision rather than a surprise: during
a flood, a caller with a merely wrong token gets 429 where it would otherwise get
401. It holds no valid credential either way.

---

## Step 43 — SPEC-029 and SPEC-030 implemented, and three tests that tested nothing (2026-08-08)

Both specs from Step 40 are `implemented`. What is worth keeping is not the code
— it is small — but what the tests did before they were made to work.

### SPEC-029: one accessor, not five guards

`actionsOf()` replaces five copies of `assertion.data?.actions ?? []`. That
expression is total for `?.` and not for `for…of`, which is the whole defect.
The choice worth recording is that it **replaces** the unsafe access rather than
sitting behind it: the spec's API sketch worried that a defensive helper would be
"a second guard hiding the boundary failing", and one accessor avoids that by
leaving exactly one place that knows how to read an actions array.

`server.js` now exports its helpers and guards `listen()` behind
`require.main === module`, so AC8 exercises them without HTTP.

### SPEC-030: implemented in two steps, on purpose

Four criteria gated on an `auth-limited` profile that did not exist, so they were
*skipped* rather than red — and four permanently skipped tests prove nothing.
So `/health` reporting landed on its own first, the gate flipped, and AC4 was
watched going red before the behaviour was written.

That first step also caught something the tests could not: `AUTH_FAIL_LIMIT` was
not in `docker-compose.yml`, so the override never reached the container and the
tests kept skipping while looking configured.

### ⚠️ The spec contradicted itself, and only implementing it showed that

AC8 said "bounded by the budget, not by the number of requests" **and** "every
429 is recorded". With a fixed window and unbounded attempts the 429s scale with
the requests, so the log grows with the flood — the leak the criterion exists to
close, one layer over. Amended to at most two records per window: the first
failure, and the moment the budget runs out. 15 attempts and 15 000 both produce
two.

Worth noting the process: SPEC-030 was `approved`, so this went back through an
amendment rather than being fixed quietly. CLAUDE.md's "spec contradiction found
mid-implementation → STOP, amend, back to step 2" is not ceremony; the wrong
reading was the one already written down.

### ⚠️ Three of my own tests passed while testing nothing

Ninth, tenth and eleventh in this log's collection, all in one sitting.

1. **AC8 passed on zero records.** `written (0) < attempts (15)` holds when
   nothing is written at all. Found only because amending the criterion forced a
   lower bound onto it — `records >= 1` — which is what a bound on "not too many"
   always needs.
2. **`toContain(429, 'explanation')`.** Step 21 records this exact trap:
   `toContain()` is variadic, so the second argument is a second NEEDLE. The test
   asserted the array contained both the status and the explanatory sentence, and
   reported a correct implementation as broken. It has now cost this project
   twice.
3. **A test with no assertions at all.** "writes no body-parser refusal for a
   request it never parsed" looped over the records for that request, found none,
   and performed zero assertions. **Pest caught it** — `RISKY`, not green — which
   is the second time a tool has found one of these rather than a person. Fixed
   with the control case Step 26 prescribes: the same oversized body *with* a
   valid token must produce exactly such a record.

### ⚠️ And a measurement that had to be taken twice

AC7's first run reported the post-change burst costing +0.5 MiB, which is the
Step 37 shape: too good. The baseline was a container that had been signing all
day, so its heap was already warm and the burst reused memory instead of
allocating. Re-measured on a fresh container:

| Ordering | idle | peak | burst | answer |
|---|---|---|---|---|
| parser first | 17.3 MiB | 54.3 MiB | **+37.1 MiB** | 8 × 413 |
| auth first | 17.3 MiB | 26.8 MiB | **+9.5 MiB** | 8 × 401 |

The statuses are part of the measurement, not decoration: they prove the requests
arrived. And the residual 9.5 MiB is the useful half of the result — it confirms
with a number what the spec could only assert in prose, that refusing before the
parser removes the allocation and the parse and **not the bytes arriving**.

### Verified

`composer check` green (296 passed). Integration 136 passed / 16 skipped
(defaults) and 137 / 15 (auth-limited). SPEC-029 17 passed in both the defaults
and hardened profiles; SPEC-030 10 passed / 4 skipped and 11 / 3 across its two.
`bin/e2e.php` and `bin/verify.sh` all PASS. `php bin/spec-check.php` 0 errors.
A sixth CI profile, `auth-limited`, covers the half `defaults` cannot.

---

## Step 44 — Node 24 and a contained container, and the two tests that depended on a writable one (2026-08-08)

Two findings from Step 40's list, taken together because they touch the same
file. No spec: `service/` only, no `src/` change, verified by hand — the same
footing as Steps 8, 9 and 10.

### The runtime, and the gap `npm audit` cannot see

`node:20-slim` left maintenance in **April 2026**. The image holding the signing
key had been running unpatched for four months, and nothing in the project could
have told us: `npm audit` and Dependabot audit *packages*, and the interpreter
underneath them is not one. SPEC-018 automated dependency scanning and this sat
outside its field of view the whole time.

Moved to `node:24-slim` — the current LTS line, so the decision has the longest
runway before it repeats. `engines` raised to `>=22.0.0`. Verified: node 24.19.0,
c2pa-node 0.8.1 and express 5.2.1 unchanged, and the **async TSA path still
works** (`hasTimestamp: true`, signed PNG 55,478 bytes — byte-identical in size
to every run since Step 6). That path is the standing regression risk on any bump
touching the runtime.

### Containment

The container ran as **root**. Now: unprivileged `node` user, `cap_drop: ALL`,
`no-new-privileges`, `read_only: true` with a `tmpfs` at `/tmp` — which the
signing path genuinely needs, because `builder.sign()` writes the asset to a file
and returns the manifest store (NOTES Step 2). Plus `mem_limit: 1g`, sized from
the measured ~650 MiB saturation figure rather than from habit, and
`pids_limit: 256`. A `HEALTHCHECK` using node's own `fetch`, since the image has
no curl and no wget (Step 21).

Confirmed as applied rather than as written, which is not the same thing:

```
ReadonlyRootfs: true   CapDrop: [ALL]   Memory: 1073741824
PidsLimit: 256   SecurityOpt: [no-new-privileges:true]   User: node
touch /app/nope -> Read-only file system
```

### ⚠️ `docker cp` refuses against a read-only rootfs, whatever the destination

Two integration tests went red: SPEC-012 AC9 (the `/dev/full` audit probe) and
SPEC-018 AC2 (a second service on a second certificate). Both `docker cp` a
fixture into `/tmp`.

The first guess was that the tmpfs was the problem. It was not:

```
docker cp file c2pa-spike-service-1:/tmp/file
  -> Error response from daemon: container rootfs is marked read-only
docker exec -i c2pa-spike-service-1 sh -c 'cat > /tmp/file' < file
  -> works
```

Docker refuses the copy on the **container**, not on the path — so the tmpfs
destination is irrelevant. Both probes now pipe through `docker exec -i`.

Worth keeping for two reasons. It is the ordinary way to get a file into a
hardened container, and it will be needed again the next time a probe wants one.
And it is a small instance of a general shape: hardening did not break the
service, it broke two **test harnesses** that had quietly depended on the
container being writable. That dependency was invisible while it held.

### Verified

`composer check` green (296 passed). Integration 136 passed / 16 skipped.
`bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true;
`bin/verify.sh` signature valid / cert trusted / Art.50 mark all PASS.
`php bin/spec-check.php` 0 errors.

### Still open from Step 40

Eight of the twelve findings. In rough order of weight: the `extractError`
asymmetry (the cap went into one of two identical methods), `SignAssetJob`
retrying deterministic failures with backoff, two Guzzle clients where one would
do, `ExtC2paReader` never confirming its trust anchors took, and the fifth stale
enumeration in `SignCommand`'s help text.

---

## Step 45 — SPEC-031: the gap a scope authorised and a criterion missed (2026-08-08)

`extractError()` existed twice, character for character, in
`SigningServiceSigner` and `SigningServiceReader`. Only the signer's copy was
capped, so `ReadFailedException` could carry up to `maxResponseBytes` — 32 MiB —
into one log line, on the path SPEC-019 and SPEC-020 encourage applications to
use from request handlers.

### The failure mode is new to this log, and worth naming

SPEC-025's **Scope** says "Capping the service error text copied into an
exception" — generic, no client named. Its **AC4** says "When the client raises
`SigningFailedException`". The implementation followed the criterion, and the
criterion was narrower than the scope that authorised it.

That is the mirror of Step 38's SPEC-028 AC8, where a criterion said "accepted
**or refused**" and only the accepted half was built. There the criterion was
broad and the work was narrow; here the scope was broad and the *criterion* was
narrow. Same outcome — a spec marked `implemented` over a real gap — and the
second kind is invisible to tooling: `bin/spec-check.php` compares criteria to
tests, and cannot compare a criterion to the scope bullet above it.

No proposal to build that check. The scope-to-criteria step is a reading, not a
lookup. But it is worth knowing that of the two ways a spec can be under-built,
only one of them has a tool watching it.

### Two copies of one decision

The fix is one `Core\Support\ServiceError`, called by both. Recorded here because
the reasoning generalises: this is the third time a shared decision living in two
places has cost something. `ManifestStoreParser` was extracted for the same
reason (SPEC-019: "two decoders would be two places for the definition of trusted
to drift"), and SPEC-021 derived three allow-list error messages from one list
after all three went stale. Here the duplication was not even a risk of drift —
they had already drifted, and had been apart since SPEC-025 shipped.

### No ext-mbstring, and the reason the truncation is safe anyway

`substr()` cuts by bytes, so a UTF-8 message capped at byte 256 can end
mid-codepoint. The obvious fix is `mb_substr()`, and it was rejected: this
package requires `php-http/discovery` and three PSR packages and nothing else,
and a truncation helper is a poor reason to put an extension in `require`.

`preg_match('/^.{0,256}/us')` gives character semantics from PCRE, which ships
with every build. What makes that sufficient rather than half a fix is where the
input comes from: `json_decode()` **rejects** malformed UTF-8 outright
(`JSON_ERROR_UTF8`), so by the time there is an `error` string to cap it is valid
UTF-8 by construction. The guarantee is the decoder's, not the truncation's, and
the code says so — otherwise the next person adds a repair pass that can never
fire.

The test asserts validity with `preg_match('//u', …)` rather than
`mb_check_encoding()`, for the same reason: a suite may not assume an extension
the package declines to require.

### ⚠️ A three-byte character, deliberately

AC4's fixture is `str_repeat('⚡', 300)` — three bytes per character. A two-byte
character would divide evenly into 256 and the test would pass against
`substr()`, which is the defect. Watched red before the fix: the signer failed,
the reader passed **because it did not truncate at all**. That reader half only
became meaningful once AC1 was fixed, which is worth remembering when reading
the run — a green cell is not always the same green.

### Verified

`composer check` green (312 passed). Integration 136 passed / 16 skipped.
SPEC-025's own group still 24 passed, and its AC4 traceability row now points at
`ServiceError` — the Traceability section being the one part of an `implemented`
spec that may change. `bin/e2e.php` and `bin/verify.sh` PASS.
`php bin/spec-check.php` 0 errors.
