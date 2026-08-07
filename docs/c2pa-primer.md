# C2PA Primer — verified project reference

Topic-ordered reference distilled from the spike log (@NOTES.md). Everything
here was verified against running code (c2patool 0.27.3, @contentauth/c2pa-node
0.8.1, c2pa-rs test certs), first on 2026-07-27 and last reconciled with
NOTES.md on 2026-08-05 — none of it is from model memory. When this page and
NOTES.md disagree, NOTES.md (the raw log) wins; fix this page. When neither
answers a question, ask — do not guess.

## 1. Manifest structure (claim v2)

- c2pa-rs ≥ 0.90 / c2pa-node ≥ 0.7 emit **claim version 2** manifests.
- The actions assertion label is **`c2pa.actions.v2`** (not `c2pa.actions`).
- Claim v2 REQUIRES an actions assertion whose **first action is
  `c2pa.created` or `c2pa.opened`**; anything else →
  `assertion.action.malformed: "first action must be created or opened"`.
- c2pa-node auto-adds a `c2pa.thumbnail.claim` assertion. Expected; harmless.

## 2. The AI-generated marking (EU AI Act, Art. 50)

The canonical assertion for "this asset is AI-generated":

```json
{
  "label": "c2pa.actions.v2",
  "data": { "actions": [
    { "action": "c2pa.created",
      "digitalSourceType": "http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia",
      "softwareAgent": { "name": "<generator name>" } }
  ] }
}
```

- `digitalSourceType` MUST be the full IPTC URI shown above (introduced in
  C2PA spec 1.3; also valid inside a v1 actions assertion).
- Convenient coincidence: this single assertion satisfies BOTH the Article 50
  marking AND claim-v2 well-formedness (first action = created).

## 3. Signing service contract (`service/`)

`POST /v1/sign` — `Authorization: Bearer $CONTENTAUTH_API_KEY`, JSON body:

| field              | required | meaning                                  |
|--------------------|----------|------------------------------------------|
| `content`          | yes      | base64 of the raw file bytes             |
| `mime_type`        | yes      | e.g. `image/png`                         |
| `signature_type`   | no       | `product` (default) / `cawg_org` / `both`|
| `creator_name`     | no       | prepended to claim_generator             |
| `org_name`/`org_url`| cawg only | CAWG organisational identity            |
| `extra_assertions` | no       | raw C2PA assertion objects, appended     |

Response: `{ "signed_content": "<base64>", "manifest_url": null }`.
Also: `POST /v1/read` (`{content, mime_type}` → decoded manifest) and public
`GET /health`.

**Deliberate divergence from the CAI wp-plugin contract:** our service does
NOT inject a hardcoded `c2pa.published` actions assertion. The PHP client
owns the actions assertion via `extra_assertions`, so the manifest carries
exactly one, correct actions assertion. (Upstream's service is unrunnable
scaffolding anyway — see NOTES.md Step 1 for the four blockers.)

## 4. c2pa-node API essentials (`service/src/`)

- Maintained package: **`@contentauth/c2pa-node`** (currently 0.8.1, carrying
  c2pa-rs 0.90.4). The old unscoped `c2pa-node` is EOL at 0.5.26 — never depend
  on it. The
  `contentauth/c2pa-node-v2` repo was archived ~2026-06-08; development and the
  real CHANGELOG moved to the `contentauth/c2pa-js` monorepo under
  `packages/c2pa-node`, while npm keeps publishing from there. Do not read
  version history off the archived repo — its tags stop at v0.5.5.
- Signer: `LocalSigner.newSigner(certChainBuf, privateKeyBuf, "es256"
  [, tsaUrl])`.
- **Timestamping requires the async path (SPEC-007, implemented).** Passing a
  `tsaUrl` and then calling the *synchronous* `builder.sign(...)` fails with
  `the sync http resolver is not implemented` — fetching the RFC 3161 token is
  an HTTP call. With a TSA the service uses `CallbackSigner.newSigner({ alg,
  certs, reserveSize: 20000, tsaUrl, directCoseHandling: false }, cb)` plus
  `await builder.signAsync(...)`; without one it keeps the sync `LocalSigner`.
  An unreachable TSA fails closed — there is no untimestamped fallback.
- Build: `Builder.withJson({ claim_generator_info, format, assertions, ... })`
  or `builder.addAssertion(label, data)`.
- **Critical gotcha:** `builder.sign(signer, source, dest)` RETURNS the JUMBF
  manifest-store bytes, NOT the signed asset. The signed asset is written to
  `dest` (`{path}` or `{buffer}`). Returning sign()'s value as the file yields
  a broken "image" whose read-back fails with header `6A 75 6D 62`
  (ASCII "jumb").
- Read/verify: `Reader.fromAsset({buffer, mimeType})` → `.json()`,
  `.getActive()`.
- **Gotcha (SPEC-010):** `Reader.fromAsset()` resolves to **`null`** — it does
  not throw — for an asset with no C2PA manifest, so an unguarded `.json()`
  crashes. `POST /v1/read` returns `{}` in that case, which decodes client-side
  to an empty `ManifestReport` (`hasManifest() === false`) per the SPEC-003
  contract.

## 5. Certificates & trust

- Test material comes from c2pa-rs `cli/sample/`: `es256_certs.pem` +
  `es256_private.key` (**ES256**, so `CONTENTAUTH_SIGN_ALG=es256`), plus
  `trust_anchors.pem`, `allowed_list.pem`, `store.cfg`.
- **"Valid signature" ≠ "trusted cert".** Test certs always produce
  `signingCredential.untrusted` under default verification; the signature
  itself is still cryptographically valid.
- Trusted verification needs BOTH `verify.verify_trust = true` AND trust
  material: `trust.trust_anchors` (CA PEM) + `trust.trust_config` (allowed
  EKU OIDs), or `trust.allowed_list`.
