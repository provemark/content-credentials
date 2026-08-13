# Step 49 — What TYPO3 actually offers, and why it cannot live in this repo (2026-08-12)

Investigated as a second integration target, on a chain of reasoning that started
somewhere else: the market question. Looking for who could use this library
turned up an uncomfortable answer — the PHP AI ecosystem is overwhelmingly
**text** (chatbots, translation, SEO), and C2PA marks media files.
`openai-php/client` has 30.6M downloads; `thehocinesaad/stability-php` has 5,101,
and the Replicate and Bannerbear clients have **zero dependent packages** between
them. Image generation in PHP lives in closed product code, so Packagist cannot
find those companies.

What it can find is where media is *processed*. That reframes the prospect from
"who generates" to "who destroys", which this repository already documented:
every re-encode invalidates a manifest. PHP is enormous there — Drupal 67.7M,
TYPO3 13.8M, Pimcore 3.9M, Contao 1.7M — and CAI has already shipped a Drupal
module, which validates the route and leaves the others unclaimed.

Everything below was read from the TYPO3 source, not from documentation.

### The seam exists, and it is better than expected

`typo3/sysext/core/Classes/Resource/Event/` holds 43 PSR-14 events. The relevant
one is `AfterFileProcessingEvent`:

```php
getProcessedFile(): ProcessedFile   // the derivative — just re-encoded
setProcessedFile(ProcessedFile)     // mutable: a listener may replace it
getFile(): FileInterface            // the ORIGINAL — still carrying its manifest
getTaskType(): string
getConfiguration(): array
```

The original and the derivative arrive in the same call, at the moment the
manifest has just been destroyed, with a setter for the result. For ingest there
is `AfterFileAddedEvent(FileInterface $file, Folder $folder)`.

### ⚠️ The event fires outside the guard

`FileProcessingService::processFile()` is fully synchronous — no queue, no
deferral in core, which the existence of a third-party
`webcoast/deferred-image-processing` package confirms from the other side. But
the detail that matters is where the dispatch sits:

```php
if ($task->fileNeedsProcessing()) {
    $this->getProcessorByTask($task)->processTask($task);   // blocks
    ...
}
$event = $this->eventDispatcher->dispatch(
    new AfterFileProcessingEvent(...)                        // OUTSIDE the if
);
```

Processing happens once per unique original × taskType × configuration. The event
fires on **every** call, including the cache hits where nothing happened. A naive
listener would attempt to re-sign an already-signed derivative on every page
render, against a rate limiter, for nothing. This is not in any guide and it is
the kind of thing found in production.

### Both majors, one seam

`AfterFileProcessingEvent` and `AfterFileAddedEvent` are **identical in shape**
between v13.4 and v14 — same constructor, same methods. Both majors require
`php: ^8.2`. v13 is supported to end-2027, v14 (released 2026-04-21) to ~2029.

What does differ is the TCA, exactly where it was predicted to: v13 writes
`--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general`
where v14 writes `--div--;core.form.tabs:general`.

So the split falls out on its own: **the reading half is version-independent, the
display half is not.** Supporting both majors costs a CI column rather than a
second code path — the same shape as the Laravel 11/12/13 matrix.

Registering a metadata field is three files, copied from core's own
`filemetadata` sysext: `ext_tables.sql` for the column on `sys_file_metadata`, a
TCA override to place it in `showitem`, and a listener to fill it.

### Why it cannot live here — ADR-0005

The code side fits: the Core is framework-agnostic, TYPO3's `psr/http-*`
constraints are all satisfied, and it ships Guzzle 7, which discovery finds by
class detection even though Guzzle 7 declares no
`provide: psr/http-client-implementation`.

Packaging is what does not fit. A TYPO3 extension must declare
`type: typo3-cms-extension` plus `extra['typo3/cms']['extension-key']`, and
**Composer's `type` is a single value** — this package spends it on `library`.
TYPO3 also wants `ext_emconf.php`, `Configuration/` and `ext_tables.sql` in the
package root. Hence ADR-0005: integrations needing their own type ship as
separate packages, and `src/Laravel/` is recorded as the exception rather than
the precedent, because Laravel asks only for a key in a file that already exists.

### Where this stops

Deliberately not started. The recommendation on the table is a **read-only**
version one: `AfterFileAddedEvent` to read an incoming asset, a metadata column
to show what it said, and `CONTENTAUTH_READER=extension` so there is no signing
key, no second process, no queue and no touch of the render path at all. The
re-signing half owns the cache-hit problem above and deserves its own spec.

Two measured exclusions for whoever builds it: `php: ^8.3` here excludes TYPO3
sites on 8.2, which both majors still permit, and `psr/http-message: ^2.0`
narrows TYPO3's `^1.1 || ^2.0` to the 2.x line.

---

[← Step 48](step-48-c2pa-node-0-8-3-underflow-measured.md) · [index](../NOTES.md) · [Step 50 →](step-50-external-projects-and-a-stale-count.md)
