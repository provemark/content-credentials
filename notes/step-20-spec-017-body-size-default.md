# Step 20 — SPEC-017: a body-size default matched to what we sign (2026-08-06)

`MAX_BODY_SIZE` 50mb → **20mb**, plus `max_body_bytes` on `/health`, a proper
413, and the refusal in the audit trail.

### The measurement that drove it
Container memory at the concurrency cap of 4, idle baseline 17.6 MiB:

| Asset | Peak | Per request | × asset |
|---|---|---|---|
| 1.0 MB | 66 MiB | 12.1 MiB | 12.1× |
| 4.1 MB | 161 MiB | 35.9 MiB | 8.7× |
| 11.4 MB | 332 MiB | 78.6 MiB | 6.9× |

So **~7×**, not the "roughly four copies" SPEC-015 and the v0.5.1 changelog
claim. Neither is edited — an approved spec is frozen outside Traceability, and
a published changelog records what shipped. The correction lives in SPEC-017 and
in the README, which is where someone sizing a container actually looks.

At 50mb a body carried a ~37 MB asset; four in flight peaked near 1 GB, in a
container many people give 512 MB. 20mb carries ~15 MB and peaks around 420 MB.

### Two latent bugs surfaced while implementing
**The correlation id was assigned after the body parser.** So any request that
failed to parse — oversized, malformed JSON — was answered with no correlation
id at all, which is exactly when a caller most needs one. Moved ahead of
`express.json`.

**Body-parser failures were unhandled.** They fell through to express's default
error page, and nothing was recorded. Now caught explicitly: 413 for
`entity.too.large`, 400 for malformed JSON, both audited. Note what the record
cannot say — auth runs *after* the parser, so there is no verified caller to
attribute it to, and recording an unverified token would let anyone write
arbitrary `token_id` values into the log. The field is simply absent.

### ⚠️ Three vacuous tests in one sitting
All three of my own, all green while testing nothing:

- the aggregator probe Pest never collected, because the filename lacked `Test`
- an assertion that the README does not say "four copies" — it never did
- a check for `peak` in the README that matched **`speaks`** in "speaks plain HTTP"

A substring check on a short word is not a check. Phrases now (`peak memory`,
`concurrency cap`). The recurring lesson of this session, in its purest form:
**green is not evidence unless you have seen it go red.**

### One unexplained failure, recorded rather than dismissed
A single `composer check` run reported `1 failed, 117 passed`, and the output
was not captured. Eleven consecutive runs since have all been green, so it could
not be reproduced or identified. Most likely candidate is one of the Eris
property suites, which generate random input — meaning it may be a real case
rather than noise. Noted here so that if it recurs there is a record; do not
assume it was nothing.

---

[← Step 19](step-19-official-c2pa-trust-list.md) · [index](../NOTES.md) · [Step 21 →](step-21-spec-018-rotation-and-scanning.md)
