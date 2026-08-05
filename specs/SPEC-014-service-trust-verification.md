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

- **AC5 — settings that would not actually verify are rejected at startup**
  *(error path; the experiment's key finding)*
  - Given a settings document that parses but cannot verify trust: `verify_trust`
    absent or false, or **no non-empty** `trust.trust_anchors` /
    `trust.allowed_list`
  - When the service starts
  - Then it exits non-zero naming which part is missing
  - Rationale: verified 2026-08-05 that `{ verify: { verify_trust: true },
    trust: {} }` produces **no error and no verification** — the read returns
    `Valid` + `signingCredential.untrusted`, byte-identical to configuring
    nothing at all. A malformed PEM does throw, so the dangerous case is the
    *absent* one, not the malformed one. Without this check an operator who
    believes trust is on gets a service that verifies nothing and reports the
    same `isTrusted() === false` as a correctly-configured service reading an
    untrusted asset — the two are indistinguishable from the outside, which is
    exactly the silent failure this spec exists to prevent

- **AC6 — trust status is observable**
  - Given any configuration
  - When `GET /health` is called
  - Then it reports whether trust verification is active, so an operator can
    confirm the mode of a running service without reading its environment
    (consistent with the existing `timestamping` flag)

- **AC7 — a manifest-less asset is unaffected**
  - Given trust verification is active
  - When an asset with no C2PA data is read
  - Then the response is still an empty store (HTTP 200, `{}`) per SPEC-010,
    and `isTrusted()` is `false` per SPEC-013 — trust verification must not turn
    absence into an error

- **AC8 — reading stays offline**
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

- ~~**Do the `trust.*` fields take file *contents* or file *paths*?**~~
  **RESOLVED by experiment (2026-08-05, NOTES.md Step 11): contents.** A path
  throws `Invalid settings: bad parameter: could not parse configuration: TOML
  parse error`. The NOTES Step 5 gotcha holds for c2pa-node exactly as it does
  for c2patool, and the README's `"path/to/anchors.pem"` is misleading.
  **`certs/c2pa-trust.settings.json` works verbatim** — passing it straight to
  `Reader.fromAsset` yields `validation_state: "Trusted"` with an empty
  `validation_status`. The API sketch below is therefore confirmed as written.
- ~~**Is `trust_config` (permitted EKU OIDs) required?**~~ **RESOLVED by
  experiment: no.** `trust.trust_anchors` alone yields `Trusted`; so does
  `trust.allowed_list` alone. AC1 is reachable with the existing test
  certificates, so the concern about the E-mail Protection EKU does not apply on
  this path. `trust_config` stays in the settings document because c2patool needs
  it and one document serving both is worth more than a minimal one.
- ~~**Should the exported settings helpers be used?**~~ **RESOLVED by
  experiment: no — they are a trap.** `createTrustSettings()` /
  `createVerifySettings()` emit **camelCase** keys (`trustAnchors`,
  `verifyTrust`), and passing the merged object straight to `Reader.fromAsset`
  **silently disables trust verification** — no error, `state=Valid`,
  `signingCredential.untrusted`, indistinguishable from having configured
  nothing. It only works when routed through `settingsToJson()`, which converts
  to snake_case. `loadSettingsFromFile()` is broken outright in 0.8.1
  (`fs.readFile is not a function`). The implementation must use the plain
  snake_case document and must not depend on these helpers.
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
| AC8                  | —                           | —                    |
