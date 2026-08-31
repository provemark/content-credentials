# SPEC-035: Declaring a C2PA specification version

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (extends SPEC-001 building, SPEC-002 signing)   |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A manifest is a set of statements, and every statement is read against a
rulebook. Ours does not say which rulebook. A verifier meeting one of our assets
in five years has to infer the version from its shape, and inference is exactly
what a provenance format exists to remove.

C2PA 2.3 introduced a `specVersion` declaration; 2.4 moved it from the claim into
`claim_generator_info`, deprecated the claim-level field, and strengthened the
recommendation that claim generators include it. Our manifests carry
`claim_generator_info` already — a name, a version, and the
`org.contentauth.c2pa_rs` key the engine adds — and no `specVersion`.

**The engine will never supply it.** That is settled, not pending, and it is the
finding that turns this from a chore into a decision: if the value appears, it is
because this package chose it.

And a version declaration is not a label. It is a claim that this manifest
follows those rules — signed, like everything else in the manifest. **The work
here is therefore not adding a key; it is establishing which version we actually
satisfy, and building a guard that keeps the declaration true as the package
changes.**

Note what this spec deliberately does *not* argue: that we should declare the
newest version. Declaring 2.4 while following 2.2 would be a false statement in
the one artefact whose purpose is that its statements can be relied on.
Declaring 2.2 accurately is both honest and more useful than declaring nothing.
**Aim for true, not for high.**

### Measurements this spec rests on

All 2026-08-31, against `tools/c2patool` 0.27.16 carrying c2pa-rs **0.90.16** —
the newest engine, signing on its own with neither this package nor the service
in the path.

- **The engine emits no `specVersion` at all.** A manifest signed by c2patool
  alone comes back with `claim_generator_info` holding only the supplied name and
  version plus `org.contentauth.c2pa_rs: 0.90.16`; a search of the whole report
  for `specVersion` finds nothing.
- **By design, not omission.** In `sdk/src/claim.rs`, `spec_version` is
  `Option<String>`, set to `None` in three constructors, serialised only
  `if self.spec_version.is_some()`, and exposed through a public
  `set_spec_version()`. It is opt-in, and the reference tool does not opt in.
- **The field passes through verbatim if supplied.** `specVersion` placed in
  `claim_generator_info` survives signing and reads back unchanged, with
  `validation_state: Valid`.
- **`claim_generator_info` is an open map.** c2pa-rs adds its own key and does
  not validate ours. Nothing upstream checks that a declared version is accurate,
  which is why AC2 exists and is the substance of this spec.
- **It is built entirely service-side** (`service/server.js:973`). See the
  delivery note under Scope: this ships through a rebuild, not through Composer.

## Scope

**In scope**

- Establishing, by audit against the published requirements, which specification
  version this package's manifests actually satisfy.
- Declaring that version in `claim_generator_info`, chosen by this package rather
  than by the caller.
- A guard that the declaration stays **true** as the package changes.
- Exposing, on read, what a foreign manifest declares.

**Out of scope** (each needs its own spec before it may be built)

- **`allActionsIncluded`.** Removed from this spec deliberately, and the reason
  is worth recording because the field looks like it belongs here. It is a claim
  that the action list is *complete* — that nothing else was done to the asset.
  This package receives bytes and cannot know an asset's history, so it can only
  ever be a caller's statement that we relay unverified. That is a feature with
  its own risk profile, nobody has asked for it, and bundling it here would mean
  approving two unrelated decisions at once. Recorded findings for whoever
  picks it up: it belongs **beside** `actions` at assertion level, and placing it
  on an individual action instead causes c2pa-rs to **discard it without error**
  while validation still reports `Valid` — a mis-shaped manifest is
  indistinguishable from a correct one by any signal the engine gives, so that
  spec must refuse the mis-shape in our own layer.
- The **deprecated claim-level** `spec_version`. 2.4 moved it; we implement the
  location that is current, not the one c2pa-rs happens to expose a setter for.
- Other 2.4 additions: `relatedAssertions` in action parameters, watermarking
  actions referencing soft bindings, live/CMAF streaming, enhanced cloud data
  assertions.
- **Raising the version we satisfy.** This spec declares what is true today. Work
  to *become* compliant with a higher version is a separate undertaking, and AC2
  is what will tell you it is needed.
- Changing the **claim version**. We emit claim v2 and this spec does not touch
  that.

