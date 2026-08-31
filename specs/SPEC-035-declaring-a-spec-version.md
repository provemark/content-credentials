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
`claim_generator_info` already — a name, a version, and the `org.contentauth.c2pa_rs`
key the engine adds — and no `specVersion`.

**The engine will never supply it.** That is settled, not pending, and it is the
finding that turns this from a chore into a decision: if the value appears, it is
because this package chose it.

And a version declaration is not a label. It is a claim that this manifest
follows those rules — signed, like everything else in the manifest. Declaring
2.4 while not meeting a recommendation 2.3 introduced would be a statement we do
not honour, in the one artefact whose purpose is that its statements can be
relied on. **The work here is deciding what we declare and making it true; the
field itself is one key.** That is why this is a spec rather than a patch.

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
- **Both fields pass through verbatim if supplied.** `specVersion` placed in
  `claim_generator_info` and `allActionsIncluded` placed beside `actions` both
  survive signing and read back unchanged, with `validation_state: Valid`.
- **`claim_generator_info` is an open map.** c2pa-rs adds its own key and does
  not validate ours. Nothing upstream checks that a declared version is accurate.
- **A silent-drop trap, measured by falling into it.** `allActionsIncluded`
  placed on an individual *action* rather than beside `actions` is **discarded
  without error**, and validation still reports `Valid`. The field simply is not
  in the read-back. A mis-shaped manifest is therefore indistinguishable from a
  correct one by any signal the engine gives.
- **The two fields live in different layers.** `claim_generator_info` is built
  entirely service-side (`service/server.js:973`), so `specVersion` reaches users
  through `git pull` plus a rebuild. `allActionsIncluded` belongs to the actions
  assertion, which the PHP client owns through `extra_assertions`, so it reaches
  users through `composer update`. One spec, two delivery routes.

## Scope

**In scope**

- Declaring a specification version in `claim_generator_info`, chosen by this
  package rather than by the caller.
- A guard that the declaration is **true**: a test that fails if we declare a
  version whose applicable requirements our manifests do not meet.
- Emitting `allActionsIncluded` where it can be stated honestly, at the assertion
  level the specification defines.
- Refusing the mis-shaped placement rather than letting the engine discard it.
- Exposing, on read, what a foreign manifest declares.

**Out of scope** (each needs its own spec before it may be built)

- **Inferring `allActionsIncluded`.** This package receives bytes and cannot know
  what happened to an asset before it arrived. Setting the flag on a caller's
  behalf would convert our ignorance into their attestation.
- The **deprecated claim-level** `spec_version`. 2.4 moved it; we implement the
  location that is current, not the one c2pa-rs happens to expose a setter for.
- Other 2.4 additions: `relatedAssertions` in action parameters, watermarking
  actions referencing soft bindings, live/CMAF streaming, enhanced cloud data
  assertions.
- Changing the **claim version**. We emit claim v2 and this spec does not touch
  that.

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
  - Given the declared version and the set of its requirements that apply to what
    this package emits
  - When the guard runs
  - Then it fails if any applicable requirement is unmet, so raising the declared
    version cannot be a one-line edit that quietly outruns the manifest

- **AC3 — allActionsIncluded is a caller statement, never a default**
  - Given a caller who has not stated that the action list is complete
  - When the manifest is built
  - Then no `allActionsIncluded` is emitted; and when the caller does state it,
    the flag appears beside `actions` at assertion level with that value

- **AC4 — the mis-shaped placement is refused, not silently dropped** *(error path)*
  - Given an actions assertion carrying `allActionsIncluded` on an individual
    action rather than beside `actions`
  - When it is submitted for signing
  - Then it is rejected with a message naming the correct location, rather than
    signed into a manifest the engine has quietly stripped it from

- **AC5 — the caller cannot set or override the declared version** *(error path)*
  - Given a caller supplying `specVersion` themselves
  - When the manifest is built or signed
  - Then the value is refused rather than merged: the declaration describes what
    this package emits, not what a caller wishes to claim about it

- **AC6 — reading reports what a foreign manifest declares**
  - Given an asset whose manifest declares a specification version, and a second
    asset whose manifest declares none
  - When both are read
  - Then the first reports the declared version verbatim and the second reports
    absence without failing, per the SPEC-003 contract

- **AC7 — a deployment can be checked without signing**
  - Given a running signing service
  - When `GET /health` is called
  - Then it reports the specification version the service declares, so an
    operator can confirm a rebuild landed without spending a signature

## API sketch

Illustrative only. `strict_types=1`, value objects `readonly`, public API
`final`.

```php
// namespace Provemark\ContentCredentials\Core\Manifest;

// AC3: an explicit caller statement, not a default.
$manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withCompleteActionList()   // emits allActionsIncluded: true
    ->build();

// AC6: what a manifest we did not produce declares.
$report->declaredSpecVersion();   // '2.4' | null
```

Service side (AC1, AC7) — `service/server.js`, where `claim_generator_info` is
assembled today:

```js
claim_generator_info: [
  { name: creator_name || '...', version: '...', specVersion: SPEC_VERSION },
],
```

## Open questions

- **Which version do we declare?** The honest candidates are the version whose
  rules we actually follow and the version c2pa-rs implements, and they are not
  the same — the crate implements a documented *subset*. Declaring the higher one
  is the more useful claim and the easier one to falsify. **Blocker**: AC2 cannot
  be written until this is answered, because it is the thing AC2 guards.
- **Does declaring a version oblige us to `allActionsIncluded`?** 2.3 recommends
  every actions assertion declare the state; 2.4 mandates it for a narrow case
  (open-and-immediately-resave) that this package does not perform. If a
  recommendation is enough to make a declaration untrue, AC2 gets much stricter.
  *Non-blocker*, leaning that recommendations inform but do not falsify.
- **Two delivery routes for one spec.** `specVersion` ships through a service
  rebuild and `allActionsIncluded` through Composer, so a deployment can hold one
  half and not the other. AC7 exists to make that visible. *Non-blocker*, but the
  CHANGELOG entry must say it plainly — the same split the 0.13.0 entry had to
  explain.
- **The line this touches carries spike leftovers.** `server.js:973` still reads
  `name: creator_name || 'c2pa-spike-signer', version: '0.1.0'` — a default name
  and a version that describe the spike rather than the release. Editing the
  object for `specVersion` puts them under the cursor. *Non-blocker*, and
  deliberately not folded in: changing the default claim generator name is
  user-visible and belongs in its own change.
- **Should `allActionsIncluded` be offered at all?** We cannot verify it, so
  offering it means offering a caller the means to sign a statement we cannot
  check. The counter-argument is that this is already true of `creator_name` and
  every `extra_assertion`. *Non-blocker*, leaning offer it with the docs naming
  it as the caller's assertion rather than the library's.

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
| AC7                  | —                           | —                    |