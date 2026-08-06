# SPEC-017: A body-size default matched to what the service actually signs

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-06                     |
| Supersedes | — (completes an item SPEC-015 put out of scope)   |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`MAX_BODY_SIZE` defaults to `50mb`. SPEC-015 identified it as the single biggest
lever on the service's memory use and deliberately left it alone, because
lowering it is a behaviour change for anyone signing large assets. This spec
picks that decision up with measurements rather than opinion.

Two facts make the current default wrong by more than it looks.

### The multiplier is bigger than documented

The README and SPEC-015 both say a signing request holds "roughly four copies"
of the asset — the parsed base64 string, the decoded buffer, the signed file
read back, and its base64 in the response. **Measured 2026-08-06, that
understates it.** Container memory at the concurrency cap of 4, against an idle
baseline of 17.6 MiB:

| Asset | base64 body | Peak at 4 concurrent | Above idle | Per request | × asset |
|---|---|---|---|---|---|
| 1.0 MB | 1.4 MB | 66 MiB | 48 MiB | 12.1 MiB | **12.1×** |
| 4.1 MB | 5.5 MB | 161 MiB | 143 MiB | 35.9 MiB | **8.7×** |
| 11.4 MB | 15.3 MB | 332 MiB | 314 MiB | 78.6 MiB | **6.9×** |

The ratio falls as assets grow — fixed per-request overhead amortises — but it
settles around **7× the asset**, not 4×. That correction belongs in the docs
regardless of what this spec decides.

### Extrapolated, the current default is a memory hazard

At `50mb` a body carries an asset of roughly 37 MB after base64 overhead. Four
of those in flight, at the measured 7×, is about **1 GB of peak memory** — in a
container many people will give 512 MB. The concurrency cap from SPEC-015 does
not help here: express buffers the body *before* any limit is consulted, so the
allocation happens whether or not the request is subsequently refused.

50 MB is also far above what this service legitimately signs. It accepts PNG and
JPEG only (SPEC-009). A 2000×2000 PNG of incompressible pixels — well beyond
typical generated output — measured 11.4 MB.

## Scope

**In scope**

- Lowering the `MAX_BODY_SIZE` default, and returning a clear error above it.
- Reporting the effective limit on `GET /health`, alongside the SPEC-015 limits.
- Correcting the "roughly four copies" claim in the README and documenting the
  relationship between body size, concurrency and peak memory, so an operator
  can size a container.
- A CHANGELOG entry under `Service`, marking it a behaviour change.

**Out of scope** (each needs its own spec before it may be built)

- Streaming or chunked signing, which would remove the multiplier rather than
  bound it. That is the real fix and a much larger one.
- Any change to the concurrency cap or rate limits (SPEC-015).
- Rejecting on a declared `Content-Length` before buffering. Attractive — it
  would close the allocate-then-refuse gap — but it is a separate mechanism from
  a body-size default and needs its own criteria.
- Asset types beyond PNG and JPEG.
- Any change to `src/`. The PHP client surfaces a non-2xx through
  `SigningFailedException`; it needs no knowledge of the limit.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-017')`, with the size-dependent
criteria in the integration group.

- **AC1 — realistic assets still sign**
  - Given a PNG or JPEG within the range this service exists for — up to a
    2000×2000 PNG of incompressible pixels, measured at 11.4 MB
  - When it is signed
  - Then it succeeds, and the manifest is what it was before this spec

- **AC2 — an oversized body is refused clearly** *(error path)*
  - Given a request body above the configured limit
  - When it is posted to `/v1/sign` or `/v1/read`
  - Then the service answers **413**, signs nothing, and the body names the
    limit and carries the correlation id (SPEC-012)
  - And the response is not an unhandled express error page or a bare 500

- **AC3 — the limit is configurable and observable**
  - Given `MAX_BODY_SIZE` set to any valid value
  - When `GET /health` is called
  - Then the effective limit appears in the `limits` block that SPEC-015 added

- **AC4 — the refusal is recorded** *(error path)*
  - Given a request refused for size
  - When it is refused
  - Then an audit record is written with `outcome: "rejected"` and a reason
    naming the limit (SPEC-012 AC2), and the record does not contain the body

- **AC5 — the documented multiplier matches measurement**
  - Given the README's guidance on sizing a container
  - When a reader follows it
  - Then it states the measured ~7× rather than "roughly four copies", and gives
    the relationship — peak ≈ concurrency × 7 × maximum asset — so an operator
    can compute a limit for their container rather than guess

## API sketch

Illustrative only. Confined to `service/server.js`; request and response shapes
do not change apart from the new 413.

```js
// 20mb of base64 carries an asset of roughly 15 MB, which at the measured 7x
// and a concurrency cap of 4 peaks around 420 MB — comfortable in a 512 MB
// container. 50mb peaked around 1 GB.
const MAX_BODY = process.env.MAX_BODY_SIZE ?? '20mb';
```

## Open questions

- **Is 20mb the right number?** It allows a ~15 MB asset, which covers every
  PNG and JPEG measured here with room to spare, and peaks around 420 MB at the
  concurrency cap. 10mb would halve that again but starts to exclude large PNGs.
  *Non-blocker*: the reasoning is what matters, and the value is one env var.
- **413 or 400?** Express's body parser raises `entity.too.large`, which maps
  naturally to 413. SPEC-009 established 400 for client errors generally, so
  this is a small divergence — justified because 413 tells the caller
  specifically that retrying with a smaller asset is the fix. *Non-blocker*,
  leaning 413.
- **Should this be released on its own?** It is a behaviour change for anyone
  signing assets above the new limit, however unlikely. It should not ride
  quietly on `main` — the same argument that made v0.5.1 worth tagging.
  *Non-blocker*, but decide before merging.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |