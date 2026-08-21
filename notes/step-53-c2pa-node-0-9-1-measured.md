# Step 53 — c2pa-node 0.9.1, and a bump Dependabot never offered (2026-08-21)

`@contentauth/c2pa-node` 0.8.3 → **0.9.1** (published 2026-08-17), carrying
c2pa-rs **0.90.5 → 0.90.15**. Ten patch releases of the engine, four of them on
the read/verify path — the surface `/v1/read` exposes to untrusted bytes:

| c2pa-rs | fix |
|---|---|
| 0.90.15 | hardening against deep recursion in update manifests with parent cycles (#2493) |
| 0.90.14 | diamond `inputTo` reverifications going exponential on ingredient-path reachability (#2492) |
| 0.90.12 | validate `inputTo` ingredients against manifest tampering (#2476) |
| 0.90.11 | panic on an out-of-range timestamp in GeneralizedTime conversion (#2469) |

**No advisory is attached to any of them.** Checked both ecosystems —
`gh api '/advisories?ecosystem=rust&affects=c2pa'` and the same for
`npm`/`@contentauth/c2pa-node` — and both return empty; no Dependabot alert, and
`npm audit --omit=dev` is clean on the new tree. So this is defence in depth on
the untrusted-input path, not a patched CVE. Saying more than that would be the
inflation Step 48 was careful to avoid.

## The part worth remembering: automation did not see it

The weekly Dependabot npm job ran on 2026-08-20, three days after 0.9.1 was
published, and opened nothing. Its log is explicit:

```
Checking if @contentauth/c2pa-node 0.8.3 needs updating
  proxy | GET  https://registry.npmjs.org/@contentauth%2Fc2pa-node     200
  proxy | HEAD https://registry.npmjs.org/.../c2pa-node-0.9.1.tgz      200
Latest version is 0.8.3
No update needed for @contentauth/c2pa-node 0.8.3
```

It fetched the packument, saw the 0.9.1 tarball, and then decided the latest
version was the one already installed. Four candidate explanations were tried
and all fail:

- **engines mismatch** — no. Both 0.8.3 and 0.9.1 declare `node: ">=22"`, and
  `service/package.json` declares `>=22.0.0`.
- **deprecated version** — no. `npm view @contentauth/c2pa-node@0.9.0 deprecated`
  and the same for 0.9.1 both return empty.
- **unresolvable transitive dependency** — no. 0.9.0 adds
  `@contentauth/c2pa-utilities` and promotes `@contentauth/c2pa-types` to a real
  dependency; both exist on the registry and
  `npm install --package-lock-only --omit=dev` resolves the tree in one pass.
- **the exact pin** — no. `"0.8.3"` is an exact requirement, which Dependabot
  updates like any other.

**The cause is unexplained and lives on Dependabot's side.** That is the finding,
not the bump: SPEC-018 AC5's automated scanning silently missed a two-minor
release of the one dependency `dependabot.yml` already singles out as never
routine. The comment in that file says a c2pa-node bump gets its own reviewable
PR; it now also needs someone to *notice*. `npm view @contentauth/c2pa-node
version` is the whole check.

## Measured, before and after

Same machine, same `.env` (a working TSA, so the async SPEC-007 path is live),
service rebuilt between the two columns.

| | 0.8.3 / c2pa-rs 0.90.5 | 0.9.1 / c2pa-rs 0.90.15 |
|---|---|---|
| `org.contentauth.c2pa_rs` in the manifest | `0.90.5` | `0.90.15` |
| `/health` document | — | byte-identical to the baseline |
| `bin/e2e.php` | all checks ✓ | all checks ✓ |
| signed PNG | 55,478 bytes | 55,479 bytes |
| `claim_version` | 2 | 2 |
| assertions | `[c2pa.actions.v2]` + claim thumbnail | identical |
| `hasTimestamp` (SPEC-007 AC4) | true | true |
| `verify.sh` under trust settings | 4× PASS, no remaining status | 4× PASS, no remaining status |
| reader equivalence (SPEC-019 AC2) | agrees | agrees |
| integration suite | 137 passed, 19 skipped | 137 passed, 19 skipped |

The engine version was read **from the running container**, not from the
changelog — the rule Step 48 wrote after the raw release note disagreed with what
was actually shipped. This time the changelog was right; the point is that it was
checked rather than believed.

A structural diff of the two c2patool reports (assertion labels, action bodies,
thumbnail format, signature alg/issuer, validation success/failure/informational
codes) is identical except the manifest URN, which is random per signing. The
one-byte size difference is signature DER length, not structure.

Three profiles beyond `defaults` were run by hand, chosen because they are the
ones that reach the engine rather than our own limits:

- **`hardened`** (trust settings on, `REQUIRE_AI_MARKING=true`) — 136 passed,
  20 skipped. `it reads a signed asset as Trusted when trust verification is
  active` ran and passed; it is present-shaped, so a broken trust path fails it
  rather than skipping it.
- **`tsa-unreachable`** (`CONTENTAUTH_TSA_URL=http://127.0.0.1:9/tsa`) — 9
  passed. `it refuses to sign when the timestamp authority cannot be reached`
  holds, so SPEC-007 still fails closed on 0.90.15.
- **`ext-c2pa`** — covered by `e2e.php`, which found the extension installed on
  this machine; the 0.89.0 reader still agrees with the 0.90.15 service reader
  accessor by accessor.

### The Step 48 probe, re-run

The JUMBF description-box underflow repro (`jumd size=26, toggles=0x0F` in a PNG
`caBX` chunk), plus the private-box variant, plus a garbage-payload case and a
no-manifest control, against `/v1/read`. Extending that step's table with a third
row:

| Path | Engine | Outcome | Process |
|---|---|---|---|
| service `/v1/read` | c2pa-node 0.8.1 / **0.90.4** | `HTTP 500` + cid, audit `outcome:"failed"` | `RestartCount=0` |
| service `/v1/read` | c2pa-node 0.8.3 / **0.90.5** | identical `HTTP 500` + cid | `RestartCount=0` |
| service `/v1/read` | c2pa-node 0.9.1 / **0.90.15** | identical `HTTP 500` + cid | `RestartCount=0`, `/health` ok |

Zero `panic`/`abort`/`SIGSEGV`/`backtrace` in the container logs; the control
still returns `{}`. So the bump changes nothing observable here either — which is
the expected result for a configuration that was never vulnerable, and is worth
recording precisely because "nothing changed" is the claim easiest to assert
without checking.

## What else moved

`0.9.0` restructures the package: a new `@contentauth/c2pa-utilities` dependency,
`@contentauth/c2pa-types` promoted from dev to real, `ts-deepmerge` beneath them.
Three added entries in the lockfile, nothing removed, 124 packages, 0
vulnerabilities. The Reader now validates asset size before reading, with a
server limit of **10 GB** against a `@contentauth/c2pa-utilities` default of 1 GB
— both far above `MAX_BODY_SIZE` (20 MB), so no read that used to succeed can
now be refused by it.

Our call sites are untouched by the API changes: `Reader.fromAsset(asset,
settings)` keeps its settings argument, and `LocalSigner.newSigner(...)` /
`CallbackSigner.newSigner(config, cb)` are unchanged, so the async TSA path
signature is intact. That was read out of the published `dist/` before the
rebuild, not inferred.

`tools/c2patool` went 0.27.7 → **0.27.15** in the same pass, so the authoritative
verifier is not a generation behind what signs. It verifies both the new asset
and the 0.8.3-era baseline asset with trust enabled, all PASS — the backward
direction mattering more than the forward one.

## Not measured

- **CI.** Only four of the eight integration profiles were run locally, and the
  four skipped ones (`rate-limited`, `read-limited`, `auth-limited`,
  `auth-unlimited`) bound our own limiters rather than the engine. CI runs all
  eight.
- **Media types beyond PNG.** The integration suite covers all thirteen and
  passed; nothing was signed and inspected by hand per type the way SPEC-021 did.

## Afterwards: the SPEC-006 line, and what checking it turned up

`specs/SPEC-006-jobs-and-commands.md:253` still says the service carries 0.90.5,
and it is an `approved` spec, so nothing above touched it. Amending it properly
meant reading the paragraph rather than replacing the number — which found a
second expired claim in the same sentence, and a better one.

**"No CI profile sets `CONTENTAUTH_TSA_URL`" was false 24 minutes after it was
written.** The amendment landed in `653ff9d` at 07:50 on 2026-08-13; `20cd04d`
added the `tsa-unreachable` profile at 08:14 the same morning, and that profile
sets exactly that variable. `git merge-base --is-ancestor 653ff9d 20cd04d`
confirms the order. A wrong premise under a conclusion that happens to hold.

The conclusion does hold, for a reason the paragraph never gave: `tsa-unreachable`
points at the discard port, so every signature is refused and no timestamped
asset exists to compare — and it runs `groups: SPEC-007` anyway, while the only
profile running SPEC-019 is `ext-c2pa`, which sets no TSA. So the gap needs a
profile that installs the extension **and** reaches a working TSA, not one that
merely sets the variable.

And the precondition that amendment left open is no longer entirely open. On this
machine, `.env` carrying a working TSA, the same signed asset read both ways:

| reader | c2pa-rs | `hasTimestamp` |
|---|---|---|
| `SigningServiceReader` | 0.90.15 | `true` |
| `ExtC2paReader` | 0.89.0 | `true` |

First non-vacuous agreement on that accessor — once, by hand, on one machine,
which is worth strictly less than a CI profile. Recorded in the SPEC-006
amendment of 2026-08-21 as "no longer never observed, still not observed by
anything that runs on its own".
