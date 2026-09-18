# Step 67 — c2pa-node 0.9.6 deprecates the path the service reads trust through (2026-09-17)

Three days after Step 66, the currency question again. This time one thing
moved, and the interesting part is that it is not the kind of move the
verification ritual (Steps 8, 9, 48, 55, 64) is built for: **no engine bump, an
API deprecation.** Nothing to take, one watch item to open.

## What moved, and what did not

| what | pinned here | what the registry serves |
|---|---|---|
| `@contentauth/c2pa-node` | 0.9.5 | **0.9.6** (published 2026-09-15) |
| c2pa-rs (through that binding) | 0.90.22 | **0.90.22** — 0.9.6 pins `c2pa = "=0.90.22"` in the workspace `Cargo.toml` |
| `express` | `^5.2.1` | 5.2.1 |
| `ericmann/ext-c2pa` | =0.1.0 | still the only release |
| `tools/c2patool` (local, gitignored) | 0.27.22 | 0.27.22 is the latest stable |

`composer audit` and `npm audit --omit=dev` clean, `gh api
repos/contentauth/c2pa-rs/security-advisories` empty, Dependabot nothing, no
open PRs or issues, tree clean, `[Unreleased]` empty. `actions/checkout@v7` and
`shivammathur/setup-php@v2` are current.

Upstream has also tagged **c2pa-rs 0.91.0-rc.1** and **c2patool 0.28.0-rc.1**
(both 2026-09-14). Release candidates, not releases: nothing here moves on them
until a c2pa-node release carries the engine, which is the only route the
service takes (Step 64 explains why chasing Rust tags directly is wasted
effort).

## 0.9.6: `Context` in, raw settings deprecated

The whole 0.9.6 changelog entry is one line: *"Use Context as the new way to
construct and configure Reader and Builder objects. Deprecate Settings-based
workflows."* The commit (`contentauth/c2pa-js` #204) touches `Builder.ts`,
`Reader.ts`, `Settings.ts` and the README — no Rust, no native binary change.

That deprecation lands on one line of ours. `service/server.js` reads a
trust-enabled manifest with

```js
Reader.fromAsset({ buffer, mimeType }, trustSettings)
```

where `trustSettings` is the raw settings object loaded from
`TRUST_SETTINGS_PATH` (SPEC-014). In 0.9.6 that parameter is typed
`settingsOrContext?: C2paSettings | Context | null`, and the docblock says
passing a raw `C2paSettings` value *"is deprecated and will be removed in a
future version"*. So the call **still works in 0.9.6** — verified by reading the
signature, not by running it — and will stop working in some release after it,
with no date attached.

The signing side is unaffected for now: `Builder.withJson(...)`,
`LocalSigner.newSigner(...)` and `CallbackSigner.newSigner(...)` are not what
the deprecation names, though the same commit reworks `Builder.ts` and the
README's Builder examples, so a migration should read both.

## Why not bump now

A bump to 0.9.6 without touching `server.js` buys a deprecation warning and
nothing else: same engine, same behaviour, same thirteen types. A bump *with*
the migration is a `service/` change that has to be verified by hand — the
SPEC-014 trust verdict is exactly what the changed line produces — and
`service/` is `export-ignore`d, so a tag would deliver it to nobody. Spending
that on a release that fixes nothing is the wrong order.

The right moment is **the first c2pa-node release that carries c2pa-rs 0.91**,
which will need the ritual anyway (SPEC-035 AC7 goes red on any engine bump by
design; `hasTimestamp` on the async path; both readers agreeing under SPEC-019
AC2). Folding the `Context` migration into that bump means one hand-verification
instead of two.

## The watch item, stated so it can be closed

**Open (2026-09-17):** `server.js` passes raw trust settings to
`Reader.fromAsset()`, deprecated since c2pa-node 0.9.6. Close it by migrating to
`Context` on the next engine-carrying bump, verifying:

- `POST /v1/read` with `TRUST_SETTINGS_PATH` set still returns
  `signingCredential.trusted` for the test chain (SPEC-014), and `untrusted`
  without it — the migrated line is the one that decides this;
- `hasTimestamp` true on the async TSA path (SPEC-007);
- `bin/verify.sh` clean on a freshly signed asset;
- the SPEC-019 AC2 equivalence check still agreeing, in the `ext-c2pa` CI
  profile as well as locally.

It closes early if a c2pa-node release removes the deprecated path before an
engine bump arrives — in which case the migration stops being optional. Note
where that would show: **not at build time.** `npm ci` installs whatever the
lockfile says, the pin stays at 0.9.5 until someone moves it, and only a
deliberate bump followed by the first trust-enabled `POST /v1/read` reaches the
changed call. So the bump ritual is the guard, not the container build.

Note the shape, because Step 64 recorded the opposite one: **there is no
tripwire for this either.** A deprecation is not an advisory, `npm audit` does
not see it, and SPEC-035 AC7 only fires on an engine change. It is visible only
by reading the changelog, which is what the currency check is for.

## Addendum, 2026-09-18: #204 read, the migrated lines drafted

Read `contentauth/c2pa-js` #204 (merged 2026-09-15, shipped in c2pa-node
0.9.6) and the 0.9.6 sources it touched — `Reader.ts`, `Builder.ts`,
`Settings.ts`, `c2pa-utilities/src/{context,settings,caseConversion}.ts` —
so the bump that closes this item starts from a known shape. Nothing here is
run; it is read, and the ritual above stays the measurement.

### What #204 actually does

- `Context` (from `c2pa-utilities`, re-exported by `c2pa-node` via
  `export * from '@contentauth/c2pa-utilities'`) is a thin holder: `new
  Context(settings)` stores a `Settings` object; `Reader.fromAsset()` and the
  new `Builder.newAsync()` / `Builder.withJsonAsync()` take it. Inside,
  `resolveSettingsForNeon(ctx)` = `settingsToJson(withDefaultSettings(ctx.settings))`,
  i.e. merge defaults, then `snakeCaseify`, then `JSON.stringify` — and that
  string goes to the same Neon call as before. The raw path is unchanged:
  a string is passed through, an object is `JSON.stringify`'d, no defaults.
- **The deprecation reaches the signing side too**, which the entry above
  read too narrowly. `Builder.withJson()` — `server.js:1149` — is `@deprecated`
  in favour of `withJsonAsync(json, context = new Context())`. So the bump
  migrates two call sites, not one.
- **`Settings` is camelCase** (`verify.verifyTrust`, `trust.trustAnchors`,
  `trust.trustConfig`, `trust.allowedList`); our SPEC-014 document is the
  c2pa-rs native snake_case shape, on purpose — the same file feeds
  `c2patool --settings` and is what `docs/production.md` teaches. `snakeCase()`
  in `caseConversion.ts` is `str.replace(/[A-Z]/g, …)`: a key with no capitals
  passes through untouched, so a snake_case document handed to `new Context()`
  serialises to exactly what we send today. That is a property of a regex, not
  a documented contract. Two options at bump time, in order of preference:
  1. keep the file format, map the three-or-four known keys to camelCase in
     `loadTrustSettings()` before constructing the `Context` — explicit, and
     the AC5 validation already reads those exact keys;
  2. rely on the passthrough and let SPEC-014's trust verdicts prove it.
  Either way the SPEC-014 integration tests are the proof; option 1 just does
  not depend on the regex staying as it is.
- **The only default `Context` adds is `builder.generateC2paArchive: true`**
  (`DEFAULT_SETTINGS` in `settings.ts`), serialised as
  `builder.generate_c2pa_archive`. c2pa-rs 0.90.22 already defaults that to
  `Some(true)` (`sdk/src/settings/builder.rs`), so it is behaviour-neutral
  for both the Reader and the Builder — but the Builder currently receives
  *no* settings string and would then receive one, which is a reason to re-run
  the SPEC-035/036 placement audit rather than assume.
- `c2pa-node` does not yet resolve trust-anchor URLs inside a `Context`
  (README note); ours are inline PEM, so irrelevant.

### The migrated lines, as a draft

```js
const { LocalSigner, CallbackSigner, Builder, Reader, Context } = require('@contentauth/c2pa-node');

// after loadTrustSettings(): one Context for the life of the process
const trustContext = trustSettings ? new Context(toCamelSettings(trustSettings)) : undefined;

// /v1/read — the line SPEC-014 hangs on
const reader = await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type }, trustContext);

// /v1/sign — was Builder.withJson(manifestDefinition)
const builder = await Builder.withJsonAsync(manifestDefinition);
```

where `toCamelSettings` maps `verify.verify_trust → verify.verifyTrust`,
`trust.trust_anchors → trust.trustAnchors`, `trust.trust_config →
trust.trustConfig`, `trust.allowed_list → trust.allowedList` (option 1 above),
or is the identity (option 2). `Reader.fromAsset(asset, undefined)` is the
untrusted path as before; `null` is documented as equivalent.

### What stays in the ritual, unchanged

The four checks listed under "The watch item" above, plus, because the Builder
now receives a settings string it never had: the SPEC-035 AC7 spec-version
audit and the `created: true` placement check on a freshly signed asset. None
of this moves the pin today — 0.9.6 carries the same engine, and the item
closes on the first c2pa-node release that carries c2pa-rs 0.91.
