# Step 9 — Dependency bump `@contentauth/c2pa-node` 0.8.0 → 0.8.1 (verified 2026-08-05)

Patch bump of the service dependency; `service/` only, no `src/` change.

### 0.8.0 → 0.8.1 delta (from the c2pa-js monorepo CHANGELOG)
Two patch entries: **"incorporate c2pa builder filter_actions_and_ingredients"**
and **"Update c2pa to 0.90.4"** (c2pa-rs 0.90.0 → 0.90.4, so still the 0.90 line
— claim v2 unchanged).

The first entry is the one that mattered: a builder-side *filter* over actions
and ingredients is exactly the machinery that could silently drop or rewrite our
single `c2pa.actions.v2` assertion, which is the Art.50 marking AND what makes a
claim-v2 manifest well-formed. Verified explicitly rather than assumed — see
below. It turned out to be inert on our path (we build fresh via
`Builder.withJson`, we pass one actions assertion, nothing to filter).

### Verified end-to-end
`docker-compose up -d --build`; container reports c2pa-node **0.8.1**,
`/health` = `{"status":"ok","signing_alg":"es256","timestamping":true}`.
`php bin/e2e.php`: sign+read OK, AI mark present, `hasTimestamp` true (so the
async TSA `CallbackSigner`/`signAsync` path still works — the main risk point),
signed PNG 55,478 bytes (byte-identical in size to the 0.7.0 timestamped output
recorded in Step 6). `bin/verify.sh` (c2patool 0.27.3, trust on): signature valid
PASS / cert trusted PASS / Art.50 mark PASS / no remaining failures.

Manifest inspected directly to close out the filter question:
`claim_version: 2`, assertions = exactly **one** `c2pa.actions.v2`, first (only)
action `c2pa.created` with `softwareAgent` + the full IPTC `digitalSourceType`
URI, `validation_state: Trusted`. The auto-added `c2pa.thumbnail.claim` is still
there (reported as a manifest-level thumbnail property, not in the `assertions`
array — worth knowing, it looks absent if you only list assertion labels).

### ⚠️ npm audit: a high-severity transitive advisory, and why the lockfile did not fix it
`npm audit --omit=dev` flagged **brace-expansion ≤1.1.17** (high, DoS via
unbounded expansion — GHSA-mh99-v99m-4gvg / GHSA-rgw5-rvv9-x895), reached via
`@contentauth/c2pa-node → unzipper → fstream → rimraf → glob → minimatch`.
`npm audit fix --package-lock-only` moved it 1.1.16 → 1.1.18, 0 vulnerabilities.

But per Step 8 the Dockerfile does `COPY package.json` + `npm install`, **not**
`npm ci` with the lockfile — so that lockfile pin does not reach the container.
The container was checked directly and happens to run brace-expansion **1.1.18**
anyway, because a fresh `npm install` resolves `^1.1.7` to the newest 1.x. So we
are fine here **by luck, not by construction**: the lockfile remains decorative,
and any future advisory whose fix requires pinning *below* the newest satisfying
version would silently not apply. Fixing this means copying `package-lock.json`
and switching to `npm ci --omit=dev` — a build-behaviour change for everyone
running the service, so it is left as a deliberate open item, not smuggled into
a dependency bump. **CLOSED in Step 10.**

---

[← Step 8](step-08-c2pa-node-0-8-0.md) · [index](../NOTES.md) · [Step 10 →](step-10-reproducible-service-builds-npm-ci.md)
