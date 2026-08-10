# Step 41 — Measuring SPEC-029's blocking question, which reversed its own sketch (2026-08-08)

SPEC-029 shipped as `draft` with one blocking open question: is an actions
assertion with no `data.actions` acceptable? Its API sketch had answered yes,
reasoning that SPEC-011 settled "an actions assertion is not required", so an
empty one is the degenerate case of the same permission. Measured against the
container (`@contentauth/c2pa-node` 0.8.1, c2pa-rs 0.90.4, TSA on) rather than
reasoned, and the reasoning was wrong twice over. The full table lives in the
spec; what follows is what it cost and what it taught.

Two shapes, nothing alike:

- `data: {}` — c2pa-rs refuses at **build** time, `missing field 'actions'`. So
  permitting it buys a 500 instead of a 400 and nothing else.
- `data: {actions: []}` — **signs**. HTTP 200, a 55 KB signed PNG comes back.

### ⚠️ The one shape that signs is the one nothing can read

| Engine | Reading the signed asset |
|---|---|
| the signing service, c2pa-rs 0.90.4 | HTTP 500, `validation rule was violated: No Action array in Actions` |
| `c2patool` 0.27.3 | `Error: validation rule was violated: No Action array in Actions` |
| `ExtC2paReader`, c2pa-rs 0.89.0 | `ReadFailedException: … No Action array in Actions` |

Three engines, two c2pa-rs minors, one answer — so it is not a version quirk and
not our decoder. This is SPEC-028 AC13's situation reached from the other
direction: a signature spent on a manifest no verifier accepts, with our
certificate on it. Worse than AC13's case in one respect, which is worth stating
precisely: a caller-supplied `c2pa.opened` produced an *Invalid* manifest that a
verifier could still parse and explain. This one throws.

**Absent is a worse claim; empty is a worse artefact.** Sending no actions
assertion at all still signs and still reads back `Invalid` with
`assertion.action.malformed` — a verifier correctly reporting a claim-v2
violation, which is a caller's claim to make badly. SPEC-011's permission is
untouched; the new constraint is conditional on there being an actions assertion
at all, and SPEC-029 AC6 tests all three outcomes together so an implementation
cannot quietly refuse the absent case too.

### What is worth keeping beyond the answer

**The draft argued from a permission and the permission did not transfer.**
"Not required" is about a manifest lacking a claim. "Empty array" is about a
manifest whose claim is structurally broken. They look adjacent in a scope
sentence and behave nothing alike in an engine, and no amount of re-reading
SPEC-011 would have surfaced that — only signing it did. This is the fourth time
in this log that an open question marked *blocker, measure it* returned an answer
opposite to the leaning written beside it (Steps 27, 30, 32, now this).

**It is also the first time the two readers agreed about something that was not
a test.** SPEC-019 AC2 exists to catch the engines drifting apart; here they were
used as three independent witnesses to rule out "our decoder is wrong", which is
a use the equivalence work paid for without being written for.

### Method note

The container, not the host — deliberately, per Step 40's own caveat. The two
defect-1 rows from Step 40 were re-measured here too, and came back identical to
the host run, which retires the caveat for those two specific results without
retiring it in general.

---

[← Step 40](step-40-outsider-review-envelope-guard.md) · [index](../NOTES.md) · [Step 42 →](step-42-spec-030-peer-identity.md)
