# Step 65 — Recent c2pa developments, and the SSRF that is not one (measured 2026-08-10)

**Measured on 2026-08-10; landed on 2026-09-12.** It sat on an unmerged local
branch for a month and was found during a loose-ends sweep, by which time Steps
49 and 50 had taken its original number. The body below is the record as
measured, unedited — the survey half of it has since been overtaken, and the
section at the end says exactly where. The SSRF measurement is why it was kept
rather than dropped: a verified non-issue that is written down nowhere else is
one somebody investigates again.

Prompted by "looking at recent c2pa, do we need to add or change anything?"
Surveyed the spec, c2pa-rs and the conformance surface, then chased the two
things that could actually change what we do. The headline is a correction to my
own framing: the concern I raised — a CAWG did:web SSRF on the read path —
**turned out to be a non-issue on both engines, for two different verified
reasons.** Recorded because "measure, don't reason" applied to a security claim I
had reasoned my way into.

### The manifest shape does not change

Current spec is 2.x (2.2, May 2025). The AI-marking shape this package emits —
one `c2pa.actions.v2` with `c2pa.created` + `trainedAlgorithmicMedia` +
`softwareAgent` — is still exactly what 2.2's Implementation Guidance prescribes.
There is no `c2pa.actions` v3 and no new AI assertion to add. The SPEC-028
manipulation route (`setIntent('edit')` → auto-`c2pa.opened`) matches c2patool's
new `--update` mechanism. So nothing in the manifest we build is stale — the
primer's "introduced in spec 1.3" line is updated to say the current spec still
prescribes this, and that is the whole of the content change.

### ⚠️ The CAWG did:web SSRF: real upstream, not reachable here

c2pa-rs #2168 (CAI-10364, merged 2026-06-22) fixed a Server-Side Request Forgery
via **CAWG did:web resolution during post-validation** — reading an asset whose
CAWG identity names `did:web:<attacker>` made the library fetch an
attacker-controlled URL. Our service reads untrusted assets on `/v1/read` and on
the SPEC-028 parent-ingredient, so this looked like it could bite. It does not,
and neither reason was guessed:

