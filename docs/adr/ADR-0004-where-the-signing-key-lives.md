# ADR-0004: Where the signing key lives — no in-process signing, no WASM, no per-user keys; hardware is the one upgrade path

| Field    | Value                                                        |
|----------|--------------------------------------------------------------|
| Status   | accepted                                                     |
| Date     | 2026-08-07                                                   |
| Spec     | — (roadmap/architecture; a KMS/HSM signer would need its own) |
| Deciders | Maurice van Loon (maintainer)                                |
| Amends   | ADR-0003 (decision 3)                                        |

## Context

"Could the key live somewhere other than the isolated signing service?" has now
been asked in three forms — a native extension, WebAssembly, and a key held on
the user's own machine. Each arrives as a fresh idea and each ends at the same
place, so the reasoning is worth recording once rather than re-derived a fourth
time.

**ADR-0003 is also stale on one point.** Its decision 3 plans an
`ExtC2paSigner` adapter "as a future Signing spec". Since then, NOTES Step 23
found that `ericmann/ext-c2pa` hardcodes `tsa_url = None`, and Step 24 corrected
the reach argument that made an extension look attractive. CLAUDE.md and NOTES
already say the adapter stays unbuilt; the ADR — the artefact whose job is to
hold decisions — still says "plan it". This ADR closes that gap.

## Decision

**1. `ExtC2paSigner` is not planned.** This supersedes ADR-0003 decision 3. Three
independent reasons, any one of which is sufficient:

- it puts the private key in the PHP worker — the process that parses uploads and
  runs application code — which is the trade ADR-0003 rejected;
- the extension **cannot timestamp** (`tsa_url = None` in its `signer.rs`), so it
  would silently produce untimestamped manifests where SPEC-007 fails closed —
  a capability regression invisible in the output;
- its engine lags the service (c2pa-rs 0.89.0 against 0.90.4), which is already
  why SPEC-020 does not default to it for *reading*.

Reading through the extension stays supported and opt-in. The asymmetry is
deliberate and is documented as a trade rather than a free win
(`docs/c2pa-primer.md` §9, SPEC-025 AC6).

**2. No WebAssembly runtime inside PHP.** Compiling c2pa-rs to WASM is feasible —
`c2pa-js` is that, and it works — but running it *in a PHP process* requires
either a native WASM extension or `ext-ffi`, which is disabled in the web SAPI on
exactly the hosting this would be for. It swaps `c2pa.so` for `wasmer.so` and
adds a runtime to maintain. This is NOTES Step 24's error in a new costume:
trading one installation barrier for another and reporting it as the barrier
being gone.

**3. No per-user or browser-held signing keys.** The blocker is PKI, not
technology. A non-extractable WebCrypto key is in some ways better protected than
a PEM on a volume, but key material that reaches the browser *is* the user's key
by construction, and no CA issues C2PA certificates per end user. The result is
the state NOTES Step 19 measured against the official trust list: `Valid` +
`signingCredential.untrusted` — a correct signature nobody trusts. It would also
delete the audit trail (SPEC-012), leave revocation with no mechanism, and put
Conformance requirement O.2 out of reach.

Where a caller wants the *creator's* identity in the manifest, the C2PA answer is
a **CAWG identity assertion** on top of the operator's signature, not a second
signing key. That remains unbuilt and unspecced (NOTES Step 16, item 3).

**4. Hardware-backed keys (HSM/KMS) are the sanctioned upgrade path, and are not
built.** The hook already exists: SPEC-007's async `CallbackSigner` receives bytes
and returns a signature, which is the exact shape of a KMS `Sign` call. The
service would swap `crypto.createPrivateKey()` for an API call and nothing else
in the architecture moves.

Not built now, for the SPEC-016 reason: there is no deployment to shape the
design. **The trigger is a user with a real certificate asking how to store the
key.** Building it needs its own spec, and three things measured rather than
assumed:

- the signature encoding — a KMS typically returns DER-wrapped ECDSA while COSE
  ES256 wants fixed-length `r‖s`;
- the certificate stays a mounted file; a KMS provides signatures, not a chain;
- latency and cost per signature, against `MAX_CONCURRENT_SIGNS` and the rate
  budget.

## Consequences

- **Positive.** "The private signing key never touches your web application"
  stays literally true under all three rejected variants, and it stays the
  differentiator. The upgrade path to hardware costs no architectural change,
  because SPEC-007 already built the callback for a different reason.
- **Positive.** Three recurring proposals now have a written answer, so a future
  session can decline them by reference instead of re-deriving them.
- **Negative, and stated plainly.** Reach on cheap shared hosting remains
  unsolved. Neither an extension nor WASM-in-PHP reaches it, and the only route
  that would — a reader in pure PHP (JUMBF, CBOR, COSE, hash binding) — is large,
  unbuilt and unrequested (NOTES Step 24).
- **Negative.** Restart-based key rotation with a PEM on a volume remains
  Assurance Level 1. Level 2 wants hardware-backed storage and attestation, so it
  stays out of reach until decision 4 is acted on (`docs/production.md`).
- **Cost of decision 1.** Deployments that can install a native extension still
  need a second process to sign. That is the price of key isolation, and it is
  the price this package exists to charge.

## Follow-ups (not part of this ADR)

- **Browser-side verification via `c2pa-js`** is unaffected by any of the above:
  it holds no key, and the WASM runs in the visitor's browser rather than on the
  host, so hosting constraints do not apply. It is a front-end deliverable and a
  separate repository — explicitly *not* a third `ReaderInterface`. Note for
  whoever picks it up: a client-side verdict is a display, not a trust decision.
- Re-check `ericmann/ext-c2pa` for TSA support on any version bump; decision 1's
  second reason expires if that changes, though the other two do not.
- If CAWG identity assertions are ever wanted, they belong in a spec of their
  own; `@contentauth/c2pa-node` exports `createCawgTrustSettings`, deliberately
  left out of SPEC-014.
