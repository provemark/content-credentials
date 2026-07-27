# C2PA Primer — verified project reference

Topic-ordered reference distilled from the spike log (@NOTES.md). Everything
here was verified against running code (c2patool 0.27.3, @contentauth/c2pa-node
0.7.0, c2pa-rs test certs) on 2026-07-27 — none of it is from model memory.
When this page and NOTES.md disagree, NOTES.md (the raw log) wins; fix this
page. When neither answers a question, ask — do not guess.

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

- Maintained package: **`@contentauth/c2pa-node`** (repo `c2pa-node-v2`).
  The old unscoped `c2pa-node` is EOL at 0.5.26 — never depend on it.
- Signer: `LocalSigner.newSigner(certChainBuf, privateKeyBuf, "es256"
  [, tsaUrl])`. No tsaUrl configured yet → no trusted timestamp (open item;
  spec required before adding).
- Build: `Builder.withJson({ claim_generator_info, format, assertions, ... })`
  or `builder.addAssertion(label, data)`.
- **Critical gotcha:** `builder.sign(signer, source, dest)` RETURNS the JUMBF
  manifest-store bytes, NOT the signed asset. The signed asset is written to
  `dest` (`{path}` or `{buffer}`). Returning sign()'s value as the file yields
  a broken "image" whose read-back fails with header `6A 75 6D 62`
  (ASCII "jumb").
- Read/verify: `Reader.fromAsset({buffer, mimeType})` → `.json()`,
  `.getActive()`.

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

## Open items (spec required before touching)

- `c2pa.actions` vs `c2pa.actions.v2` naming in public API/docs wording.
- TSA timestamping for production-grade provenance.
- Asset types beyond PNG/JPEG (MP4, WAV).
