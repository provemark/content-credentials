# Step 47 — The equivalence test compared configurations, not engines (2026-08-08)

Found while verifying SPEC-029 and left alone at the time: two SPEC-019 AC2 tests
fail on a machine with `ext-c2pa` installed while the service runs the hardened
profile.

```
readers disagree on isTrusted(): extension (c2pa-rs 0.89) says false,
                                 service (0.90.4) says true
```

Not an engine divergence. The service's trust verification comes from
`CONTENTAUTH_TRUST_SETTINGS` on the container; the extension's comes from PEM
contents passed in PHP, and the test constructed it with none. So it compared a
**configuration difference** and reported it in the vocabulary of an engine
difference — which is worse than a plain failure, because the message names a
cause that is not the cause.

### ⚠️ Why CI could not see it, and the shape of that

The only profile installing the extension is `ext-c2pa`, and it was the one
profile with trust settings OFF. The combination that breaks — extension present
*and* trust verification on — existed on a developer's machine and in no CI
profile at all.

That is a blind spot with a specific shape, and it is worth naming: **a matrix
covers each variable and not their combinations.** Trust-on is covered
(`hardened`), extension-present is covered (`ext-c2pa`), and their intersection
was covered nowhere. Every profile was individually justified; the gap was
between them.

### The fix, and proving it is the fix

`spec019MatchingExtensionReader()` gives the extension the anchors the service is
using when `/health` reports trust verification active. The material is the same
either way — `certs/trust_anchors.pem` is what `certs/c2pa-trust.settings.json`
embeds — so this aligns configuration rather than weakening the comparison.

Verified in both directions, because a test that was just made to pass is
suspect: reverting the helper to an unconfigured reader reproduces the failure
verbatim against a trust-verifying service, and restoring it gives 14 passed in
both profiles. The comparison still detects real disagreement; only the
configuration noise is gone.

The `ext-c2pa` CI profile now runs **with** trust settings, so the combination is
exercised on every push. It also makes AC2 stronger than it was: the readers are
now compared on `Trusted` rather than on `Valid`.

### No changelog entry

Test and CI only. Nothing a user of the package can observe changed, and a
changelog that records test fixes stops being readable as a record of what
shipped.

---

[← Step 46](step-46-spec-032-client-layer-corrections.md) · [index](../NOTES.md)
