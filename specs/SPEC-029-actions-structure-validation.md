# SPEC-029: Validating the actions structure `/v1/sign` reads

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-08                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-011 gave the service an opinion about what it will attest to: how many
assertions, how large, how deeply nested, how many actions assertions, and — as
opt-in policy — which `digitalSourceType`. It validates the assertion
**envelope**. It never validates the one structure it then reads.

Five helpers in `service/server.js` walk the actions array —
`suppliesOpenedAction()`, `needsParentAsset()`, `markingSourceTypes()`,
`firstActionSourceTypes()`, `allSourceTypes()` — and four of them do it as

```js
for (const action of assertion.data?.actions ?? []) { … }
```

which assumes `data.actions` is iterable. Nothing has checked that.

### Measured against the container, 2026-08-08

`docker-compose up -d --build`, `@contentauth/c2pa-node` 0.8.1 (c2pa-rs 0.90.4),
TSA configured. Every request carries a valid bearer token and otherwise
well-formed fields, and every one passes every SPEC-011 limit.

| `extra_assertions` | Sign | Read back | Engine said |
|---|---|---|---|
| one well-formed actions assertion | 200 | `Valid` | — |
| `data: {actions: 123}` | **500** `request failed` | — | `unhandled: number 123 is not iterable` *(our catch-all, not the engine)* |
| `data: {actions: "xx"}` | **500** `signing failed` | — | `could not decode assertion c2pa.actions.v2 …: invalid type: string "xx", expected a sequence` |
| `data: {}` — no `actions` key | **500** `signing failed` | — | `could not decode assertion c2pa.actions.v2 …: missing field 'actions'` |
| `data: {actions: []}` — empty | **200** | **500** | `validation rule was violated: No Action array in Actions` |
| `[]` — no actions assertion | 200 | `Invalid` | `assertion.action.malformed` |
| one non-actions assertion only | 200 | `Invalid` | `assertion.action.malformed` |

Four distinct failures from one omission, and the fifth row is the one that
changes what this spec has to require.

**The non-iterable value is a contract failure.** SPEC-011 AC7 requires that a
rejection "names the violated constraint and its limit"; SPEC-009 establishes 400
as the client-error convention. A caller gets neither. What they get instead is
the catch-all handler, which is a safety net for the unanticipated — and note
what it proves: it audited the request, answered JSON, and carried a correlation
id, exactly as it was built to. The catch-all is not the defect. Being reachable
by a one-field payload is.

**The non-array value is worse and quieter.** A string in place of the array
passes every guard, reaches `Builder.withJson()`, and is refused by c2pa-rs. So a
malformed payload consumed a concurrency slot and an actual signing attempt
before anything noticed. SPEC-011 exists precisely so that "the service will sign
**any** assertion structure an authenticated caller supplies" stops being true,
and for the actions array it is still true — it stops at the engine rather than
at the boundary.

### The empty actions array is signed, and then nothing can read it

`{actions: []}` is the only shape that gets a signature. The service answers 200
and returns a 55 KB signed PNG — and that asset cannot be read back by anything:

| Engine | Result |
|---|---|
| the signing service, c2pa-rs **0.90.4** | HTTP 500, `validation rule was violated: No Action array in Actions` |
| `c2patool` **0.27.3** | `Error: validation rule was violated: No Action array in Actions` |
| `ExtC2paReader`, c2pa-rs **0.89.0** | `ReadFailedException: … No Action array in Actions` |

Three engines across two c2pa-rs minors, one answer. So this is not a version
quirk and not our decoder: the manifest is unreadable, full stop.

That is the SPEC-028 AC13 situation again, arrived at from the other direction —
"a signature spent on a manifest no verifier accepts, with our certificate on
it". AC13 refuses a caller-supplied `c2pa.opened` for exactly this reason. An
empty actions array is worse in one respect: `c2pa.opened` produced an *Invalid*
manifest that a verifier could still parse and explain, while this one throws.

