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

Classes marked **`@internal`** are not API and may change or disappear in any
release: `ManifestStoreParser`, `ServiceError`, `TrustAnchorsGuard`,
`AtomicWrite`. Do not depend on them.

### Two honest caveats

**`ExtC2paReader` is not covered by the stability promise.** It wraps
[`ericmann/ext-c2pa`](https://packagist.org/packages/ericmann/ext-c2pa), which is
at **v0.1.0** and whose own planning documents lag its code. If that extension
changes its API, this adapter changes with it, and we do not control the timing.
`ReaderInterface` and `SigningServiceReader` *are* covered — write against the
interface and you are insulated. See [Reading](readers.md).

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
interface you might implement; changing what an existing method *means* — for
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

Three things have to be true first.

**Someone other than the maintainer has used it in anger.** This is the one that
cannot be manufactured, and it is the most important. An API designed and tested
only by its author is an API that has never met a use its author did not think
of. The usual cause of a painful 2.0 is a 1.0 declared before that contact.

**`ExtC2paReader`'s position is settled** — either the extension it wraps has
reached a stable release, or this adapter stays explicitly outside the promise.
Promising stability over a `v0.1.0` dependency is promising something we do not
control.

**Whether `ReaderInterface` grows a capability method is decided.** If a media
type ever becomes readable but not signable — the likeliest candidate is PDF,
which c2pa-rs can read and cannot yet write — then `MediaType` needs to
distinguish the two directions and `ReaderInterface` probably needs
`supports()`. Adding a method to an interface is breaking for anyone
implementing it, so the room for it costs nothing now and costs a major later.
That decision belongs before 1.0, not after.

Until then, `0.x` is the honest label.

## Reporting a break

If a minor release broke you, that is worth an issue even pre-1.0 — the point of
the **Upgrading** notes is that nothing should surprise you, and a surprise is a
documentation defect at minimum. See [SECURITY.md](../SECURITY.md) for anything
with a security dimension.
