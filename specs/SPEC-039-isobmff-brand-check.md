# SPEC-039: refuse foreign ISOBMFF containers on the signing path

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.

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
  - Given a JPEG XL container asset (first box `JXL `, `ftyp` major brand `jxl `)
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

- **AC3 — the check applies to the `parent` ingredient too**
  - Given a valid MP4 as `content` and a JPEG XL container as `parent`, declared
    `video/mp4`, with an edit-intent actions assertion (SPEC-028)
  - When posted to `/v1/sign`
  - Then the response is 400 naming the **parent** as the offending asset, and
    nothing is signed

- **AC4 — malformed container input is refused, not crashed** *(required error path)*
  - Given input declared `video/mp4` that is truncated before its first box
    length, or whose first box length is smaller than 8, or which has no `ftyp`
    box at all
  - When posted to `/v1/sign`
  - Then the response is **400** with a stable reason, the process does not
    throw, and no partial asset is written

- **AC5 — the refusal is audited and quotes nothing raw**
  - Given any AC1/AC3/AC4 refusal
  - When the audit record is inspected (SPEC-012)
  - Then it carries the request `cid`, `outcome: failed` and a reason, and the
    reason contains only the four-character brand rendered printably — no raw
    asset bytes, and no control characters (SPEC-006 AC8 reasoning)

- **AC6 — reading is unaffected**
  - Given the same JPEG XL container
  - When posted to `/v1/read` as `video/mp4`
  - Then the response is **200** with `{}`, exactly as today

- **AC7 — the three lists still agree**
  - Given `GET /health`
  - When `media_types` is compared to `MediaType` and to `InfersMediaType`
  - Then all three still match — this spec narrows what is *accepted*, never
    what is *declared*

## API sketch

Illustrative only. The check belongs beside the existing `SUPPORTED_MIME` test in
`service/server.js` (around `server.js:1002`), before any base64 decode of the
full body is handed to the builder.

```js
// ISOBMFF families we declare, and the brands measured as belonging to them.
// A brand counts if it appears as the major brand OR among the compatible
// brands — `mif1`-major AVIF is real and must not be refused.
const BMFF_BRANDS = {
  'video/mp4':        new Set(['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'dash']),
  'video/quicktime':  new Set(['qt  ']),
  'image/avif':       new Set(['avif', 'avis', 'mif1', 'miaf']),
};

/** @returns {null|{major: string, compatible: string[]}} null when not ISOBMFF-shaped */
function readFtyp(buf) { /* first box must be `ftyp`; JXL puts `JXL ` there */ }
```

Note the JPEG XL container is caught twice over: its first box is the 12-byte
`JXL ` signature box rather than `ftyp`, and its brand is `jxl `. Either test
alone closes AC1; the brand table is the general form and also closes HEIC and
any future ISOBMFF sibling.

## Open questions

- **Allow-list of brands, or deny-list of known foreigners?** The sketch above
  allows. That is the general fix, and it is also the one that can refuse a
  legitimate file if a brand is missing from a set — the failure mode this
  project has been bitten by before. A deny-list (`jxl `, `heic`, `heix`, `mif1`
  when declared as mp4) cannot over-refuse but closes only what is enumerated.
  **Non-blocking**: AC2 makes either choice testable. Maintainer decides.
- Should `mif1` be accepted for `image/avif`? It is in our own fixture's
  compatible brands, so yes for compatibility — but `mif1` alone, without
  `avif`, is generic HEIF and may be a different format. Measure before deciding.
  **Non-blocking.**
- This is service-side, so it ships through `git pull` + rebuild and a tag
  delivers it to nobody. Whether that warrants a CHANGELOG entry under a
  `### Service` heading — as the c2pa-node 0.9.3 bump did — or something louder,
  is a release decision. **Non-blocking.**

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
