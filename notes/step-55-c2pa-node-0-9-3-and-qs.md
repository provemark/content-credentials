# Step 55 — c2pa-node 0.9.3, the qs advisories, and a tripwire that fired (2026-09-03)

Two service-side updates landed together, and one of our own guards did the job
it was written for.

## What moved

`@contentauth/c2pa-node` 0.9.1 → **0.9.3**, carrying c2pa-rs **0.90.15 →
0.90.16**. This is the bump CLAUDE.md had been carrying as the single open watch
item since 2026-08-27: the engine fix existed, and could only reach us through a
c2pa-node release that had not been published yet. It published on 2026-08-31
(0.9.2) and 2026-09-01 (0.9.3).

| release | what it carries |
|---|---|
| 0.9.2 | c2pa-rs 0.90.15 → 0.90.16; removes redundant archive-metadata assertion filtering |
| 0.9.3 | `fs-extra` namespace import → default import (packaging only) |

Of the 0.90.16 changes, two touch the surface `/v1/read` exposes to untrusted
bytes: rejecting a BMFF Merkle map location that overflows the u32 chunk index
(CAI-12884), and updating the `h2` dependency for **RUSTSEC-2026-0258**. Unlike
Step 53's bump, this one does carry an advisory — but it is against a transitive
Rust dependency of the engine, not against `c2pa` itself, and there is still no
GitHub advisory for `@contentauth/c2pa-node`.

## The qs advisories, one day old

`npm audit --omit=dev` in `service/` was clean on 2026-08-31 and reported one
moderate finding on 2026-09-03. Nothing in the tree had changed; the advisories
had. **GHSA-x5fp-wj9c-mxmx** (array-limit bypass via bracket-key comma parsing)
and **GHSA-4mjr-xmp4-gh2g** (DoS via attacker-controlled `isBuffer`) were both
published on **2026-09-02**, covering `qs` 2.2.5 – 6.15.3. Our lockfile had
6.15.3.

`qs` arrives transitively through express 5.2.1, whose own range is `^6.15.2`, so
the fix is a lockfile move to 6.16.0 and express is untouched. Note that
`npm install --package-lock-only` alone does **not** do it — it keeps a satisfying
entry that is already present. `npm update qs --package-lock-only --omit=dev` is
what moved it. `npm audit --omit=dev` then reports zero.

No Dependabot alert had been raised at the time of the bump, which is unsurprising
one day after publication and is the reason the weekly `audit.yml` job is not the
only thing looking.

## The tripwire fired, which is the part worth remembering

`composer check` went red on the bump, at SPEC-035 AC7 — the test that pins
`@contentauth/c2pa-node` in `service/package.json` to the version the spec-version
audit was made against, with the message *"engine bumped — re-run the SPEC-035
audit before declaring 2.4.0 still true"*.

That is a guard whose whole purpose is to fail exactly once, on exactly this
event, and it had never been seen red on a real bump before. It was written to
read a committed file rather than a running service precisely so it could not
report `skipped` and quietly never fire — the failure mode CLAUDE.md's "do not
trust a green test you have not seen go red" section collects. It fired.

So the audit was re-run against 0.90.16 before the pin moved:

| claim | measured on 0.9.3 |
|---|---|
| `specVersion` in `claim_generator_info` | `2.4.0`, still emitted by us (the engine never sets it) |
| actions assertion placement | `created_assertions` — 2.4 §18.15.2 satisfied |
| `created: true` on read-back | present |
| auto-thumbnail placement | still `gathered_assertions` (c2pa-rs #2106, unchanged) |
| `hasTimestamp` on the async TSA path | `true` — SPEC-007's regression risk in any bump |
| `bin/verify.sh` with trust on | `Valid`, `signingCredential.trusted` |
| reader equivalence with `ext-c2pa` 0.89.0 | agrees (SPEC-019 AC2) |
| integration suite | 147 passed, 19 skipped |

## A stumble worth writing down: the rate-limit variable is the container's

The first integration run after the rebuild reported **73 failures**, all
`HTTP 429: rate limit exceeded`. Nothing was wrong with the bump.

`RATE_LIMIT_REQUESTS` is read by `docker-compose.yml` when the container starts,
not by the test process. The long-running container had been started with
`1000`; `docker-compose up -d --build` recreated it, and the recreation took the
default `60`. Prefixing the pest command with `RATE_LIMIT_REQUESTS=1000` sets it
in the wrong process and does nothing at all.

The service says which one it is running — `curl -s http://127.0.0.1:3000/health`
reports `limits.rate_limit_requests`. Check it after any rebuild, and start the
container with the value:

```
RATE_LIMIT_REQUESTS=1000 READ_RATE_LIMIT_REQUESTS=1000 docker-compose up -d
```

A wall of 429s is what a genuine regression would not look like, but it costs a
few minutes to tell them apart, and the run before it is not evidence of anything.
