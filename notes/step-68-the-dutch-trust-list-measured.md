# Step 68 — The Dutch trust list, measured before joining it (2026-09-17)

Media Campus Nederland runs a verification service, verifieermij.nl, and
publishes the trust policy behind it as a repository:
`Dawn-Technology/c2pa-mcnl-trust-list`. The suggestion on the table was to
"join" it — verify against the Dutch list instead of our test anchors, then
honour its DID allowlist, and eventually write verifiable credentials at
signing time. Step 19 did the first of those against the official C2PA list, so
the question here was whether the same move against this list is worth
anything. The answer depends less on our code than on what the list contains,
so that was measured first.

## What the repository holds

Three files under `trust-list/`, named after the official conformance
programme's files on purpose (the README says so):

| file | README says | actually contains (2026-09-17) |
|---|---|---|
| `C2PA-TRUST-LIST.pem` | trust anchors for C2PA signing chains | **one** self-signed certificate: `C=NL, ST=Zuid-Holland, O=My Company, OU=IT Department, CN=Root CA`, valid to 2027-06-02 |
| `C2PA-TSA-TRUST-LIST.pem` | trust anchors for RFC 3161 timestamp chains | **zero** certificates |
| `ICA-ISSUERS-TRUST-LIST.txt` | one issuer DID per line, applied only when a manifest carries an identity assertion | four entries: one `did:jwk` (a bare Ed25519 key), `did:web:verify.contentauthenticity.org`, `did:web:c2pa.org`, and Adobe's staging identity endpoint |

`O=My Company, OU=IT Department` are the placeholder values openssl offers when
generating a certificate interactively. Nothing in the repository says whose
key sits behind that root, or how a Dutch media organisation would obtain a
certificate under it. The README is careful and well written — one issuer per
PR, git history as the audit trail, "never add an issuer without ownership and
trust validation" — but the list it governs has no policy content yet. The
official list (Step 19) has 29 certificates; this one has an example.

## Verifying against it: one settings file, and a predictable verdict

Both of our verification routes take a c2pa-rs settings document and nothing
else: `bin/verify.sh` hands `certs/c2pa-trust.settings.json` to c2patool, and
the service loads the same format through `CONTENTAUTH_TRUST_SETTINGS`
(SPEC-014). The PEM bundle goes into `trust.trust_anchors` as a string. So
"verify against the Dutch list" is a second settings file, and it was built:
both PEMs concatenated into `trust_anchors`, our own `trust_config` kept, same
asset as always (`out/signed.png`, signed with the c2pa-rs test certificate on
2026-09-14 by the current engine).

| settings | `validation_state` | signing credential | timestamp |
|---|---|---|---|
| ours (`certs/`) | `Valid` | `signingCredential.trusted` | `timeStamp.validated`, `timeStamp.untrusted` (informational) |
| **Dutch list** | `Valid` | **`signingCredential.untrusted`** | `timeStamp.validated`, `timeStamp.untrusted` (informational) |

Exactly Step 19's shape: the mechanism works, and our test certificate is not
on the list, which is the correct answer. Nothing we can sign today is trusted
by this policy, and nothing this policy trusts is something anyone can obtain.
"First non-TypeScript consumer of the Dutch trust list" would be a true
sentence about a settings file pointed at one anonymous certificate. Not worth
writing down anywhere but here.

Two things c2pa-rs 0.90.22 settles about the file layout, read from
`sdk/src/settings/mod.rs`:

- **There is no separate knob for timestamp trust.** `Verify` has
  `verify_timestamp_trust` (default true), documented as verifying "the
  timestamp certificates against the trust lists specified in `Trust`" — the
  same `trust_anchors`. The split into a C2PA bundle and a TSA bundle is
  verifieermij.nl's policy layout; a c2pa-rs consumer concatenates them.
- **The list carries no EKU configuration.** `trust.trust_config` still has to
  come from somewhere; ours (`certs/store.cfg` embedded) stayed in.