Note the contrast with the last two rows. Sending **no** actions assertion is
permitted (SPEC-011 settled "at most one, not required") and degrades honestly:
the manifest reads back as `Invalid` with `assertion.action.malformed`, which is
a verifier telling the truth about a claim-v2 rule (CLAUDE.md, Domain rules: the
first action MUST be `c2pa.created` or `c2pa.opened`). An empty actions array
does not degrade — it breaks the reader. **Absent is a worse claim; empty is a
worse artefact.**

### Why the failure modes differ, which is the part worth keeping

`firstActionSourceTypes()` reads `assertion.data?.actions?.[0]` — indexing, which
is total over any value. The other four use `for…of`, which is not. So whether a
hostile payload becomes a 500-with-no-reason or a 500-from-the-engine depends on
which accessor happens to touch it first. That is not a design; it is what
"validate the envelope, trust the contents" produces.

This is the same shape NOTES Step 29 recorded for the depth guard: *a correct
guard placed behind an unbounded one is not a guard.* Here the guards are correct
and complete for what they measure, and the thing they never measure is the thing
every consumer downstream assumes.

### What this spec does not claim

It adds no semantic opinion. SPEC-011 put "validating assertion *contents*" out
of scope with a reason that stands: the service cannot verify a claim better than
the caller who made it. This is a **shape** requirement — an actions assertion
must be the structure the C2PA domain rules describe (CLAUDE.md, Domain rules:
one `c2pa.actions.v2` assertion, an `actions` array, first action
`c2pa.created`/`c2pa.opened`) — not a judgement about what the actions say.

## Scope

**In scope**

- Requiring, for every assertion whose label starts `c2pa.actions`, that `data`
  is an object and `data.actions` is a **non-empty** array whose entries are
  objects. Sending no actions assertion at all stays permitted, unchanged —
  the constraint is conditional on there being one (see Open questions, and the
  last two rows of the measurement).
- Refusing a violation with **400** and a named constraint, through the existing
  `reject()` path, so the refusal is audited in the SPEC-012 shape (correlation
  id, token id, reason, `mime_type`, the SPEC-028 parent fields) rather than as
  `unhandled: …`.
- Refusing **before** any signing is attempted, so no malformed payload reaches
  `Builder.withJson()`.
- Making the five actions helpers total over any accepted input, so no future
  caller of them has to re-derive this precondition.
- Documenting the constraint in the README service section, beside the other
  SPEC-011 limits.

**Out of scope** (each needs its own spec before it may be built)

- Semantic validation of an action's contents: the action verb, whether
  `digitalSourceType` is a registered IPTC URI, the `softwareAgent` shape.
  SPEC-011 excluded this and its reasoning is unchanged.
- Requiring an actions assertion at all. SPEC-011 settled "at most one, not
  required" (Open questions, resolved 2026-08-05) and this spec does not reopen
  it. Measured: no actions assertion signs and reads back `Invalid` with
  `assertion.action.malformed`, which is a verifier correctly reporting a
  claim-v2 violation. That is a caller's choice to make badly, not a manifest
  this service must refuse to produce.
- Bounding what an unauthenticated caller can make the service do. That is the
  companion finding and is **SPEC-030**; the two are independent and neither
  blocks the other.
- A committed test for the catch-all handler itself. NOTES Step 29 verified it by
  patching `server.js` on a spare port and recorded that a committed test needs
  its own acceptance criterion. This spec removes the only known HTTP-reachable
  trigger, which returns the handler to unreachable-by-design; the question of
  covering it stays open and stays out of scope here.
- Any change to `src/`. `ManifestBuilder` emits the correct shape and a test
  pins it; if a constraint here turns out to refuse what the client sends, that
  is a spec contradiction → stop and amend.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-029')`, in the integration group
where a live service is required.

- **AC1 — the legitimate client is unaffected**
  - Given a manifest built by `ManifestBuilder::forAiGenerated()` and one built
    by `forAiManipulated()` with a parent
  - When each is signed via `/v1/sign`
  - Then both succeed, and the resulting manifests are what they were before this
    spec: one `c2pa.actions.v2` assertion, Art.50 marking present, timestamp
    behaviour unchanged
  - *(Both entry points, because the edited shape puts `c2pa.opened` first and is
    the one the helpers read most.)*

