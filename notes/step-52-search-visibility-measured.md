# Step 52 — What the search results actually show, and the half that was missing (2026-08-14)

[Step 51](step-51-reach-baseline-measured.md) left one claim explicitly
untested: that a developer searching for this finds policy prose rather than
code, and that an article would therefore fill a gap. Eleven searches later the
claim is half right, and the half that is wrong is more useful than the half
that is right.

### Developer queries: the gap is not there

| query | on page 1 |
|---|---|
| `c2pa php` | repo + the certificates article |
| `content credentials php library` | repo, **first result** |
| `c2pa laravel` | repo + article |
| `sign c2pa manifest php` | article first, repo in the summary |
| `php c2pa signing library` | repo **first**, article second |
| `c2pa-node php integration` | repo **first** |
| `laravel mark AI generated image content credentials` | repo **first** |
| `read c2pa metadata php` | **absent** |

Seven of eight, mostly at the top. That terrain is already held, which also
explains the six Google visitors in Step 51's referrer table. **The premise that
licensed the article does not survive contact with the results.**

Compliance queries are the other way round and confirm the premise exactly:
`EU AI Act article 50 machine readable marking` and `EU AI Act watermarking
requirement technical implementation` return artificialintelligenceact.eu, three
European Commission pages, Jones Day, Cooley, Stibbe, arXiv and a row of
compliance blogs. No code, and not an audience this package converts.

### The one query that missed, and why

`read c2pa metadata php` returned Python, JavaScript and online viewers — and a
summary stating that no PHP library for reading C2PA metadata appears to exist
and that one might have to be written. Two readers have shipped since v0.6.0.

Measured on the repository rather than guessed:

```
grep -n "^#\{1,3\} " README.md          # no heading about reading
grep -rioc "metadata\|inspect\|extract" README.md docs/*.md
```

Reading had no entry point of its own. It appeared as **step 4 of the signing
quickstart** — "Check that it worked" — which frames it as verifying your own
output rather than as a thing you can come here to do. `metadata` occurred four
times across the README and all five doc pages; `inspect` and `extract` not
once. `docs/readers.md` did own the subject, under the title *Choosing a
reader*, which is a question you only ask once you are already inside.

The vocabulary mismatch is the whole finding: the package says manifest,
assertion, reader, credential; the searcher types metadata, inspect, extract,
check.

### What changed

- **README** gained a top-level *Reading C2PA metadata from an existing file*
  section with a standalone example, and the Requirements list now says the
  signing service is needed **for signing only** — it previously read as a hard
  prerequisite for everything, which for a read-only visitor is false and
  expensive.
- **`docs/readers.md`** is now *Reading and verifying C2PA metadata*, opening
  with the question people arrive with, and carries a new *What the report tells
  you* section: the four separate questions a `ManifestReport` answers, the
  `isAiGenerated()` / `involvesGenerativeAi()` distinction, valid-versus-trusted,
  and the fact that a file with no credential is a report, not an exception.
- **`composer.json`** keywords gained `metadata`, `image-metadata` and
  `verification`. Deliberately **not** `ai-detection`: reading detects a
  *declaration*, not AI, and a keyword that says otherwise sells the wrong thing.

⚠️ **The obvious fix was blocked, and the block is the useful part.** The plan
was a new `docs/reading.md`. `SPEC-027` fixes the documentation layout at five
pages and `tests/Unit/DocumentationLayoutTest.php` hardcodes them
(`spec027Pages()`, "The five pages this spec creates"). A sixth page is a spec
amendment, not a docs edit. The content went into the page that already owned
the subject instead — which avoided duplicating `readers.md` and is probably the
better outcome regardless. If a standalone reading page is ever wanted, it starts
with an amendment to SPEC-027.

The README is now **296 lines against AC1's limit of 300**. That is tight enough
that the next addition trips the test; the next person to add a section should
plan on moving something out rather than squeezing.

### Verifying the sample rather than trusting it

The README example was type-checked before it shipped, by writing it to a scratch
file and running the project's own PHPStan configuration over it:

```
vendor/bin/phpstan analyse --level=max --autoload-file=vendor/autoload.php <file>
```

That confirms the class exists, that the constructor takes no arguments, that
`read()` accepts an `Asset`, and that all four accessors are real. A code sample
in a README is checked by nothing otherwise — it is exactly the shape of thing
this log has repeatedly caught being green while testing nothing.

### What this does not prove

Adding the right words does not produce a ranking. The honest possibility is that
`read c2pa metadata php` has almost no volume and that Python keeps the slots
whatever the README says. What makes it worth doing anyway is that the claim was
*true and unstated*: the reading half genuinely needs no key, no certificate and,
with the extension, no service — and nothing on the way in said so.

Re-run the eight queries against the table above in roughly six weeks, and set up
Search Console on `provemark.github.io` first, since GitHub reports the referrer
and never the query.

`composer check` green. Docs, one keyword list; no code, no spec, no changelog
entry.

---

[← Step 51](step-51-reach-baseline-measured.md) · [index](../NOTES.md)
