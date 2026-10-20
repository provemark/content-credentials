# Step 74 — c2pa-node 0.9.10: the trust migration works, and signing lost its timestamp (2026-10-20)

The pin on `@contentauth/c2pa-node` 0.9.5 was waiting for an engine with the
fix for c2pa-rs #2731 (Step 67 addendum, 2026-09-28). #2731 was closed in
favour of #2746, which was backported in c2pa-rs 0.91.2. c2pa-node **0.9.10**
(2026-10-16) carries c2pa-rs **0.91.3** and was the first release the bump
could take. SPEC-014 Amendment 1 was approved for it the same day.

The bump was built and measured. It does not ship: the pin stays at 0.9.5, and
the Amendment 1 work waits on a branch that is not published yet.

## What works on 0.9.10

- **The `Context` migration.** The service maps the snake_case trust document
  to camelCase, passes it through `new Context()`, and signs with
  `withJsonAsync`. Measured inside the container: the shipped document,
  unmapped in a `Context`, reads `Valid` with no `signingCredential.trusted`.
  Mapped, it reads `Trusted`. Step 67's option 2 is still wrong on this engine.
- **Amendment 1's criteria**, through a new test that starts a second service
  in the container for each trust document. With that, AC5, AC9, AC10 and AC11
  run in every integration profile and need no profile of their own. 4 tests
  red on 0.9.5, 10 green on 0.9.10. The three verdict tests go red when the
  mapping is removed. **AC10 is now measured, not just read from the source**:
  an allowed list next to anchors that do not cover the signer reads `Trusted`.
  With the signer on neither, the verdict is `Valid` + `signingCredential.untrusted`.
- **`specVersion` signs and reads back.** The #2746 fix does what Step 67
  needed. The actions assertion is still in `created_assertions`, and the
  thumbnail is still in `gathered`. `bin/verify.sh` reads the result trusted,
  with the Article 50 mark intact. The manifest names `c2pa_rs: 0.91.3`.

## What broke: no timestamp, and no error

With a TSA configured, a signature from 0.9.10 carries no RFC 3161 timestamp:
no `time` in `signature_info`, no `timeStamp.*` codes. `bin/e2e.php` reports
the mismatch. **An unreachable TSA also signs**: HTTP 200, and the audit record
says `timestamped: true`. SPEC-007 requires the opposite, and it fails without
any error.

A repro script outside the service, each version in a fresh `node:24-slim`
container, `CallbackSigner` with a `tsaUrl`, then `signAsync`:

| c2pa-node | engine | working TSA | unreachable TSA |
|---|---|---|---|
| 0.9.5 | 0.90.22 | timestamp | refused |
| 0.9.7 | 0.90.22 | timestamp | refused |
| 0.9.8 | 0.91.0 | no timestamp | signed |
| 0.9.10 | 0.91.3 | no timestamp | signed |

Building the builder three ways (`withJson`, `withJsonAsync` with a `Context`,
`withJsonAsync` without one) made no difference, so the migration is not the
cause. `LocalSigner` is not affected: its sync path still fails with "the sync
http resolver is not implemented", so it still tries to reach the TSA.

**Cause, read from the source and not built.** In c2pa-rs 0.90, `cose_sign.rs`
used `signer.async_raw_signer()` when the signer offered one, and
`NeonCallbackSigner` offered itself. The TSA URL came from its
`AsyncTimeStampProvider` impl. c2pa-rs 0.91 removed `async_raw_signer()` from
`AsyncSigner`. Inferred from the history of `cose_sign.rs` between the two
tags, that is #2231, the raw-crypto split; the diff of #2231 itself was not
read. c2pa-js #217 ("Bump c2pa-rs to 0.91.0") then removed the
`AsyncRawSigner` impl from `neon_signer.rs`. Every async signer now goes
through `AsyncSignerWrapper`, which asks `AsyncSigner::time_authority_url()`.
`NeonCallbackSigner` does not implement that method, so it gets the default
`None`, and no timestamp is requested.

Reported as **contentauth/c2pa-js#244**, with the repro and a possible fix. The
fix would be two methods in the `AsyncSigner` impl, returning `tsa_url` and
`tsa_headers`. The bump's trigger is now **a c2pa-node release that fixes #244
and still carries the #2746 fix**. Dependabot's PR for 0.9.9 (#168) was closed
for the same reason.

## Verify after sign, and a test that tested nothing

c2pa-rs 0.91 verifies a manifest after signing it, by default (#2277). A
claim-v2 manifest without an actions assertion is invalid
(`assertion.action.malformed`), and the engine now refuses to sign it. The
service answers 500. Two tests are affected:

- **SPEC-029's "still signs a request carrying no actions assertion"** goes
  red. SPEC-011 settled "at most one, not required". Whether the service should
  now refuse such a request itself (with a 400), or switch the check off, is a
  decision for a spec. It is open.
- **SPEC-007 AC5's test passed on 0.9.10 for the wrong reason.** It sent
  `extra_assertions: []`, so the engine refused the manifest before it got to
  the TSA, while every valid manifest signed without a timestamp. Fixed in
  PR #184: the test now sends a valid manifest and asserts that the audit
  record's reason names the TSA URL the container was started with. Red on
  0.9.10 ("a signature was produced without a timestamp"), green on 0.9.5, CI
  20/20.

This is the same shape as the other tests in this log that passed while
testing nothing. A refusal is only evidence when its reason is the one under
test, and the reason was in the audit log the whole time.

## Smaller finding

`AuditLoggingTest`'s degraded-audit case starts `PORT=3999 node server.js &`
inside the container, with stdout on `/dev/full`, and ends with `kill %1`.
After a full integration run that process was still running (measured). The
likely reason is that `%1` does not resolve in a non-interactive `sh -c`, which
has no job control; this was not verified. A second-service probe on 3999 then
got answers from the stray process, and it looked like a service that had
started and printed nothing. The new probe uses port 3996, and it counts the
service as started only when its own child has logged its listening line.
`TrustSettingsStartupTest` also uses 3999. It is unaffected because every one
of its cases exits before binding the port.

## Still open

- c2pa-js#244.
- SPEC-029's answer to a request without an actions assertion on c2pa-rs 0.91.
- The SPEC-035 AC7 pin, which fires on the bump as intended. Not moved, since
  the bump does not go ahead.
- The `trust.allowed_list` paragraph in `docs/production.md`. The Amendment 1
  rule (anchors beside an allowed list) is true only once the code ships, and
  `docs/` is in the dist.
- c2pa-rs 0.92 (mid-November 2026) removes the flat `trust.trust_anchors`.
  That only matters at the next bump, and the `Context` path already emits
  `anchors[]`. This is reasoned, not measured.
