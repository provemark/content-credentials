# Stability and support

What this package promises not to break, what it does not promise, and which
versions it is tested against. If you are deciding whether to depend on it, this
page is the contract.

## Where it stands today

**This package is pre-1.0.** Under [Semantic Versioning](https://semver.org/) a
`0.x` release may break its public API in any minor version, and this one has —
see the **Upgrading** notes in the [changelog](../CHANGELOG.md).

In practice the breaks have been small and always documented, but "documented"
is not "won't happen". Pin a minor if you need certainty:

```json
"provemark/content-credentials": "^0.10"
```

What 1.0 would mean, and what has to be true first, is at the bottom of this
page.

## Supported versions

| | Supported | Where that is enforced |
|---|---|---|
| PHP | **8.3, 8.4, 8.5** | `composer.json` requires `^8.3`; CI runs all three |
| Laravel | **11, 12, 13** | CI matrix; see below for why it is not a constraint |

The Laravel range needs an explanation, because `composer.json` does not express
it. `illuminate/*` is in `require-dev` and `suggest`, never `require` — the
framework-agnostic Core depends only on PSR interfaces, and the Laravel provider
is auto-discovered when a framework is present. So **Composer imposes no Laravel
constraint on you**, and the CI matrix is the only thing that makes the supported
range real. Widening it means adding a matrix column, not editing a version
string.

Dropping a PHP or Laravel version is a **breaking change** and gets a minor bump
pre-1.0, a major after.

## What counts as public API

Anything you can reach that is not marked `@internal`:

- **`Core\Manifest`** — `ManifestBuilder`, `Manifest`, `MediaType`,
  `DigitalSourceType`, `SoftwareAgent`
- **`Core\Signing`** — `SignerInterface`, `SigningServiceSigner`, `Asset`,
  `SignedAsset`, `SigningServiceConfig`
- **`Core\Reading`** — `ReaderInterface`, `SigningServiceReader`,
  `ExtC2paReader` (with the caveat below), `ManifestReport`, `SignerInfo`,
  `ValidationState`
- **Every exception type**, and the `ContentCredentialsException` interface they
  all implement. Catching by that interface is supported and is the recommended
  way to catch everything this package raises.
- **The Laravel surface** — the service provider, the `ContentCredentials`
  facade, `ContentCredentialsManager`, `SignAssetJob`, the `AssetSigned` event,
  the two artisan commands, and `config/content-credentials.php`

### The interfaces are for depending on, not for implementing

`ReaderInterface` and `SignerInterface` are contracts you **program against**.
They are not extension points, and **adding a method to either is not a breaking
change** under this policy.

That is a real distinction and not a loophole. Depending on an interface — type
hinting it, binding it in a container, swapping which implementation is bound —
is fully supported and is what the rest of this page protects. Implementing one
yourself is expected only for test doubles, which break loudly and are fixed in a
line.

The reason it is stated rather than assumed: if a media type ever becomes
readable but not signable, `ReaderInterface` will need a way to say so, and that
method should not have to wait for a major. Nothing in the package invites
third-party implementations today — only the two readers and one signer here
implement them — so reserving that room costs nothing and buys the room to answer
a question we cannot yet answer well. See the road to 1.0 below.

Classes marked **`@internal`** are not API and may change or disappear in any
release: `ManifestStoreParser`, `ServiceError`, `TrustAnchorsGuard`,
`AtomicWrite`. Do not depend on them.

### Two honest caveats

**`ExtC2paReader`'s contract is covered; its continued operation is not.** Its
public surface is `__construct(?string $trustAnchorsPem)`, `isAvailable()` and
`read(Asset): ManifestReport` — every type in it is ours. Nothing from
[`ericmann/ext-c2pa`](https://packagist.org/packages/ericmann/ext-c2pa) appears in
a signature, so an upstream API change breaks our *implementation* and not your
code: you would need a release from us, not an edit.

What we cannot promise is that it keeps working across an upstream break, because
that timing is not ours. The extension is at **v0.1.0** and its own planning
documents lag its code. Two things bound the risk: the extension is opt-in and
off by default (`reader` defaults to `service`), and CI pins the version it
tests, so a new upstream release is a deliberate bump rather than a surprise.

The one case where the *contract* would have to move is narrow and worth naming:
if the extension dropped trust-anchor support, the constructor's parameter would
become meaningless. Unlikely, and not something to claim perfect insulation
against. See [Reading](readers.md).

**The signing service is not part of this package.** `service/` is
`export-ignore`d, so it is not in the Composer dist at all. Its HTTP contract is
versioned separately under `/v1/`, and service-side changes reach you through
`git pull` and a rebuild rather than through a Composer update — with or without
a release tag. The changelog marks those entries accordingly.

## Deprecation policy

1. A method that is being replaced gets `@deprecated` in its docblock, naming the
   replacement and the version the replacement arrived in.
2. **No runtime notice is raised** unless the docblock says otherwise.
   Applications that promote notices to exceptions — and PHPUnit does this for
   deprecations by default — must not break on a purely cosmetic rename.
3. A deprecated method is removed no earlier than the next **major**, and only if
   the docblock announced a removal. A docblock that says the alias is **kept
   indefinitely** means exactly that.

The one deprecation in the package today is `ManifestBuilder::forAiGeneratedImage()`,
superseded by `forAiGenerated()` in 0.8.0 when media types beyond images arrived.
It is kept indefinitely, raises nothing, and costs three lines — removing it would
break working code for a cosmetic gain.

## What is a breaking change

**Breaking:** removing or renaming a public class, method or parameter;
narrowing an accepted type; widening a return type; adding a method to an
interface **other than `ReaderInterface` and `SignerInterface`**, whose position
is above; changing what an existing method *means* — for
example, if `isAiGenerated()` started answering true for a second source type.
Dropping a supported PHP or Laravel version.

**Not breaking:** adding a class, a method, an enum case, or an optional
parameter at the end; adding a new exception type to an existing hierarchy;
anything behind `@internal`; anything in `service/`, `bin/`, `tests/` or
`specs/`; documentation.

One of those deserves a note, because it has bitten before. **Adding an enum case
is additive for Composer and not free for you**: an exhaustive `match ($mediaType)`
with no `default` arm throws `UnhandledMatchError` the first time it meets a new
case. `MediaType` and `DigitalSourceType` have both grown and will grow again.
Add a `default` arm.

## The road to 1.0

1.0 is not a claim that the package is finished. It is one promise: **the public
API will not break without a 2.0.** Feature completeness is not a criterion —
PDF support, streaming, a pure-PHP reader and a WordPress plugin can all arrive
after 1.0, or never, without affecting it.

One thing has to be true first.

**Someone other than the maintainer has used it in anger.** This is the one that
cannot be manufactured, and it is the most important. An API designed and tested
only by its author is an API that has never met a use its author did not think
of. The usual cause of a painful 2.0 is a 1.0 declared before that contact.

**A capability method on `ReaderInterface` is deliberately not on this list
either**, though an earlier draft had it there. If a media type ever becomes
readable but not signable — the likeliest candidate is PDF, which c2pa-rs can
read and cannot yet write — then `MediaType` would need to distinguish the two
directions and `ReaderInterface` would need something like `supports()`.

It is not built, and building it now would be speculative. All thirteen media
types are signable and readable by both engines, so the case does not exist; the
method signature is the cheap part and its *meaning* is the expensive one, and
that meaning cannot be settled without the real case in front of us. A
`supports()` that answered true for everything would ship with a branch that had
never run.

What put it on the list was semver, not design: adding a method to an interface
breaks implementers. The policy above removes that, which is why the condition is
gone rather than met.

**`ExtC2paReader` is deliberately not on this list.** An earlier draft made 1.0
wait for the extension to reach a stable release. That was the wrong call: it
hangs our own versioning on the roadmap of a project that exists to serve a
different product, in exchange for nothing. The adapter's contract is insulated
(see above), the feature is opt-in and off by default, and the version CI tests
against is pinned. None of that improves by waiting.

Until then, `0.x` is the honest label.

## Reporting a break

If a minor release broke you, that is worth an issue even pre-1.0 — the point of
the **Upgrading** notes is that nothing should surprise you, and a surprise is a
documentation defect at minimum. See [SECURITY.md](../SECURITY.md) for anything
with a security dimension.
