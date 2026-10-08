# SPEC-043: The service never fetches a remote manifest

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon, 2026-10-08                      |
| Supersedes | — (extends SPEC-014 trust settings, SPEC-028 the parent ingredient, SPEC-031 the read error path) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A C2PA asset can declare its manifest by URL instead of carrying it: XMP
`dcterms:provenance` (C2PA 2.4 §11.4, remote manifests). c2pa-rs fetches that
URL by default (`verify.remote_manifest_fetch`, default on), and
`service/server.js` never sets it. So the signing service makes an outbound
HTTP request to a URL chosen by whoever produced the uploaded file.

That is server-side request forgery from the process that holds the signing
key. The service publishes on loopback only and both endpoints need the
bearer token, so the attacker is not the caller. The attacker is the author of
a file the caller forwards, which is the ordinary case: an application reads or
edits what its users upload.

It was found by accident: during SPEC-042, the service read
`c2pa-rs/cloud.jpg` and an Adobe Photoshop export as `Valid`, after fetching
their manifests from `cai-manifests.adobe.com`. ExtC2paReader and the verifier
both refuse such a file.

### Measured 2026-10-08 (c2pa-node 0.9.5 / c2pa-rs 0.90.22, the running service)

A listener on the development host, reached from the container as
`host.docker.internal:8765`, and a JPEG fixture with one XMP segment whose
`dcterms:provenance` points at it:

| path | today | with `verify.remote_manifest_fetch: false` |
|---|---|---|
| `POST /v1/read` | **GET to the URL from the file**; HTTP 500 to the caller when it answers 404 | no request; c2pa-rs raises "must fetch remote manifests from url …", the same text ext-c2pa raises |
| `POST /v1/sign` with a `parent` (SPEC-028) | **GET to the URL from the parent**; the signature still succeeds | no request; the ingredient is added (`Builder.withJson(json, settings)`) |
| `POST /v1/sign`, the asset itself | no request | — |

