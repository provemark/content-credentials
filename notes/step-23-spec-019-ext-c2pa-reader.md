# Step 23 — SPEC-019: reading without the service, and what the extension really does (2026-08-06)

Came out of an adoption question — how could this become the PHP norm — and the
answer turned out to be architectural rather than promotional.

### The finding that reframed it

`ReaderInterface` had one implementation, which POSTs to the Node service. So
**verifying a credential required running a signing service**: a second process,
Docker, a certificate mount, a token. For signing that is key isolation working
as designed. For reading it is a cost with no matching benefit — verification
needs no key, no certificate and no service. It needed one only because reading
and signing shared a transport.

That is backwards in a specific way. Marking is what a generator does; *checking*
is what everyone downstream does, including applications that never sign
anything. And the deployments most likely to meet somebody else's credential —
WordPress on shared hosting, 80,000+ installs for AI Engine alone (Step 16) — are
exactly the ones that cannot run a second process.

### ericmann/ext-c2pa: what is true, versus what its own README says

- **It is on Packagist** as `ericmann/ext-c2pa`, `type: php-ext`, `v0.1.0`, and
  `RELEASE.md` documents a worked PIE procedure with a platform × PHP-minor build
  matrix. Its README says "listing on Packagist/PIE is still deferred" — that
  line is stale. I believed the README first and told the user it was not
  installable; Packagist said otherwise. **Check the registry, not the prose.**
- `PLAN.md` still reads `Scaffold | rendered` with an empty release table while
  `src/` holds a real implementation, so the project's own planning lags its
  code. Treat the API as movable.
- It is an **Automattic VIP product** (namespace `Automattic\VIP\C2PA`) built to
  serve the `wp-c2pa` plugin. We are not its audience.
- Built sensibly for a web process: `c2pa` with `default-features = false` (no
  `file_io`, no remote-manifest fetch) and `rust_native_crypto` (no system
  OpenSSL link).

### Verified before implementing, and it behaved better than assumed

Probed inside the running PHP, against v0.1.0:

| Probe | Result |
|---|---|
| `withTrustAnchors(certs/trust_anchors.pem)` | `Trusted` — PEM **contents**, our existing file, no extraction |
| unsigned asset | Reader with `hasManifest() === false` — **not null** |
| garbage bytes | catchable `C2paException`, no fatal |
| `json()` | `active_manifest`, `manifests`, `validation_status`, `validation_state` |

The null case matters most: that is the exact shape of the SPEC-010 bug on the
service side, and it simply does not exist here. And the JSON keys are the ones
our decoder already reads, which is what made the adapter thin.

### ⚠️ The declared media type is advisory, in BOTH engines

A signed PNG offered as `image/jpeg` is read fine — by the extension *and* by the
service (200, one manifest, `Valid`). c2pa-rs recognises the format from the
bytes. The 400 our service returns for `image/gif` comes from SPEC-009's own
allow-list, not from c2pa.

I had written an acceptance criterion asserting that case must throw. That was
**my assumption, not the spec's requirement**, and it was wrong. It did not get
deleted: it moved to AC2, because shared behaviour between two engines is
precisely what an equivalence test should pin. Deleting would have left the
agreement untested; asserting would have failed against correct code.

### One decoder, deliberately

`SigningServiceReader::parse()` moved verbatim into `ManifestStoreParser`. Two
decoders would be two places for the definition of "trusted" to drift, and
SPEC-013 is the record of how expensive that definition is to get wrong. It also
makes the equivalence criterion mean something: when the readers disagree, the
difference is in c2pa-rs, not in our parsing.

This required reading SPEC-019's "no change to `SigningServiceReader`" as *no
change to its behaviour or contract*, since the decoder lived inside it as a
private method while "reuse the existing decoder" was explicitly in scope. Both
could not hold. Recorded in the spec's implementation notes rather than decided
quietly.

### ⚠️ Signing through the extension is blocked by more than principle

`Signer::fromPem()` and `Builder` exist, so in-process signing is possible — and
it would put the private key in the web process, which ADR-0003 rejected. But
there is a concrete blocker too, from `signer.rs`:

```
tsa_url is None, so no timestamp authority is contacted
```

**The extension cannot timestamp.** SPEC-007 implements RFC 3161 timestamping and
fails closed. In-process signing would silently produce untimestamped manifests —
a capability regression invisible in the output unless someone checks
`hasTimestamp()`. Reading a wrong answer is bad; *producing* a permanently wrong
manifest is worse.

### ⚠️ A green Pest run is not a run

The CI profile for this needed two guards, both added after watching the
unguarded version pass while testing nothing:

```
# with a deliberately bad extension_dir:
Tests:    9 skipped, 8 passed        <- exit code 0
```

Every criterion skipped, and the job reports green. So the profile now asserts
`extension_loaded('c2pa')` after the PIE install — PIE reports success on paths
that do not end in a loaded extension — and greps the output for the equivalence
test having actually **passed**, not merely being present. Confirmed red against
that skipped run before trusting it.

The extension is installed in **one** profile only. AC5 is about the extension
being ABSENT; installing it everywhere would delete the only place that criterion
is exercised.

### PHPStan caught a vacuous test, again

An assertion that `ExtC2paReader implements ReaderInterface` was rejected as
`function.alreadyNarrowedType` — always true. It was: the `implements` clause is
enforced by the type system, so the test exercised the compiler. Removed rather
than silenced. That is now the sixth documented case in this repository, and the
first one a tool caught instead of a person.

### Verified

`composer check` green (131 passed), integration 64 passed / 5 skipped locally
with the extension installed, and CI's new `integration (ext-c2pa)` profile shows
`Install complete: /usr/lib/php/20230831/c2pa.so`, `c2pa Version => 0.1.0`, and
the three equivalence tests passing. The two engines — c2pa-rs 0.89.0 and
0.90.4 — agree on every public accessor today, and that comparison now runs on
every push instead of only where somebody had installed the extension by hand.

---

[← Step 22](step-22-dependabot-first-prs-and-v0-5-3.md) · [index](../NOTES.md) · [Step 24 →](step-24-correcting-step-23-what-it-unlocks.md)
