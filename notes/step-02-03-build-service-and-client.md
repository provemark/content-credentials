# Step 2/3 — Build the service + client (friction log)

### npm install-script hardening (gotcha)
This machine's npm blocks package install scripts by default
(`allow-scripts`). `@contentauth/c2pa-node` has a `postinstall` that fetches the
prebuilt native `.node` binary. npm printed a warning that the script was "not
yet covered by allowScripts" — but signing still worked, so the binary was
present. If a clean install ever yields a "cannot find native binary" error, run
`npm approve-scripts @contentauth/c2pa-node` (or `npm rebuild`) first. In Docker
this is a non-issue (scripts run during build).

### Signing works locally (real library + test certs) ✅
`LocalSigner.newSigner(cert, key, "es256")` + `Builder.withJson(...)` +
`builder.sign(...)` produced a 45 KB signed PNG. c2patool 0.27.3 confirms:
`claimSignature.validated` = **"claim signature valid"** → DoD (1) met.

### Two expected c2patool findings
- `signingCredential.untrusted` — the test certs are not on any trust list.
  This is EXPECTED and does not affect signature validity. Clearing it needs
  c2patool trust config (a `c2pa.toml` settings file with `verify_trust=true` +
  the sample `trust_anchors.pem`/`allowed_list.pem`); c2patool 0.27 moved trust
  under a `trust` sub-command / `--settings` file. Left as an OPEN item — not
  required for "valid signature".
- `assertion.action.malformed: "first action must be created or opened"` —
  appeared only because the smoke test sent *empty* assertions. C2PA claim v2
  REQUIRES an actions assertion whose first action is `c2pa.created`/`opened`.
  The spike's AI payload (`c2pa.created` + digitalSourceType) satisfies exactly
  this — so the marking we need is also what makes the manifest well-formed.

### claim_version is 2
c2pa-rs 0.90 / c2pa-node 0.7 emit **claim v2** manifests. So the actions label
is `c2pa.actions.v2` and the first action must be created/opened.

### ⚠️ Biggest gotcha: `builder.sign()` return value is NOT the signed image
`builder.sign(signer, source, dest)` **returns the C2PA manifest store bytes
(JUMBF)**, not the signed asset. The signed ASSET is written to `dest` (`{path}`
or `{buffer}`). First attempt returned the sign() value as `signed_content` →
`out/signed.png` was actually the raw manifest store, and read-back failed with
`invalid header … got [.. 6A 75 6D 62]` (`6A 75 6D 62` = ASCII "jumb"). Fix: sign
to a temp file and read that file back as the signed image. Easy to miss because
the `Builder.spec.ts` example only asserts `bytes.length > 0`.

### PHP 8.5 deprecation
`curl_close()` is deprecated (no-op since PHP 8.0). Harmless but noisy; removed
from the client. Worth knowing when the real code targets 8.3 vs 8.5.

---

[← Step 1](step-01-investigate-wp-plugin-signing-service.md) · [index](../NOTES.md) · [Step 4/5 →](step-04-05-end-to-end-result.md)
