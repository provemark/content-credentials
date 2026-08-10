# Step 11 — c2pa-node trust settings: what actually works (verified 2026-08-05)

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

[← Step 10](step-10-reproducible-service-builds-npm-ci.md) · [index](../NOTES.md) · [Step 12 →](step-12-spec-014-trust-verification-in-read.md)
