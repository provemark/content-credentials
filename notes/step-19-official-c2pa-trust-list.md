# Step 19 — Verified against the OFFICIAL C2PA trust list (2026-08-06)

`c2pa-org/conformance-public` publishes the real thing:
`trust-list/C2PA-TRUST-LIST.pem` — 29 certificates, 36 KB, and a separate TSA
trust list beside it. That file is what decides whether the outside world
trusts a signature, as opposed to `certs/trust_anchors.pem`, which is our own
test material trusting our own test certificate.

Pointed the service at it (`CONTENTAUTH_TRUST_SETTINGS` → a settings document
with the real PEM as `trust_anchors`). Same asset, same test certificate:

| Trust list | `validation_state` | `isTrusted()` |
|---|---|---|
| our test anchors | `Trusted` | true |
| **official C2PA list** | `Valid` + `signingCredential.untrusted` | **false** |

`isSignatureValid()` stays true in both, and the Art.50 marking reads back in
both. So the pipeline works end to end against the real list — the only thing
missing for production is a certificate that is actually on it. SPEC-014's
startup validation accepts the official document without change.

### ⚠️ It also caught a bad assertion in bin/e2e.php
The SPEC-014 AC1 check was written as `isTrusted() === $trustEnabled`, which
assumes trust verification being ON implies *this* asset is trusted. That only
holds when the configured anchors cover the signing certificate. Against the
official list it reported

```
✗ trust mismatch: service trust_verification=true but isTrusted()=false
```

for a completely healthy configuration, where `false` is the correct answer.

Rewritten to assert what actually must hold in every configuration:

- trust off → `isTrusted()` false, by design;
- trust on and trusted → state is `Trusted` **and** no untrusted code;
- trust on and not trusted → there **is** a `signingCredential.untrusted` code
  explaining it, rather than silence.

Verified across all three. Same failure shape as the rest of this session — a
check going red for the wrong reason — except this one failed loudly on a
correct setup rather than passing quietly on a broken one, which is the
direction you want to be wrong in.

### And a flaky concurrency test, again
SPEC-015 AC3 failed on the `hardened` CI profile for a change that touched only
`bin/e2e.php`. Same cause as AC4 before it: a single burst is a race. Whether
the cap is reached depends on how fast the clients start relative to how fast
the service drains, and on a quick enough runner 20 requests arrive spread out
enough that nothing exceeds it.

Fixed the same way AC4 was — retry the burst to a deadline and stop on the
first one showing both outcomes. That does not weaken the criterion; it
establishes the precondition the criterion needs, namely that the cap was
actually exceeded. Burst also raised to `max(30, cap * 8)`.

The lesson, twice over now: a test that asserts something about concurrency
cannot assume it achieved concurrency. Assert it, or retry until you observe
it.

---

[← Step 18](step-18-branch-protection-on-main.md) · [index](../NOTES.md) · [Step 20 →](step-20-spec-017-body-size-default.md)
