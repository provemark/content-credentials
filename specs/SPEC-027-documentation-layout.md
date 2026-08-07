# SPEC-027: A README you can read in one sitting

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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

- **Does the quickstart stay in the README, or move to `docs/usage.md`?**
  Recommendation: **stays.** It is 108 lines of the 300, and it is the thing a
  reader came for. A README that explains what the package is and then sends you
  elsewhere to see it work has optimised its own length at the reader's expense.

- **Five pages, or fewer?** `docs/readers.md` (110 lines) and `docs/marking.md`
  (120) could both fold into `usage.md`, giving three. Recommendation: **five**,
  because both are decisions rather than instructions — which reader to bind, and
  what you are claiming about an asset — and a reader arrives at them with a
  question rather than a task.

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