- Settings gotcha: the `trust.*` fields take PEM/EKU **file contents as
  strings, not paths**. `certs/c2pa-trust.settings.json` embeds them;
  `bin/verify.sh` wraps `c2patool --settings` with that file. Expected clean
  result: `validation_status: []`.
- EKU note: the test leaf cert's EKU is E-mail Protection
  (1.3.6.1.5.5.7.3.4); `store.cfg` lists the EKUs C2PA permits, so the chain
  passes.
- **NEVER run `c2patool init trust`** in this project: it fetches the
  PRODUCTION trust list, which (correctly) rejects test certs.
- Committable: public test CA certs, trust settings JSON. Gitignored forever:
  `es256_private.key`. Real/production keys never enter the repo, any branch,
  any fixture.

## 6. Immutability rule

Signing embeds a hash binding over the asset's byte ranges. ANY post-sign
mutation — re-encode, optimize, resize, metadata rewrite — invalidates the
manifest. Applies to code paths AND documentation examples.

## 7. Environment quirks

- npm allow-scripts may block `@contentauth/c2pa-node`'s postinstall (which
  fetches the native binary). Fix: `npm approve-scripts @contentauth/c2pa-node`
  or `npm rebuild`. Non-issue in Docker (scripts run at build).
- PHP target is ^8.3; dev machines may run 8.5. Use no 8.4/8.5-only features.
  `curl_close()` is a deprecated no-op — do not call it.

## 8. Supported asset types (SPEC-021/023, measured 2026-08-06/07)

The engine was never limited to PNG and JPEG; two hand-written allow-lists
were. Each type below was signed, read back `Valid` with the Article 50 marking
intact, and confirmed with `c2patool` under trust settings:

`image/png`, `image/jpeg`, `image/webp`, `image/avif`, `image/gif`,
`image/tiff`, `image/svg+xml`, `audio/wav`, `audio/mpeg`, `audio/flac`,
`video/mp4`, `video/quicktime`, `video/x-msvideo`.

- `audio/mp3`, `audio/x-flac` and `video/avi` are accepted as input spellings,
  normalised to the registered types.
- **SVG is signable but fragile**: SVGO's default preset removes the manifest
  silently, and re-serialising the XML makes the file unparseable as C2PA. Sign
  it as a deliverable, never as a build asset (SPEC-023, measured).
- **Not supported, each for its own reason** (NOTES Step 27): `application/pdf` —
  c2pa-rs registers readers and writers separately and PDF is read-only upstream,
  though the C2PA spec does define PDF embedding; `video/webm` — no Matroska
  handler at all; `image/x-adobe-dng` and JPEG XL — unmeasured.
- The declared media type is **advisory in both engines**: c2pa-rs recognises
  the format from the bytes, so a WAV offered as `image/webp` signs as a WAV.
  The 400 the service returns for e.g. `image/bmp` comes from our own
  allow-list, not from c2pa.
- **`video/mp4` is a container, not video support.** `MAX_BODY_SIZE` (20 MB) and
  the ~7× memory multiplier apply to every type, and the transport is base64 in
  one HTTP body. Small clips only; the 413 says so.
- Three lists must agree: `MediaType`, `SUPPORTED_MIME` in `service/server.js`
  (compared through `GET /health`), and the extension map in `InfersMediaType`.
- Unmeasured, therefore undeclared: DNG, JPEG XL.

## 9. The two readers, and where parsing happens

`SigningServiceReader` (HTTP, c2pa-rs 0.90.4) and `ExtC2paReader` (in-process,
0.89.0) answer the same questions. Two differences matter when choosing:

- **Engine version.** The extension lags the service, which is why `auto` is not
  the default (SPEC-020).
- **Process boundary.** The extension parses untrusted assets *inside the
  application process*; the service reader keeps that in a separate one. This is
  the mirror image of ADR-0003's key-isolation argument, and it is a deliberate
  trade rather than a free operational win (SPEC-025 AC6).

## 10. digitalSourceType: what can be claimed (SPEC-026)

Emittable — all three ride on the single `c2pa.created` action:
`trainedAlgorithmicMedia`, `compositeSynthetic`, `algorithmicMedia`.

Declared but refused by the builder, because C2PA records them as `c2pa.opened`
+ an **ingredient** (`parentOf`) + `c2pa.edited`, which this package cannot
build: `compositeWithTrainedAlgorithmicMedia`, `algorithmicallyEnhanced`,
`humanEdits`.

Absent on purpose: every authenticity term (`digitalCapture`,
`computationalCapture`, `digitalCreation`, film and print). A web application
receives bytes and cannot know a physical origin; signing such a claim turns
hearsay into attestation.

- ⚠️ **URIs come from `cv.iptc.org`, never from a document quoting it.** The C2PA
  Implementation Guidance misspells one as `compositedWithTrainedAlgorithmicMedia`
  (with a "d"); IPTC has never registered that term.
- ⚠️ `compositeWithTrainedAlgorithmicMedia` means *edited with generative AI*,
  not "contains AI elements" — that is `compositeSynthetic`.
- Reading: `isAiGenerated()` means exactly `trainedAlgorithmicMedia`;
  `involvesGenerativeAi()` is the wider question and is false for
  `algorithmicMedia`.
- Retired, never emit: `minorHumanEdits`, `digitalArt`, `softwareImage`.

## Open items (spec required before touching)

- `c2pa.actions` vs `c2pa.actions.v2` naming in public API/docs wording.

Closed since the spike: **TSA timestamping** (SPEC-007, implemented — see §4),
**manifest-less reads** (SPEC-010, implemented — see §4) and **asset types
beyond PNG/JPEG** (SPEC-021, implemented — see §8).
