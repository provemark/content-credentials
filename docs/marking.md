# What you can mark, and what you are claiming

The media types this package signs, and the `digitalSourceType` terms it can
put in a manifest. Choosing the wrong term is a false statement about an asset
rather than a style error, so each is given with its definition. Back to the
[README](../README.md).

## Supported media types

| Media type | `MediaType` case | File extensions |
|---|---|---|
| `image/png` | `Png` | `.png` |
| `image/jpeg` | `Jpeg` | `.jpg`, `.jpeg` |
| `image/webp` | `Webp` | `.webp` |
| `image/avif` | `Avif` | `.avif` |
| `image/gif` | `Gif` | `.gif` |
| `image/tiff` | `Tiff` | `.tif`, `.tiff` |
| `image/svg+xml` | `Svg` | `.svg` |
| `audio/wav` | `Wav` | `.wav` |
| `audio/mpeg` | `Mp3` | `.mp3` |
| `audio/flac` | `Flac` | `.flac` |
| `video/mp4` | `Mp4` | `.mp4` |
| `video/quicktime` | `Mov` | `.mov` |
| `video/x-msvideo` | `Avi` | `.avi` |

`audio/mp3`, `audio/x-flac` and `video/avi` are accepted as input spellings and
normalised to the registered `audio/mpeg`, `audio/flac` and `video/x-msvideo`.
**That normalisation happens in the PHP client**, which then sends the
registered type — so if you call `/v1/sign` directly rather than through this
package, use the registered spelling: the service's own allow-list holds those
only, and answers an alias with a 400.

Anything outside this table is refused — by the client with
`UnsupportedMediaTypeException`, and by the service with a **400** naming what
it does accept. A running service publishes its own list at `GET /health`
(`media_types`), so you can check a deployment rather than trust this table.

**Size applies to every media type, not per format.** `MAX_BODY_SIZE`
(default 20 MB) and the ~7× memory multiplier described under
[Sizing the container](service.md#sizing-the-container) are the same for a PNG and for an
MP4. That comfortably covers images and short audio — but note that **lossless
audio is not short audio**: a few minutes of FLAC approaches or exceeds the
20 MB body limit, so `MAX_BODY_SIZE` is the first thing to check before signing
music rather than voice clips.

It does **not** cover real video. `video/mp4`, `video/quicktime` and
`video/x-msvideo` are supported as *containers* — they sign, read back and carry
the Article 50 marking exactly like an image — but they are **bounded to small
files**, because the transport is base64 in one HTTP body: the asset is
inflated by a third, buffered whole, and held several times over while signing.
A body over the limit is refused with a **413** that says so. Signing video of a
realistic length needs a different transport
(streaming or path-based signing), which is a separate piece of work and not
something this version does.

#### Signing SVG: sign the deliverable, not the build asset

SVG is the one format whose ordinary tooling destroys the credential by default,
so it needs more than the general rule that post-sign edits invalidate a
manifest. Measured against a signed SVG:

| Operation | Result |
|---|---|
| **SVGO with default settings** | the manifest is removed **silently** — the image renders identically and a verifier cannot tell it from a file that was **never signed** |
| **Any tool that re-serialises the XML** | the namespace prefix is rewritten and the file no longer parses as C2PA |

`preset-default` includes `removeMetadata`, and every common bundler
(`vite-svg-loader`, `svgr`, webpack's SVGO loaders) runs SVGO with defaults. So
an SVG signed and then added to a front-end build arrives at the browser
unsigned, with nothing anywhere reporting a problem.

The rule that follows: sign SVG as a final deliverable, **not as a build asset**
— a generated diagram or illustration handed over as a file, rather than one
about to enter an asset pipeline. If you must do the latter, disable
`removeMetadata` and verify the output.

#### Formats this package does not accept

Not excluded on principle — each has a reason:

| Format | Why not |
|---|---|
| `application/pdf` | c2pa-rs can **read** C2PA from PDF but not write it (`pdf_io.rs` returns "PDF write functionality will be added in a future release"). The C2PA specification does define PDF embedding, so this is an upstream gap, not a specification one. |
| `video/webm` | Matroska. c2pa-rs has no handler at all, which is why it fails on reading too. |
| `image/x-adobe-dng` | Unmeasured. A TIFF renamed `.dng` proves nothing, because the engine reads the format from the bytes. |
| JPEG XL | A handler exists upstream; we have not measured it. |

This project does not ship what it has not seen work.

## What you are claiming: digitalSourceType

The marking says *how the asset came about*, and choosing the wrong term is a
false statement about provenance rather than a style error. Three can be
emitted, each with IPTC's own definition:

| Constructor | Term | IPTC's definition |
|---|---|---|
| `forAiGenerated()` | `trainedAlgorithmicMedia` | created algorithmically using an AI model trained on captured content |
| `forSynthetic()` | `compositeSynthetic` | a mix or composite of several elements, at least one of which is generative AI |
| `forAlgorithmic()` | `algorithmicMedia` | created purely by an algorithm not based on any sampled training data |
| `forAiManipulated()` | `compositeWithTrainedAlgorithmicMedia` | augmentation, correction or enhancement **using** a Generative AI model |

`ManifestBuilder::forSourceType($type, $mediaType)` is the general form beneath
them.

**Do not reach for `compositeWithTrainedAlgorithmicMedia` because it sounds like
"composite".** It means an edit of something that already existed, not a new
asset assembled from parts. For a new asset mixing AI and non-AI elements the
term is `compositeSynthetic`, above.

## Marking manipulated content

Article 50(2) covers content that is *generated **or manipulated***, and the two
are different manifests. Generation rides on a single `c2pa.created` action.
Manipulation is three things: a `c2pa.opened` action, an **ingredient** for the
original with a `parentOf` relationship, and a `c2pa.edited` action carrying the
source type.

The consequence for you is one extra argument — **the original asset**, not a
filename or a hash, because the ingredient is a hash binding over its bytes:

```php
$manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
    ->withSoftwareAgent('ACME Inpainting', '2.0')
    ->build();

$signed = ContentCredentials::sign(
    new Asset($editedBytes, MediaType::Png),
    $manifest,
    parent: new Asset($originalBytes, MediaType::Png),
);
```

Omit the parent and you get `MissingParentAssetException` before any request is
sent; pass one for a manifest that marks *creation* and you get
`UnexpectedParentAssetException`. Neither is pedantry: c2pa-rs signs both of
those shapes without complaint and reports the result `Valid`, so nothing below
this package would tell you the manifest claims a lineage it does not carry.

Three consequences worth knowing before you build a pipeline on it:

- **Both assets travel in one request**, and `MAX_BODY_SIZE` applies to their
  sum. The client refuses over-budget pairs before encoding rather than letting
  the service answer 413.
- **A signed original is carried into the result.** If the parent already has a
  credential, its whole manifest comes with it — provenance is preserved for
  free. Measured: a chain of edits grows by about **90 KB per generation**
  (55 KB → 144 KB → 234 KB → 324 KB from a small fixture), linearly rather than
  compounding.
- **Peak memory is about 4.6× the two assets together**, so four concurrent
  manipulations of the largest admissible pair peak near 245 MiB — below the
  ~420 MiB that four maximum-size single-asset signings cost, because the parent
  is hashed rather than signed. `MAX_BODY_SIZE` remains the lever.

The remaining editing terms — `algorithmicallyEnhanced` and `humanEdits` — build
through `forSourceType()` and need a parent asset in exactly the same way.

**On the reading side**, `isAiGenerated()` means exactly `trainedAlgorithmicMedia`
and always will — code already gates Article 50 decisions on it. Use
`involvesGenerativeAi()` for the wider question: true for
`trainedAlgorithmicMedia` and `compositeSynthetic`, false for `algorithmicMedia`,
which is the point of that term — synthetic, but no model and no training data.

This package deliberately cannot assert that something was **captured**. A web
application receives bytes; whatever it says about a physical origin it is
repeating, and a C2PA assertion is signed, which turns hearsay into attestation.
