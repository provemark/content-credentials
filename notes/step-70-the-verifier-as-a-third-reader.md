# Step 70 — The verifier as a third reader, and what a foreign corpus showed (2026-10-07/08)

SPEC-042 made `provemark/c2pa-verifier` a `ReaderInterface` implementation,
`C2paVerifierReader`, so a host that can run neither the signing service nor
ext-c2pa can read and verify in pure PHP. PRs #158 (draft), #159, #160, #161.
ADR-0007 keeps the verifier optional: `suggest` and `require-dev`, never
`require`, with a `conflict` entry (`<0.5 || >=0.6`) so a host cannot install
a minor nobody measured.

## What made it cheap

The verifier's own suite already fed its report through this package's
`ManifestStoreParser`. Measured again here against the running service and
ext-c2pa: on assets this package signs, eleven of thirteen media types agree
accessor by accessor, trust and timestamp included. TIFF and SVG the verifier
does not read; the reader refuses them up front rather than letting a signed
file read as "no manifest".

## What the mapping needed

The verifier never throws on input; it answers every file with a report. So
`VerifierOutcome` maps: no manifest and no failure is an empty report (the
verifier says `Invalid` there, the other readers `null`); no decoded manifest
with a failure is `ReadFailedException`. Amendment 1 moved that rule off
`has_manifest`, which the verifier sets as soon as it reaches a store, even a
truncated one. Amendment 2 refuses a file whose only manifest is remote, by
name. Amendment 3 bounded only the URL in that message and moved the reader's
copy of the asset to `php://temp` (33.4 MB over baseline on a 32 MiB asset
before, 1.4 MB after).

## The corpus that changed the docs

After #159 merged, the three readers were run over the verifier's own corpus,
482 files signed elsewhere. The first docs had said the readers agree; that
was measured only on our own assets. On the foreign files, 47 that c2pa-rs
0.90.22 calls `Valid` did not read `Valid` through the verifier: 29 `Invalid`,
16 `ReadFailedException`, 2 with a remote manifest. Each is a choice the
verifier documents; the ones checked here were an unanchored timestamp
authority (Amazon, Truepic), Truepic's hash exclusions, and Bing's stores. Two
went the other way, both certificate names in T61String, where c2patool 0.28.1
agrees with the verifier. `docs/readers.md` now carries the numbers.

The same run found two things outside SPEC-042: the service fetched remote
manifests (Step 71) and ext-c2pa aborted the PHP process on one file (Step 72).

## Tests that passed for the wrong reason

Two SPEC-042 tests first passed before the class existed: one asserted that a
refusal message was absent, the other asserted only an exception type that an
unknown reader mode also raised. Both were rewritten to assert something
present. And the first CI run of #159 went red on a test that pinned
`hasTimestamp() === true`: CI runs the service without a TSA. Integration
tests now pin to what the service reports on `/health`.
