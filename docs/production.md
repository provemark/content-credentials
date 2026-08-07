# Going to production

Certificates a public verifier will trust, trust-list verification, and how
this package maps onto the C2PA Conformance Program. Back to the
[README](../README.md).

## Trust-list verification

By default the service does **not** verify the signing certificate against a
trust list. A signed asset then reads back as `Valid` with
`signingCredential.untrusted`, and `ManifestReport::isTrusted()` is `false` **by
design, not by failure** — the read simply never established trust. Signature
validity is unaffected: use `isSignatureValid()` for the integrity verdict.

Set `CONTENTAUTH_TRUST_SETTINGS` to a c2pa settings document to switch it on;
Docker Compose mounts the bundled **test** anchors ready to use:

```dotenv
CONTENTAUTH_TRUST_SETTINGS=/run/secrets/c2pa-trust.settings.json
```

`GET /health` then reports `"trust_verification": true`, and a certificate the
anchors cover reads back as `Trusted` with `isTrusted() === true`.

The service **refuses to start** if the document is unreadable, does not parse,
or could not actually verify — `verify.verify_trust` plus a non-empty
`trust.trust_anchors` or `trust.allowed_list`. That last check matters:
`verify_trust` without trust material verifies nothing *silently*, producing
reads indistinguishable from having configured nothing at all. Failing at
startup is what stops you believing trust is on when it is not.

The bundled anchors trust only the c2pa-rs **test** certificates. Replace them
with the trust list your verifier uses before production.

## Conformance alignment

The C2PA runs a [Conformance Program](https://github.com/c2pa-org/conformance-public)
with a public **Conforming Products List**. Worth being precise about who that
applies to, because it is easy to get backwards.

**This library cannot be on that list, and neither can any library.** A
*Generator Product* is defined as the set of software and configuration that
works together as a system to produce assets, and it "is always the Signer" and
the entity named on the list. That is **your deployment** — your application,
this service, and your certificate. The programme explicitly allows a Generator
Product to rely on a claim-generator service "created by the Applicant **or by a
different entity**", which is the role this package plays. You would be the
applicant; we would be a component.

If you do apply, you must submit a Generator Product Security Architecture
document. Here is how this service maps onto the Level 1 requirements that
concern the signing key (**O.2**), so you can describe it rather than reverse
engineer it:

| Requirement | How this architecture answers it |
|---|---|
| The key is held by a discrete component "with an unrelated attack surface" | The signing service is a separate process, in its own container, published on loopback only. The key never enters your PHP application. |
| Access follows least privilege | Certificate and key are read-only mounts; the service reads them once and exposes no endpoint that returns them. |
| Capable of rotating the claim signing key | Restart-based rotation, above, with `/health` reporting the live certificate so a rotation is verifiable. |

Dependency scanning (**O.3**) is covered in [SECURITY.md](../SECURITY.md).

Two things this does **not** claim. Assurance **Level 2** requires
hardware-backed key storage and attestation, which a PEM on a mounted volume is
not. And nothing here has been assessed by the Conformance Program — it is a
mapping to published requirements, not a conformance claim.

The Quickstart above is the shortest path. These sections are the reference:
the full set of accessors, and what each one does and does not tell you.

## Going to production

The test certificates above are only trusted against the bundled test settings.
For a signature a public verifier will trust, you need a certificate from a CA on
the C2PA trust list, issued through the C2PA conformance program. As of 2026,
[SSL.com](https://www.ssl.com/products/content-authenticity/content-credentials/c2pa/)
issues production-ready C2PA-conformant certificates, and its free tier includes
a Level&nbsp;1 signing certificate plus trusted timestamps — note it still
requires a valid C2PA conformance record ID at application.

For the full picture of certificates, trust lists and the valid-vs-trusted
distinction, see the write-up:
[**Valid ≠ trusted: a practical guide to C2PA signing certificates**](https://provemark.github.io/articles/c2pa-certificates/).
Whichever certificate you use, the private key stays isolated behind the signing
service — it never enters your web application.

**Trusted timestamps.** Set `CONTENTAUTH_TSA_URL` on the signing service to an
RFC 3161 Time Stamping Authority (e.g. `http://timestamp.digicert.com`) and every
signature carries a trusted timestamp, so its validity survives certificate
expiry. Unset, no timestamp is added (the default); if the TSA is unreachable the
signing request **fails closed** rather than producing an untimestamped
signature. `GET /health` reports `timestamping`, and
`ManifestReport::hasTimestamp()` confirms a read manifest is timestamped. A
timestamp's *trust* still depends on the TSA's own certificate chain.
