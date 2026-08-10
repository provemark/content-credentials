# Step 34 — The anchors SPEC-027 broke, and the test that let it (2026-08-07)

Reported within the hour of merging: the anchor links in the README did not work.
Three of them, all created by the split:

```
README.md   -> #signing-service        (section left for docs/service.md)
README.md   -> #going-to-production    (left for docs/production.md)
marking.md  -> #sizing-the-container   (left for docs/service.md)
```

### The test was written to catch exactly this, and skipped it

SPEC-027 AC2 exists because "link rot is the failure this reorganisation is most
likely to introduce". Its implementation opened with:

```php
// External links and in-page anchors are somebody else's problem.
if (str_starts_with($target, 'http') || str_starts_with($target, '#') ...) continue;
```

In-page anchors *were* somebody else's problem — until the move made every one of
them a cross-file link that had not been rewritten. The criterion was right and
the implementation excluded the case it was written for, which is worse than
having no test: the suite reported the link check green.

The check now resolves the anchor too, by generating the GitHub-style slug for
every heading in the target file. Verified against the broken state before
trusting it: three failures, named individually.

### Two duplicated headings, from moving blocks whole

The script that did the split moved each heading block intact, which is what kept
AC3 honest — and it left `# Running the signing service` immediately followed by
`## Signing service`, and `# Going to production` with a `## Going to production`
further down. Faithful, and silly to read.

Fixed by dropping the two headings that duplicated their page title and promoting
the orphaned `###` levels. Worth noting as the cost of the automated approach:
it cannot know that a section title has become a page title. Cheaper than
retyping 900 lines, and the review that catches it is a human reading the result
once.

### The lesson, in one line

**A test that skips a case "for now" has to be re-read when the ground moves.**
That exclusion was correct when it was written and wrong an hour later, and
nothing about the test itself changed in between.

---

[← Step 33](step-33-spec-026-three-claims.md) · [index](../NOTES.md) · [Step 35 →](step-35-spec-028-drafted-two-questions.md)
