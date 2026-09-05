# SPEC-039: refuse foreign ISOBMFF containers on the signing path

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-05                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). Only the Traceability section of an
> `approved` spec may change without a new approval.

> **Approval decision, 2026-09-05.** The open question below was resolved on
> approval: **deny known foreign brands, do not allow known good ones.** A
> deny-list cannot refuse a legitimate file, which is the failure mode this
> project has been bitten by and which AC2 exists to catch. It closes only what
> it enumerates, and that is accepted: the measured defect is closed, and a new
> sibling format costs one line.

## Problem

`POST /v1/sign` accepts a **JPEG XL container** declared as `video/mp4`, signs
it, and returns HTTP 200 with a plausible `signed_content`. The credential it
writes cannot be read back by anything — including the handler that wrote it.

Measured 2026-09-05 (NOTES Step 58) against `@contentauth/c2pa-node` 0.9.3 /
c2pa-rs 0.90.16 and `c2patool` 0.27.16:

| step | result |
|---|---|
| `cjxl --container=1` → 5 119 bytes, first box `JXL ` | — |
| sign as `video/mp4` | **HTTP 200**, 26 443 bytes back |
| `djxl` on the result | decodes, 320×240 — still a valid image |
| box dump of the result | `JXL ` / `ftyp` / **`uuid` (21 284 bytes)** / `jxlc` |
| `c2patool` as `.jxl` | `Error: No claim found` |
| `c2patool` as `.mp4` | `Error: No claim found` |
| `/v1/read` as `video/mp4` | `{}` — no manifest |
| `/v1/read` as `image/png` | `{}` — no manifest |

The cause is not the JPEG XL parser (which is unreachable — the allow-list
refuses `image/jxl` and `image/jpegxl` with 400, and a bare codestream is
refused by every allowed type). The cause is that **a JXL container is ISOBMFF,
and so are MP4, QuickTime and AVIF**. The declared type selects a handler, not a
format, and the BMFF handler takes any ISOBMFF box structure without asking what
it is. Primer §8 now says so.

Why this matters more than its rarity suggests: the failure is a **silent wrong
success**. The caller is told the asset is signed. Nobody discovers otherwise
until someone tries to verify, at which point the asset looks like one whose
manifest was stripped — indistinguishable from tampering. This project's
domain rules already treat "fails closed" as the required shape for the TSA path
(SPEC-007); the same reasoning applies here.

Reaching it requires deliberately mislabelling a JPEG XL file as MP4, so this is
a correctness and diagnostics defect rather than a security finding. It is
reported as one.

## Scope

**In scope**

- Validating the container brand of assets declared as an **ISOBMFF type**
  (`video/mp4`, `video/quicktime`, `image/avif`) on `POST /v1/sign`, for both
  the primary `content` and the SPEC-028 `parent`.
- Refusing a mismatch with the existing 400 shape, audited like every other
  rejection (SPEC-012).

**Out of scope** (each needs its own spec before it may be built)

- `POST /v1/read`. A foreign container there yields `{}`, which is already the
  correct answer for "no manifest" and misleads nobody.
- Adding JPEG XL, HEIF/HEIC or any other type to `SUPPORTED_MIME`.
- Brand validation for the RIFF family (`audio/wav`, `image/webp`,
  `video/x-msvideo`). The RIFF handler already refuses foreign input with
  `error parsing RIFF: expected "RIFF"` — measured, and a correct failure.
- Client-side (PHP) validation. `MediaType` describes what may be declared; the
  service is the guard on what the bytes are.

## Behavior

Acceptance criteria as Given/When/Then, each covered by a Pest test tagged
`->group('SPEC-039')`.

- **AC1 — a JPEG XL container declared as `video/mp4` is refused**
  - Given a JPEG XL container asset (`ftyp` major brand `jxl `)
  - When it is posted to `/v1/sign` with `mime_type: "video/mp4"`
  - Then the response is **400**, no signing takes place, and the reason names
    the brand found and the type declared

- **AC2 — every currently supported asset still signs, unchanged**
  - Given the existing fixtures, whose brands are measured as
    `fixture.mp4` major `isom`, compatible `isom iso2 avc1 mp41`;
    `fixture.mov` major `qt  `, compatible `qt  `;
    `fixture.avif` major `avif`, compatible `avif mif1 miaf MA1A`
  - When each is signed under its own declared type
  - Then each returns 200 and reads back with the Article 50 marking intact
  - *This is the overreach guard. A brand check that refuses a legitimate file is
    a worse defect than the one being fixed, so this criterion pins concrete
    measured values rather than asserting "nothing broke".*

