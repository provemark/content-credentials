# Step 63 — The head-and-tail probe, measured on large files (2026-09-10)

`drupal/content_credentials` asked a question it deliberately did not answer
itself: most uploads carry no credential, so can a manifest be ruled out without
reading the whole file? Its own NOTES Step 2 measured the offset of the ASCII
string `c2pa` in signed assets, found it within a few hundred bytes for eleven
of thirteen types and in the tail for the two RIFF ones, and then wrote that the
finding *"belongs in `provemark/content-credentials`, not here — it is a claim
about container layout"*. It was measured over fixtures of 0.1 to 16 KiB.

At that size every window contains the whole file, so the measurement could not
fail. This step re-runs it where it can.

## What was measured

Thirteen large assets, one per supported type, generated with `ffmpeg` (noise
sources, so nothing compresses away) and one hand-written SVG: **3.6 MB to
148 MB**, two to four orders of magnitude above the original fixtures. Each was
signed with `c2patool` 0.27.16 against the c2pa-rs test certificates, and each
reads back `validation_state: Valid`.

The four tail-side types were then signed a second and third time — small
(13–38 KB) and mid-size (1.9–9.8 MB) — through **this repository's own signing
service**, to see whether size or signer moves the answer.

The needle is `strpos($bytes, "c2pa")`, a literal ASCII search, exactly as the
downstream measurement did it. No JUMBF parsing.

## Nine types put it at the head

First `c2pa` occurrence, signed with `c2patool`:

| type | signed size | first `c2pa` |
|---|---:|---:|
| FLAC | 18,488,976 | 33 |
| JPEG | 31,503,136 | 48 |
| PNG | 45,814,228 | 57 |
| MP3 | 9,615,495 | 56 |
| MOV | 50,995,077 | 81 |
| AVIF | 31,413,164 | 93 |
| MP4 | 50,995,130 | 93 |
| SVG | 7,438,216 | 136 |
| GIF | 3,889,960 | 812 |

The offset does not move with file size: the 45 MB PNG has its first hit on byte
57, the same as a small one. GIF at 812 is the worst case.

## Four types anchor it to the end — and the distance is ours, not theirs

WAV, AVI, WebP and TIFF get the manifest store appended after the payload, so
the first hit sits wherever the original file ended. What matters for a probe is
the **last** hit, which is the one closest to EOF:

| signed by | distance from EOF to last `c2pa` | measured across |
|---|---:|---|
| `c2patool` 0.27.16 | **12,320** | 3.8 MB – 148 MB |
| this repository's service | **20,023–20,024** | 13 KB – 11 MB |

Both numbers are constant. Not approximately constant — the same integer for
every file of every one of those types, at every size tried.

**The difference is `reserveSize: 20000` in `service/server.js`, and it was
falsified before being written down.** The obvious suspect was the RFC 3161
timestamp, since the service timestamps and `c2patool` by default does not. So
`c2patool` was run again with `ta_url` set to the same TSA, and the timestamp
demonstrably landed — `signature_info.time` is present in the read-back. The
distance stayed at **12,320**. The timestamp is not the cause; the reserved
signature box is.

That is the load-bearing result of this step:

> **The tail window a probe needs is set by the signer's reserve size, not by
> the format and not by the file size.**

A 16 KiB tail window would have passed every `c2patool` file above and **failed
on this repository's own output**. The small-file measurement could not see
that, and neither could any measurement that used only one signer.

## The window that follows

**4 KiB head + 32 KiB tail** catches all thirteen types on both signers: five
times the worst head case (812) and a little over 1.5× the worst tail case
(20,024). For the 148 MB AVI that is 36 KiB read instead of 148 MB.

**False positives: none.** Zero occurrences of `c2pa` in **525,644,756 bytes**
of unsigned data across the thirteen types. And a false positive is the cheap
direction — it costs one wasted full read. The expensive direction is a false
negative, and this measurement says exactly where one would come from: a signer
reserving more space than the window covers.

## Reasoned, not measured

- **Signers other than these two.** Adobe's tools, cameras and any other
  generator pick their own reserve, and the reserve is precisely the parameter
  that sets the window. For a CMS reading uploads from anywhere, that is the
  significant unknown, and it is an argument for making the window a setting
  rather than a constant.
- **Fragmented BMFF, ingredient or parent manifests, and remote manifests**
  (where the asset carries a reference rather than a store) were not measured at
  all.
- **Whether 12,320 and 20,024 are stable across c2pa-rs versions.** Both were
  measured against one engine version on each side. A bump could move them, and
  nothing would go red — which is an argument for asserting the window against a
  freshly signed fixture rather than pinning a number.

## ⚠️ One disagreement with the downstream table

That table lists WebP as head-side, first hit at 336 in a 108,674-byte file.
This service produces no such file at any size: WebP signed small (13,560 →
2,558,256 bytes) has its first hit at 13,584, and signed large at 34,710,208 —
appended after the payload both times, like the other RIFF types. The fixture
behind the 336 could not be inspected from here, so this is recorded as a
disagreement to resolve against that file, not as a correction of it.

## What this does not settle

Nothing is implemented, and no spec exists. A probe in this library would be new
public behaviour and needs one, and the spec has a real design question in it
rather than a formality: whether the window is a constant or a setting, given
that the number is a property of whoever signed the file.

## The trigger, and why it has not fired

**Do not build this yet.** The measurement is worth having on its own — a 16 KiB
tail window looked reasonable and would have failed on this repository's own
output — but a measured saving is not the same as a needed one. Three things
stand between the two.

- **The expensive cases are already excluded.** The module that asked the
  question skips any file above its `read_ceiling_bytes`, 15 MiB by default,
  because raising it costs roughly 3.7× the file in queue-worker memory. So the
  probe could only ever run on files at or below that ceiling. The 148 MB AVI
  that makes the saving look dramatic never reaches it.
- **What is left is throughput, not latency.** The reading happens in a queued
  worker on cron, not while an editor saves. Not base64-ing a few megabytes is a
  real saving and nobody is waiting for it.
- **It buys a new silent failure mode.** Today a signed asset cannot be skipped
  unnoticed. With a probe it can: one false negative and the file counts as
  carrying no credential — no error, no log entry. That is the shape this
  project rates worse than a hard failure, and the measurement above says the
  window depends on the reserve size of whoever signed the file, which for
  uploads is exactly the parameter a host does not control.

**The trigger is a site reporting the reading cost as a real problem** — many
uploads, few credentials, a queue that does not keep up — with numbers from that
site rather than from a fixture. Then the spec has something to size the window
against, including the third-party reserve sizes this step could not measure.

**Not the trigger:** that the finding is measured and written down, that a probe
would be easy to write, or that this library is the right place for it. All
three are true and none of them is a reason. Subject-matter fit is the
temptation these sections exist to refuse.
