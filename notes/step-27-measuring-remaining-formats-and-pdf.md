# Step 27 — Measuring the remaining formats, and what PDF really costs (2026-08-07)

SPEC-021 listed six formats as "unmeasured, therefore undeclared": SVG, PDF,
DNG, AVI, MOV, WEBM. Measured them, plus one that turned up on the way.

### Results

Signed inside the running container with `@contentauth/c2pa-node` 0.8.1
(c2pa-rs 0.90.4), bypassing `SUPPORTED_MIME`, then read back, verified with
`c2patool` (trust on) and re-read through `ExtC2paReader` (c2pa-rs 0.89.0).

| Format | MIME | Sign | Read back | Art. 50 | c2patool | ext-c2pa 0.89 |
|---|---|---|---|---|---|---|
| SVG | `image/svg+xml` | ok | `Valid` | present | PASS | agrees |
| MOV | `video/quicktime` | ok | `Valid` | present | PASS | agrees |
| AVI | `video/x-msvideo` | ok | `Valid` | present | PASS | agrees |
| **FLAC** | `audio/flac` | ok | `Valid` | present | PASS | agrees |
| PDF | `application/pdf` | **fails** | (reads) | — | — | **throws** |
| WEBM | `video/webm` | **fails** | **fails** | — | — | throws |
| DNG | `image/x-adobe-dng` | *not measured — see below* | | | | |

FLAC was not on the list. It surfaced from the binary's parser names and turned
out to work end to end, so it is a candidate alongside the other three.

MOV carries an extra `c2pa.hash.bmff.v3` assertion — the BMFF hash binding,
same family as MP4. Expected, not a defect.

### ⚠️ DNG was NOT measured, though it looked like it was

I could not produce a real DNG, so the probe was a plain TIFF named `.dng`. It
signed, read back `Valid`, kept the marking and passed c2patool — and all of
that is worthless as evidence. Reading the signed file back as `image/tiff`,
as `image/x-adobe-dng` and as `image/png` gave the same answer three times, and
its first bytes are `49492a00` (TIFF magic). The declared type is advisory, so
c2pa-rs simply saw TIFF, which we already support.

Recorded because the wrong conclusion was one step away: "DNG works" would have
shipped on the strength of a green result that measured a format we already had.
A real DNG — raw sensor data, DNG tags — is needed before that claim.

### ⚠️ And a probe that produced nothing while looking like a result

The first attempt to inspect the native binary ran `strings` **inside** the
container. That image has no `strings`, so every candidate came back "absent" —
a clean table of negatives, produced by a command that never ran. Copy the
binary to the host (`docker cp`) and inspect it there. Same shape as the vacuous
tests this log keeps collecting: absence of output is not evidence of absence.

### Why PDF fails, precisely

Not an allow-list of ours, and not a missing parser. c2pa-rs keeps **two
registers**, both visible in the binary:

```
c2pa::jumbf_io::get_cailoader_handler    <- readers
c2pa::jumbf_io::get_caiwriter_handler    <- writers
c2pa::asset_handlers::pdf_io::SUPPORTED_TYPES
c2pa::asset_handlers::pdf::Pdf::from_reader
```

PDF is registered as a reader only. Signing looks for a *writer*, finds none,
and that surfaces as `type is unsupported`. Confirmed at the source: in
`sdk/src/asset_handlers/pdf_io.rs` all three write methods return
`Err(NotImplemented(WRITE_NOT_IMPLEMENTED))` — "PDF write functionality will be
added in a future release" — and `get_writer()` returns `None`.

So it is a **not-yet**, not a refusal, and not a specification gap either: the
C2PA spec defines PDF embedding (§3.4 lists PDF 1.7 and PDF 2.0; Appendix A.4
carries the normative procedure, including how a C2PA manifest relates to a
native PDF signature). The spec exists; the Rust implementation lags. Worth
re-checking on every `@contentauth/c2pa-node` bump, like the TSA path.

WEBM is a different answer. c2pa-rs's `asset_handlers/` holds bmff, c2pa, flac,
gif, jpeg, **jpegxl**, mp3, pdf, png, riff, svg, tiff — no Matroska, in either
register. That is why WEBM fails on reading too. Nothing suggests it is coming.
(JPEG XL is there and is now the one remaining unmeasured candidate.)

### The real cost of PDF is not the enum — it is that our engines disagree

| | service (0.90.4) | extension (0.89.0) |
|---|---|---|
| PDF read | works (returns "no manifest") | **throws `type is unsupported`** |

PDF would be the first media type where the two readers give different answers.
SPEC-019 and SPEC-020 both rest on them being interchangeable, and SPEC-020's
`auto` mode picks the extension whenever it is loaded, **without knowing the
format** — so the same application code would read a PDF or throw, depending on
whether an extension happens to be installed on that host.

On top of that, `MediaType` serves both directions: `Asset` carries it, and the
service checks one `SUPPORTED_MIME` on `/v1/sign` *and* `/v1/read`. A read-only
type has nowhere to live. Adding `application/pdf` would make `sign()` compile
and then fail with a 400 from the service, where the type system gives certainty
today.

### What would have to happen in the package

**If the engine gains PDF writing** and both engines carry it, PDF is ordinary:
an enum case, `.pdf` in the extension map, `SUPPORTED_MIME`, a fixture, an AC
that signs and reads back, README and CHANGELOG. Half a day, no structural
change. Four things to verify rather than assume:

1. PDF's own digital signatures — what adding a manifest does to an
   already-signed PDF. Appendix A.4 covers it; read it, do not reason it out.
2. Embedding goes through annotations and the associated-files array (per the
   binary's own error strings). Ordinary PDF tooling — linearise, merge,
   compress, "save as" — rewrites the file, so the immutability rule bites far
   harder here than for a JPEG.
3. Size. Scanned PDFs are large, and the 20 MB body limit with its ~7×
   multiplier is not theoretical for them.
4. Both engines, not one. The extension lags the service by a minor version
   today; a format is only supported for us when both carry it, or we accept
   per-deployment variation deliberately.

**If we ever want asymmetric capability at all** — read-only PDF, or a format
only one engine knows — the package needs a capability model, not a longer
list:

- `MediaType` carrying what can be signed and what can be read, rather than one
  flat set;
- `ReaderInterface::supports(MediaType): bool`, with SPEC-020's `auto` binding
  taking it into account instead of picking blind;
- the service splitting `SUPPORTED_MIME` into sign and read lists, publishing
  both on `/health` — SPEC-021 AC2 then compares two pairs;
- a distinct exception so "this cannot be signed" fails early in PHP rather than
  as a 400 from the service.

**Deliberately not built now.** The design should be shaped by what actually
arrives: if PDF writing lands and the extension catches up, symmetry returns and
the whole capability layer is unnecessary. Building it first would add
complexity for a situation that may never occur.

### Consequence for the next spec

Four shippable formats — SVG, MOV, AVI, FLAC — all working in both engines and
confirmed by c2patool. That is a SPEC-021-shaped spec and nothing more. PDF,
WEBM, DNG and JPEG XL stay out, each for a different and now-documented reason,
which is worth more than the four types.

---

[← Step 26](step-26-spec-022-builder-entry-point-name.md) · [index](../NOTES.md) · [Step 28 →](step-28-spec-023-thirteen-media-types.md)
