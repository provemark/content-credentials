# SPEC-035: Declaring a C2PA specification version

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon (maintainer), 2026-08-31         |
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
newest version. Declaring 2.4 while following 2.3 would be a false statement in
the one artefact whose purpose is that its statements can be relied on.
Declaring `2.3.0` accurately is both honest and more useful than declaring
nothing. **Aim for true, not for high.**

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
- **The declaring half is service-side** (`service/server.js:973`), and
  `claim_generator_info` is built there as an object literal with fixed keys —
  only `name` comes from the caller, via `creator_name`. So AC3 is **already
  true by construction**: there is no path by which a caller can reach
  `specVersion`. What AC3 buys is a test that keeps it that way, not code.
- **AC6 carries no reader-equivalence risk.** The obvious worry is that
  `ExtC2paReader` runs c2pa-rs **0.89.0** while the service runs 0.90.x, so the
  two readers might disagree and trip SPEC-019 AC2. Measured directly: the
  extension reading an asset signed by 0.90.16 returns
  `claim_generator_info: [{"name":"specversion probe","version":"1.0.0",`
  `"org.contentauth.c2pa_rs":"0.90.16","specVersion":"2.4"}]` — the declaration
  survives verbatim through the older engine. Both readers can return the same
  value, so adding the accessor to `spec019Accessors()` is safe.

### The audit, and its answer: **2.3.0**

Carried out 2026-08-31, before any implementation, because the answer is AC1's
pinned value and writing code for an unknown version number is the wrong order.
Our own output was taken from an asset signed through the service and read with
`c2patool --detailed`.

| Requirement | Version | Level | What we emit | |
|---|---|---|---|---|
| Claim v2 | 2.0+ | — | `claim_version: 2` | ✓ |
| Actions label is `c2pa.actions.v2` | 2.x | — | yes | ✓ |
| First action is `c2pa.created` or `c2pa.opened` | 2.3 | MUST | `c2pa.created` | ✓ |
| Actions assertion declares `allActionsIncluded` | 2.3 | SHOULD | absent | does not falsify |
| `specVersion` present | 2.3 *may* → 2.4 *should* | SHOULD | this spec adds it | — |
| `specVersion`, if present, is **SemVer** | 2.3 §10.2.2 | SHALL | see AC1 | constrains the value |
| Actions assertion appears **only** in `created_assertions` | 2.4 | **MUST** | ours is in `gathered_assertions` | **✗** |
| `allActionsIncluded: true` on open-and-immediately-resave | 2.4 | MUST (conditional) | we never perform that action | vacuously ✓ |

**We satisfy 2.3 and fail 2.4 on exactly one requirement**, quoted from the 2.4
specification: *"Required that the mandatory actions assertion appear only in
`created_assertions` (not `gathered_assertions`)."*

**That failure is not ours, and this is why the out-of-scope entry below says
the fix is upstream.** `c2patool` 0.27.16 — pure c2pa-rs 0.90.16, with neither
this package nor the service in the path — produces the identical layout:

```
created_assertions  -> ['c2pa.hash.data']
gathered_assertions -> ['c2pa.thumbnail.claim', 'c2pa.actions.v2']
```

The reference implementation does not satisfy that requirement either, so
**nobody signing through c2pa-rs 0.90.x can honestly declare 2.4.**

⚠️ **This paragraph originally continued "nor is there a lever we are failing to
pull". That was too absolute — see the 2026-08-31 amendment below.** A lever
does exist (`builder.created_assertion_labels`, present in 0.90.16); it could
not be reached through any of three routes tried. `ManifestAssertionKind` in
c2pa-node is indeed irrelevant here — it is `"Cbor" | "Json" | "Binary" | "Uri"`,
a serialisation form rather than the created/gathered distinction — but that
observation never supported the wider claim it was used for.

**What this audit did not cover.** Only the requirements that touch what this
package emits, enumerated from the 2.3 and 2.4 version histories — not the full
specification text, which is far larger. AC2's guard inherits that scope, and its
docblock must say so, or a passing guard will be read as full conformance.

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
- **Raising the version we satisfy.** This spec declares what is true today, and
  as the audit shows, the single thing between us and 2.4 is **upstream work, not
  ours**: c2pa-rs places the actions assertion in `gathered_assertions` and
  offers no API to do otherwise, so its own reference tool fails the same
  requirement. The trigger to revisit is c2pa-rs gaining that control — at which
  point AC2 fails against a raised declaration and tells you so.
- Changing the **claim version**. We emit claim v2 and this spec does not touch
  that.

**Delivery note — this spec has two halves that arrive by different routes, and
a deployment can hold one without the other.**

*Declaring* (AC1, AC4, AC5) happens in `service/server.js`, where
`claim_generator_info` is assembled. `service/` is `export-ignore`d, so that half
reaches users through `git pull` plus a rebuild and **not** through
`composer update`.

