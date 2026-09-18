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

## Addendum, 2026-09-18: where the Dutch list sits, and why it looks the way it does

The question that followed the measurement was whether a *Dutch* list is the
wrong scale — should it not be European? Answering it meant looking at what
already exists at that scale, and at MCNL's own stated reasons. Measured and
reasoned are kept apart below.

### Measured

- **The interim C2PA list is frozen; the official one is not.** CAI's
  documentation: the Interim Trust List (ITL) was frozen on 2026-01-01 — "No
  new certificates will be added to the list, and no updates will be made" —
  with existing entries kept for legacy validation. The official list in
  `c2pa-org/conformance-public` is the replacement, and it is live: 30 signing
  anchors and 22 TSA anchors on 2026-09-17, `log.txt` showing updates daily
  through that evening, the word "interim" absent from the repository. A
  sentence like "the official list is barely usable" conflates the two.
- **A European layer exists: IPTC's Origin Verified News Publishers List.**
  `https://trust.iptc.org/end-entity-list.pem` held **20 certificates** on
  2026-09-18 — BBC, CBC/Radio-Canada, AFP, France Télévisions, Deutsche Welle,
  WDR, NTB, RTÉ, Radio New Zealand and IPTC itself — issued by Truepic
  (first round), GlobalSign and DigiCert. No Dutch organisation. It is an
  **end-entity** list, which is exactly what c2pa-rs's `trust.allowed_list`
  field takes: a verifier loads the official anchors in `trust_anchors` and
  this file in `allowed_list`, in the same settings document. The EBU and
  CBC/Radio-Canada won this year's NAB Technology Innovation Award for an
  open-source C2PA video player that combines precisely those two lists
  (tech.ebu.ch, 2026).
- **IPTC's door is half open, and open for the NPO.** Its Credential Policy:
  "Applications to the IPTC Verified News Publisher programme are not currently
  'open'" — a phased roll-out judged on "internal priorities", among them "ease
  of identity proofing (which may be easier for IPTC members)" and whether the
  applicant's market is already served. But the Application Procedure has a
  short-cut: "Organizations that are members of IPTC or the **European
  Broadcasting Union** may fill in the self-certification form and email the
  IPTC Verified News Publisher contact point, using an email address that is
  already proofed as coming from a member." The NPO is an EBU member. So the
  organisation that commissioned the 2025 report had the cheapest route there
  is to a certificate every verifier recognises, and "it could not simply
  apply" — which this addendum said until 2026-09-18 — is wrong for the one
  party that matters. It holds for a non-member (a technology partner, a
  publisher outside the EBU, a freelancer). The timeline is softer than
  "closed" too: IPTC announced the list on 2024-04-14 as a BBC/CBC trial via
  Truepic, and in June 2025 it held six agencies — a pilot among large
  broadcasters, not a counter, but not shut.
- **MCNL's stated reason, in their own words, is independence from big tech;
  IPTC is never weighed.** The `c2pa-mcnl` README: the list is separate from
  the Conformance Program "ensuring independence and flexibility from big tech
  organizations for Dutch media corporations regarding the entities included
  in the list." The report the project builds on, *C2PA n'est pas une pipe*
  (June 2025, commissioned by NPO Innovatie, Media Campus NL and Beeld &
  Geluid), argues a power relation in the C2PA coalition and recommends an own
  verification tool so that usage data does not leak to contentcredentials.org,
  which "stelt de mediapartij ook in staat om een eigen 'trust list' te
  bepalen". IPTC's list appears once in that report, in an interview appendix,
  as a fact about Origin Verify ("adds certificates from six news agencies"),
  not as an option. Broadcast Magazine (11 September 2026) quotes VPRO: "Wij
  gebruiken een gedeelde specificatie, maar bouwen daar een eigen architectuur
  en trustlist omheen. Zo krijgt de Nederlandse mediasector een klein stukje
  autonomie terug." Searched and empty: `iptc` in the `c2pa-mcnl` code, its
  commits, PRs and issues; the Media Campus and SIDN pages.

### Reasoned: why the architecture is different, and what that costs

The report explains the choice better than the README does. The Dutch
precursor, *Proof of Provenance* (2022), signed with an identity from the IRMA
wallet (now Yivi), a third party confirming who signs. Measured against that,
the report finds C2PA lacking: "the (current) lack of a mandatory link to a
verifiable digital identity", one can sign as "NOS" in Photoshop with no check
on the name, and — quoting Tom Demeyer — "De meeste mensen hebben geen eigen
x.509 certificaat." It also records that C2PA 1.x carried W3C Verifiable
Credentials in the manifest, that 2.0 removed them, and that the replacement,
the CAWG identity assertion, was still being drafted.

So there are two ways of saying who signed, and MCNL picked the second:

| | X.509 organisational certificate (IPTC, Conformance Program) | Verifiable Credential + DID in a CAWG identity assertion (MCNL) |
|---|---|---|
| who is identified | the organisation | an organisation or a person |
| who issues the identity | a CA, on application | a wallet issuer — Yivi today, the EU Digital Identity Wallet under eIDAS 2.0 |
| where it lives | in the signing certificate itself | in a separate assertion; the X.509 certificate only signs |
| understood by every verifier | yes — c2pa-rs checks X.509 trust natively | no — each verifier must validate the credential itself |

The last row explains the rest of their design. An identity the engine does
not check itself needs a verifier that does (verifieermij.nl) and a list of who
may issue such identities (the DID allowlist). Both follow from the choice;
neither is the choice. And it explains why IPTC was not in view: IPTC improves
the certificate, MCNL wanted something other than a certificate.

With the IPTC route open to the NPO, "unreachable" drops out of the list of
reasons; what remains is the wallet model, the identity tradition, autonomy and
the funding shape — and that nobody looked.

What the choice buys: continuity with what the Netherlands already runs
(Yivi), alignment with what the EU will mandate (the EUDI wallet), and reach
down to an individual maker, which a publisher certificate never has. What it
costs, and what their README concedes ("a feature currently absent from the
specification"): outside verifieermij.nl the credential is invisible. c2pa-rs
validates the X.509 flavour of the CAWG identity assertion, not the VC flavour;
Adobe's Verify, the EBU player and every other verifier see a VPRO signature as
an unknown certificate. The identity so carefully attached is seen by one site.

None of this makes the two approaches rivals. A broadcaster can hold a
certificate a public verifier recognises **and** attach a credential for what
the Dutch sector wants to know on top. MCNL currently does only the second.
Two things follow for anyone talking to them, neither of which is a proposal
from this repository: loading the IPTC end-entity list beside their own is one
`allowed_list` entry and would make European broadcasters trusted on
verifieermij.nl at no cost; and a Dutch signature is unrecognised abroad for as
long as no Dutch organisation is on any list a foreign verifier reads.

For this package one paragraph changes. Trust lists are configuration
(SPEC-014), but `docs/production.md` named only `trust_anchors` and
`trust_config`; an end-entity list like IPTC's needs `trust.allowed_list`, and
that field was documented nowhere but in the SPEC-014 startup check. Measured
before writing it, c2patool 0.27.22 against `out/signed.png` (our test leaf):

| `trust_anchors` | `allowed_list` | verdict |
|---|---|---|
| empty | our leaf certificate | `Trusted`, `signingCredential.trusted` |
| empty | our leaf + `trust_config` | `Trusted` |
| official list (30) | our leaf | `Trusted` — the IPTC shape, anchors and end-entities side by side |
| official list (30) | the real IPTC file (20 certificates, ours not among them) | `Valid`, `signingCredential.untrusted` |

So `allowed_list` needs neither anchors nor an EKU configuration (no chain is
built for an end-entity), combines with `trust_anchors` in one document, and
the fourth row shows it is not "trust everything". The service side is read,
not run: `loadTrustSettings()` in `server.js` accepts `trust_anchors` or
`allowed_list` as trust material, so a document with only an `allowed_list`
starts. `ManifestReport` still surfaces X.509 signer identity only — a CAWG
identity assertion, X.509 or VC, would need its own reading spec and a user
asking for it.

Sources: CAI, *Trust lists* (opensource.contentauthenticity.org/docs/conformance/trust-lists);
IPTC, *Verified News Publisher Credential Policy* and *Certificate Policy*
(iptc.org/media-provenance); tech.ebu.ch, *EBU and CBC/Radio-Canada win NAB
Technology Innovation Award for C2PA-enabled video player* (2026); Media
Campus NL, *C2PA n'est pas une pipe* (June 2025, PDF); Broadcast Magazine,
*Digitale lakmoesproef* (11 September 2026); `Dawn-Technology/c2pa-mcnl`
README.

## Addendum, 2026-09-18 (later): their verifier loads the official list too, and the stated reason is a misreading

The previous addendum framed MCNL as running an own root *instead of* a
recognised certificate. Reading their production configuration and their
own "why" page corrects that in one direction and sharpens it in another.

### Measured

- **verifieermij.nl loads three anchor sources, not one.**
  `libs/verify-webapp/shared/environments/src/lib/environment.prod.ts` in
  `Dawn-Technology/c2pa-mcnl` lists, for signing anchors: their own
  `C2PA-TRUST-LIST.pem`, the official `c2pa-org/conformance-public` list, and
  Adobe's `https://verify.contentauthenticity.org/trust/anchors.pem`. The same
  three for TSA anchors; only their own file for DID issuers. So on the
  verifying side the official layer is present, beside their own — which is
  what the VPRO's "een Nederlandse vertrouwenslijst *ernaast*" (Broadcast
  Magazine) meant. "Continuing without the first layer" was overstated.
- **The stated reason for an own list is on verifieermij.nl/why**, an Angular
  route whose text sits in a JS chunk. Verbatim: "Bij C2PA worden deze
  certificaten uitgegeven door het C2PA-consortium dat gedomineerd wordt door
  Amerikaanse big-tech." and "Verifieer Mij vertrouwt de standaard
  C2PA-certificaten, maar schept ook de mogelijkheid om externe certificaten
  te vertrouwen. Hierdoor zijn we niet uitsluitend afhankelijk van de
  Amerikaanse techgiganten en behouden we onze digitale soevereiniteit."
- **The first sentence is wrong on the facts.** The consortium issues no
  certificates; it publishes a list of CAs that passed the Conformance
  Program. Certificates come from CAs, and the CA behind most IPTC-listed
  broadcasters (AFP, DW, WDR, France Télévisions, NTB, RTÉ) is GlobalSign
  nv-sa, Leuven. A European sovereignty argument does not need an own root;
  a Belgian CA reached through the EBU short-cut satisfies it, and every
  verifier recognises the result.
- **GlobalSign and Truepic are NOT on the official list.** Read from
  `C2PA-TRUST-LIST.pem` on 2026-09-17: Adobe, Castlabs, DigiCert (4),
  Encypher, Google (6), Huanyu, Huawei, Irdeto, SSL Corporation (2),
  Snowball, Tauth, Trufo, TrustAsia, Verimago, Whole Earth Labs, Xiaomi,
  vivo. No GlobalSign, no Truepic. Adobe's `anchors.pem` (27 certificates,
  20 organisations) has both: 2× GlobalSign nv-sa, 1× Truepic, 3× DigiCert.
  So the IPTC broadcasters are trusted on verifieermij.nl **only through the
  third source, the frozen interim list.** When those anchors expire or the
  file goes, the BBC and AFP read as unknown there. Loading IPTC's
  end-entity list into `allowed_list` would cover it; it is not loaded.
  (This is also a fact about IPTC: a verifier on the official list alone does
  not trust its publishers today. IPTC passed the Conformance Program in
  spring 2026, so its own CA may appear; GlobalSign's has not.)

### Not measured, and the only layer-1 question that still matters

Which certificate the VPRO signs with. Under MCNL's own root, no verifier
outside the Netherlands recognises it; under a listed CA, all of them do.
There is no public source for this — a signed VPRO asset would settle it in
one `c2patool` call, and none has been found.

### What this does to the earlier reading

"Why is layer 1 absent" was the wrong question; on the verifier it is not
absent. The remaining findings are narrower and all verifiable: a factual
error on the public "why" page (consortium ≠ CA), a European CA already in
use by the broadcasters they would want to resemble, a dependency on a frozen
list for trusting those broadcasters, an unloaded end-entity list that would
remove that dependency, and one unknown on the signing side. None of it
changes this package; `allowed_list` is documented since #142.

## Addendum, 2026-09-18 (evening): looking for a VPRO-signed image, and why the cloud cannot

The open question above — which certificate the VPRO signs with — needs one
signed VPRO file. Two attempts to find one, both empty, and one finding about
where such a check can run at all.

### Measured

- **Where the signed content is supposed to appear.** VPRO Medialab
  (vpro.nl, 2026-06-11): "Dawn Technology bouwt een signeertool, waarmee we
  vanaf dit najaar Tegenlicht-artikelen en afbeeldingen op onze website
  zullen ondertekenen volgens de C2PA standaard." The pilot was announced on
  2026-09-15 (Emerce, Fonk, Broadcast Magazine); none of the three articles
  names a sample file, a URL or a certificate authority.
- **Two local sweeps, zero manifests.** Every image URL on `vpro.nl/` and
  `vpro.nl/programmas/tegenlicht.html` (thumbnail variants `w_160/320/480`
  excluded, capped at 80 per page), downloaded and tested for the byte string
  `c2pa`:

  | run | homepage | Tegenlicht | hits |
  |---|---|---|---|
  | 2026-09-18 morning | 40 | 68 | 0 |
  | 2026-09-18 07:13 | 80 | 68 | 0 |

- **The CDN would strip a manifest anyway.** Every image is served through
  `images.vpro.nl/<id>/<transform>/<name>.webp`, resized and re-encoded; a
  request without the transform segment returns a 48-byte GIF, so no
  untransformed original is exposed. Under the immutability rule
  (`docs/c2pa-primer.md` §6) nothing that passes through that CDN can carry a
  valid manifest. So "afbeeldingen op onze website ondertekenen" requires
  either a CDN bypass for signed assets or a separate download path, and that
  is the first practical obstacle the pilot meets — which may be why nothing
  is visible yet.
- **The placeholder root is their generator's README example.**
  `tools/cert-generator/README.md` in `c2pa-mcnl` documents
  `--country NL --state "Zuid-Holland" --organization "My Company"`, and the
  signing webapp asks the user to upload a leaf certificate and key made with
  that tool. The certificate in the published trust list is that example.
  It says what the June proof-of-concept signed with; it says nothing about
  September production.
- **This check cannot run from the cloud routine.** A test run of the weekly
  routine with a VPRO part added got `connect_rejected` on `www.vpro.nl:443`
  from the sandbox's egress proxy ("organization policy"), both attempts. The
  routine reported it as unreachable rather than as "no manifest" — the
  right behaviour — and the VPRO part was removed the same day. The check is
  local-only: fetch the two pages, download the images, `grep -a c2pa`; a hit
  is read with `c2patool <file> -d | grep -i issuer`, which is the one line
  that would close this question.

### Not measured

Whether any VPRO content is signed through another channel (a press download,
a social original). No such file was found in any article about the pilot.
The question stays open; the search summary's mention of "a Bureau Buitenland
header image" as first test could not be traced to any page actually read and
is not used.