- **AC2 — a non-iterable actions value is refused** *(error path)*
  - Given `extra_assertions` containing `{label: "c2pa.actions.v2", data: {actions: 123}}`
  - When `/v1/sign` is called
  - Then HTTP **400** with a message naming the constraint, and no signing takes
    place
  - And the audit record carries `outcome: "rejected"` with that constraint as
    its `reason` — never a `reason` beginning `unhandled:`

- **AC3 — a non-array actions value is refused before the engine sees it** *(error path)*
  - Given `extra_assertions` containing `{label: "c2pa.actions.v2", data: {actions: "xx"}}`
  - When `/v1/sign` is called
  - Then HTTP **400**, and the audit stream carries **no** record with
    `outcome: "failed"` — i.e. `Builder.withJson()` was never reached
  - *(The distinction between `rejected` and `failed` in the record is what makes
    "no signing was attempted" observable from outside. Asserting only the status
    code would pass against a service that signs and then refuses.)*

- **AC4 — a malformed action entry is refused** *(error path)*
  - Given an actions array containing a non-object entry — `null`, a string, a
    number, a nested array
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing

- **AC5 — a non-object `data` is refused** *(error path)*
  - Given `{label: "c2pa.actions.v2", data: "text"}`, and separately
    `{label: "c2pa.actions.v2", data: null}`
  - When `/v1/sign` is called
  - Then HTTP **400**, no signing

