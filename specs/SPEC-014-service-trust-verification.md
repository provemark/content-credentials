# SPEC-014: Trust-list verification in `/v1/read`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-05                     |
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
  - *Amendment 1 replaces this criterion's Given: an allowed list without
    anchors beside it, and a `trust.anchors` block, are refused too. The text
    in force is Amendment 1's AC5, below.*

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

- **AC9 — every key the service passes on reaches the engine** (Amendment 1)
  - Given a settings document
  - When the service starts
  - Then each key under `trust` and `verify` is either mapped to its camelCase
    `Context` key (`trust_anchors → trustAnchors`, `trust_config →
    trustConfig`, `allowed_list → allowedList`, `verify_trust → verifyTrust`,
    and the keys SPEC-043 sets) or the service exits non-zero naming the key
  - Rationale: a `Context` drops an unknown key without a word; that is how
    item 2 above happened. Refusing is the only behaviour an operator can
    notice.

- **AC10 — an allowed list beside anchors still confers trust** (Amendment 1)
  - Given trust settings whose anchors do **not** cover the signing
    certificate, and whose `allowed_list` contains it
  - When a correctly signed asset is read
  - Then the verdict is `Trusted`, as AC1
  - And with the certificate on neither, `Valid` +
    `signingCredential.untrusted`, as AC2
  - Rationale: this is the IPTC configuration once AC5 requires anchors. That
    `toWireSettings()` attaching `allowedList` to each anchor entry gives this
    verdict is **reasoned from the source, not measured**; this criterion is
    what measures it.

- **AC11 — the Step 67 table, pinned** (Amendment 1)
  - Given the bump to the `Context` path
  - Then AC1 and AC2 hold through `new Context(mapped)`, and a test fails if
    the document reaches the engine unmapped (option 2 of Step 67)

## Amendment 1 — the trust document reaches the engine through a `Context`, and an allowed list alone is refused (approved 2026-10-20)

Approved by Maurice van Loon on 2026-10-20, choosing refusal at startup over
the service writing `trust.anchors[]` itself (see *Alternatives considered*).
Sources: NOTES Step 67 and its addenda (2026-09-18, 2026-09-28).

**Why now.** The pin on `@contentauth/c2pa-node` 0.9.5 (c2pa-rs 0.90.22) was
waiting for an engine carrying the fix for c2pa-rs #2731 (Step 67 addendum,
2026-09-28). #2731 was closed unmerged as superseded by **#2746**, backported in
**c2pa-rs 0.91.2**; **c2pa-node 0.9.10** (2026-10-16) pins `c2pa = "=0.91.3"` and
is the first release the bump can take *(read from release notes and the
workspace `Cargo.toml` at the tag, not run)*. c2pa-rs **0.92.0**, scheduled for
mid-November 2026, removes the flat `trust.trust_anchors` / `trust.user_anchors`
fields. Three things SPEC-014 states stop being true on that engine, and each is
silent rather than an error:

1. **Raw settings are deprecated**; the supported path is a `Context`, which
   takes **camelCase** keys. The resolved open question *"Should the exported
   settings helpers be used? … must use the plain snake_case document"* was true
   on 0.8.1 and is now reversed.
2. **A snake_case document handed to `new Context()` loses its trust material**
   while `verify.verify_trust` survives, so the service believes trust is on and
   verifies nothing *(measured, Step 67 addendum, c2pa-node 0.9.8: `Valid` +
   `signingCredential.untrusted`)*. AC5 cannot see it: the keys it checks are
   still in the file.
3. **An allowed-list-only document confers no trust**, raw or through a
   `Context` *(measured, same addendum; control: `Trusted` under c2patool
   0.27.22)*. Through a `Context` it cannot be expressed at all:
   `c2pa-utilities`' `toWireSettings()` rebuilds `trust` into `trust.anchors[]`
   from `trustAnchors` / `userAnchors` only, and attaches `allowedList` to those
   entries — with neither present, the list is dropped *(read: 0.3.1 in Step 67;
   0.3.2, the version c2pa-node 0.9.10 depends on, diffed 2026-10-20 — identical
   code, only the version number differs)*. A `trust.anchors[]` block in the
   input is discarded the same way.