The setting was exercised in a throwaway container of the service image (the
service's own root filesystem is read-only). It is accepted both by
`Reader.fromAsset(asset, settings)` and by `Builder.withJson(json, settings)`.

Not measured end to end, because it needs the change: the running service
with the setting in place. AC1's audit wording and AC2's completed signature
rest on what c2pa-node returned in the throwaway container (the error text,
and `addIngredient()` succeeding), not on a request to the service.

Not measured at all: redirects, how long c2pa-rs waits on a slow URL, and how
large a response it accepts. Turning the fetch off makes all three moot, which is the
reason not to bound it instead.

## Scope

**In scope**

- `verify.remote_manifest_fetch: false` on every `Reader.fromAsset()` and
  `Builder.withJson()` call in `service/server.js`, with and without
  `CONTENTAUTH_TRUST_SETTINGS`.
- A trust settings file cannot turn it back on.
- `GET /health` reports it, so a rebuild is confirmable without a request.
- A CI profile that proves both paths make no request, with a positive control
  in the same run.
- `docs/readers.md`, `docs/service.md` and the primer: the service no longer
  fetches, and what a remote-only file now reads as.

**Out of scope** (each needs its own spec before it may be built)

- **An opt-in to fetch.** It would bring back the request this spec removes;
  doing it safely means an allow-list of hosts, redirect and size limits, and a
  timeout, each with its own criteria. Trigger: a user who needs remote
  manifests verified by the service, with their own numbers.
- **A distinct status code for a remote manifest on `/v1/read`.** Telling it
  apart means matching a c2pa-rs error string, which is not a stable interface
  (the SPEC-038 AC3 lesson). The read stays the generic SPEC-031 failure.
- **`verify.ocsp_fetch`** and any other fetch c2pa-rs can make. Not measured
  here; its default needs its own measurement before a criterion.
- **Refusing a parent whose only manifest is remote.** See Open questions.

## Behavior

- **AC1 — reading makes no request to a URL from the file** *(error path)*
  - Given a JPEG whose only manifest is declared by a URL that points at a
    listener the service can reach
  - When it is sent to `POST /v1/read`, with trust settings and without
  - Then the listener receives no request, and the response is the SPEC-031
    read failure (HTTP 500, `read failed`). The audit record's `reason` names
    the remote URL, as c2pa-rs words it.

- **AC2 — signing with such a parent makes no request** *(error path)*
  - Given a parent (SPEC-028) whose only manifest is declared by a URL that
    points at the same listener
  - When an edit is signed with it
  - Then the listener receives no request, and the signature succeeds with the
    parent as a `parentOf` ingredient that carries no manifest of its own.

- **AC3 — the absence is shown against a positive control**
  - Given the same listener, in the same CI run
  - When the service makes a request it is configured to make (the RFC 3161
    timestamp request, with `CONTENTAUTH_TSA_URL` pointed at the listener, in
    a profile that runs only this spec)
  - Then the listener receives that request. AC1 and AC2 assert an absence,
    and an absence is also what a listener the container cannot reach would
    show. Without the control, both pass on a broken setup.

- **AC4 — a trust settings file cannot turn it back on** *(error path)*
  - Given `CONTENTAUTH_TRUST_SETTINGS` pointing at a file that sets
    `verify.remote_manifest_fetch: true`
  - When the service starts
  - Then it exits with a message naming the setting, as SPEC-014 does for a
    file that verifies nothing. Silently overriding the file would leave an
    operator believing the file is in force.
  - And a file that does not mention the setting is used with it set to false.

- **AC5 — `/health` reports it**
  - When `GET /health` is called
  - Then the document carries `remote_manifest_fetch: false`.

- **AC6 — a remote-only file reads the same through all three readers**
  - Given the AC1 fixture
  - When it is read through `SigningServiceReader`, `ExtC2paReader` (where
    loaded) and `C2paVerifierReader`
  - Then all three raise `ReadFailedException`. Today the service reader is the
    odd one out: it reports `Valid` after fetching. The SPEC-042 docs record
    that difference, and they change with this spec.

## API sketch

```js
// service/server.js
const NO_REMOTE_FETCH = { verify: { remote_manifest_fetch: false } };

function readerSettings() {
  // trustSettings already passed loadTrustSettings(); AC4 refused a `true`.
  return trustSettings
    ? { ...trustSettings, verify: { ...trustSettings.verify, remote_manifest_fetch: false } }
    : NO_REMOTE_FETCH;
}

Reader.fromAsset({ buffer, mimeType }, readerSettings());
Builder.withJson(manifestDefinition, NO_REMOTE_FETCH);
```

No change to the PHP side. `service/` is `export-ignore`d, so this reaches
users through `git pull` and a rebuild, never through a Composer update; a tag
delivers only the docs.

**Interaction with the Context migration** (the open watch item in NOTES
Step 67): a `Context` takes camelCase settings, and the drafted migration maps
four trust keys. `remote_manifest_fetch` becomes a fifth, and the Step 67
finding that an unmapped snake_case key is dropped silently applies to it.
AC1 and AC2 are what would catch that.

## Open questions

- **A parent whose only manifest is remote.** With the fetch off, the edit is
  signed and the ingredient carries no manifest (measured). The alternative is
  refusing the edit. Proposed: sign, because the edit itself is truthfully
  recorded, and refusing would block every edit of a cloud-stored Adobe file;
  `docs/service.md` says the parent's own provenance is not carried in that
  case. Non-blocking.
- **The CI listener.** On Linux runners the container reaches the host only
  with `extra_hosts: host.docker.internal:host-gateway`. Proposed: a compose
  override used only by the AC3 profile, so `docker-compose.yml` as users run
  it gains no route to the host. Non-blocking.
- **Severity in the release note.** This is a security fix in `service/`, so a
  tag delivers none of it. Proposed: say so in the first sentence of the
  release note and the GitHub Release, as v0.14.1 did for SPEC-039. Non-blocking.

## Traceability

Implemented 2026-10-08. Measured locally against the rebuilt service:
`--group=SPEC-043` 7 passed / 1 skipped in both probe configurations (trust off
and on), the AC3 control passing in each; full integration 199 passed / 23
skipped (defaults, TSA on) and 198 / 24 (hardened); `bin/e2e.php` trusted with
the Article 50 mark intact, actions assertion still in `created_assertions`,
`specVersion` 2.4.0; the AC1 audit reason read from the container log.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/RemoteManifestFetchTest.php` :: "makes no request when reading a file whose manifest is remote" | `service/server.js` `readerSettings`, `POST /v1/read` |
| AC2 | `tests/Integration/RemoteManifestFetchTest.php` :: "makes no request when signing with a parent whose manifest is remote", "still signs an edit whose parent declares a remote manifest" | `service/server.js` `NO_REMOTE_FETCH`, `Builder.withJson()` |
| AC3 | `tests/Integration/RemoteManifestFetchTest.php` :: "reaches the listener with the request it is configured to make" | `tests/Integration/RemoteProbe.php`, `tests/Integration/remote-probe-router.php`, `./docker-compose.remote-probe.yml`; `.github/workflows/ci.yml` profiles `remote-probe`, `remote-probe-trust` and step "Assert the remote-fetch probe ran (SPEC-043)" |
| AC4 | `tests/Integration/RemoteManifestFetchTest.php` :: "refuses to start when trust settings turn remote fetching on", "starts with trust settings that do not mention remote fetching" | `service/server.js` `loadTrustSettings()` |
| AC5 | `tests/Integration/RemoteManifestFetchTest.php` :: "reports on /health that it never fetches a remote manifest" | `service/server.js` `GET /health` |
| AC6 | `tests/Integration/RemoteManifestFetchTest.php` :: "refuses a remote-only file through every reader" | `service/server.js` `readerSettings`; `src/Core/Reading/VerifierOutcome.php` (SPEC-042 Amendment 2) |
