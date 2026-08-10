# Step 6 — SPEC-007 TSA timestamping (verified findings)

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

[← Step 4/5](step-04-05-end-to-end-result.md) · [index](../NOTES.md) · [Step 7 →](step-07-property-based-tests-eris.md)
