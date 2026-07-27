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
- **Thumbnail**: c2pa-node auto-adds a `c2pa.thumbnail.claim` (seen in the
  smoke test). Harmless; note it exists.