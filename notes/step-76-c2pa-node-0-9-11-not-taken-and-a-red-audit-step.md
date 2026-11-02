# Step 76 — c2pa-node 0.9.11 not taken, and an audit step red for a week (2026-11-02)

A currency check on `@contentauth/c2pa-node`, following Step 74. The pin stays
at 0.9.5. The same check found the weekly `audit` workflow red in one step
since 2026-10-26, which is fixed in #202.

## c2pa-node 0.9.11: seen, not taken

0.9.11 (2026-10-27) moves the engine from c2pa-rs 0.91.3 to **0.91.7**
(c2pa-js #246). Its changelog names one change: patched TLS certificate
verification and new roots after the eMudhra incident.

- **The blocker from Step 74 is still there.** contentauth/c2pa-js#244
  (`CallbackSigner` no longer timestamps on `signAsync`) is open. Two other
  users have confirmed it there, one with a table across 0.9.7 to 0.9.10. The
  fix is **c2pa-js #251**, "time-stamp CallbackSigner signatures again",
  opened 2026-10-30, CI green, review required, not merged. 0.9.11 does not
  contain it.
- **The trigger becomes: the first c2pa-node release that contains #251** and
  still carries the #2746 fix. The Amendment 1 work waits on the branch
  `spec-014-am1-context`, as in Step 74.
- **The TLS change hardly touches this service.** This is reasoned, not
  measured. Since SPEC-043 the engine fetches no remote manifest, the TSA URL
  in `.env.example` is plain `http://`, and Node's own roots come from the
  `node:24-slim` base image, whose tag moves. A rebuild picks up a patched
  Node 24 without any bump here.

Dependabot's PR for 0.9.10 (#188) is closed for the same reason as #168 for
0.9.9. Its PR for 0.9.11 will get the same answer until #251 ships.

## The audit step that was red for a week

Run 40336364319 (2026-10-26) reported success. Its step `npm audit (service)`
failed, and `composer audit` was skipped. The run of 2026-10-19 was green step
by step. This is the second time the workflow looked green with a red step;
the same shape preceded #165 (2026-10-05). It is
`continue-on-error` by design, so the only way to see this is to read the
steps.

What `npm audit --omit=dev` reported:

| package | in the lockfile | advisory | reachable? |
|---|---|---|---|
| body-parser | 2.3.0 | GHSA-27r3-jxmh-p828, high: denial of service through trailing bytes after a compressed body; fixed in 2.4.0 | Only with a valid token. The Bearer check (`server.js:901`) runs before `express.json` (`server.js:960`). |
| qs | 6.16.0 | GHSA-6pq8-jhm2-2vcw, moderate, in `qs.stringify` with `arrayFormat: 'comma'`; fixed in 6.16.1 | No. The service never calls `qs`, and Express only parses. |
| minimatch | 3.1.5 | `< 11.0.1`, moderate, denial of service through glob patterns | No. Only on the install path: unzipper → fstream → rimraf → glob. |

Reachability is reasoned from the code and the dependency tree, not tested.

**Fixed in #202** (squash `d2c15d0`) with
`npm update body-parser qs --package-lock-only --omit=dev`: body-parser 2.4.0
with its nested content-type 2.1.0, and qs 6.17.1. Nothing else moved. Express
stays at 5.2.1 and c2pa-node at 0.9.5. Verified on a rebuilt container: it
holds the new versions, `bin/e2e.php` reads trusted with the Article 50 mark
intact, the integration group runs 217 passed / 23 skipped without failures,
and `composer check` runs 528 passed / 7 skipped. That integration count was
not compared with a run on `main` before the change.

- **Express 5.3.0 would not have fixed it.** It still accepts body-parser
  `^2.3.0`, so the lockfile keeps 2.3.0. Dependabot's PR for it (#183) is
  closed. Express moves with the next service dependency update.
- **minimatch is left.** glob 7 requires `^3.1.1` and the fix is in 11, so
  only an `overrides` entry would move it. The `npm audit (service)` step
  therefore stays red. Whoever reads it next should expect minimatch there and
  nothing else.

Like every `service/` change, this reaches users through `git pull` and a
rebuild, not through a tag. The CHANGELOG has it under `[Unreleased]`.

## A red ext-c2pa leg on #202

The first run of #202 had `integration (ext-c2pa)` red at
`Install ext-c2pa (PIE)`: "Could not download ericmann/ext-c2pa using
pre-packaged-binary method", three times. No tests ran. The aggregate was green,
as the leg is non-blocking. The neighbouring runs on `main` were green on that
step, but three days old. A lockfile change in `service/` cannot affect that
download, so the job was re-run once to tell a transient failure from a broken
download: green, and all steps that apply to the profile succeeded. The same
shape as 2026-09-18 (PR #146). Only after that was #202 merged.

## Still open

- c2pa-js#244, and its fix in #251.
- Everything Step 74 lists as open, unchanged.
- minimatch in the service's install path, reported by every audit run until
  the packages on that path move.
