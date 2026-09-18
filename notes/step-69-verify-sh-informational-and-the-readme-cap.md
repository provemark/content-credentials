# Step 69 — `bin/verify.sh` shows informational codes, and the README cap fires (2026-09-18)

Two small changes that fell out of Step 68, and one test that did its job.

## The line that was never printed

`bin/verify.sh` read c2patool's `validation_results` for `success` and
`failure` only. c2pa-rs files a third array, `informational`, and that is
where `timeStamp.untrusted` goes when the timestamp chain is not among the
anchors. Our bundled anchors never carried DigiCert's TSA chain, so every
timestamp from `timestamp.digicert.com` has been reported that way since
SPEC-007 — invisibly. It surfaced only while building settings documents for
the Dutch and official trust lists (Step 68).

PR #147 adds one line to the script:

```
Informational  : ['timeStamp.untrusted']      # out/signed.png, service-signed, TSA set
Informational  : none                          # c2patool-signed fixture, no TSA
```

Verdict and exit code are untouched; `bin/e2e.php` uses `passthru` and the
exit code, so nothing parses the output. `bin/` is tracked, public, and not in
the dist.

## The README sample had drifted twice, and nothing could have said so

The README's sample `bin/verify.sh` output showed
`AI Art.50 mark : PASS (digitalSourceType=trainedAlgorithmicMedia)`. The script
has printed `(generated)` since SPEC-028 split generated from manipulated — so
the sample was a line the script never emitted, and now it also lacked the new
`Informational` line. SPEC-027's tests assert phrases the README must contain
and links it must resolve; none of them can know that a fenced code block
claims to be program output. That is a test gap by construction, not an
omission: sample output is prose to a test.

PR #148 replaces the block with measured output. The `none` case needed an
asset signed without a TSA, because that is the quickstart's default
(`CONTENTAUTH_TSA_URL` unset) — `c2patool` with the test key on
`tests/Fixtures/fixture.png`, 47,757 bytes, `Trusted`, `Informational: none`.

## The cap that fired

The first push of #148 went red on all three PHP 8.5 `composer check` legs:

```
Failed asserting that 300 is less than 300.
```

SPEC-027 keeps the README below 300 lines, and `main` sat at exactly 299. One
added sample line was enough. The test's own comment explains the number —
"300 rather than something tighter: the quickstart is ~a third of it and is
what the reader came for" — and it is not raised. The fix freed a line by
tightening the "Deeper background" paragraph from three lines to two, and
dropped a four-line sentence about `timeStamp.untrusted` that
`docs/production.md` already carries.

The lesson is not about the README. It is that **"docs only" is not "test
free" in this repository**: README length, verbatim phrases, relative links and
dist contents are all under Pest, and the push went out without `composer
check` because a docs change did not feel like one. Thirty seconds locally
would have saved a CI round and a second commit. `composer check` is the single
definition of green for a CHANGELOG line exactly as for a class.

Note the count: the test uses `substr_count(…, "\n")`, so the number to watch is
`python3 -c "print(open('README.md').read().count('\n'))"` — 299 today, and the
next README addition starts by freeing a line.
