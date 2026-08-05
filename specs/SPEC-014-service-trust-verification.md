# SPEC-014: Trust-list verification in `/v1/read`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The signing service reads manifests with `Reader.fromAsset({ buffer, mimeType })`
and no settings, so c2pa-rs performs **no trust-list verification**. A correctly
signed asset therefore comes back `validation_state: "Valid"` with
`signingCredential.untrusted` in its status codes, whatever certificate signed
it.

The consequence is that this library cannot currently answer the question its own
API asks. SPEC-013 redefines `ManifestReport::isTrusted()` positively as
`validation_state === Trusted`, which is the accurate reading — but until the
service can produce that state, the method is **safe and permanently `false`**.
SPEC-013 records that as a deliberate consequence and points here.

Trust verification is not missing from the project, only from the read path.
`bin/verify.sh` already does it authoritatively, via c2patool and
`certs/c2pa-trust.settings.json` — a settings file this repo generates and has
proven end to end (signature valid / cert trusted / Art.50 mark, no failures).
The same capability exists in the library the service already depends on:
`Reader.fromAsset(asset, settings)` accepts per-instance settings including
`verify.verify_trust` and a `trust` block (verified 2026-08-05 against the
installed `@contentauth/c2pa-node` 0.8.1 README, which also exports
`createTrustSettings`, `createVerifySettings`, `mergeSettings` and
`loadSettingsFromFile`).

So the gap is plumbing, not capability: the service holds no trust configuration
and passes no settings. Closing it turns `isTrusted()` from safe-but-useless into
the verdict callers need, and lets `bin/e2e.php` assert trust through the library
path rather than only through c2patool.

**A silent failure here is the real danger.** Trust verification that is
misconfigured and quietly disabled is worse than none: `isTrusted()` would keep
answering `false` and look like a working, conservative check while nothing is
being verified. Configuration errors must therefore stop the service at startup,
the same way missing key material already does.

## Scope

**In scope**

- Loading a c2pa trust settings document at service startup from an env-supplied
  path, and passing it to every `Reader.fromAsset()` call in `/v1/read`.
- Failing fast at startup on a missing, unreadable or malformed settings file.
- Reporting whether trust verification is active on `GET /health`.
- Keeping the default unchanged: no settings configured ⇒ today's behaviour,
  `Valid` + `signingCredential.untrusted`, no new failure mode.
- `.env.example` and README documentation, including that `certs/c2pa-trust.settings.json`
  is **test** trust material and must be replaced for production.
- Extending `bin/e2e.php` to assert `isTrusted()` through the library path when
  the service reports trust verification active.

**Out of scope** (each needs its own spec before it may be built)

- OCSP fetching (`verify.ocsp_fetch`) and timestamp-trust verification
  (`verifyTimestampTrust`). Both add per-read network calls with their own
  failure and latency profile, and `ocsp_fetch` in particular turns a read into
  an outbound request — a deliberate decision, not a default to inherit.
- CAWG trust settings (`createCawgTrustSettings`), which belong with a CAWG
  identity spec.
- Trust verification on the **signing** path (`verify.verify_after_sign`).
- Fetching settings from a URL (`loadSettingsFromUrl`) — a remote trust list is
  a supply-chain surface and needs its own threat analysis.
- Any change to `src/`. `ManifestReport` already maps `validation_state` to
  `ValidationState::Trusted` (SPEC-005) and SPEC-013 makes `isTrusted()` read it;
  this spec only makes the service capable of producing that state.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-014')`, with the end-to-end
criteria in the integration group (they need a live service).

- **AC1 — a trusted certificate reads as Trusted**
  - Given the service is started with trust settings whose anchors cover the
    signing certificate
  - When a correctly signed asset is read via `/v1/read`
  - Then the manifest store reports `validation_state: "Trusted"`, no
    `signingCredential.untrusted` status is present, and
    `ManifestReport::isTrusted()` returns **true**
  - And this is the criterion that closes SPEC-013's open consequence

- **AC2 — an untrusted certificate stays untrusted**
  - Given trust verification is active with anchors that do **not** cover the
    signing certificate
  - When a signed asset is read
  - Then `validation_state` is `"Valid"`, `signingCredential.untrusted` is
    present, `isTrusted()` is `false` and `isSignatureValid()` is **true** —
    integrity and trust stay independent