- **AC3 — the deny-list reads the MAJOR brand only, never the compatible brands**
  - Given `fixture.avif`, whose compatible brands include `mif1`
  - When it is signed as `image/avif` with `mif1` present in the deny-list
  - Then it still returns 200
  - *Measured trap: `mif1` is generic HEIF and a plausible deny-list entry, and
    it sits in our own AVIF fixture's compatible brands. Denying on compatible
    brands would refuse a file we support today.*

- **AC4 — the `ftyp` box is located, not assumed to be first**
  - Given a JPEG XL container, whose box order is `JXL ` / `ftyp` / `jxlc`
  - When the brand is read
  - Then the check finds the `ftyp` box at its real position and refuses per AC1
  - *Measured: a JXL container puts a 12-byte `JXL ` signature box ahead of
    `ftyp`. An implementation that reads bytes 4..8 of the file finds `JXL `,
    no `ftyp`, and lets the asset through — the fix would silently not work.*

- **AC5 — an asset with no `ftyp` box at all is accepted, not refused**
  - Given input declared `video/quicktime` that carries no `ftyp` box
  - When it is posted to `/v1/sign`
  - Then the brand check does not refuse it, and the outcome is whatever the
    BMFF handler decides
  - *This is the deny-list's own discipline. QuickTime does not require `ftyp`,
    so "no brand found" must mean "nothing to deny", never "refuse". Reasoned
    from the QuickTime container format, not measured — our own `fixture.mov`
    does carry `ftyp`. If a future measurement contradicts it, this criterion is
    the one to revisit.*

- **AC6 — the check applies to the `parent` ingredient too**
  - Given a valid MP4 as `content` and a JPEG XL container as `parent`, declared
    `video/mp4`, with an edit-intent actions assertion (SPEC-028)
  - When posted to `/v1/sign`
  - Then the response is 400 naming the **parent** as the offending asset, and
    nothing is signed

- **AC7 — malformed container input is refused, not crashed** *(required error path)*
  - Given input declared `video/mp4` that is truncated inside its first box
    header, or whose first box length is smaller than 8, or whose declared box
    length runs past the end of the buffer
  - When posted to `/v1/sign`
  - Then the response is **400** with a stable reason, the process does not
    throw, and no partial asset is written
  - *A box-length walk is attacker-controlled arithmetic. It must be bounded by
    the buffer length and by a fixed maximum number of boxes inspected.*

- **AC8 — the refusal is audited and quotes nothing raw**
  - Given any AC1/AC6/AC7 refusal
  - When the audit record is inspected (SPEC-012)
  - Then it carries the request `cid`, `outcome: failed` and a reason, and the
    reason contains only the four-character brand rendered printably — no raw
    asset bytes, and no control characters (SPEC-006 AC8 reasoning)

- **AC9 — reading is unaffected**
  - Given the same JPEG XL container
  - When posted to `/v1/read` as `video/mp4`
  - Then the response is **200** with `{}`, exactly as today

- **AC10 — the three lists still agree**
  - Given `GET /health`
  - When `media_types` is compared to `MediaType` and to `InfersMediaType`
  - Then all three still match — this spec narrows what is *accepted*, never
    what is *declared*

## API sketch

Illustrative only. The check belongs beside the existing `SUPPORTED_MIME` test in
`service/server.js` (around `server.js:1002`), on the sign path only.

```js
// Major brands that positively identify a container we do not support. Denying
// cannot over-refuse: an unknown brand, or no ftyp at all, is let through.
// `jxl ` is measured (NOTES Step 58). The HEIF/HEVC brands are reasoned — none
// of them is the major brand of any type in SUPPORTED_MIME — and unmeasured.
const DENIED_BMFF_BRANDS = new Set([
  'jxl ',                                     // JPEG XL container  (measured)
  'heic', 'heix', 'heim', 'heis',             // HEIF still image   (reasoned)
  'hevc', 'hevx', 'hevm', 'hevs',             // HEVC still image   (reasoned)
  'crx ',                                     // Canon CR3          (reasoned)
]);

const BMFF_TYPES = new Set(['video/mp4', 'video/quicktime', 'image/avif']);

/**
 * Major brand of the first `ftyp` box, or null when there is none to find.
 * Null means "nothing to deny" — see AC5. A JPEG XL container puts a 12-byte
 * `JXL ` box ahead of `ftyp`, so this walks boxes rather than reading offset 4.
 * Bounded by the buffer length and by MAX_BOXES_SCANNED — see AC7.
 */
function majorBrand(buf) { /* ... */ }
```