*Reading* (AC6) is a client accessor and ships **in** the Composer package.

So after a `composer update` alone, a user can read what someone else's manifest
declares while their own service still declares nothing — the halves are
independent and that combination is not a bug. AC5 exists so an operator can
confirm the service half landed without spending a signature, and the CHANGELOG
entry must set both routes out plainly rather than describe the spec as one
change.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-035')`.

- **AC1 — a signed manifest declares a specification version**
  - Given an asset signed through this package
  - When the manifest is read back
  - Then `claim_generator_info` carries `specVersion` with the value **`"2.3.0"`**
    — pinned as an exact string, alongside the existing name, version and
    `org.contentauth.c2pa_rs` keys. **SemVer, not `"2.3"`**: 2.3 §10.2.2 says the
    field *"may be present, and if so, shall contain a SemVer formatted
    `specVersion` field"*, so a two-part value violates the requirement the field
    itself is subject to

- **AC2 — the declaration is guarded against becoming untrue**
  - Given a manifest signed through this package, and the requirements below —
    which are the ones the audit found that both apply to what this package
    emits and belong to the declared version `2.3.0` or earlier:

    1. **The claim is version 2** — `claim_version == 2`. *(MUST)*
    2. **The actions assertion is labelled `c2pa.actions.v2`** — exact match on
       the label, not a substring of it. *(MUST)*
    3. **Exactly one actions assertion is present** — counted across
       `c2pa.actions*`, because two are contradictory and resolve
       verifier-dependently. *(MUST)*
    4. **The first action is `c2pa.created` or `c2pa.opened`** — the first entry
       of `actions`, which is what claim v2 makes mandatory. *(MUST)*
    5. **`digitalSourceType`, where present, is one this package may emit** — a
       full IPTC URI from the SPEC-026 emittable set. *(MUST)*
    6. **`specVersion`, being present, is SemVer-formatted** — checked against
       our own declaration, per 2.3 §10.2.2. *(SHALL)*
    7. **The declared value equals the version this list was written for** — the
       service constant compared against `2.3.0`.

  - When the guard runs
  - Then it fails if any row is unmet, **naming the row** — so a later change
    that breaks one is caught as a failing test rather than as a false statement
    in signed output
  - And **row 7 is what makes raising the declaration impossible to do by
    accident**: the list is written for one version, so editing the constant
    without extending the list fails immediately rather than silently declaring
    more than has been checked
  - And the guard's docblock states the scope the audit had — requirements
    touching this package's own output, enumerated from the 2.3 and 2.4 version
    histories, not the full specification — so that a passing guard is not read
    as full conformance

- **AC3 — the caller cannot set or override the declared version** *(error path)*
  - Given a caller supplying `specVersion` themselves, through `creator_name`,
    an extra assertion, or any other field they control
  - When the manifest is built or signed
  - Then the value is refused rather than merged: the declaration describes what
    this package emits, not what a caller wishes to claim about it

- **AC4 — a malformed declared version stops the service at startup** *(error path)*
  - Given a service configured with a declared version that is empty or **not
    SemVer-formatted** — `"2.3"` is the case to cover, because it is the shape a
    reader would reach for first and it is exactly what 2.3 §10.2.2 forbids
  - When the service starts
  - Then it refuses to start, naming the offending value, rather than signing
    manifests that carry a declaration violating the format rule the field is
    subject to — the same fail-closed stance SPEC-014 takes for trust settings
    carrying no material. The check is on **SemVer shape**, not membership of a
    list of known versions: a list would need editing every time C2PA publishes,
    and would reject a valid declaration for being unfamiliar

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

- **AC7 — the engine the audit was made against is pinned, and alarms on a bump**
  - Given the engine version pinned in `service/package.json`, and the version
    this spec's audit was carried out against
  - When they differ
  - Then the test fails, and **its failure is a prompt rather than a defect**:
    the message says so and points at the audit, because a new engine can change
    what we emit and therefore whether `2.3.0` is still the highest true value
  - And because it reads a committed file rather than a running service, it needs
    no profile and no fixture, and **cannot become a test that reports `skipped`
    everywhere** — which is the failure mode this criterion replaced

  **Why not the more direct check.** The obvious form is to assert the actions
  assertion sits in `gathered_assertions` — the measured fact that costs us 2.4,
  and it would alarm the moment c2pa-rs gained the control it does not expose.
  It was written that way first and **it is not testable here**: measured
  2026-08-31, neither `POST /v1/read` nor `ExtC2paReader` surfaces
  `created_assertions` or `gathered_assertions`; only `c2patool --detailed`
  does, and CI does not install c2patool. A test conditioned on a local binary
  would report `skipped` everywhere and never go red — which is the SPEC-020
  failure this repository already paid for once. Pinning the engine is the wider
  alarm anyway: **any** bump should trigger a re-audit of the declaration, not
  only the one requirement we happen to be watching.

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
  hold it there. **The audit has since been done and the answer is `2.3.0`** —
  see *The audit, and its answer* above. Worth noting against the guess recorded
  here first: the expectation was "lower than 2.4, probably 2.2, because the
  domain rules were verified against 2.2". That was wrong in a useful direction —
  we clear 2.3 outright, and fail 2.4 on one requirement that the reference
  implementation fails too.
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

