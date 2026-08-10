# Step 21 — SPEC-018: rotation you can confirm, and scanning that runs itself (2026-08-06)

Came out of reading the C2PA Conformance Program documents in
`c2pa-org/conformance-public` (`docs/v0.2/`). Two Level 1 requirements land on
code and process we own; we met neither.

### The finding that reframes the whole programme question

> "Generator Product: the set of software, hardware, and platform configurations
> […] that work together as a system to produce digital Assets […] the Generator
> Product is **always the Signer**, and is always the entity listed on the
> Conforming Products List."

**A library cannot be on that list. Neither can any library.** The thing that
gets listed is the deployed system that signs. The programme explicitly permits a
Generator Product to rely on a claim-generator service "created by the Applicant
**or by a different entity**" — that is the role this package plays. Our users
could apply; we cannot, unless we ever run signing as a service ourselves.

Also confirmed, since it changes the calculus: **there is no fee.** No
application fee, no fee to be added to the Conforming Products List. The cost is
a Security Architecture document, sample assets per media type, and a legal
agreement signed on behalf of a registered company. No external audit at Level 1
— it is self-declaration plus document review.

So the useful move was not to apply. It was to make the architecture *describable*
by a user who does apply, and to close the two gaps while we were in there.

### O.2 — the gap was not rotation, it was knowing

The requirement is only "SHALL be capable of rotating the claim signing key".
Replace the mounted files and restart, and you have rotated; that already
conformed. What was missing is that **nothing reported which certificate was
live**. A mount that did not take, a stale image layer, a path typo — every one
leaves the service signing with the superseded key and looking, from outside,
exactly like a successful rotation.

That is the fourth time this session's shape has appeared (`isTrusted()` failing
open, trust settings verifying nothing silently, three vacuous tests). `/health`
now carries `signing_cert: {fingerprint_sha256, not_after}`, and the README's
rotation procedure ends with the comparison rather than the restart.

Leaf, not chain: a chain digest also changes when an intermediate is renewed
without the signing key changing, which would report a rotation that did not
happen.

Deliberately NOT built: hot reload. Not required, and a reload has failure modes
(a half-written PEM, a reload mid-signature) that need their own criteria. The
README says restart-based rotation satisfies the requirement, so nobody goes
looking for an endpoint that is absent on purpose.

### A latent bug the work surfaced

The startup check accepted **any file containing the word `CERTIFICATE`**. A
truncated or corrupt PEM therefore started a service that could not sign. Now
parsed with `crypto.X509Certificate` and fatal on failure — which the identity
work forced, because there is no fingerprint to report for a file you cannot
parse.

### O.3 — the scan that only ever ran by hand

No `dependabot.yml`, no advisory step in CI. The brace-expansion finding in
Step 9 was found *by accident* during an unrelated version bump, and its fix
reached the container by luck. Step 10 fixed the mechanism; nothing fixed the
detection.

Now Dependabot over three ecosystems (`service/` npm, root Composer, Actions)
plus a weekly `audit` workflow. The split matters: **Dependabot cannot open a PR
for an advisory that has no fix**, and those are exactly the ones worth knowing
about. That job is `continue-on-error` and outside the `all checks passed`
aggregate — an unfixable advisory must be visible without blocking unrelated work
on `main`.

### ⚠️ Two test defects, same family as Step 20

- **`toContain()` is variadic.** `expect($x)->toContain('needle', 'explanation')`
  does not attach a message — it asserts the haystack contains BOTH strings. The
  test failed against a correct README, and would have been trivially "fixed" by
  editing the README to contain the explanation. Messages belong to `toBe()`,
  `toBeArray()` and friends; `toContain()` takes needles only.
- **Phrase matching breaks on hard-wrapped prose.** "Generator Product Security
  Requirements" carries a newline in the README, so the substring was never
  there. The helper now collapses whitespace before matching. This is the
  counterpart to Step 20's `peak`/`speaks`: phrases are right, but only against
  normalised text.

### ⚠️ The service image has no curl, no wget, and no openssl

AC2 needs a second service on a second certificate, which meant starting one
inside the container and asking it for `/health`. None of the usual tools exist
in the image. The poll is node's own global `fetch` via `node -e`, and the
throwaway certificate is generated on the host and `docker cp`-ed in. Port
override is essential — without it the probe hits EADDRINUSE against the live
service and dies for the wrong reason, which is the SPEC-014 startup trap again.

### Verified

`composer check` green (126 passed). Full integration suite 55 passed / 5
skipped. `bin/e2e.php` sign+read OK with the AI mark and `hasTimestamp` true;
`bin/verify.sh` signature valid PASS / cert trusted PASS / Art.50 mark PASS.
The `/health` fingerprint matches `openssl x509 -fingerprint -sha256` on
`certs/es256_certs.pem` exactly.

---

[← Step 20](step-20-spec-017-body-size-default.md) · [index](../NOTES.md) · [Step 22 →](step-22-dependabot-first-prs-and-v0-5-3.md)