Note the deny-list is consulted for the **major** brand only. `mif1` is a
plausible entry and deliberately absent: it sits among our own AVIF fixture's
compatible brands (AC3).

## Open questions

- ~~Allow-list of brands, or deny-list of known foreigners?~~ **Resolved on
  approval, 2026-09-05: deny-list.** See the decision note at the top.
- ~~Should `mif1` be accepted for `image/avif`?~~ **Resolved: yes**, and the
  deny-list mechanism makes it moot — `mif1` is not denied, and AC3 pins that it
  stays that way.
- The HEIF/HEVC/CR3 entries in the deny-list are **reasoned, not measured**. They
  cost nothing to include and cannot over-refuse, but no test asserts they behave
  like `jxl ` because no fixture exists for them. Adding one is optional and
  needs no new approval. **Non-blocking.**
- This is service-side, so it ships through `git pull` + rebuild and a tag
  delivers it to nobody. Whether that warrants a CHANGELOG entry under a
  `### Service` heading — as the c2pa-node 0.9.3 bump did — or something louder,
  is a release decision. **Non-blocking.**

## Traceability

All fourteen tests carry `->group('SPEC-039', 'integration')` and need the
service running; they are excluded from `composer check` like every other
integration test. Verified 2026-09-05: **14 passed** in the group, **161 passed /
19 skipped** for the whole integration suite (up from 147/19, exactly these
fourteen), `composer check` green at 354 passed / 7 skipped / 10 deprecated, and
`php bin/e2e.php` still ends in a trusted `bin/verify.sh` verdict with the
Article 50 marking intact.

Seen red before green: against the unpatched service the group reported **8
failed, 6 passed** — the six that pass either way are the AC2/AC3 fixtures, AC5,
AC9 and AC10, which assert that nothing was broken rather than that something was
fixed.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/IsobmffBrandTest.php` :: "refuses a JPEG XL container declared as video/mp4", "refuses a JPEG XL container declared as video/quicktime", "refuses a JPEG XL container declared as image/avif" | `service/server.js` `rejectForeignBrand()`, `DENIED_BMFF_BRANDS` |
| AC2 | `tests/Integration/IsobmffBrandTest.php` :: "still signs fixture.mp4, whose major brand is isom", "still signs fixture.mov, whose major brand is qt" | `service/server.js` `DENIED_BMFF_BRANDS` — a deny-list, so an unlisted brand is never refused |
| AC3 | `tests/Integration/IsobmffBrandTest.php` :: "still signs fixture.avif, which carries mif1 among its compatible brands" | `service/server.js` `majorBrand()` — reads `offset + 8 .. offset + 12` only, never the compatible brands |
| AC4 | `tests/Integration/IsobmffBrandTest.php` :: "finds the ftyp box behind the JXL signature box rather than at offset four" | `service/server.js` `majorBrand()`, `MAX_BOXES_SCANNED` |
| AC5 | `tests/Integration/IsobmffBrandTest.php` :: "leaves an asset with no ftyp box to the engine instead of refusing it" | `service/server.js` `majorBrand()` returns null → `rejectForeignBrand()` returns null |
| AC6 | `tests/Integration/IsobmffBrandTest.php` :: "refuses a JPEG XL container offered as the parent asset" | `service/server.js` `brandProblem`, parent branch in `POST /v1/sign` |
| AC7 | `tests/Integration/IsobmffBrandTest.php` :: "refuses a box header that runs past the end of the buffer", "refuses a first box shorter than its own header" | `service/server.js` `majorBrand()` bounds: `size < 8`, `offset + size > buf.length`, `MAX_BOXES_SCANNED` |
| AC8 | `tests/Integration/IsobmffBrandTest.php` :: "names the brand printably and carries a correlation id" | `service/server.js` `rejectForeignBrand()` printable render, `reject()` audit record |
| AC9 | `tests/Integration/IsobmffBrandTest.php` :: "still reads a JPEG XL container as an empty manifest report" | `service/server.js` — the check sits on the sign path only |
| AC10 | `tests/Integration/IsobmffBrandTest.php` :: "narrows what is accepted without narrowing what is declared" | `service/server.js` `SUPPORTED_MIME` unchanged, `GET /health` unchanged |
