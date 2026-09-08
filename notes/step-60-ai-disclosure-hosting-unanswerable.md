# Step 60 — The hosting question had no answer to give (2026-09-08)

Step 59 ended waiting on one question, because it decides the build order: what
do `ai_disclosure` sites actually run on? The maintainer answered the thread
again and did not answer that. This note records why he could not, which is the
useful part, plus two design corrections he did give.

Same thread: `project/ai_disclosure` issue **32** on git.drupalcode.org.

## The question was unanswerable, and that is measurable

- `ai_disclosure` **1.0.0-alpha1 was released 2026-09-07** — one day before the
  question was asked. Works with Drupal `^10.4 || ^11`.
- Its usage page reports, verbatim: *"There is no usage information available."*

So there is no population to describe. Asking a maintainer what his users run on
the day after his first alpha is asking for a guess, and a guess coming back
through a thread reads later like a measurement. The question was withdrawn in
the reply rather than repeated.

**This closes Step 59's "Next" without firing SPEC-038's trigger.** The trigger
asks for a measured need among reachable users. Zero installs is not that.

## What he did say (2026-09-08, ~09:15 UTC — derived from relative timestamps, not read off the page)

Three points, the first two of which are corrections to the proposal:

- **Trust must gate the write, not annotate it.** His reasoning: the manifest
  arrives with the file, so it is attacker input. If an unsigned manifest can
  produce a suggestion an editor accepts, the uploader is writing the site's
  Article 50 label. The proposal had trust as something *recorded*. He is right,
  and this is the stronger rule: only a manifest the reader reports as trusted
  produces a suggestion at all; `apply()` from this path stays off by default and
  refuses anything unverified even when a site turns it on.
- **Queue the read.** Reading a file and calling a sidecar inside a save request
  will time out on the sites uploading the largest media.
- **The map stays ours.** A manifest records how an asset was made; it cannot say
  what it depicts or how much of a text a person wrote. Those stay with the site.

And one offer: a lookup on his side returning every grade that carries a given
IPTC URI, so a default map stops going stale when a site edits its grades. Asked
for in the reply, with the shipped map kept as the fallback — the lookup does not
exist in alpha1, so a module that requires it cannot ship, and a module that
requires it would also break on every site running alpha1. It resolves *what
exists*, never *which one to pick*: `trainedAlgorithmicMedia` still returns two
grades.

## What this changes for the build order

The reading route becomes a **site setting behind `ReaderInterface`**, not a
choice the module makes. Then the hosting question belongs to the site, the
module does not block on it, and a c2patool reader can arrive later without a
module change. SPEC-038 stays parked with its trigger unchanged.

Two things measured while deciding that:

- **This repository needs nothing new for the module.** `ManifestReport` already
  exposes `digitalSourceTypes(): list<string>` (the raw IPTC URIs, which is what a
  URI-to-grade map consumes), `isTrusted()` for the gate, and `softwareAgents()`
  for the note. Re-check with
  `grep -n "public function" src/Core/Reading/ManifestReport.php`. So: no spec
  here, no release, no changelog entry.
- **A trust gate must read the reader's verdict, not interpret validation state
  itself.** Relevant to the c2patool route specifically: below c2patool 0.26.6 an
  untrusted signer counts as a validation *failure*, so the same asset comes back
  `Invalid` rather than "valid but untrusted" (PR #118). Two different shapes for
  one situation is exactly what an accessor is for.

## A harder barrier than any reading route

His module supports `^10.4 || ^11` and its `composer.json` requires only
`league/commonmark ^2.8` — no PHP constraint, so it inherits core's floor. Drupal
10.4 still permits **PHP 8.1 and 8.2**; Drupal 11 requires 8.3. This library
requires **`php: ^8.3`**. For a Drupal 10.4 site on 8.2 the module is
uninstallable, and no choice of reading route changes that. Raised in the thread
as the question replacing the withdrawn one, because it decides who can install
this at all.

## Next

Waiting on the PHP-floor answer. Build order otherwise settled: module first,
against the interface, in its own repository; SPEC-038 unchanged and unbuilt.