**Delivery note.** `claim_generator_info` is assembled in `service/server.js`,
which is `export-ignore`d. This change therefore reaches users through `git pull`
plus a rebuild and **not** through `composer update`. AC5 exists so an operator
can confirm a rebuild landed without spending a signature, and the CHANGELOG
entry must say this plainly.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-035')`.

- **AC1 — a signed manifest declares a specification version**
  - Given an asset signed through this package
  - When the manifest is read back
  - Then `claim_generator_info` carries `specVersion` with the declared value as
    a pinned exact string, alongside the existing name, version and
    `org.contentauth.c2pa_rs` keys

- **AC2 — the declaration is guarded against becoming untrue**
  - Given the declared version and the list of that version's requirements that
    apply to what this package emits
  - When the guard runs
  - Then it fails if any applicable requirement is unmet, naming the requirement,
    so that raising the declared value cannot be a one-line edit that quietly
    outruns the manifest — and so that a later change which breaks a requirement
    is caught as a failing test rather than as a false declaration in signed
    output

- **AC3 — the caller cannot set or override the declared version** *(error path)*
  - Given a caller supplying `specVersion` themselves, through `creator_name`,
    an extra assertion, or any other field they control
  - When the manifest is built or signed
  - Then the value is refused rather than merged: the declaration describes what
    this package emits, not what a caller wishes to claim about it

- **AC4 — a malformed declared version stops the service at startup** *(error path)*
  - Given a service configured with a declared version that is empty or not a
    recognised C2PA specification version
  - When the service starts
  - Then it refuses to start, naming the offending value, rather than signing
    manifests that carry a meaningless declaration — the same fail-closed stance
    SPEC-014 takes for trust settings carrying no material

- **AC5 — a deployment can be checked without signing**
  - Given a running signing service
  - When `GET /health` is called
  - Then it reports the specification version the service declares, so an
    operator can confirm a rebuild landed

- **AC6 — reading reports what a foreign manifest declares**
  - Given an asset whose manifest declares a specification version, and a second
    asset whose manifest declares none
  - When both are read
  - Then the first reports the declared version verbatim and the second reports
    absence without failing, per the SPEC-003 contract

## API sketch

Illustrative only. `strict_types=1`, value objects `readonly`, public API
`final`.

Service side (AC1, AC4, AC5) — `service/server.js`, where
`claim_generator_info` is assembled today:

```js
claim_generator_info: [
  { name: creator_name || '...', version: '...', specVersion: SPEC_VERSION },
],
```

Client side (AC6) — what a manifest we did not produce declares:

```php
// namespace Provemark\ContentCredentials\Core\Reading;

$report->declaredSpecVersion();   // '2.2' | null
```

## Open questions

- ~~**Which version do we declare?**~~ **RESOLVED (2026-08-31): the one we
  actually satisfy, determined by audit.** The question was posed as a choice
  between the newest version and the one c2pa-rs implements, which made it look
  like a strategic decision needing a policy. It is not: a declaration is a
  signed statement, so the only defensible value is the true one. That converts
  the blocker into measurable work — audit what we emit against each version's
  applicable requirements, take the highest one that fully passes, and let AC2
  hold it there. The expected answer is **lower than 2.4**; the domain rules were
  verified against 2.2 and nothing since has moved us up.
- **How granular is the AC2 requirement list?** The full specification is far
  larger than the part that describes what a claim generator emits. *Non-blocker*,
  leaning: enumerate only the requirements that touch our own output — actions
  assertion shape, claim version, assertion labels, `digitalSourceType` — and say
  in the test's docblock that the list is scoped that way, so nobody reads a
  passing guard as full conformance.
- **Does the declared version belong in configuration or in code?** Configuration
  invites an operator to raise it and break the truth AC2 protects; a constant
  makes AC4's malformed-value path unreachable in practice. *Non-blocker*,
  leaning a constant in the service with AC4 covering the configured-override
  path if one is ever added.
- **The line this touches carries spike leftovers.** `server.js:973` still reads
  `name: creator_name || 'c2pa-spike-signer', version: '0.1.0'` — a default name
  and a version describing the spike rather than the release. Editing the object
  for `specVersion` puts them under the cursor. *Non-blocker*, and deliberately
  not folded in: changing the default claim generator name is user-visible and
  belongs in its own change.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |