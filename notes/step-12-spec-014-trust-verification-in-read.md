# Step 12 — SPEC-014 implemented: trust verification in `/v1/read` (2026-08-05)

`isTrusted()` now has something to be true about. Before this, the service read
with no settings, so every signed asset came back `Valid` +
`signingCredential.untrusted` whatever certificate signed it, and c2patool was
the only thing in the project that could say "trusted".

`CONTENTAUTH_TRUST_SETTINGS` points at a settings document; unset keeps the old
behaviour exactly. `docker-compose.yml` mounts `certs/c2pa-trust.settings.json`
read-only at `/run/secrets/c2pa-trust.settings.json` — mounted always, used only
when the env var names it.

### Verified across BOTH configurations
The suite has to be run twice, because AC1 (trust on) and AC3 (trust off)
describe service configurations that cannot coexist in one process; each test
gates on what `/health` reports.

| Run | `/health` | Result |
|---|---|---|
| no settings | `trust_verification: false` | 3 skipped, **7 passed** |
| settings on | `trust_verification: true` | 1 skipped, **9 passed** (incl. AC2) |

`bin/e2e.php` now asserts the library verdict matches the service mode, and
prints `✓ certificate trusted via the library path (SPEC-014 AC1)`. The read
report went from `isTrusted: false` to `isTrusted: true`, matching what
`bin/verify.sh` has always reported.

### AC2 needed a second service — and it was worth building
Everything above only proves trust verification can say *yes*. Proving it can
say *no* needs anchors that do not cover the signing cert:

```bash
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -days 1 -nodes \
  -subj "/CN=Foreign Test CA" -keyout foreign_ca.key -out foreign_ca.pem
# {"trust":{"trust_anchors":"<that PEM>"},"verify":{"verify_trust":true}} -> out/foreign.settings.json
docker run -d --rm --name c2pa-foreign -p 127.0.0.1:3001:3000 \
  -e CONTENTAUTH_TRUST_SETTINGS=/run/secrets/foreign.json ... c2pa-spike-service
CONTENTAUTH_SERVICE_URL_FOREIGN_ANCHORS=http://127.0.0.1:3001 vendor/bin/pest --group=SPEC-014
```

Result: same signed asset, `Valid` + `signingCredential.untrusted`,
`isSignatureValid()` true, `isTrusted()` false. Verification discriminates; it
does not rubber-stamp.

### ⚠️ Docker Desktop will not mount from the agent scratchpad
Mounting a file from `/private/tmp/claude-501/...` produced **`EISDIR`** — Docker
created a directory because that path is not in its shared set. Put throwaway
mount fixtures under the project (`out/` is gitignored). The failure surfaced as
a clean startup error rather than a mystery, which was AC4 doing its job on its
first real outing.

### Implementation notes
- `loadTrustSettings()` exits non-zero on unreadable / unparseable / non-object,
  and — the part that matters — on a document that could not verify:
  `verify.verify_trust !== true`, or no non-empty `trust.trust_anchors` /
  `trust.allowed_list`. Step 11 showed that case is a **silent** no-op.
- The settings object is passed straight to `Reader.fromAsset(asset, settings)`.
  No `createTrustSettings()` — see Step 11 for why those helpers silently
  disable verification.
- `/health` gained `trust_verification`, alongside `timestamping`.

---

[← Step 11](step-11-c2pa-node-trust-settings.md) · [index](../NOTES.md) · [Step 13 →](step-13-spec-013-istrusted-fails-closed.md)
