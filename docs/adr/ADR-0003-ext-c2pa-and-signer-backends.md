# ADR-0003: ext-c2pa exists — drop our own FFI route, keep the service model, plan an extension adapter

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-07-27                     |
| Spec     | — (roadmap/architecture; informs a future Signing adapter spec) |
| Deciders | Maurice van Loon (maintainer)  |

## Context

A native PHP extension for C2PA now exists and was missed in the initial field
scan (it is tagged Rust on GitHub, so a `language:php` query skipped it):

- **`ericmann/ext-c2pa`** — verified on Packagist 2026-07-27: `type: php-ext`,
  `license: GPL-2.0-or-later`, `require php ^8.3`, published **2026-07-16**,
  repo `github.com/ericmann/ext-c2pa`. Description: *"PHP 8.3+ native C2PA
  Content Credentials: read/validate manifests and sign Media Library images
  incl. derivatives."*
- It is a native extension built with `ext-php-rs` on top of `c2pa-rs`, authored
  by Eric Mann (a well-known PHP-security figure), in a WordPress VIP context
  (Media Library signing). It is effectively the FFI/native route others left
  unfinished — now being completed by a well-resourced party.

This is primarily **market validation**: a major PHP organisation started
building this exact thesis eleven days before our first release. It also forces
two decisions about our own direction.

## Decision

1. **Drop our own FFI / native-signing ambition from the roadmap.** CLAUDE.md
   listed FFI as "out of scope until a spec says otherwise"; this ADR makes that
   permanent as a *build* goal. A better-resourced party (Automattic/Eric Mann)
   is building and will maintain the heavy Rust/native layer; we should not
   duplicate it.
2. **Keep the isolated signing-service as the default and differentiator.** Our
   `SigningServiceSigner` delegates to a separate service, so the **private key
   never lives in the web-app process** — the pattern the CAI wp-plugin itself
   uses (its `signing-service/` holds the key on the plugin's behalf; see
   NOTES.md Step 1). A native extension signs **in-process**, so the key sits on
   the web server. "We do not put the signing key on your web server" is our
   defensible, security-legible position.
3. **Plan an `ExtC2paSigner` adapter as a future Signing spec** (not now).
   Because signing sits behind `SignerInterface`, a second adapter can later sign
   **in-process where the extension is installed**, with no Docker sidecar —
   Automattic maintains the native layer, we provide the developer experience and
   the Laravel/pure-PHP ergonomics on top. This is opt-in; the service model
   stays the default.

## Consequences

- **Positive:** validated market timing; we avoid building/maintaining a native
  layer; `SignerInterface` gains a credible second backend later at low cost; our
  key-isolation story is sharpened as the differentiator.
- **Distribution/licence contrast (why the segments differ):** a native
  extension must be installed per platform × PHP version (impossible on much
  managed hosting) and is **GPL-2.0-or-later**; our package is `composer require`
  and **MIT**. Their segment is WordPress VIP; ours is Laravel / any-framework —
  these do not overlap.
- **Cost/risk:** if we later add `ExtC2paSigner`, we take a dependency on a
  GPL-2.0 extension at the edge — acceptable as an *optional* adapter the
  consumer installs, since it does not affect this MIT library's own licence.

## Follow-ups (not part of this ADR)

- Watch `github.com/ericmann/ext-c2pa` (its `DESIGN.md` / spec-driven notes are
  useful reference).
- When in-process signing is wanted, open a Signing spec for `ExtC2paSigner`
  behind the existing `SignerInterface`.
