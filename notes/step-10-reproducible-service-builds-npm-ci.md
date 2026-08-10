# Step 10 — Reproducible service builds: `npm install` → `npm ci` (2026-08-05)

Closes the open item from Step 9. `service/Dockerfile` now does
`COPY package.json package-lock.json ./` + `RUN npm ci --omit=dev`.

### Why the lockfile could be dropped in safely
`npm ci` refuses to run on an incomplete or out-of-sync lockfile, and this repo
generates the lockfile with `npm install --package-lock-only --omit=dev`, which
can prune dev entries — a real risk of breaking `npm ci`. Checked before
changing anything: `lockfileVersion: 3`, 131 entries, **0 marked `dev`**,
because `service/package.json` has no `devDependencies` at all (only
`@contentauth/c2pa-node` and `express`). Nothing is omitted, so the lockfile is
complete and the existing update command can stay as it is.

### Verified
`docker-compose build --no-cache` succeeds; the postinstall still fetches the
native binary under `npm ci`. Container versions are **identical** to the
`npm install` build (c2pa-node 0.8.1, express 4.22.2, brace-expansion 1.1.18) —
which is the point: today the two resolve the same, and now that sameness is
guaranteed instead of coincidental. `/health` ok, `php bin/e2e.php` sign+read OK
with the AI mark and `hasTimestamp` true, signed PNG 55,478 bytes (unchanged),
`bin/verify.sh` all PASS.

Drift detection confirmed rather than assumed: with `package.json` edited to
`0.8.0` against the 0.8.1 lockfile, `npm ci` exits `EUSAGE` with
`Invalid: lock file's @contentauth/c2pa-node@0.8.1 does not satisfy
@contentauth/c2pa-node@0.8.0`. That failure is the feature — drift becomes a
build error instead of a silent divergence.

### No tag
Service-only, no `src/` change, and functionally invisible in the running
container, so this rides in `[Unreleased]` until something else warrants a
release. Tagging every internal build improvement would erode the meaning of the
`Service (requires git pull + rebuild)` heading introduced in v0.4.3.

---

[← Step 9](step-09-c2pa-node-0-8-1.md) · [index](../NOTES.md) · [Step 11 →](step-11-c2pa-node-trust-settings.md)
