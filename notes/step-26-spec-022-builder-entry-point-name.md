# Step 26 — SPEC-022: the name SPEC-021 broke (2026-08-07)

Same day, and a direct consequence: `ManifestBuilder::forAiGeneratedImage()` was
the only entry point, and after SPEC-021 the call a user writes is
`forAiGeneratedImage(MediaType::Mp4)`. `forAiGenerated()` is now canonical; the
old name delegates to it and **stays indefinitely** (settled at approval).

### Why it had to be the same release

Nothing was broken by the name — that is exactly why it needed doing now rather
than later. SPEC-021 is on `main` and unreleased, so fixing it in the same
release means "MP4 through a method called forAiGeneratedImage" is never a state
we published. Afterwards it is the same correction against an API users have
already copied into their code, at a higher price for no extra benefit.

### The alias raises no runtime deprecation, deliberately

`@deprecated` in the docblock only. An alias with no removal date must not
shout: applications that promote notices to exceptions — and PHPUnit does that
for deprecations by default — would break on a purely cosmetic change. AC4
asserts the silence, so nobody adds a `trigger_error` later without revisiting
the decision.

Note the wording problem this creates: a bare `@deprecated` reads as "will be
removed". The docblock has to say "kept indefinitely" in the sentence after the
tag, and AC5 tests for that phrase, or people migrate under a deadline that does
not exist.

### ⚠️ A no-op assertion needs a control case

AC4 installs an error handler and asserts nothing was raised. That test passes
just as happily if the handler was never installed — the seventh instance of the
shape this log keeps recording (Steps 18, 20, 21, 23). So a second test fires
`E_USER_DEPRECATED` through the same mechanism and asserts it *is* caught.
Without it, AC4 cannot fail.

The general form, worth stating once: **an assertion that nothing happened is
only meaningful next to a demonstration that something could have.**

### ⚠️ The bulk rename renamed the alias

`perl -0pi -e 's/forAiGeneratedImage\(/forAiGenerated(/g'` across `src/`,
`tests/`, `bin/` and the docs also rewrote the *declaration* of the alias, so
the method called itself under a duplicate name. The IDE flagged it within
seconds, but the lesson holds: a rename whose entire point is that one
occurrence must survive cannot be a blanket substitution. The BC test was
excluded by hand for the same reason — a suite that no longer calls the alias
cannot detect it breaking.

### Verified

`composer check` green (187 passed), integration 80 passed / 5 skipped,
`bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true,
`bin/verify.sh` all PASS, `php bin/spec-check.php` 0 errors.

### Open question 2, settled the same day: the family shape (A)

`forAiGenerated()` is one of a family, not a shortcut. The next spec adds
siblings rather than a general constructor:

```php
ManifestBuilder::forAiGenerated(MediaType::Png)     // trainedAlgorithmicMedia
ManifestBuilder::forAiManipulated(MediaType::Png)   // the Art. 50(4) case
ManifestBuilder::forCaptured(MediaType::Png)        // digitalCapture
```

and **not** `ManifestBuilder::for(DigitalSourceType $source, MediaType $type)`.

So what SPEC-022 shipped is final: `forAiGenerated()` stays *the* canonical entry
point for the AI-generated case rather than being demoted to a convenience
wrapper in the next release. That was the whole point of asking — the cost of
getting it wrong is telling users in two consecutive releases what the canonical
call is.

What it commits us to: every additional source type is a new public method.
Additive and cheap, but it means each name is a decision that ships, and the
IPTC vocabulary is long — the next spec must pick which cases we actually
support rather than mirroring the whole list.

**Still to verify before that spec, not assumed here:** whether the manipulated
case differs only in `digitalSourceType`, or also in the action sequence. Claim
v2 requires the first action to be `c2pa.created` or `c2pa.opened`, and "edited
with AI" is plausibly `c2pa.opened` plus an edit action — which would make it a
different assertion shape, not a different constant. If so, form A was the right
call for a second reason: one parameter would have implied a symmetry that does
not exist. Do not write that spec from memory; check it against the C2PA spec
first (CLAUDE.md: ask rather than guess).

### Also recorded: an upgrade note SPEC-021 needed and did not have

Adding seven enum cases is additive for Composer, and not free for consumers:
an exhaustive `match ($mediaType)` with no `default` arm now throws
`UnhandledMatchError` the first time it meets a WEBP. That is in the CHANGELOG
under **Upgrading** for 0.8.0. It is the kind of break that does not show up in
any of our own tests, because our own code has the new cases.

---

[← Step 25](step-25-spec-021-seven-more-media-types.md) · [index](../NOTES.md) · [Step 27 →](step-27-measuring-remaining-formats-and-pdf.md)