`docs/production.md` teaches the allowed-list-only document for the IPTC
end-entity list. On the new engine that configuration would start reading every
publisher as untrusted, with no message anywhere.

### Changes

**AC5 is replaced by:**

- **AC5 — settings that would not actually verify are rejected at startup**
  - Given a settings document that parses
  - When the service starts
  - Then it exits non-zero, naming the problem and the path, if any of these
    holds:
    - `verify.verify_trust` is absent or not `true`;
    - `trust.trust_anchors` is absent or empty — **including when
      `trust.allowed_list` is non-empty**: the message says that an allowed list
      needs anchors beside it on this engine, and names `docs/production.md`;
    - the document carries `trust.anchors` (the c2pa-rs 0.91 / c2patool 0.28 /
      verifier form): the message says the service takes the flat form and that
      this block would be discarded unread
  - Rationale: unchanged from the original AC5 — the dangerous case is the
    configuration that is accepted and verifies nothing. This amendment adds
    the two shapes that became that case on c2pa-rs 0.91.

**New criteria:**

- **AC9 — every key the service passes on reaches the engine**
  - Given a settings document
  - When the service starts
  - Then each key under `trust` and `verify` is either mapped to its camelCase
    `Context` key (`trust_anchors → trustAnchors`, `trust_config →
    trustConfig`, `allowed_list → allowedList`, `verify_trust → verifyTrust`,
    and the keys SPEC-043 sets) or the service exits non-zero naming the key
  - Rationale: a `Context` drops an unknown key without a word; that is how
    item 2 above happened. Refusing is the only behaviour an operator can
    notice.

- **AC10 — an allowed list beside anchors still confers trust**
  - Given trust settings whose anchors do **not** cover the signing
    certificate, and whose `allowed_list` contains it
  - When a correctly signed asset is read
  - Then the verdict is `Trusted`, as AC1
  - And with the certificate on neither, `Valid` +
    `signingCredential.untrusted`, as AC2
  - Rationale: this is the IPTC configuration once AC5 requires anchors. That
    `toWireSettings()` attaching `allowedList` to each anchor entry gives this
    verdict is **reasoned from the source, not measured**; this criterion is
    what measures it.

- **AC11 — the Step 67 table, pinned**
  - Given the bump to the `Context` path
  - Then AC1 and AC2 hold through `new Context(mapped)`, and a test fails if
    the document reaches the engine unmapped (option 2 of Step 67)

**API sketch, replacing the reader construction:**

```js
// service/server.js — after loadTrustSettings() has passed AC5 and AC9
const trustContext = trustSettings
  ? new Context(toContextSettings(trustSettings)) // snake_case → camelCase, AC9
  : new Context(NO_REMOTE_FETCH_CTX);             // SPEC-043, mapped as well
const reader = await Reader.fromAsset({ buffer, mimeType }, trustContext);
```

`Builder.withJson()` is deprecated in the same release for `withJsonAsync(json,
context)`; the signing path gets the same `Context` (Step 67 addendum,
2026-09-18).

**Documentation:** the `trust.allowed_list` paragraph in `docs/production.md`
says that from this release an end-entity list needs `trust_anchors` beside it
(for IPTC: the official C2PA trust list), shows that document, and says that
an allowed list alone is refused at startup rather than ignored.

**Unchanged:** AC1–AC4, AC6–AC8; the operator still writes the snake_case
document this repository generates and c2patool 0.27 reads. The service never
reads the trust-list *structure* beyond the key mapping.

### Alternatives considered

- **The service writes `trust.anchors[]` itself, bypassing `Context`** (raw
  settings): keeps an allowed-list-only document working and would let the
  service read the same `trust.anchors[]` form the verifier route (SPEC-042)
  accepts. Rejected for now: it builds on the deprecated raw path, which
  c2pa-node says "will be removed in a future release", and makes the service
  own trust-list structure — the thing SPEC-014's "one document" answer chose
  to avoid. Not measured on 0.9.10.
- **Wait for upstream to accept an allowed list alone in a `Context`.** No
  issue for it exists in c2pa-js as of 2026-10-20 (not searched beyond the
  package source). Waiting runs into 0.92.0; a refusal now costs an operator one
  extra line of configuration and no silent failure.

### Open questions

