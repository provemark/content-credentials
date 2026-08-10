# Step 8 — Dependency bump `@contentauth/c2pa-node` 0.7.0 → 0.8.0 (verified 2026-08-02)

Routine minor bump of the service dependency; `service/` only, no `src/` change,
no spec needed for a version bump.

### Upstream context (the repo moved)
`contentauth/c2pa-node-v2` was **archived ~2026-06-08**; development moved to the
`contentauth/c2pa-js` monorepo under `packages/c2pa-node`. So GitHub
tags/releases/CHANGELOG for that old repo stop at v0.5.5, while npm keeps
publishing 0.6.x → 0.8.0 from the monorepo. The real changelog lives at
`c2pa-js/packages/c2pa-node/CHANGELOG.md`.

### 0.7.0 → 0.8.0 delta (from that CHANGELOG)
- **0.8.0** minor: "Strip archive metadata assertion when constructing builder
  from archive" (does NOT touch our path — we build fresh via `Builder.withJson`,
  never from an archive) + patch: "Fix node binary download".
- Underlying **c2pa-rs stays 0.90.0** (bumped back in 0.6.2), so claim v2 /
  `c2pa.actions.v2` / the Art.50 marking are unchanged. Non-breaking for us.

### Verified end-to-end
`docker-compose up -d --build`; container reports `c2pa-node 0.8.0`, health ok.
`php bin/e2e.php`: sign+read OK, AI mark present, timestamp present (the async
TSA `CallbackSigner`/`signAsync` path still works — the main risk point), and
`bin/verify.sh` (c2patool 0.27.3, trust on) = signature valid PASS / cert trusted
PASS / Art.50 mark PASS / no failures.

### Files
`service/package.json` + `service/package-lock.json` → `0.8.0`. NB the Dockerfile
does `COPY package.json` + `npm install` (not `npm ci`, lockfile not copied), so
the Docker build resolves off `package.json` and ignores the lockfile; the
lockfile was updated with `npm install --package-lock-only --omit=dev` (no native
binary download — sidesteps the allow-scripts postinstall) for repo hygiene only.
c2patool itself is unaffected (still the vendored 0.27.3, the latest release).

---

[← Step 7](step-07-property-based-tests-eris.md) · [index](../NOTES.md) · [Step 9 →](step-09-c2pa-node-0-8-1.md)
