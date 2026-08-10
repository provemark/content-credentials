# Step 13 — SPEC-013 implemented: `isTrusted()` fails closed (2026-08-05)

First `src/` change since v0.4.0, so the next release is **v0.5.0**.

```php
- return ! in_array(self::UNTRUSTED_CODE, $this->validationStatusCodes, true);
+ return $this->validationState === ValidationState::Trusted;
```

### The defect was wider than reported
The review found that an asset with no C2PA data answered `isTrusted() === true`.
Writing the tests first showed that was one instance of a shape, not the whole
of it. Because the definition named exactly ONE status code, everything the code
did not name fell through to trusted. Measured on the pre-change implementation
(11 failed, 2 passed):

| Report | Old answer |
|---|---|
| no manifest at all | `true` |
| `Valid`, no status codes reported | `true` |
| absent / unrecognised `validation_state` | `true` |
| `signingCredential.revoked` / `.expired` / `.invalid` | `true` |
| `Invalid` manifest with no untrusted code | `true` |

A **revoked certificate reading as trusted** is the one that would have hurt
most in production. Defining trust positively removes the whole class: there is
no longer a list of failures to keep complete.

### Two existing tests encoded the old rule and had to change
Not "fixed to pass" — they asserted the superseded contract (SPEC-013 amends
SPEC-003 D3), so leaving them would have pinned the defect:

- `SigningServiceReaderTest` "reports trusted when no untrusted code is present"
  → split into "does not report trusted merely because no untrusted code is
  present" plus a new case carrying the `Trusted` verdict.
- The Eris property "treats trust as exactly the absence of the untrusted code"
  → now "decides trust by the Trusted verdict alone, whatever codes accompany
  it", generating over states AND code sequences. A second property pins AC6:
  `isVerifiedAiGenerated()` is never more permissive than the two checks it
  combines.

### Eris gotcha: `Generators::elements()` cannot take a literal `null`
`Generators::elements([null, 'Valid', ...])` throws `OutOfBoundsException` — the
generator indexes its array. Use a sentinel (`'NONE'`) and map it back;
`ValidationState::tryFrom('NONE')` returns null anyway, which is exactly the
"absent or unrecognised state" case.

### Dead constant removed
`UNTRUSTED_CODE` had no remaining reference and PHPStan level max flagged it. The
spec's API sketch said it would stay, but the sketch is explicitly illustrative,
and a private constant nothing reads is dead code. `validationStatusCodes()`
stays — the codes are still useful diagnostics, they just no longer define trust.

`composer check`: 115 passed, PHPStan clean, 0 Deptrac violations.

---

[← Step 12](step-12-spec-014-trust-verification-in-read.md) · [index](../NOTES.md) · [Step 14 →](step-14-spec-011-and-spec-012.md)