- **Two document shapes for two routes.** The verifier route refuses a flat
  `trust.allowed_list` and accepts `trust.anchors[]`; the service would refuse
  `trust.anchors[]` and accept the flat form. An operator using an allowed list
  on both routes needs two files. **Accepted with the approval:** say so in
  `docs/production.md`; revisit if c2pa-node accepts `anchors` in a `Context`.
- **Should AC5 also refuse `trust.user_anchors`?** `toWireSettings()` maps it;
  SPEC-014 never documented it. *Leaning: map it (AC9) rather than refuse.*
- **c2patool 0.28** (`bin/verify.sh`, `certs/c2pa-trust.settings.json`) faces
  the same questions when `tools/c2patool` is refreshed. Not measured; out of
  scope here unless the measurement shows the shipped document breaks.

### Verification before the bump merges

AC1, AC2, AC5, AC9–AC11 green on c2pa-node 0.9.10; SPEC-035 AC7 (`specVersion`
signs and reads back, the reason the pin held); SPEC-043's probe tests (the
remote-fetch key survives the mapping); `php bin/e2e.php` trusted with the
Article 50 mark intact; a signed asset read back by `tools/c2patool`.

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
| AC1 | `tests/Integration/TrustVerificationTest.php` :: "reads a signed asset as Trusted when trust verification is active"; `bin/e2e.php` trust check | `service/server.js` `/v1/read` reader construction |
| AC2 | `tests/Integration/TrustVerificationTest.php` :: "keeps a signature valid but untrusted when the anchors do not cover it" | `service/server.js` `/v1/read` reader construction |
| AC3 | `tests/Integration/TrustVerificationTest.php` :: "leaves reads unchanged when no trust settings are configured" | `service/server.js` `trustSettings` (undefined when unset) |
| AC4 | `tests/Integration/TrustSettingsStartupTest.php` :: "refuses to start when the trust settings path does not exist"; "refuses to start when the trust settings file is not a settings document" | `service/server.js` `loadTrustSettings()` |
| AC5 | `tests/Integration/TrustSettingsStartupTest.php` :: "refuses to start on settings that parse but could never verify trust" | `service/server.js` `loadTrustSettings()` |
| AC6 | `tests/Integration/TrustVerificationTest.php` :: "reports trust verification status on /health" | `service/server.js` `GET /health` |
| AC7 | `tests/Integration/TrustVerificationTest.php` :: "still reads a manifest-less asset as an empty report under trust verification" | `service/server.js` `/v1/read` null-reader guard (SPEC-010) |
| AC8 | `tests/Unit/TrustSettingsDocumentTest.php` :: "does not enable any option that would make a read hit the network", "carries trust material that would actually verify" | `certs/c2pa-trust.settings.json` |
| AC5 (Am. 1) | `tests/Integration/TrustDocumentShapeTest.php` :: "refuses an allowed list without anchors beside it, pointing at the docs", "refuses a document carrying the trust.anchors form", "refuses a document whose verify_trust is not true", "starts with the trust document this repository ships" | `service/server.js` `loadTrustSettings()` |
| AC9 (Am. 1) | `tests/Integration/TrustDocumentShapeTest.php` :: "refuses a key under verify that it does not map, naming it", "refuses a camelCase key under trust, naming it", "accepts every key it maps" | `service/server.js` `toContextSettings()`, `readerContext` |
| AC10 (Am. 1) | `tests/Integration/TrustDocumentShapeTest.php` :: "reads Trusted when the allowed list beside foreign anchors names the signer" | `service/server.js` `readerContext` |
| AC11 (Am. 1) | `tests/Integration/TrustDocumentShapeTest.php` :: "reads Trusted under covering anchors and untrusted under foreign ones", "reads Trusted when user_anchors cover the signer beside foreign trust_anchors" | `service/server.js` `readerContext` |

Amendment 1 verified 2026-11-11 on c2pa-node 0.10.1 (c2pa-rs 0.91.10): the
`TrustDocumentShapeTest` cases green in the default profile; AC1 through
`php bin/e2e.php` with `CONTENTAUTH_TRUST_SETTINGS` set (`isTrusted: true`,
time-stamp present, c2patool `signingCredential.trusted`). Seen red on 0.9.5
when written (NOTES Step 74: 4 red, 10 green on 0.9.10).
