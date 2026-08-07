# SPEC-027: A README you can read in one sitting

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`README.md` is **866 lines**, twelve top-level sections and seventeen below them,
in one column. It grew by accretion: every spec since SPEC-001 that needed to say
something to a user said it here, and each addition was correct on its own.

The result is that the two audiences it serves are both badly served. Someone
deciding whether to use this package has to scroll past audit-log formats and
memory-sizing tables to find the quickstart. Someone operating a signing service
has to find the four separate places that talk about limits.

Measured, by top-level section:

| Lines | Section |
|---|---|
| 270 | Signing service *(audit logging, rate limiting, sizing, assertion limits, trust, rotation, conformance)* |
| 178 | Usage (plain PHP) *(of which 120 are media types and digitalSourceType)* |
| 108 | Quickstart |
| 84 | Reading without the signing service |
| 39 | Installation and configuration |
| 12–37 | the remaining seven |

### This is not only prose

Nine test files assert phrases in `README.md`, and nine specs name it as the
source satisfying an acceptance criterion. Moving text moves the evidence for
those criteria, so this is a change to be made deliberately rather than an
afternoon of tidying — which is the reason it has a spec at all.

### What it is not about

Packagist. Checked 2026-08-07: Packagist **does** render READMEs — `symfony/console`
has one in a `readme markdown-body` block — but our package page has no such block
at all and contains none of our README's text. That is a stale crawl, fixed by an
account action on packagist.org, and nothing in this repository can cause or fix
it. This spec would be worth doing if Packagist did not exist.

## Scope

**In scope**

- `README.md` reduced to what someone needs in the first five minutes, ending in
  a map of where the rest lives.
- Five pages under `docs/`, per the mapping below.
- `/docs` removed from `export-ignore`, so the links resolve from
  `vendor/provemark/content-credentials/` and not only on GitHub. `docs/` is 391
  lines today; the cost is negligible and the alternative is absolute URLs that
  need the internet and break if the repository ever moves.
- The doc tests follow their text to whichever file now owns it.
- The Traceability tables of the affected specs updated — the one section an
  `implemented` spec may change.

**The mapping**

| To | From |
|---|---|
| `docs/usage.md` | Usage (Laravel), Usage (plain PHP), Installation and configuration |
| `docs/service.md` | Signing service: running it, audit logging, rate limiting, sizing the container, assertion limits, rotating the key |
| `docs/marking.md` | Supported media types, What you are claiming: digitalSourceType |
| `docs/readers.md` | Reading without the signing service, Which reader and what it costs |
| `docs/production.md` | Going to production, Trust-list verification, Conformance alignment |
| stays in `README.md` | Requirements, Quickstart, What you have and what you do not, Verifying the output, Development, Security, License |

**Out of scope** (each needs its own spec before it may be built)

- **Changing what any of it says.** This is a move. Where wording changes at all
  it is to let a page stand on its own — a sentence of context at the top, a link
  back — never to revise a claim. AC3 is what holds that line.
- A documentation website, or anything on provemark.github.io.
- `CHANGELOG.md`, `NOTES.md`, `SECURITY.md`, `CONTRIBUTING.md`, `docs/c2pa-primer.md`
  and the ADRs, which are already separate and already the right size.
- Making Packagist render the README. Not a repository change.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-027')`.

- **AC1 — the README is a map, not the territory**
  - Given `README.md`
  - When its length is measured
  - Then it is under **300 lines**
  - And it links to every page in `docs/`, so nothing is orphaned
  - *(300 rather than something tighter: the quickstart is the most valuable
    thing on the page and shortening it would be optimising the number rather
    than the reader.)*

- **AC2 — no link points at nothing** *(error path)*
  - Given every relative markdown link in `README.md` and in `docs/*.md`
  - When each target is resolved against the file that contains it
  - Then the file exists
  - *(Link rot is the failure this reorganisation is most likely to introduce,
    and the one nobody notices: a broken link renders as text and looks
    deliberate.)*

- **AC3 — nothing documented is lost**
  - Given every phrase a doc test asserted before this change
  - When the suite runs afterwards
  - Then each is still asserted, against whichever file now owns it
  - *(The guarantee that this is a move and not a rewrite. Six specs' acceptance
    criteria are satisfied by these phrases; a criterion that quietly stops being
    checked is worse than one that fails.)*