- **AC3 — the default is unchanged** *(backwards compatibility)*
  - Given no trust settings are configured (the default)
  - When any asset is read
  - Then behaviour is byte-for-byte what it is today: `Valid` plus
    `signingCredential.untrusted` for the test certificates, no new errors, and
    `GET /health` reports trust verification inactive

- **AC4 — misconfiguration stops the service** *(error path)*
  - Given `CONTENTAUTH_TRUST_SETTINGS` points at a path that does not exist, is
    unreadable, or does not parse as a settings document
  - When the service starts
  - Then it exits non-zero with a message naming the problem and the path, and
    does **not** start serving — mirroring the existing cert/key startup checks
  - And it never starts with trust verification silently disabled

- **AC5 — trust status is observable**
  - Given any configuration
  - When `GET /health` is called
  - Then it reports whether trust verification is active, so an operator can
    confirm the mode of a running service without reading its environment
    (consistent with the existing `timestamping` flag)

- **AC6 — a manifest-less asset is unaffected**
  - Given trust verification is active
  - When an asset with no C2PA data is read
  - Then the response is still an empty store (HTTP 200, `{}`) per SPEC-010,
    and `isTrusted()` is `false` per SPEC-013 — trust verification must not turn
    absence into an error

- **AC7 — reading stays offline**
  - Given trust verification is active and no OCSP or timestamp-trust option is
    enabled
  - When an asset is read
  - Then the service makes no outbound network request, so a read cannot be
    delayed or failed by a third party

## API sketch

Illustrative only. Confined to `service/server.js`; the `/v1/read` request and
response shapes do not change.

```js
// service/server.js
const TRUST_SETTINGS_PATH = process.env.CONTENTAUTH_TRUST_SETTINGS || undefined;

let trustSettings; // undefined => no trust verification (today's behaviour)
if (TRUST_SETTINGS_PATH) {
  try {
    trustSettings = JSON.parse(fs.readFileSync(TRUST_SETTINGS_PATH, 'utf8'));
  } catch (err) {
    console.error(`CONTENTAUTH_TRUST_SETTINGS is not a readable settings document: ${TRUST_SETTINGS_PATH}`);
    process.exit(1);
  }
}

// ...
const reader = trustSettings
  ? await Reader.fromAsset({ buffer, mimeType }, trustSettings)
  : await Reader.fromAsset({ buffer, mimeType });
```

The settings document is the shape this repo already generates and has proven
with c2patool — `certs/c2pa-trust.settings.json`:

```json
{
  "trust":  { "trust_anchors": "<PEM contents>", "trust_config": "<EKU OIDs>" },
  "verify": { "verify_trust": true }
}
```

## Open questions

- **Do the `trust.*` fields take file *contents* or file *paths*?** This is the
  single biggest implementation risk and must be settled by experiment before
  coding. NOTES.md (Step 5) records that **c2patool** settings take the PEM and
  EKU **contents as strings**, and `certs/c2pa-trust.settings.json` is built that
  way. The c2pa-node README, by contrast, shows `trustAnchors: "path/to/anchors.pem"`
  in `createTrustSettings`. Both cannot be true for the same field. *Blocker for
  the API sketch, not for approval*: if c2pa-node wants paths, either the
  existing settings file is reused via a different helper, or a second
  path-shaped document is generated — decided by testing, and the finding
  recorded in NOTES.md.
- **Is `trust_config` (permitted EKU OIDs) required?** NOTES.md (Step 5) records
  that the c2pa-rs test leaf certificate's EKU is E-mail Protection
  (1.3.6.1.5.5.7.3.4) and that `store.cfg` is what lets the chain pass. If
  c2pa-node's settings expose no equivalent, AC1 may be unreachable **with the
  test certificates** even when the anchors are correct — which would be a
  property of the fixtures, not of the implementation. Must be proven, not
  assumed. *Non-blocker for approval*, blocking for marking AC1 met.
- **Should trust verification become the default once it works?** Leaving it
  opt-in keeps upgrades safe; making it the default makes the secure
  configuration the one you get without reading the docs. *Non-blocker*, leaning
  opt-in for this spec and revisiting at the next major, since flipping it would
  change `isTrusted()` for existing deployments without a code change on their
  side.
- **One settings document or discrete env vars?** A single JSON path reuses what
  `bin/verify.sh` already proves and keeps the service ignorant of trust-list
  structure; discrete vars are friendlier for container orchestration. *Non-blocker*,
  leaning the single document.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |