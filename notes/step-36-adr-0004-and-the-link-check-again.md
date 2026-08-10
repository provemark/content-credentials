# Step 36 — ADR-0004, and the same link check failing the same way twice (2026-08-07)

### The ADR was holding a decision that had been reversed

Went looking for where to record why WebAssembly, browser-held keys and
in-process signing all get declined, and found that **ADR-0003 decision 3 still
says "plan an `ExtC2paSigner` adapter"**. NOTES Step 23 found the extension
cannot timestamp (`tsa_url = None`), Step 24 corrected the reach argument, and
CLAUDE.md says the adapter stays unbuilt — but the artefact whose entire job is
to hold architectural decisions held the superseded one.

That is worth noticing as a class: this repository keeps its reasoning in four
places (specs, ADRs, NOTES, CLAUDE.md), and only specs have a lifecycle that
forces them to be revisited. An ADR can quietly go stale because nothing ever
reads it back.

**ADR-0004** (`proposed`, amending ADR-0003 §3) now carries all four answers in
one place: no `ExtC2paSigner`, no WASM runtime inside PHP, no per-user or
browser-held signing keys, and HSM/KMS through SPEC-007's existing
`CallbackSigner` as the one sanctioned upgrade — unbuilt, with the trigger and
the three things to measure written down. Opened as `proposed` rather than
`accepted`, because it reverses a decision the maintainer had accepted and that
ratification is not an assistant's to make. **Accepted the same day.**

### ⚠️ SPEC-027 AC2 did not check `docs/adr/` — eighth instance

The new ADR link went green immediately, which by now is a reason for suspicion
rather than comfort. Broke it deliberately:

```
sed 's#ADR-0004-where-the-signing-key-lives.md#ADR-0004-does-not-exist.md#'
-> ✓ it resolves every relative link in the documentation   (1 passed)
```

The check globbed `docs/*.md` — one level — so every ADR was outside it, and the
ADRs link to each other. Fixed with a recursive walk (`spec027DocPages()`) and
confirmed red before trusting green, with the failure naming the file by its
root-relative path rather than its basename, since basenames stop being unique
once subdirectories are in scope.

**This is the second defect in the same criterion in one day.** Step 34 fixed it
skipping in-page anchors; this fixed it skipping a whole directory. Both times
the criterion was right and the implementation was narrower than the sentence it
implemented — and both times it reported green over the exact failure it was
written to catch.

The generalisation, which is not "write better tests": a check that enumerates
*where* to look is a check with a scope that silently ages. `spec027Pages()`
lists the five pages SPEC-027 created, and that is correct because the criterion
is about those five. AC2's sentence says "the documentation", so it must
discover, not enumerate.

`composer check` green (273 passed), SPEC-027 group 10 passed.

---

[← Step 35](step-35-spec-028-drafted-two-questions.md) · [index](../NOTES.md) · [Step 37 →](step-37-spec-028-implemented-article-50-2.md)
