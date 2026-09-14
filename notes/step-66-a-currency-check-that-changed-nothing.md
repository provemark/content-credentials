# Step 66 — A currency check that changed nothing, and the c2patool refresh that came out of it (2026-09-14)

Two days after Step 64 closed the last dependency watch item, the whole currency
question was asked again: is anything in this library behind? The answer is no,
and this note exists anyway — because two of the things checked along the way are
worth writing down, and because a refresh happened that has a trap in it.

## Nothing moved, and that is the measurement

| what | pinned here | what the registry serves |
|---|---|---|
| `@contentauth/c2pa-node` | 0.9.5 | **0.9.5** (published 2026-09-11) |
| c2pa-rs (through that binding) | 0.90.22 | **0.90.22** (2026-09-10) |
| `express` | `^5.2.1` | 5.2.1 |
| `ericmann/ext-c2pa` | =0.1.0 | **still the only release** (16 July 2026) |

`composer audit` and `npm audit --omit=dev` are clean, `gh api
repos/contentauth/c2pa-rs/security-advisories` is still empty, Dependabot has
nothing open, there are no open PRs or issues, and the working tree is clean with
`[Unreleased]` empty. For the first time in a while there is **no open dependency
watch item at all** — Step 58 opened one, Step 64 closed it, and nothing has
opened since.

The CI run was read the way this project insists on reading one: **per job, not by
the run's own status.** Two jobs here are `continue-on-error` by design — the
`ext-c2pa` integration profile and the weekly `audit` workflow — so an aggregate
`success` is not evidence about them. `gh run view <id> --json jobs` reports all
eighteen jobs `success`, those two included.

## The trap: the CLI's source moved, its binaries did not

Release 0.15.1 was cut because upstream split the CLI out of `c2pa-rs` into
`contentauth/c2patool` and the README's key-fetch `curl` started returning 404.
The natural next inference — *so that is where the binary lives now* — is **wrong**,
and it is worth stating before someone acts on it. Measured today:

| | `contentauth/c2pa-rs` | `contentauth/c2patool` |
|---|---|---|
| newest c2patool tag | **`c2patool-v0.27.22`** (09-10) | `v0.27.20` |
| newest *release with assets* | `c2patool-v0.27.22` | **`v0.9.12`, from October 2024** |
| `sample/` trust material | gone | present |

So the new repository holds the source and the sample material, while the
downloadable builds are still published from `c2pa-rs` under `c2patool-v*` tags —
and the new repository's own release list is nearly two years stale, which is
exactly the kind of page that looks authoritative and is not. `gh release
download c2patool-v0.27.22 --repo contentauth/c2pa-rs` is the command that works.

**A repository split does not move all of a project's artefacts at once**, and
"the CLI moved" is true of source and samples while being false of releases. That
is the general shape; the 404 in 0.15.1 was the same lesson arriving from the
other direction.

## The refresh, and what did and did not verify it

The locally kept `c2patool` (gitignored, never installed by CI, used only by
`bin/verify.sh` and `bin/e2e.php`) went 0.27.16 → **0.27.22**.

Two checks before trusting it:

- **The five `sample/` trust files at 0.27.22 are byte-identical to the ones this
  repository carries** (`trust_anchors.pem`, `allowed_list.pem`, `store.cfg`,
  `es256_certs.pem`, `es256_private.key`, compared by SHA-256). So the embedded
  trust settings needed nothing. Worth re-checking on every refresh rather than
  assuming: the settings file embeds PEM *contents*, so drift upstream would
  silently produce an untrusted verdict that looks like a code regression.
- **`composer check` proves nothing about this change.** It is green — Pint
  passed, PHPStan level max reports no errors, 372 passed / 7 skipped /
  18 deprecated, deptrac 0 violations — but no test in the suite invokes the
  binary at all. A check that *cannot* go red on a change is not evidence about
  that change, which is the "green test you have not seen go red" rule one step
  removed: here the test is genuinely green and genuinely irrelevant.

What did verify it was the chain itself, `php bin/e2e.php` against a freshly
built service: sign through the library, read back through both readers, then the
authoritative `bin/verify.sh`.

```
hasTimestamp    : true              (SPEC-007 AC4 — the async TSA path)
in-process reader agrees with the service reader   (SPEC-019 AC2)
Signature valid : PASS     Cert trusted : PASS     AI Art.50 mark : PASS
Remaining status/failures: none
```

And the manifest structure, read with `--detailed`, which is still the only place
the created/gathered split is visible:

- `claim_generator_info` carries `org.contentauth.c2pa_rs: 0.90.22` and
  `specVersion: "2.4.0"` — the current engine, correctly declared.
- `created_assertions`: `c2pa.actions.v2` and `c2pa.hash.data`. The actions
  assertion is where 2.4 §18.15.2 requires it (SPEC-035/036).
- `gathered_assertions`: only `c2pa.thumbnail.claim` — still the upstream
  misplacement tracked as c2pa-rs #2106, unmoved.

## The version numbers in the primer were deliberately left alone

`docs/c2pa-primer.md` still says c2patool 0.27.15, and §11 says 0.27.16. Those
are not stale pins waiting to be bumped: **they record which build each section
was measured against.** Rewriting them to 0.27.22 without re-running the
measurements would turn a record of evidence into a claim, and `docs/` is in the
dist, so the claim would ship. The same reasoning is why §11 already carried a
different number from the rest of the page.

## No release

Nothing tracked changed. The refreshed binary and the signed test asset are both
gitignored, so `git status` stayed empty throughout — which is also the honest
signal that this step delivers a measurement and not a change. Nothing for
`[Unreleased]`, nothing to tag.
