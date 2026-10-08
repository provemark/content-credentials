# Step 71 — The signing service fetched remote manifests (2026-10-08)

SPEC-043, PR #162. Found while running the readers over the verifier corpus
(Step 70): the service read two files as `Valid` whose manifests live only at a
URL, by fetching them from `cai-manifests.adobe.com`.

## What was measured

c2pa-node 0.9.5 / c2pa-rs 0.90.22, a listener on the host, a JPEG whose XMP
`dcterms:provenance` points at it:

- `POST /v1/read` made a GET to the URL from the file.
- `POST /v1/sign` with a SPEC-028 `parent` made a GET to the URL from the
  parent, and still signed.
- The asset being signed made none.

The URL comes from whoever made the file, and the request came from the
process holding the signing key. Both endpoints need the bearer token and the
service listens on loopback, so the attacker is the author of a file the
application forwards, not the caller.

`verify.remote_manifest_fetch: false` on every `Reader.fromAsset()` and
`Builder.withJson()` stops both. A trust settings file that turns it back on is
refused at startup, and `/health` reports `remote_manifest_fetch: false`.
Service-side, so a tag delivers only the docs.

## How the absence is tested

Most SPEC-043 tests assert that the listener received nothing, the shape that
has passed while testing nothing here before. So they run only in two CI
profiles that also run a positive control: `CONTENTAUTH_TSA_URL` points at the
same listener, and the timestamp request arriving proves the container reaches
it in that run. On a Linux runner the route needs
`docker-compose.remote-probe.yml` (host-gateway), which is test-only and kept
out of `docker-compose.yml` and out of the dist.

## Step 65 is about something else

Step 65's title says "the SSRF that is not one". That was CAWG `did:web`
resolution, fixed upstream before our engine. It did not examine remote
manifest fetch on the service, which is this step. Both conclusions stand.
