# Running the signing service

Operating the Node service that holds the signing key: what it logs, what it
refuses, and how much memory to give it. The [README](../README.md) covers
getting one running; this page covers running it well.


> **Note:** the signing service, test certificates and verification tooling
> (`service/`, `certs/`, `bin/`) live in the
> [source repository](https://github.com/provemark/content-credentials) — they
> are **not** part of the Composer package. Clone the repo to run them; the
> installed package is the PHP client that talks to the service.

The service holds the signing key and performs the C2PA operation. Run it from a
clone of the repository with Docker Compose. The **public** c2pa-rs ES256 test
**certificate chain and trust settings are committed in `certs/`**; the matching
private key is intentionally **not** — fetch it below:

```bash
cp .env.example .env          # set a CONTENTAUTH_API_KEY value

# The private test key is intentionally NOT committed. Fetch the c2pa-rs sample
# key for local development (test material only — never a real key):
curl -sfSL https://raw.githubusercontent.com/contentauth/c2pa-rs/main/cli/sample/es256_private.key \
  -o certs/es256_private.key

docker compose up -d --build  # service on http://localhost:3000
```

`POST /v1/sign` and `POST /v1/read` are Bearer-authenticated with
`CONTENTAUTH_API_KEY`; `GET /health` is public.

## Audit logging

Every `/v1/sign` request writes one line of JSON to **stdout**, for accepted and
refused requests alike, so you can answer the question an incident starts with:
*did we sign this, when, and at whose request?* Without a record, a fabricated
credential carrying your certificate cannot be told apart from a genuine one —
which makes every credential you ever issued suspect.

```json
{"ts":"2026-08-05T09:14:22.104Z","cid":"01J…","event":"sign","outcome":"signed",
 "token_id":"9f2a41c0b7de","mime_type":"image/png","input_sha256":"3b1f…",
 "input_bytes":1699,"output_sha256":"c07e…","creator_name":"ACME GenAI Image Model",
 "assertion_labels":["c2pa.actions.v2"],"digital_source_types":["…trainedAlgorithmicMedia"],
 "timestamped":true}
```

Every response also carries an `X-Correlation-Id` header, repeated as `cid` in
error bodies. Service errors return a generic message — the detail belongs in
the record, not in a client-side exception — so quote the `cid` in a bug report.

**What is deliberately never recorded:** the bearer token, key material, the
base64 content, the signed bytes, full assertion payloads, or the manifest
store. Callers are identified by `token_id`, a salted one-way digest: two
requests with the same token correlate, and the token cannot be recovered from
it. Caller-supplied strings are length-capped, so nobody can write unbounded
data into your log.

> **Personal data.** `creator_name` is supplied by the caller and reproduced in
> records. In normal use it is application metadata (a tool or model name), but
> if your deployment puts a person's name there, **you are processing personal
> data in your logs** and the retention decision is yours. Records go to stdout;
> collecting, rotating and expiring them is your platform's job.

If the audit write ever fails, the request still succeeds — a logging outage
must not become a signing outage — and `GET /health` reports
`"audit_degraded": true` until the process restarts, so the loss is visible to
monitoring rather than silent.

## Rate limiting and concurrency

The service bounds how much work it will accept at once. Excess requests get
**429** with `Retry-After`, and nothing is signed. It refuses rather than
queues: the PHP client bounds a request at 10 seconds, so a queued request would
time out client-side while still holding a slot server-side — the caller has
given up and the service is still paying for it.

`GET /health` reports the effective limits and how many signs are in flight:

```json
{"in_flight": 2, "limits": {"max_concurrent_signs": 4, "rate_limit_requests": 60,
 "rate_limit_window_ms": 60000, "request_timeout_ms": 15000, "headers_timeout_ms": 10000}}
```

That last part matters more than it looks. Signing does **not** block the event
loop — measured, six concurrent signatures complete in roughly the time of two —
so a saturated instance answers `/health` just as fast as an idle one. Without
`in_flight`, an orchestrator cannot tell them apart and keeps routing to an
instance about to run out of memory.

Limits are **on by default**; a protection that ships off is one nobody turns
on. Setting one to `0` disables it explicitly, and `/health` says so.

**Reading is bounded separately from signing.** `/v1/read` has its own
concurrency cap and its own rate budget, and `/health` reports both alongside
`reads_in_flight`. The separation is deliberate: with one shared budget a
verification loop could consume what the application needs to sign its own
output, and that failure would present as "signing is broken".

Measured, reading costs about **3–5×** the asset in memory against signing's
~7× — cheaper, same order of magnitude — so the read cap defaults to the same 4
rather than to something generous. A fully saturated instance holds both paths
at once: four signs plus four reads of maximum-size assets is roughly **650 MiB**,
which is the number to size a container against.

Note that a **sign-then-verify round-trip spends from both budgets** — the
common pattern of reading back what you just signed costs one of each. That is
an argument for the separation rather than against it: with one shared budget
the same round-trip would spend double from a single bucket.

Tune with `MAX_CONCURRENT_SIGNS`, `RATE_LIMIT_REQUESTS`, `MAX_CONCURRENT_READS`,
`READ_RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW_MS` (shared by both budgets),
`REQUEST_TIMEOUT_MS` and `HEADERS_TIMEOUT_MS`.

## Sizing the container

Signing is memory-hungry in a way that is easy to underestimate. A request holds
the parsed base64 string, the decoded buffer, the signed file read back from
disk, and its base64 in the response — and measured against an idle baseline of
about 18 MiB, the real cost is roughly **7×** the asset, not the four copies
that suggests:

| Asset | Peak at 4 concurrent | Per request | Multiplier |
|---|---|---|---|
| 1.0 MB | 66 MiB | 12.1 MiB | 12.1× |
| 4.1 MB | 161 MiB | 35.9 MiB | 8.7× |
| 11.4 MB | 332 MiB | 78.6 MiB | 6.9× |

The ratio falls as assets grow, because fixed per-request overhead amortises.
So, to size a container:

```
peak memory ≈ MAX_CONCURRENT_SIGNS × 7 × largest asset you accept
```

`MAX_BODY_SIZE` (default **20 MB**) is what caps that last term. Base64 inflates
an asset by a third, so 20 MB of body carries roughly a 15 MB asset — well above
the 11.4 MB a 2000×2000 PNG of incompressible pixels measures — and peaks around
420 MB at the default concurrency cap. The previous default of 50 MB carried a
~37 MB asset and peaked near 1 GB, which does not fit in the 512 MB container
many people would give it.

A body over the limit is refused with **413** before it reaches the signing
path. Raise `MAX_BODY_SIZE` if you sign larger assets, but raise the container's
memory with it — the concurrency cap will not save you, because the body is
buffered *before* any limit is consulted. That is also why lowering this setting
does more for memory than anything else here.

## Assertion limits

The service constrains what it will attest to. At most **one** `c2pa.actions`
assertion (two would be contradictory, and which one a verifier honours is
undefined), a bounded number of assertions, and bounds on each assertion's size
and nesting depth. Violations return **400** naming the constraint, and nothing
is signed. Tune with `MAX_ASSERTIONS`, `MAX_ASSERTION_BYTES`,
`MAX_ASSERTION_DEPTH` and `MAX_CREATOR_NAME`.

The service takes **no position on `digitalSourceType`** by default. Requiring
`trainedAlgorithmicMedia` would not make an attestation truer — the service can
verify it no better than a camera-capture claim — while excluding the
authenticity use case entirely. If your certificate exists solely to mark
AI-generated content, set `REQUIRE_AI_MARKING=true`; `GET /health` reports the
effective policy.

## Rotating the signing key

The service reads `SIGNING_CERT_PATH` and `SIGNING_KEY_PATH` **once at startup**.
There is no reload endpoint and no file watching, by design: a restart is simple,
atomic, and cannot half-apply. Rotation is three steps.

```bash
# 1. Note the certificate you are replacing, so you can tell it changed.
curl -s localhost:3000/health | jq .signing_cert

# 2. Put the new certificate and key where the mounts point, then restart.
docker compose up -d --force-recreate service

# 3. Confirm the new certificate is the one now in use.
curl -s localhost:3000/health | jq .signing_cert
```

```json
{
  "fingerprint_sha256": "6fb5eddb353a82fa8720b1d54a4925eaa20e128b10cc4b3fa4d3e9e920c04001",
  "not_after": "Aug 26 18:46:40 2030 GMT"
}
```

**Step 3 is not optional.** A mount that did not take, a stale image layer, a
path typo — each leaves the service happily signing with the *old* key while
looking, from the outside, exactly like a successful rotation. The fingerprint is
the SHA-256 digest of the loaded leaf certificate; compare it against the one you
installed (`openssl x509 -in your.crt -noout -fingerprint -sha256`). `not_after`
is the expiry, so you can see a rotation coming rather than discover it.

Two things to plan for:

- **In-flight requests are lost.** Signing is not resumable, and the restart does
  not drain. Rotate during a quiet window, or take the instance out of rotation
  first. A caller sees a connection error, not a corrupt asset.
- **Assets signed with the old certificate stay valid.** A signature is checked
  against the certificate embedded in the manifest, not against whatever the
  service holds today. Rotation does not invalidate history — but if the old
  certificate was rotated because it was *compromised*, that is exactly the
  problem, and revocation is a matter for the issuing CA.

This restart-based procedure is what the C2PA Generator Product Security
Requirements mean by being **capable of rotating** the claim signing key (O.2,
Assurance Level 1); hot reload is not required.