- **AC6 — an actions assertion must carry at least one action** *(error path)*
  - Given `{label: "c2pa.actions.v2", data: {}}` — no `actions` key — and
    separately `{label: "c2pa.actions.v2", data: {actions: []}}`
  - When `/v1/sign` is called
  - Then HTTP **400** in both cases, and no signing takes place
  - And given `extra_assertions: []` — no actions assertion at all
  - Then the request is **signed**, and reads back `Invalid` with
    `assertion.action.malformed`, exactly as it does today
  - *(The three outcomes together are the criterion. Testing only the two
    refusals would pass against an implementation that also refuses the absent
    case, which would silently overturn SPEC-011's settled "not required".)*
  - *(The empty array is the reason this criterion exists rather than the absent
    key. Measured: it is the one shape that gets a signature, and the resulting
    asset is unreadable by c2pa-rs 0.90.4, c2patool 0.27.3 and c2pa-rs 0.89.0
    alike. A test must therefore assert that **nothing was signed**, not merely
    that a 400 came back.)*

- **AC7 — the refusal leaks nothing**
  - Given any of AC2–AC6
  - When the 400 response is returned
  - Then the message names the constraint and contains no file path, no library
    internals, no JavaScript error text and no echo of the submitted payload
  - *(SPEC-011 AC7 in this new place. Today's `unhandled: number 123 is not
    iterable (cannot read property Symbol(Symbol.iterator))` in the audit record
    is engine wording that never should have become our reason string, and
    `could not decode assertion c2pa.actions.v2 (version (no version), content
    type application/cbor): missing field 'actions'` is engine wording a caller
    should never have to interpret.)*

- **AC8 — the helpers are total**
  - Given any `extra_assertions` value that passes validation
  - When each of the five actions helpers is applied to it
  - Then none throws
  - *(Testable directly in Node against the module, without HTTP. This is the
    criterion that stops the fix being one `if` in one call site while the next
    helper added re-introduces the assumption.)*

- **AC9 — the policy path still reads the marking**
  - Given a service started with `REQUIRE_AI_MARKING=true`
  - When a well-formed manipulated manifest is signed (first action
    `c2pa.edited`, the SPEC-028 shape)
  - Then it is accepted, exactly as SPEC-028 AC7 requires
  - *(Regression guard: `markingSourceTypes()` is one of the four helpers being
    made total, and it is the one whose behaviour a policy decision hangs on.)*

## API sketch

Illustrative only. The change is confined to `service/server.js`; the request and
response shapes of `/v1/sign` do not change, only which requests are accepted.

```js
// service/server.js — inside rejectAssertions(), after the label/depth/size
// checks and before the actions-count tally, so a malformed shape is refused
// by the same pass that already walks every assertion.

/** @returns {string|null} the violated constraint, or null when acceptable. */
function rejectActionsShape(assertion) {
  if (!assertion.label.startsWith('c2pa.actions')) return null;

  const data = assertion.data;
  if (data === null || typeof data !== 'object' || Array.isArray(data)) {
    return 'an actions assertion must carry an object "data"';
  }
  if (!Array.isArray(data.actions)) {
    return 'an actions assertion must carry an array "data.actions"';
  }
  if (data.actions.length === 0) {
    return 'an actions assertion must carry at least one action';
  }
  if (data.actions.some((a) => a === null || typeof a !== 'object' || Array.isArray(a))) {
    return 'each entry of "data.actions" must be an object';
  }

  return null;
}
```

The `!Array.isArray(data.actions)` line covers the absent key as well as the
wrong type, which is why there is no `!== undefined` guard: measured, `data: {}`
is refused by c2pa-rs at build time with `missing field 'actions'`, so allowing
it here would only move a 500 later rather than remove it. The
`length === 0` line is separate because it is the only shape that would
otherwise be **signed**, and it deserves a message that says so rather than
being folded into "must be an array".

With that in place the four `for…of` helpers are total for every accepted input,
and `firstActionSourceTypes()` stops being accidentally safer than its siblings.

Whether the helpers should *also* be written defensively — `Array.isArray(...) ? ... : []`
— is a judgement call. Defence in depth argues yes; the counter-argument is that
a second guard behind a validated boundary is the thing that hides the boundary
failing, which is the shape NOTES keeps recording. AC8 is satisfied either way,
which is deliberate: it constrains the behaviour, not the technique.

The PHP client needs no change. `SigningServiceSigner` surfaces a non-2xx body
through `SigningFailedException` with the service message (SPEC-002 / SPEC-009).

## Open questions

- ~~**Is an actions assertion with no `data.actions` at all acceptable?**~~
  **RESOLVED (2026-08-08): no — an actions assertion must carry a non-empty
  array.** Measured rather than reasoned, and the measurement reversed the
  draft's own sketch, which had allowed the absent key on the argument that an
  empty actions assertion is the degenerate case of SPEC-011's "not required".

  The two shapes turned out to behave nothing alike. `data: {}` is refused by
  c2pa-rs at build time (`missing field 'actions'`), so permitting it buys only a
  500 instead of a 400. `data: {actions: []}` is the dangerous one: it **signs**,
  and the signed asset is unreadable by all three engines available to this
  project. Permitting it would mean the service can be made to spend its
  certificate on an artefact nothing can verify — SPEC-028 AC13's situation,
  reached by a different route.

  The permission SPEC-011 settled is untouched, because it is about a different
  thing: sending **no** actions assertion still signs and still reads back
  `Invalid` with `assertion.action.malformed`. That is a verifier telling the
  truth about a claim-v2 rule, which is a caller's claim to make badly. An empty
  array is not a worse claim, it is a broken artefact. Recorded as AC6, which
  tests all three outcomes together so an implementation cannot quietly refuse
  the absent case too.
- **Should the constraint be a hard invariant or an env-tunable limit?** Every
  SPEC-011 limit is env-tunable, which is consistent. But this is a shape
  requirement rather than a size, there is no legitimate value to tune it to, and
  a knob invites someone to turn it off. *Non-blocker*, leaning hard invariant
  with no env var.
- **Does the fix change any existing test's expectation?** The property-based
  suites generate assertion structures; if any of them generate a non-array
  `actions` and currently expect a 500, that expectation encodes the defect and
  must change rather than be worked around (the SPEC-013 precedent, NOTES
  Step 13). *Non-blocker*, but it must be checked before implementation rather
  than discovered during it.
- **Do the four helpers have a fifth sibling elsewhere?** `allSourceTypes()` is
  called on the *success* audit path, after signing. If validation is ever
  bypassed for a code path that does not run `rejectAssertions()`, that call
  throws after a signature has been produced. There is no such path today.
  *Non-blocker*, but AC8 is written against the helpers rather than against the
  route for exactly this reason.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | — | — |
| AC2 | — | — |
| AC3 | — | — |
| AC4 | — | — |
| AC5 | — | — |
| AC6 | — | — |
| AC7 | — | — |
| AC8 | — | — |
| AC9 | — | — |