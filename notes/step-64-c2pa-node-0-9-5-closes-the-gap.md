# Step 64 — c2pa-node 0.9.5, and the gap that had been open since 5 September (2026-09-12)

The watch item opened in Step 58's wake — *c2pa-rs runs ahead of the Node
binding* — is closed. It closed the way the note said it would: by re-checking
npm, not by chasing Rust tags.

## What moved

`@contentauth/c2pa-node` 0.9.3 → **0.9.5**, carrying c2pa-rs **0.90.16 →
0.90.22**. Two binding releases, six engine releases.

| release | published | carries |
|---|---|---|
| 0.9.4 | 2026-09-08 | c2pa-rs 0.90.20 |
| 0.9.5 | 2026-09-11 | c2pa-rs 0.90.22 |

The engine releases inside that span, in the order upstream shipped them:

- **0.90.17** — PR #2579, `fix: Merge vulnerability fixes`, five hardening
  commits. The one this repository had written down is **CAI-12751**, *"Prevent
  ingredient assertions from canceling active-manifest failures"*: a failure in
  the **active** manifest could be suppressed by an ingredient assertion. That is
  the shape that yields a wrong `Valid`, which is the outcome this project rates
  worse than an error. Also in there: path traversal in `Reader::to_folder`
  (never called here), unbounded allocation in the JPEG XL parser (measured
  unreachable through the service — Step 58), and two BMFF fixes.
- **0.90.18** — hardening against invalid labels used as CAWG identity hard
  bindings.
- **0.90.19** — ingredient manifest label-collision handling (2.4 §18.16.12).
- **0.90.20** — ZIP/EPUB/OOXML/ODF support and a Collection Data Hash assertion.
  Neither widens anything here: `SUPPORTED_MIME` gates before the engine is
  reached, and taking those types would need its own spec plus three lists
  moving together.
- **0.90.21** — identity assertion validation now uses the caller's
  `cawg_trust` settings. We send none, so nothing changes; it becomes relevant
  only if SPEC-034 is ever approved.
- **0.90.22** — path URI normalisation on collection hash, plus a build pin.

## Still no GHSA, still no tripwire

`gh api repos/contentauth/c2pa-rs/security-advisories` returns empty,
`composer audit` and `npm audit --omit=dev` are both clean, and Dependabot has
nothing open. The CAI-12751 fix would have been invisible to every automated
signal we run. It was visible because someone read the release notes — which is
what Step 58's watch item was for, and is the reason to keep writing them down
rather than waiting to be told.

## The tripwire fired, and was made to go red first

SPEC-035 AC7 pins the engine version the 2.4.0 declaration was audited against.
It failed on the bump, before the pin was touched:

```
expect($pinned)->toBe('0.9.3', 'engine bumped — re-run the SPEC-035 audit …')
-'0.9.3'
+'0.9.5'
```

That is the second time it has done its job (the first was the 0.9.3 bump). The
order matters and is the whole point of the criterion: **re-run the audit, then
move the pin.** Moving the pin first would make the check green and prove
nothing.

## The re-audit, against c2patool 0.27.16 and the new engine

Signed a fresh asset through the rebuilt service and read the claim back with
`c2patool --detailed`, which is the only place the created/gathered split is
visible:

```
claim_generator_info: {"name": "Content Credentials (e2e)", "version": "0.1.0",
  "specVersion": "2.4.0", "org.contentauth.c2pa_rs": "0.90.22"}

created_assertions  -> c2pa.actions.v2, c2pa.hash.data
gathered_assertions -> c2pa.thumbnail.claim
```

- `specVersion` is still emitted, still `2.4.0`, still SemVer-shaped.
- The actions assertion is still in **`created_assertions`** — what 2.4
  §18.15.2 requires and what SPEC-036 exists to achieve — and still reads back
  `"created": true` on the assertion itself.
- The auto thumbnail is still in `gathered_assertions`, still contradicting what
  that array means. Upstream c2pa-rs #2106 is unchanged; nothing in six engine
  releases moved it.

`org.contentauth.c2pa_rs: 0.90.22` in the signed manifest is also the only
direct confirmation that the container is running what the lockfile says. It
beats asking the binding, whose `Cargo.toml` names the engine by workspace
reference rather than by version.

## What was run, and what it said

| check | result |
|---|---|
| `php bin/e2e.php` | sign + read + both readers agree; **`hasTimestamp: true`** |
| `bin/verify.sh` (trust on) | signature PASS, cert **trusted** PASS, Art. 50 mark PASS, no remaining status |
| `composer check` | 372 passed, 7 skipped, 18 deprecated; deptrac 0 violations |
| `--group=integration` | 161 passed, 19 skipped |
| `--group=SPEC-039` | 14 passed — the JXL-container refusal still refuses |
| `--group=SPEC-028` | 32 passed, 1 skipped — the ingredient path, which is what CAI-12751 touches |
| `--group=SPEC-007` | 7 passed, 2 skipped |
| `--group=SPEC-019 SPEC-020` | 29 passed, 7 skipped (extension loaded on this machine) |

`hasTimestamp` is the one worth naming separately. A `tsaUrl` forces the async
`signAsync` path with a `CallbackSigner`, that path has no untimestamped
fallback, and it is the main regression risk in any c2pa-node bump. It survived.

The integration count is unchanged from the 147 → 161 that SPEC-039 produced, so
the bump moved no behaviour the suite can see.

## The lockfile, and the one command that does the job

`npm install --package-lock-only --omit=dev` in `service/`, after editing the
pin. The diff is four lines in one package — `c2pa-types` (0.7.4) and
`c2pa-utilities` (0.2.2) were already at the versions 0.9.5 wants, so nothing
transitive moved.

## Delivery, which is the part to say out loud

`service/` is `export-ignore`d. **A tag delivers none of this to anyone.** Users
on the service reader get it through `git pull` plus a rebuild;
`GET /health` reports `spec_version`, and a signed asset reports
`org.contentauth.c2pa_rs`, so a rebuild is confirmable — the second one without
even spending a signature.

One consequence worth noting for the reading half: the two readers are now
further apart than they have ever been. `SigningServiceReader` runs c2pa-rs
**0.90.22**; `ExtC2paReader` runs **0.89.0**, because `ericmann/ext-c2pa` still
has only its v0.1.0 release. SPEC-019 AC2 compares them accessor by accessor and
still agrees, which is the alarm working rather than the gap being harmless.
