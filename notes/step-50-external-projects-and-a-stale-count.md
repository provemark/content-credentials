# Step 50 — Two claims about other files, both aged silently (2026-08-13)

Two corrections in one afternoon, unrelated in subject and identical in shape:
each was a sentence *about* something maintained elsewhere, written once and
then never re-read against its source.

### The CAI listing moved sections

The README and both places on the provemark site said the library is listed
under *Related projects* on the CAI's community-resources page. That section
still exists; third-party entries were split out of it into a section of their
own, and our entry went with them.

```
curl -sL https://opensource.contentauthenticity.org/docs/community-resources/ \
  | tr '>' '>\n' | grep -niE 'provemark|external projects|related projects'
```

The heading is **External projects** (anchor `#external-projects`), with the
warning "CAI has not vetted and does not endorse these third-party projects"
directly under it, and our entry beneath that. Still the only PHP entry on the
page — `grep -ni php` on the same HTML returns one line.

⚠️ **The section name did not come from the section.** The note carried over
from 2026-08-11 called it "Community-Driven External Projects", which is the CAI
commit *title* (`f856135`, "Separate 3d party projects" — the longer phrase came
from the diff around it), not the heading the page renders. A near-miss like
this survives longer than a wrong claim does, because it is recognisable enough
to pass a skim. Take a heading from the rendered page.

Corrected in three files: `README.md` (PR #81), and on the site the homepage
card plus the "From valid to trusted" article, which quotes the entry.

### A count in a comment, one commit behind

`ci.yml` explains why only the `ext-c2pa` leg carries `continue-on-error`, and
the explanation turns on every leg sharing one download: the container build's
`npm ci` pulls `@contentauth/c2pa-node`'s native binary from GitHub releases.
It said "All seven legs carry that dependency". Seven was right when it was
written; `tsa-unreachable` (SPEC-007 AC5, `20cd04d`) made it eight, and that
profile builds the same container.

```
grep -n '^          - name:' .github/workflows/ci.yml   # 8 profiles
```

The argument survived the drift — the new leg carries the dependency like the
rest, so the conclusion never became wrong, only the arithmetic. That is exactly
why it lasted: **a stale number inside a correct argument raises no symptom.**
Worth noting where this comment came from: it exists *because* an earlier claim
in it was wrong — `7319cd3` is where "the one leg that reaches outside this
repository" was written down and then retracted in place. A block written to
correct a claim is not thereby immune from ageing.

`CLAUDE.md` had the same count, and next to it a survivor of that same revert:
it still called `ext-c2pa` "the only leg that downloads a prebuilt binary from a
third party". The workflow comment had already refuted that; the project file
had not been brought along. Both fixed — and the list of profile names there now
points at the matrix, so the next addition is one `grep` away rather than a
silent divergence.

### What to take from it

Neither of these is discoverable by anything this repository runs. `composer
check` cannot see a heading on someone else's site, and no test asserts a
comment's arithmetic. The only defence is that a claim about an external file
gets re-read against that file rather than restated — and that a sentence
naming a count names the command that produces it, which is what both fixed
versions now do.

PRs #81 and #82. Docs and one comment; no code, no spec, no changelog entry.

---

[← Step 49](step-49-typo3-integration-measured.md) · [index](../NOTES.md)