A side observation the measurement produced for free: **our own settings do
not trust our own timestamps either.** `timeStamp.untrusted` is reported as
informational under `certs/c2pa-trust.settings.json` too, because that file
holds the c2pa-rs test anchors and nothing from DigiCert's TSA chain. It never
showed because `bin/verify.sh` only reads `success` and `failure`. Not a defect
— SPEC-007 asserts the timestamp is present and validated, not that its chain
is trusted — but it is the first time the reason for a TSA trust list was
visible in our own output.

## The DID allowlist: the engine is further along than we are, and that is the hazard

The second proposed step was to read the issuer DID out of an identity
assertion and compare it with the text file. Three facts from c2pa-rs 0.90.22:

- `Settings` has a `cawg_trust: Trust` field beside `trust` — the same
  struct, so anchors and an allowed list for CAWG X.509 identity signers.
  0.90.21's changelog line "CAWG trust settings from the caller" is this.
- `core.decode_identity_assertions` defaults to true, so a `Reader` already
  decodes and validates CAWG identity assertions when it finds them.
- `ManifestReport::signer()` here returns X.509 issuer, common name and
  algorithm. `service/server.js` contains the word `cawg` nowhere. Whether
  c2pa-node surfaces the CAWG validation status codes in `Reader.json()` was
  **not measured**.

So the X.509 half of identity trust is engine work we merely have not exposed.
The DID list is about the other half: verifiable-credential identities
(`did:jwk`, `did:web`). Extracting a DID string from an assertion and matching
it against a file, without verifying the credential's own signature, is
trust by name — the assertion says "issued by `did:web:c2pa.org`" and a text
file agrees. That is the silent-wrong-success shape this project treats as
worse than an error. If this is ever built, the criterion is "the engine
validated the credential AND the issuer is listed", never the second half
alone.

## Writing credentials at signing time

Out of scope, and a different track from SPEC-034 (CAWG *metadata* —
creator and rights fields, not identity). The Dutch project's own README says
the DVC part is not yet in the specification. Nothing here moves on it
without a user asking.

## What "joining" would actually mean

Verifying against a list is the cheap half and proves nothing until the list
has content. The move that would matter is on the **signing** side: if Media
Campus intends to issue certificates under that root to Dutch media
organisations, a site running this package signs with such a certificate by
mounting it (`CONTENTAUTH_CERT_PATH`, rotation by restart per SPEC-018) and
verifieermij.nl then reports it trusted. No code on our side. The open
question is therefore theirs, not ours: who owns `CN=Root CA`, and is there an
issuance process. Until that is answered, this step is the whole of what
"joining" amounts to — and the trigger for any of the three proposed steps has
not fired.

Nothing in `src/`, `service/` or `specs/` changed. The settings file lives in
a scratch directory and is not committed; it is three lines of Python to
rebuild from the repository's two PEMs.

## Addendum, same day: the official list, and a wrong sentence in our own docs

Writing the settings-document instructions into `docs/production.md` meant
running them first. Built with the `jq` command now in that page, from the
official `c2pa-org/conformance-public` bundles (30 signing anchors, 22 TSA
anchors on 2026-09-17) plus `certs/store.cfg`, against `out/signed.png`:

| anchors in `trust_anchors` | signing credential | timestamp |
|---|---|---|
| official signing list only | `signingCredential.untrusted` | `timeStamp.validated`, `timeStamp.untrusted` |
| official signing + TSA lists | `signingCredential.untrusted` | `timeStamp.validated`, **`timeStamp.untrusted`** |

The first column is Step 19 again. The second is the finding: adding all 22
official TSA anchors changes nothing, because the responder behind
`http://timestamp.digicert.com` — `DigiCert SHA256 RSA4096 Timestamp Responder
2026 1` — does not chain to the four DigiCert entries on that list (`DigiCert
RSA4096 TSA ICA for C2PA G1` and its ECC and root siblings). DigiCert's public
TSA and DigiCert's C2PA TSA are different chains.

`docs/production.md` said that with `CONTENTAUTH_TSA_URL` set to that endpoint
"every signature carries a trusted timestamp". Presence, yes; trust, no. The
sentence was in the dist since the paragraph was written, and nothing in the
suite could have caught it: SPEC-007 asserts `hasTimestamp()`, and
`timeStamp.untrusted` is informational, which `bin/verify.sh` does not print.
Corrected in the same change, with the measurement date.