- **AC4 — the docs ship with the package**
  - Given a `git archive` of the repository, which is what Composer distributes
  - When its contents are listed
  - Then `docs/` is present
  - *(Otherwise every link in the shipped README is broken for anyone reading it
    in `vendor/`, which is precisely where a developer reads it.)*

- **AC5 — each page stands on its own**
  - Given any page in `docs/`
  - When it is opened directly, as a search engine or a deep link delivers it
  - Then its first lines say what it covers, and it links back to the README

## API sketch

Not applicable — no code changes. The only non-documentation edit is one line in
`.gitattributes`:

```diff
-/docs               export-ignore
```

## Open questions

- ~~**Does the quickstart stay in the README?**~~ **Settled before approval:
  stays.** It is 108 lines of the 300, and it is the thing a
  reader came for. A README that explains what the package is and then sends you
  elsewhere to see it work has optimised its own length at the reader's expense.

- ~~**Five pages, or fewer?**~~ **Settled before approval: five.**
  `docs/readers.md` (110 lines) and `docs/marking.md` (120) could both fold into
  `usage.md`, giving three. Five,
  because both are decisions rather than instructions — which reader to bind, and
  what you are claiming about an asset — and a reader arrives at them with a
  question rather than a task.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/DocumentationLayoutTest.php` :: "keeps the README short enough to read in one sitting", "links to every page it sends the reader to" | `README.md` § Where the rest lives |
| AC2 | `tests/Unit/DocumentationLayoutTest.php` :: "resolves every relative link in the documentation" | `README.md`, `docs/*.md` |
| AC3 | The nine doc tests that moved with their text: `tests/Unit/BodySizeGuidanceTest.php`, `tests/Unit/Level1AlignmentTest.php`, `tests/Unit/MediaTypeGuidanceTest.php`, `tests/Unit/RemainingMediaTypeGuidanceTest.php`, `tests/Unit/SourceTypeGuidanceTest.php`, `tests/Unit/ReaderTradeOffGuidanceTest.php`, `tests/Unit/Reading/ExtC2paReaderTest.php`, `tests/Unit/Manifest/BuilderEntryPointTest.php`, `tests/Integration/AuditLoggingTest.php` | `docs/service.md`, `docs/marking.md`, `docs/readers.md`, `docs/production.md` |
| AC4 | `tests/Unit/DocumentationLayoutTest.php` :: "ships the documentation in the Composer package", "still leaves the developer-only directories out of the package" | `/.gitattributes` (the `/docs export-ignore` line, removed) |
| AC5 | `tests/Unit/DocumentationLayoutTest.php` :: "opens each page with what it covers and a way back" | `docs/usage.md`, `docs/service.md`, `docs/marking.md`, `docs/readers.md`, `docs/production.md` |

## Implementation notes (2026-08-07)

- **AC2 found a link that was already broken.** `docs/adr/0003-signing-service-over-ffi.md`
  has never existed — the file is `ADR-0003-ext-c2pa-and-signer-backends.md`, and
  the wrong link had been sitting in the README since SPEC-025 added it that same
  day. The criterion was written to catch link rot this change might introduce
  and caught link rot that was already there, which is the better outcome.
- **AC4 could not be tested the way the spec describes.** `git archive HEAD`
  reads the `.gitattributes` of the last *commit*, so it cannot see this change
  until after it is committed — a test that can only pass post-commit is one you
  never watch go red. `git check-attr export-ignore` asks git the same question
  against the working tree. It also has a trap the first attempt fell into: the
  attribute is set on the *directory*, so asking about `tests/Pest.php` answers
  "unspecified" and the assertion passes for the wrong reason. It asks about
  `tests`, `specs`, `service`, `bin` and `certs`.
- **Two SPEC-018 criteria ended up on different pages**, which the move made
  visible: the Conforming Products List question belongs with production, while
  "which Level 1 requirements does the key handling satisfy" belongs where the
  key is operated — the rotation procedure cites the requirement it satisfies.
  They had been adjacent paragraphs under one heading.
- **The split was done by script, not by hand.** Each `##`/`###` block was
  assigned a destination and moved whole, with an `###` promoted to `##` only
  when its parent section did not travel with it. That is what keeps AC3
  honest: no sentence was retyped, so none could quietly change.
