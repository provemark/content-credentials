# Step 48 — c2pa-node 0.8.3, and measuring a "panic" that does not panic (2026-08-10)

Routine dependency check turned up one advisory worth chasing:
`@contentauth/c2pa-node` 0.8.1 → 0.8.3 carries c2pa-rs **0.90.4 → 0.90.5**, and
0.90.5 fixes an integer-underflow in JUMBF description-box parsing
(`read_desc_box`, c2pa-rs #2334) — "panic, no valid signature needed", on any
read path. That reaches us: `/v1/read`, and the parent-ingredient parse on
`/v1/sign` (SPEC-028). Both engines are affected and only one is fixable —
`ericmann/ext-c2pa` pins `c2pa = "=0.89.0"` exactly, still v0.1.0, so there is no
upgrade path for the extension.

### ⚠️ The advisory says "panic"; the shipped builds do not panic

Read the source before believing the release note. `read_desc_box` in
`sdk/src/jumbf/boxes.rs` guards `size < 26` and the label loop against
`bytes_left <= HEADER_SIZE`, but the optional fields the toggle byte can request
(`box_id` +4, signature +32, private box) are subtracted from `bytes_left` with
raw `-=`. With `toggles=0x0F` and `size=26`, `bytes_left` reaches 4 before
`bytes_left -= 32`.

The part that changes the severity: c2pa-rs's and ext-c2pa's `[profile.release]`
sets **no `overflow-checks`**. So in a release build — which is what the
distributed c2pa-node native binary and the PIE-built extension are — the
underflow **wraps** instead of panicking, and the wrapped huge value then fails
the downstream guard `bytes_left != HEADER_SIZE` and returns a parse error. The
"panic" is debug/test-build behaviour. The amplification path (`read_to_vec`) is
separately bounded against the real stream length via `safe_vec`, so no OOM
either.

### Measured, not reasoned — both engines, both before and after the bump

Built the exact reported repro (`jumd size=26, toggles=0x0F`) and a private-box
variant into a PNG `caBX` chunk (which is literally the JUMBF store), plus a
no-manifest control. `size=26` is not `< 26`, so the field reads are genuinely
reached, not rejected at the size guard.

| Path | Engine | Outcome | Process |
|---|---|---|---|
| `ExtC2paReader` | ext-c2pa 0.1.0 / c2pa-rs **0.89.0** | catchable `C2paException` "unexpected end of file", <1 ms | alive |
| service `/v1/read` | c2pa-node 0.8.1 / **0.90.4** | `HTTP 500` + cid, audit `outcome:"failed"` | `RestartCount=0`, `/health` answers |
| service `/v1/read` | c2pa-node 0.8.3 / **0.90.5** | identical `HTTP 500` + cid | `RestartCount=0` |

Zero `panic`/`abort`/`SIGSEGV`/`backtrace` in the container logs in any run. So
this is **not an exploitable DoS in our configuration**; the bump is defence in
depth (and protects anyone who ever builds these with overflow-checks on). The
CHANGELOG says exactly that rather than claiming a crash it does not cause —
avoiding the inverse of the mistakes this log keeps recording, a security claim
inflated past what was measured.

### The bump, verified by hand (NOTES Steps 8–10 ritual)

- **c2pa-rs version taken from the running container, not the changelog**
  (Step 35): a signed manifest's `claim_generator_info` reads
  `org.contentauth.c2pa_rs: 0.90.5`. So 0.8.3 is 0.90.5, not 0.90.7 as the raw
  version arithmetic might suggest — the later c2pa-rs patches (lopdf, rayon)
  ship independently.
- **The critical path is intact**: claim v2, exactly **one** `c2pa.actions.v2`
  assertion, first action `c2pa.created` with `softwareAgent` + the full IPTC
  `digitalSourceType` URI. 0.8.1's `filter_actions_and_ingredients` and 0.8.3's
  new `updateActions` are inert on our path — we build fresh via `withJson` and
  pass one actions assertion, so there is nothing to filter or update, exactly as
  0.8.1 was verified in Step 9.
- **The async TSA path still works** (`hasTimestamp: true`) — the standing
  regression risk on any engine bump. Timestamped PNG 55,478 bytes, byte-for-byte
  the same size as every timestamped run since Step 6.
- Error paths on express 5 unchanged: 413 (SPEC-017), 400 malformed JSON, 401
  missing auth, allow-list 400, and SPEC-029's named 400 for a non-iterable
  `data.actions`.
- Lockfile regenerated with `npm install --package-lock-only --omit=dev`
  (lockfileVersion 3, 121 entries, 0 marked `dev` — so `npm ci --omit=dev` stays
  valid), `npm audit --omit=dev` 0 vulnerabilities. Rebuilt `--no-cache` so the
  0.90.5 native binary was actually fetched.
- `composer check` green (324 passed, no red `out/check-*.log`), integration
  **136 passed / 17 skipped** (defaults profile, `RATE_LIMIT_REQUESTS=1000` and
  `READ_RATE_LIMIT_REQUESTS=1000` — the suite trips a default budget, Step 17).

### c2patool 0.27.3 → 0.27.7

Bumped first, before signing with the new engine — you do not want to verify a
new engine's output with a stale verifier. It is gitignored (`tools/`), so no
commit; it verifies existing signed assets with trust on unchanged.

### Not tagged

Service and docs only; `src/` unchanged. Rides in `[Unreleased]` with the twelve
0.10.0-review fixes until something warrants a release (NOTES Step 10: tagging
every internal service build erodes the meaning of the release heading). The bump
reaches users through `git pull` + rebuild, never a Composer update — `service/`
is `export-ignore`d.

---

[← Step 47](step-47-equivalence-test-configurations.md) · [index](../NOTES.md)
