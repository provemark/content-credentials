# Step 78 — An expired rogue fixture that kept passing (2026-11-09)

SPEC-045 Amendment 5, test only. No code changes, no release.

## What happened

`tests/Fixtures/spec045-c2patool027-rogue.png` holds an AI original signed by
a rogue CA (*Rogue Root*, *Rogue Signer*), re-signed once with c2patool
0.27.22 and the C2PA test signer. Both rogue certificates were valid for 30
days: 2026-10-10 06:38:52 to **2026-11-09 06:38:52 UTC**. The outer signature
has no time-stamp.

From that moment the file reads `Invalid` (`signingCredential.expired`) on
both readers, instead of a trusted file over an untrusted ingredient
(measured with `certs/c2pa-trust.settings.json`):

| File | Outer | Ingredient |
|---|---|---|
| expired fixture | `Invalid`, not trusted | not trusted |
| rebuild | `Trusted` | not trusted |

AC3's table row (`'c2patool 0.27 rogue' => [false]`) checks only the
ingredient, and under Amendment 2 nothing below an untrusted report is
trusted. So the row stayed green: it passed for a reason it was not written
for. Nothing here noticed.

The Drupal module had copied the file and asserts the log line, which names
the outer verdict. Its CI went red on its 1.0.0-alpha5 release commit, the
morning the certificate expired (content_credentials #3636349, its NOTES
Step 67).

## The fix

- The fixture is the module's rebuild, byte for byte: a new P-256 rogue CA
  and signer valid to 2036-11-05, an AI original
  (`trainedAlgorithmicMedia`) signed by it, re-signed with c2patool 0.27.22
  and `certs/es256_*`, no time-stamp. The recipe and keys are kept in the
  module's development notes.
- A new test, "keeps a trusted file trusted over its untrusted ingredient"
  (verifier, extension), pins the precondition the row relies on. Run red on
  the expired file (2 failed), green on the rebuild.
- `composer check`: 530 passed, 7 skipped.

## Still open

- The rebuilt file expires with the C2PA test signer on **2030-08-26**
  (outer signature, no time-stamp). Accepted.
- The other rogue fixtures (`spec045-c2pasign-rogue.png`, same expired chain;
  `spec045-graft.png`, to 2027) are expected untrusted either way; their rows
  do not depend on the rogue certificate's dates.
- No guard for fixture certificate expiry in general.
