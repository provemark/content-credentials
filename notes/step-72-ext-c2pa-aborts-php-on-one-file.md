# Step 72 — ext-c2pa v0.1.0 aborts the PHP process on one file (2026-10-08)

Found during the corpus run of Step 70: one malformed MP3 made the process
running `ExtC2paReader` stop with exit 134. A panic in the extension's Rust
code cannot unwind into PHP, so no `catch` sees it. The declared media type
makes no difference.

## Where it comes from

ext-c2pa `v0.1.0` (2026-07-16, the only release) pins c2pa-rs `=0.89.0`, and
its `Cargo.lock` holds the `id3` crate at 1.17.0, where the panic is. The
service (c2pa-rs 0.90.22, which requires `id3` 1.17.1 or later) and the
verifier read the same file without trouble.

## The rebuild

Built on Linux (`php:8.5-cli`, arm64) from the `v0.1.0` source:

| build | the file | signed test PNG |
|---|---|---|
| as released (`id3` 1.17.0) | process aborts | `Trusted` |
| `cargo update -p id3` to 1.17.2, nothing else | no manifest | `Trusted` |

The released build was rebuilt first as a control, so the difference is the
update and not the build. The 46 MP3s of the corpus read identically with both
builds, apart from that file. Not measured: whether 1.17.1 alone is enough. A
build on macOS 27 would not load into PHP (a linker issue unrelated to this).

## What was done, and what was not

`docs/readers.md` warns about it (PR #163): read uploads through the service or
the verifier until the extension ships the fix, and know that `auto` picks the
extension whenever it is loaded. No guard was added to `ExtC2paReader`:
recognising the input up front would mean imitating the crate's parser, and
the measurements already showed a simple imitation missing cases. The fix
belongs in the extension. The trigger to revisit is a new ext-c2pa release:
re-run the corpus with it, and drop the warning once that file reads.
