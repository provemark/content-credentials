# Step 58 — Is the JPEG XL parser reachable through the service?

**Date:** 2026-09-05
**Measured against:** service on `@contentauth/c2pa-node` 0.9.3 (c2pa-rs 0.90.16),
local `tools/c2patool` 0.27.16, `cjxl`/`djxl` from libjxl (Homebrew).

## Why the question exists

c2pa-rs 0.90.17 (2026-09-03) bundles five hardening commits as PR #2579,
`fix: Merge vulnerability fixes`. One of them is *"Harden against unbounded
memory allocation attack in JPEG XL parser"*. We are on 0.90.16 and cannot move
— `@contentauth/c2pa-node` is still 0.9.3 and carries 0.90.16 — so the useful
question is not "when do we bump" but **"can an attacker reach that parser at
all through `/v1/sign` or `/v1/read`?"**

`MediaType` and `SUPPORTED_MIME` both refuse JPEG XL. The doubt came from the
primer §8 sentence *"the declared media type is advisory in both engines: c2pa-rs
recognises the format from the bytes, so a WAV offered as `image/webp` signs as
a WAV."* If that were literally true, a JXL offered as `image/png` would reach
the JXL parser and the allow-list would be no defence.

## Answer: no. The parser is not reachable.

Two probe files from the same `tests/Fixtures/fixture.png`:

- `probe.jxl` — bare codestream, magic `FF 0A` (`cjxl` default)
- `probe_box.jxl` — ISOBMFF container, magic `00 00 00 0C 4A 58 4C 20`
  (`cjxl --container=1`)

`POST /v1/read` and `POST /v1/sign`, bare codestream, under three allowed types:

| declared type | outcome | reason logged by the service |
|---|---|---|
| `image/png`  | 500 | `error parsing PNG: invalid file signature: … got [FF, 0A, FB, 06, …]` |
| `image/jpeg` | 500 | `type is unsupported` |
| `image/webp` | 500 | `error parsing RIFF: invalid file signature: expected "RIFF"` |

The JXL handler has a recognisable message of its own — c2patool on the same
file says `JPEG XL naked codestream cannot contain C2PA manifests` — and it
**never appears**. And the two spellings that would select it are refused by our
own allow-list before any parsing happens:

```
image/jxl     → HTTP 400 unsupported mime_type
image/jpegxl  → HTTP 400 unsupported mime_type
```

So the JPEG XL parser is behind the allow-list, and 0.90.17's fix for it is not
something we are exposed to on 0.90.16.

## The primer's mechanism was wrong, its example was right

The declared type selects a **handler**, and the handler then validates the
container signature. It is not byte sniffing. The WAV-as-`image/webp` example
works for a reason the sentence does not give: **WAV and WebP are both RIFF**, so
one handler covers both. Re-measured, both still sign:

```
fixture.wav as audio/wav    → signed
fixture.wav as image/webp   → signed        (same RIFF handler)
probe.jxl   as image/webp   → 500, "expected \"RIFF\""
```

That third line is what separates the two readings, and it is the line the
original claim never ran. Correction proposed for primer §8 — note `docs/` is in
the dist, so this is dist content.

## What the measurement turned up instead: a JXL container signs as `video/mp4`

A JPEG XL **container** file is ISOBMFF, and so is MP4. Declared as `video/mp4`
it goes to the BMFF handler, which does not object:

```
probe_box.jxl as video/mp4  → HTTP 200, signed_content returned
```

The result is worse than a refusal. The output is 26 443 bytes (from 5 119),
still decodes as an image (`djxl` returns the 320×240 pixels), and carries a
21 KB `uuid` box:

```
       0       12 'JXL '
      12       20 'ftyp'
      32    21284 'uuid'      ← the manifest store
   21316     5127 'jxlc'
```

**Nothing can read that manifest back.** Four attempts, four misses:

| read as | tool | result |
|---|---|---|
| `.jxl` | c2patool 0.27.16 | `Error: No claim found` |
| `.mp4` | c2patool 0.27.16 | `Error: No claim found` |
| `video/mp4` | `POST /v1/read` | `{}` — no manifest |
| `image/png` | `POST /v1/read` | `{}` — no manifest |

Not even the handler that wrote it. So `/v1/sign` returns **200 and a plausible
`signed_content`** for an asset whose credential is unverifiable — the failure is
silent, which is the one shape this project treats as worse than an error.

This is not the JXL parser and not a memory-allocation issue; it is the BMFF
handler accepting a sibling container. It needs a decision, not a patch: the
`video/mp4` entry in `SUPPORTED_MIME` currently accepts any ISOBMFF file, and
narrowing it means checking the `ftyp` brand, which is a behaviour change and
therefore a spec.

## What to carry forward

- The allow-list is doing real work; it is not decoration over a sniffing engine.
- **Handler families, not sniffing.** RIFF covers WAV/WebP/AVI, BMFF covers
  MP4/MOV/AVIF and — unintentionally — the JXL container. Any future claim of the
  form "type X is refused" must be checked against the family, not the spelling.
- A signature that reads back nowhere is the interesting bug here, and it was
  found by probing the thing next to the question rather than the question.
