# Step 62 — What lowering the floor to PHP 8.2 would actually cost (2026-09-10)

Step 60 ended waiting on an answer: this package requires `php: ^8.3`, Drupal
10 still permits 8.1 and 8.2, and no choice of reading route changes that. The
question was asked in `project/ai_disclosure` issue 32 and left open. This step
measures it and closes it. The decision is **ADR-0006: the floor stays `^8.3`**.

## Why it stopped being hypothetical

The consumer is real now. `drupal/content_credentials` is a project on
drupal.org (node 3621990), it reads through `ReaderInterface`, and it requires
`provemark/content-credentials ^0.15`. Its `content_credentials.info.yml`
declares `php: 8.3` and states in a comment that the floor is this library's and
not Drupal core's — which is exactly right, and is what makes the floor a
question about who can install that module at all.

Media types are where this pairing has already cost something once: SPEC-041
(`audio/x-wav`) was found from that side, not from here.

## Measured — the code side is free

| Gate | Result on PHP 8.2 |
|---|---|
| `php -l` over `src/ tests/ bin/ config/ stubs/` | clean on 8.2.33 |
| PHPStan level max, `phpVersion: 80200` | no errors |
| Pest suite (`--exclude-group=integration`) | 390 passed, 6 skipped |
| Deptrac | 0 violations |
| Pint `--test` | 119 files, PASS |

Run inside `php:8.2-cli` with dependencies resolved under
`config.platform.php = 8.2.99`. Nothing in the library uses 8.3-only syntax or
8.3-only functions.

The test total is the same suite, not a smaller one: this machine reports 372
passed plus 18 that Pest 4 counts separately as deprecated, which is the same
390, and the skip count differs by one only because `ext-c2pa` is loaded here
and not in the container. On 8.2 there are no deprecations at all — the
`illuminate/console` null-offset deprecation needs PHP 8.5 to fire.

⚠️ **One test failed in the container and it was my copy, not 8.2.**
`DocumentationLayoutTest` shells out to `git check-attr export-ignore`, and the
copy was made without `.git`, so the command returns nothing and the assertion
is false. Falsified rather than assumed: run `git check-attr export-ignore --
tests` in any directory that is not a git repository and it prints nothing.
Worth noting for anyone else containerising this suite.

## Measured — the cost is the test toolchain

```
pestphp/pest[v4.0.0, ..., v4.7.8] require php ^8.3.0
```

That is the whole bill. **Pest 4 requires `^8.3`**, so an 8.2 leg would run
Pest 3: require-dev widens to `^3.0|^4.0`, and two CI legs exercise the suite
under a different test-framework major (Pest 3.8.7 / PHPUnit 11.5.56) from the
other nine (Pest 4.7.8 / PHPUnit 12.5.33). It is the mirror of the constraint
that keeps Pest 5 out of this repository: there the floor is too low for a newer
Pest, here it would be too low for the current one. The same caution applies in
both directions — a suite that stays green across a test-framework major has not
told you much.

`illuminate/*` 13 also requires `^8.3`, so an 8.2 leg could only test Laravel 11
and 12. The matrix would need its first `exclude` — `.github/workflows/ci.yml`
currently says in a comment that none are needed — and would go from nine cells
to eleven.

Everything else that moves on such a leg is minor and was measured by diffing
two dry-run resolutions: PHPUnit 12 → 11.5.56, Pint 1.32.0 → 1.30.4,
`pest-plugin-mutate` v4 → v3. PHPStan, Deptrac, Guzzle and Eris resolve
identically on both.

## Measured — and the reach closes in three months

- Week of 2026-08-30, drupal.org reports **474,292** sites: **209,588** on 10.x
  (143,560 of them on 10.6) and **83,231** on 11.x or 12.x.
- Drupal **11 and 12 require PHP 8.3+**, so the floor excludes nobody there.
  Drupal 10.4, 10.5 and 10.6 permit **8.1, 8.2, 8.3 and 8.4**, so only the 10.x
  half is affected at all.
- **Drupal 10 reaches end of life on 9 December 2026**, the week 12.0.0 and
  11.5.0 are released (drupal.org's own core release schedule).
- **PHP 8.2 loses security support on 31 December 2026** (php.net), twenty-two
  days later. PHP 8.1 is already end-of-life, so `^8.2` would not have reached
  those sites either.

So the change would buy about three months, on a population that is on an
end-of-life Drupal by the time the window shuts.

## Reasoned, not measured

Kept separate deliberately:

- **What share of Drupal 10 sites runs 8.2 is unknown.** drupal.org's usage
  pages report core versions per site, not PHP versions, and Packagist's
  php-stats JSON endpoint could not be reached for `drupal/core-recommended`
  (`/php-stats/all.json` and `/php-stats/12.json` both 404). The decision does
  not rest on it: whatever the share, both ends of its window close within three
  months.
- **`ai_disclosure` still reports "There is no usage information available"** —
  unchanged since Step 60, two days on. There is still no measured population
  behind the original question.
- **Whether Pest 3 would keep the suite honest** was not tested beyond "it
  passes". Two legs on an older major is a maintenance claim, not a green-run
  claim, and the run above cannot settle it.

## What this closes, and what it does not

Closed: the PHP-floor question from issue 32, now answered with dates rather
than with a preference. ADR-0006 carries the decision, including the trigger for
revisiting it — a user blocked by the floor on a platform version that is itself
still supported. Drupal 10 stops qualifying on 9 December 2026; TYPO3 v14, which
still permits 8.2, would qualify today if somebody asked.

Not closed, and unchanged by any of this: SPEC-038's own trigger. The module
made the reading route a site setting behind `ReaderInterface`, so it does not
block on which route a host can run, and zero installs is still not a measured
need.