## Amendment (2026-08-31, the 2.4 blocker is narrower than stated)

Found by the maintainer asking whether 2.4 was really out of reach. It was the
right question: the answer below does not change the declared value, but it does
change what this spec claims to know, and the original wording read as a
measurement when it was an inference.

**What was written.** The audit section stated that *"c2pa-rs exposes no public
builder API for placing an assertion in `created_assertions`"*. That is too
absolute, and the way it was arrived at is the recurring defect this repository
already has three entries for: it came from a code search for one function name,
`add_created_assertion`. Searching for the name of a thing you guessed at tests
your guess, not the claim.

**What is actually true.** The lever exists, and it exists in the version we run.
`sdk/src/settings/builder.rs:570` at tag `c2pa-v0.90.16` declares
`created_assertion_labels: Option<Vec<String>>`, and `sdk/src/claim.rs`
(1436–1451) is the decision point: a label listed there becomes
`ClaimAssertionType::Created` rather than `Gathered`. So the capability is
present, not absent.

**What could not be made to work.** Three routes, each producing a validly
signed asset, each leaving the actions assertion in `gathered_assertions`:

1. `c2patool --settings` with `{"version":1,"builder":{"created_assertion_labels":["c2pa.actions.v2"]}}`.
2. The **`Create` intent** (`c2patool --create trainedAlgorithmicMedia`). This is
   the strongest of the three, and it is why the finding is not about who supplies
   the assertion: c2pa-rs built the actions assertion **itself**, digitalSourceType
   included, and still placed it in `gathered_assertions`.
3. `Builder.withJson(manifest, settings)` through `@contentauth/c2pa-node` 0.9.1
   — our own path — with the same settings object.

The Rust reads `self.context...settings()`, so these are *context* settings, and
none of the three routes appears to populate that context. That is the shape of
the gap: plumbing, not capability.

**What this changes, and what it does not.** The declared value stays `2.3.0`,
because 2.4 remains unreached — AC2 would fail against a raised declaration
exactly as designed. What changes is the reason recorded for it. It is **not**
"no lever exists"; it is "the lever exists and could not be reached from here",
which is a weaker claim and an actionable one. Two open upstream issues describe
the same placement behaviour from another angle — `contentauth/c2pa-rs` #2106
(auto-generated thumbnails in `gathered_assertions` instead of `created_assertions`)
and #2238 — so this is a known area upstream rather than a local mystery.

**What would move it.** An answer to how the builder context is meant to be
populated, which is a specific and reproducible question rather than a wish. If
that answer arrives and the placement changes, AC7's engine pin fails on the next
bump, which is the prompt this spec already has in place — the re-audit path
needs no new machinery.

**No acceptance criterion changes.** Criteria live in `## Behavior` and this
amendment adds none, deliberately: `bin/spec-check.php` reads criteria from that
section only, and a criterion written into an amendment gets a traceability row
pointing at nothing the tool can find.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/SpecVersionTest.php` :: it declares the specification version in claim_generator_info | `service/server.js` — `SPEC_VERSION`, `claim_generator_info` |
| AC2 | `tests/Integration/SpecVersionTest.php` :: it satisfies every requirement of the version it declares | `service/server.js` — `SPEC_VERSION` (the guard is the test) |
| AC3 | `tests/Integration/SpecVersionTest.php` :: it ignores a specVersion a caller tries to smuggle through the generator name; `tests/Unit/SpecVersionDeclarationTest.php` :: it builds claim_generator_info from fixed keys rather than from caller input | `service/server.js` — the `claim_generator_info` object literal |
| AC4 | `tests/Unit/SpecVersionDeclarationTest.php` :: it validates the declared version as SemVer, not against a list of known versions; :: it refuses to start on a declared version that is not SemVer | `service/server.js` — `assertSemVerSpecVersion()` and its startup call |
| AC5 | `tests/Integration/SpecVersionTest.php` :: it reports the declared specification version on /health | `service/server.js` — `spec_version` in the health payload |
| AC6 | `tests/Integration/SpecVersionTest.php` :: it reports the declared version through the reader, and absence without failing; `tests/Integration/ReaderEquivalenceTest.php` :: `spec019Accessors()` | `ManifestReport::declaredSpecVersion()`, `ManifestStoreParser::parseDeclaredSpecVersion()` |
| AC7 | `tests/Unit/SpecVersionDeclarationTest.php` :: it still runs on the engine version the specification audit was made against | `service/package.json` — the `@contentauth/c2pa-node` pin |