- **Service (c2pa-node 0.8.3 / c2pa-rs 0.90.5): patched.** Checked the commit
  graph, not the version arithmetic: the fix commit `5b367284` is an ancestor of
  tag `c2pa-v0.90.4` (`compare` reports the tag *ahead_by 0, behind 63`), so it
  is in 0.90.4 and 0.90.5 both. We were never exposed on the 0.90 line — even
  before today's bump.
- **Extension (ext-c2pa, `c2pa = "=0.89.0"`): unpatched but toothless.** The fix
  is **not** in 0.89.0 (`compare` reports the fix `ahead_by 1`). But the
  extension builds c2pa with `default-features = false`, and c2pa 0.89.0's
  `default = ["openssl", "default_http"]` puts every HTTP client
  (`reqwest`/`ureq`/…) behind `default_http`. With defaults off there is **no
  HTTP client compiled in**, so did:web resolution has nothing to make the
  request with. No network, no SSRF — the same `default-features = false` posture
  that Step 23 noted for dropping remote-manifest fetch also drops this.

Method note: the decisive tool was GitHub's `compare` API (`base=tag ...
head=fix_commit`), which answers "is this fix in this release" from ancestry in
one call — far cheaper and more certain than crafting a CAWG-signed asset with a
did:web pointing at a local listener, which was the alternative.

### What remains true about the extension pin

`=0.89.0` still lags upstream on **local-parsing** hardening that
`default-features = false` does *not* neutralise, because it needs no network:
the desc-box underflow of Step 48 (#2334), a BMFF integer-overflow, and a PNG
iTxt XMP-parsing underflow all landed after 0.89.0 (backported to 0.89.1–0.89.3).
In release builds these are wrap-to-error rather than crashes — same measured
behaviour as Step 48's underflow — but the gap is now several fixes wide, not
one, and it only closes when the extension maintainer moves off the exact pin.
Unchangeable by us (Step 24), worth stating at its true size.

### The service is on the newest available c2pa-node, and one fix sits just past it

0.8.3 is the latest published `@contentauth/c2pa-node`. c2pa-rs **0.90.6** adds a
further did:web hardening ("reject did:web documents whose id doesn't match the
requested DID"), which is not yet in any published c2pa-node. Nothing to do but
take it on the next c2pa-node bump — the same standing-watch posture as the TSA
path.

### Possible additions, none required

c2pa-rs now supports HEIC/HEIF and JPEG XL upstream. Candidates for a
measured-media-types spec in the SPEC-021/023 shape (measure in both engines,
confirm with c2patool, then enum + the three allow-lists), only if a user wants
them. Video streaming (spec 2.2) stays out — the transport is base64 in one HTTP
body, and streaming is a separate project (Step 25).

### No release

Documentation only (`docs/c2pa-primer.md` is in the dist; `notes/` is
export-ignored). Rides in `[Unreleased]`. No code, no manifest, no service change
— the survey's conclusion is that none was warranted.

---

## What changed between the measurement and this landing (2026-09-12)

Read the survey above as a snapshot, not as current. Five of its statements have
moved, and one has not.

- **"The current spec is 2.2 and the manifest shape does not change" — superseded.**
  This package now declares `specVersion: "2.4.0"` and the actions assertion
  carries `"created": true` so it lands in `created_assertions`, because 2.4
  §18.15.2 narrowed the requirement from *either* array to that one
  (SPEC-035/036, primer §11). So the shape **did** change, which is the opposite
  of this note's headline. The `c2pa.created` +
  `trainedAlgorithmicMedia` + `softwareAgent` core is untouched.
- **"No new AI assertion to add" — superseded.** C2PA 2.4 §18.28 defines
  `c2pa.ai-disclosure`, which offers the `humanOversightLevel` axis this package
  cannot express. It is measured and it round-trips `Valid`; it is parked as
  SPEC-037 (`draft`) because its one mandatory field, `modelType`, is an
  ML-framework taxonomy a caller consuming a generation API cannot know.
  Transport was never the obstacle.
- **The engine numbers are four versions stale.** The service was measured here
  on c2pa-node 0.8.3 / c2pa-rs 0.90.5; it now runs **0.9.5 / 0.90.22**
  (Steps 55 and 64). The 0.90.6 did:web hardening this note flagged as "just
  past us, take it on the next bump" arrived long ago, along with sixteen more
  engine releases.
- **The SSRF conclusion still holds, and for the same two reasons.** The service
  is far past the fix. The extension is still pinned at `=0.89.0` and still
  builds with `default-features = false`, so there is still no HTTP client
  compiled in and did:web resolution still has nothing to make a request with.
  That posture is the load-bearing part; if the extension maintainer ever enables
  `default_http`, this re-opens.
- **The extension gap is no longer "several fixes wide".** It is 0.89.0 against
  0.90.22, and `ericmann/ext-c2pa` still has exactly one release (v0.1.0,
  16 July 2026). Unchangeable by us, as Step 24 said; just larger.
- **"HEIC/HEIF and JPEG XL are candidates" — withdrawn for JPEG XL.** Measured in
  Step 58: a bare JXL codestream cannot carry a manifest at all, and the
  container form only "signs" through the BMFF handler, unreadably — a silent
  wrong success. SPEC-039 now **refuses** foreign ISOBMFF major brands, `jxl `
  measured and HEIF/HEVC/CR3 by reasoning, so those brands are on the deny-list
  rather than on a candidate list. HEIC/HEIF stays unmeasured.
- **"Rides in `[Unreleased]`" — no longer true, and deliberately.** The primer
  edit this note proposed is **not** part of the landing: it said "the current
  spec is 2.x (2.2, May 2025)", which is now wrong, and §11 covers the same
  ground better. So nothing ships and there is no CHANGELOG line — `notes/` is
  `export-ignore`d. The measurement is the whole deliverable.
