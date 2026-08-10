# Step 45 — SPEC-031: the gap a scope authorised and a criterion missed (2026-08-08)

`extractError()` existed twice, character for character, in
`SigningServiceSigner` and `SigningServiceReader`. Only the signer's copy was
capped, so `ReadFailedException` could carry up to `maxResponseBytes` — 32 MiB —
into one log line, on the path SPEC-019 and SPEC-020 encourage applications to
use from request handlers.

### The failure mode is new to this log, and worth naming

SPEC-025's **Scope** says "Capping the service error text copied into an
exception" — generic, no client named. Its **AC4** says "When the client raises
`SigningFailedException`". The implementation followed the criterion, and the
criterion was narrower than the scope that authorised it.

That is the mirror of Step 38's SPEC-028 AC8, where a criterion said "accepted
**or refused**" and only the accepted half was built. There the criterion was
broad and the work was narrow; here the scope was broad and the *criterion* was
narrow. Same outcome — a spec marked `implemented` over a real gap — and the
second kind is invisible to tooling: `bin/spec-check.php` compares criteria to
tests, and cannot compare a criterion to the scope bullet above it.

No proposal to build that check. The scope-to-criteria step is a reading, not a
lookup. But it is worth knowing that of the two ways a spec can be under-built,
only one of them has a tool watching it.

### Two copies of one decision

The fix is one `Core\Support\ServiceError`, called by both. Recorded here because
the reasoning generalises: this is the third time a shared decision living in two
places has cost something. `ManifestStoreParser` was extracted for the same
reason (SPEC-019: "two decoders would be two places for the definition of trusted
to drift"), and SPEC-021 derived three allow-list error messages from one list
after all three went stale. Here the duplication was not even a risk of drift —
they had already drifted, and had been apart since SPEC-025 shipped.

### No ext-mbstring, and the reason the truncation is safe anyway

`substr()` cuts by bytes, so a UTF-8 message capped at byte 256 can end
mid-codepoint. The obvious fix is `mb_substr()`, and it was rejected: this
package requires `php-http/discovery` and three PSR packages and nothing else,
and a truncation helper is a poor reason to put an extension in `require`.

`preg_match('/^.{0,256}/us')` gives character semantics from PCRE, which ships
with every build. What makes that sufficient rather than half a fix is where the
input comes from: `json_decode()` **rejects** malformed UTF-8 outright
(`JSON_ERROR_UTF8`), so by the time there is an `error` string to cap it is valid
UTF-8 by construction. The guarantee is the decoder's, not the truncation's, and
the code says so — otherwise the next person adds a repair pass that can never
fire.

The test asserts validity with `preg_match('//u', …)` rather than
`mb_check_encoding()`, for the same reason: a suite may not assume an extension
the package declines to require.

### ⚠️ A three-byte character, deliberately

AC4's fixture is `str_repeat('⚡', 300)` — three bytes per character. A two-byte
character would divide evenly into 256 and the test would pass against
`substr()`, which is the defect. Watched red before the fix: the signer failed,
the reader passed **because it did not truncate at all**. That reader half only
became meaningful once AC1 was fixed, which is worth remembering when reading
the run — a green cell is not always the same green.

### Verified

`composer check` green (312 passed). Integration 136 passed / 16 skipped.
SPEC-025's own group still 24 passed, and its AC4 traceability row now points at
`ServiceError` — the Traceability section being the one part of an `implemented`
spec that may change. `bin/e2e.php` and `bin/verify.sh` PASS.
`php bin/spec-check.php` 0 errors.

---

[← Step 44](step-44-node-24-and-container-hardening.md) · [index](../NOTES.md) · [Step 46 →](step-46-spec-032-client-layer-corrections.md)
