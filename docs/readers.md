# Choosing a reader

Verification does not need the signing service. This page covers the
in-process reader, how to bind it in Laravel, and the trade-off between the two.
Back to the [README](../README.md).

## Reading without the signing service

**Verification needs no signing service.** It needs no private key and no
certificate either — checking a credential is a function of the asset bytes plus,
optionally, a trust list. Until now it needed a service anyway, because reading
and signing shared one transport. `ExtC2paReader` removes that.

```bash
pie install ericmann/ext-c2pa      # https://github.com/php/pie
```

```php
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;

$reader = new ExtC2paReader($anchorsPem);   // PEM contents, not a path
$report = $reader->read(new Asset($bytes, MediaType::Png));

$report->isVerifiedAiGenerated();   // no HTTP, no service, no key
```

Both readers implement `ReaderInterface` and return the same `ManifestReport`,
so choosing between them is an installation decision, not an API one:

```php
$reader = ExtC2paReader::isAvailable()
    ? new ExtC2paReader($anchorsPem)
    : new SigningServiceReader($client, $factory, $factory, $config);
```

Construction throws `ExtensionMissingException` when the extension is absent. It
deliberately does **not** fall back to the service reader: a caller who asked for
in-process reading and silently got HTTP cannot tell, and the fallback would need
a URL and token they never supplied.

### In Laravel

Set the mode in `config/content-credentials.php`; everything resolved from the
container — the facade, the jobs, the artisan commands — follows it.

```dotenv
CONTENTAUTH_READER=auto            # service (default) | extension | auto
CONTENTAUTH_TRUST_ANCHORS=/path/to/anchors.pem   # or the PEM contents
```

⚠️ **The default is `service`, so installing the extension does nothing until you
set this.** That is deliberate. The two readers run different c2pa-rs versions,
and an extension installed for an unrelated reason should not silently change
which engine decides your trust verdicts. `auto` is the setting most people want
— but as a choice you made, not one that happened to you.

`php artisan content-credentials:read <file>` prints the mode it resolved, so
"which engine produced this report?" is answerable without reading config:

```
reader             : extension
hasManifest        : true
```

`CONTENTAUTH_TRUST_ANCHORS` accepts PEM contents **or** a path — a path is read
for you, because every trust surface underneath this one takes contents and
silently verifies nothing when handed a path.

It applies to the **extension reader only**. The service reader's trust
verification is configured on the service, through `CONTENTAUTH_TRUST_SETTINGS`.
Same concept, two places: if you set `CONTENTAUTH_TRUST_ANCHORS` and the service
reader still reports `isTrusted()` false, that is why.

### What you are taking on

- **[`ericmann/ext-c2pa`](https://github.com/ericmann/ext-c2pa) is at `v0.1.0`.**
  It is an Automattic VIP product built for a WordPress plugin, not neutral
  infrastructure, and its API may move. The adapter is the containment: a break
  is one class to fix, and callers see nothing.
- **The two readers run different engines.** The extension carries **c2pa-rs
  0.89.0**; the signing service carries **0.90.4**. They agree today — an
  integration test compares both readers accessor by accessor on the same asset,
  and that test is what would tell us they had stopped. Run it with
  `vendor/bin/pest --group=SPEC-019` before relying on a mixed setup.
- **Signing still goes through the service.** The extension can sign too, and
  this library does not expose that: it would put the private key in your web
  process, which is the one thing this architecture exists to avoid. Reading
  in-process while signing through the service is a supported, and probably the
  best, combination.

## Which reader, and what it costs

`SigningServiceReader` sends the asset to the service; `ExtC2paReader` parses it
in-process through `ext-c2pa`. The usual comparison is operational — no second
process, no network hop, faster — and that is real. There is a second difference
worth deciding deliberately.

**The extension parses untrusted input inside your application process.** A
manifest arrives as bytes from somewhere you do not control, and verifying it
means parsing a container format in native code. With the service reader that
parsing happens in a separate, disposable process; with the extension it happens
in the PHP worker that also holds your session data and your database
connections.

This is the mirror image of the argument in
[ADR-0003](adr/ADR-0003-ext-c2pa-and-signer-backends.md): the signing *key* is kept
out of the web process by putting the signer behind a service, and the extension
reader moves parsing in the opposite direction. Neither is wrong — a memory-safety
bug in c2pa-rs is not a thing anyone has demonstrated — but the trade is worth
making on purpose rather than inheriting it because an extension happened to be
installed.

That is also why `reader` defaults to `service` and why `auto` has to be chosen
explicitly (SPEC-020): installing the extension for an unrelated reason should
not silently move where hostile input is parsed.
