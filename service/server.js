'use strict';

/**
 * Minimal C2PA signing service for the spike.
 *
 * Built on the MAINTAINED library, @contentauth/c2pa-node (v2 / 0.7.x), because
 * the upstream wp-plugin signing-service is pinned to a non-existent
 * c2pa-node@^1.0.0 and targets an unreleased API (see NOTES.md).
 *
 *
 * It mirrors the wp-plugin `/v1/sign` request contract so the PHP client is
 * representative of a real deployment, with one deliberate divergence: this
 * service does NOT inject a hardcoded actions assertion. The client supplies the
 * full assertions array (including the AI `c2pa.actions.v2` marking), so the
 * manifest carries exactly one, correct actions assertion.
 */

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const { LocalSigner, CallbackSigner, Builder, Reader } = require('@contentauth/c2pa-node');

/**
 * Hash for the ECDSA callback signer used on the timestamped (async) path.
 * The signing service ships es256 test certs; es384/es512 are mapped too.
 */
function hashForAlg(alg) {
  switch (alg) {
    case 'es256': return 'SHA256';
    case 'es384': return 'SHA384';
    case 'es512': return 'SHA512';
    default:
      throw new Error(`timestamped signing supports es256/es384/es512, not '${alg}'`);
  }
}

const PORT = parseInt(process.env.PORT ?? '3000', 10);
const API_KEY = process.env.CONTENTAUTH_API_KEY ?? '';
const SIGN_ALG = (process.env.CONTENTAUTH_SIGN_ALG ?? 'es256').toLowerCase();
const CERT_PATH = process.env.SIGNING_CERT_PATH;
const KEY_PATH = process.env.SIGNING_KEY_PATH;
// SPEC-017. Measured 2026-08-06 at the concurrency cap, against a 17.6 MiB idle
// baseline: a signing request costs about 7x the asset in memory, not the four
// copies previously documented (12.1x at 1 MB, 8.7x at 4.1 MB, 6.9x at 11.4 MB
// -- fixed per-request overhead amortising). At 50mb a body carries a ~37 MB
// asset, and four of those in flight peaked near 1 GB.
//
// 20mb carries ~15 MB after base64 overhead, comfortably above the 11.4 MB a
// 2000x2000 incompressible PNG measured, and peaks around 420 MB at the cap.
// The concurrency cap cannot substitute for this: express buffers the body
// before any limit is consulted, so the allocation happens either way.
const MAX_BODY = process.env.MAX_BODY_SIZE ?? '20mb';
// Optional RFC 3161 Time Stamping Authority (SPEC-007). Unset => no timestamp
// (today's behaviour). When set, signatures carry a trusted timestamp.
const TSA_URL = process.env.CONTENTAUTH_TSA_URL || undefined;
// Optional c2pa trust settings document (SPEC-014). Unset => no trust-list
// verification, i.e. every signed asset reads back as `Valid` with
// `signingCredential.untrusted`, whatever certificate signed it.
const TRUST_SETTINGS_PATH = process.env.CONTENTAUTH_TRUST_SETTINGS || undefined;

if (!CERT_PATH || !KEY_PATH) {
  console.error('SIGNING_CERT_PATH and SIGNING_KEY_PATH are required');
  process.exit(1);
}

// Load key material once at startup (fail fast if missing/wrong).
const certificate = fs.readFileSync(CERT_PATH);
const privateKey = fs.readFileSync(KEY_PATH);
if (!certificate.toString().includes('CERTIFICATE')) {
  console.error(`SIGNING_CERT_PATH is not a PEM certificate: ${CERT_PATH}`);
  process.exit(1);
}
if (!privateKey.toString().includes('PRIVATE KEY')) {
  console.error(`SIGNING_KEY_PATH is not a PEM private key: ${KEY_PATH}`);
  process.exit(1);
}

/**
 * Load and vet the trust settings document (SPEC-014 AC4/AC5).
 *
 * Both checks fail the process rather than degrading, because trust
 * verification that is quietly disabled is worse than none: reads keep
 * answering `Valid` + `signingCredential.untrusted`, which is exactly what a
 * correctly configured service reports for an untrusted asset. The two are
 * indistinguishable from outside, so an operator who believes trust is on has
 * no way to discover that it is not.
 *
 * AC5 exists because parsing proves nothing. Verified against the library
 * (NOTES.md Step 11): `{verify: {verify_trust: true}, trust: {}}` raises no
 * error and verifies nothing — byte-identical to passing no settings at all.
 * A malformed PEM does throw, so the dangerous case is the ABSENT one.
 */
function loadTrustSettings(path) {
  let raw;
  try {
    raw = fs.readFileSync(path, 'utf8');
  } catch (err) {
    console.error(`CONTENTAUTH_TRUST_SETTINGS is not readable: ${path} (${err.code ?? err.message})`);
    process.exit(1);
  }

  let settings;
  try {
    settings = JSON.parse(raw);
  } catch (err) {
    console.error(`CONTENTAUTH_TRUST_SETTINGS is not a JSON settings document: ${path} (${err.message})`);
    process.exit(1);
  }

  if (settings === null || typeof settings !== 'object' || Array.isArray(settings)) {
    console.error(`CONTENTAUTH_TRUST_SETTINGS must be a JSON object: ${path}`);
    process.exit(1);
  }

  // AC5: would these settings actually verify anything?
  const nonEmpty = (value) => typeof value === 'string' && value.trim() !== '';
  const trust = settings.trust ?? {};
  const hasMaterial = nonEmpty(trust.trust_anchors) || nonEmpty(trust.allowed_list);

  if (settings.verify?.verify_trust !== true) {
    console.error(`CONTENTAUTH_TRUST_SETTINGS does not enable verify.verify_trust: ${path}`);
    process.exit(1);
  }
  if (!hasMaterial) {
    console.error(
      `CONTENTAUTH_TRUST_SETTINGS carries no trust.trust_anchors or trust.allowed_list: ${path}. `
      + 'verify_trust without trust material verifies nothing, silently.',
    );
    process.exit(1);
  }

  // Contents, not paths: c2pa parses these fields as PEM/config text, so a path
  // fails with "could not parse configuration" (NOTES.md Step 11).
  return settings;
}

const trustSettings = TRUST_SETTINGS_PATH ? loadTrustSettings(TRUST_SETTINGS_PATH) : undefined;

// Asset types this service will sign/read (SPEC-009 #6). Must track MediaType
// in the PHP client; revisit when a spec adds asset types.
const SUPPORTED_MIME = new Set(['image/png', 'image/jpeg']);

// --- SPEC-011: structural limits on what this service will attest to --------
// Restrictive by default: too permissive is the risk for structure. The
// semantic policy below is the opposite — see REQUIRE_AI_MARKING.
const MAX_ASSERTIONS = Number(process.env.MAX_ASSERTIONS ?? 16);
const MAX_ASSERTION_BYTES = Number(process.env.MAX_ASSERTION_BYTES ?? 64 * 1024);
const MAX_ASSERTION_DEPTH = Number(process.env.MAX_ASSERTION_DEPTH ?? 16);
const MAX_CREATOR_NAME = Number(process.env.MAX_CREATOR_NAME ?? 256);
const AI_MARKING = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

// Deployment policy, NOT a structural invariant, and permissive by default:
// requiring trainedAlgorithmicMedia cannot make an attestation truer (the
// service can verify it no better than digitalCapture), it only narrows the
// direction of a possible lie — while excluding the authenticity use case
// outright. Deployments whose certificate exists solely to mark AI content can
// opt in.
const REQUIRE_AI_MARKING = process.env.REQUIRE_AI_MARKING === 'true';

/** Depth of a JSON value, stopping as soon as the limit is passed. */
function exceedsDepth(value, limit, depth = 0) {
  if (depth > limit) return true;
  if (value === null || typeof value !== 'object') return false;

  for (const child of Object.values(value)) {
    if (exceedsDepth(child, limit, depth + 1)) return true;
  }

  return false;
}

/**
 * Vet `extra_assertions` (SPEC-011).
 *
 * @returns {string|null} the violated constraint, or null when acceptable.
 */
function rejectAssertions(assertions) {
  if (assertions === undefined) return null;
  if (!Array.isArray(assertions)) return 'extra_assertions must be an array';
  if (assertions.length > MAX_ASSERTIONS) {
    return `too many assertions (max ${MAX_ASSERTIONS})`;
  }

  let actionsCount = 0;

  for (const assertion of assertions) {
    if (assertion === null || typeof assertion !== 'object' || Array.isArray(assertion)) {
      return 'each assertion must be an object';
    }
    if (typeof assertion.label !== 'string' || assertion.label.trim() === '') {
      return 'each assertion must carry a non-empty string label';
    }
    if (JSON.stringify(assertion).length > MAX_ASSERTION_BYTES) {
      return `assertion too large (max ${MAX_ASSERTION_BYTES} bytes)`;
    }
    if (exceedsDepth(assertion, MAX_ASSERTION_DEPTH)) {
      return `assertion nested too deeply (max ${MAX_ASSERTION_DEPTH})`;
    }
    if (assertion.label.startsWith('c2pa.actions')) {
      actionsCount += 1;
    }
  }

  // Two actions assertions in one signed manifest are contradictory and resolve
  // verifier-dependently. This is a correctness invariant, not a policy.
  if (actionsCount > 1) {
    return 'at most one c2pa.actions assertion is allowed';
  }

  if (REQUIRE_AI_MARKING && !firstActionSourceTypes(assertions).includes(AI_MARKING)) {
    return 'this service is configured to sign only trainedAlgorithmicMedia markings';
  }

  return null;
}

/** digitalSourceType of the FIRST action of each actions assertion. */
function firstActionSourceTypes(assertions) {
  const types = [];

  for (const assertion of Array.isArray(assertions) ? assertions : []) {
    if (!assertion || typeof assertion !== 'object') continue;
    if (typeof assertion.label !== 'string' || !assertion.label.startsWith('c2pa.actions')) continue;

    const first = assertion.data?.actions?.[0];
    if (first && typeof first.digitalSourceType === 'string') {
      types.push(first.digitalSourceType);
    }
  }

  return types;
}

/** Every digitalSourceType present, for the audit record. */
function allSourceTypes(assertions) {
  const types = new Set();

  for (const assertion of Array.isArray(assertions) ? assertions : []) {
    for (const action of assertion?.data?.actions ?? []) {
      if (action && typeof action.digitalSourceType === 'string') {
        types.add(action.digitalSourceType);
      }
    }
  }

  return [...types];
}

// --- SPEC-012: audit logging ------------------------------------------------

// Salt the token digest so a weak token cannot be brute-forced out of a
// token_id. Per-process by default, which is enough for AC6 (two requests with
// the same token correlate); set the env var to keep ids stable across
// restarts. Never logged.
const TOKEN_ID_SALT = process.env.CONTENTAUTH_TOKEN_ID_SALT || crypto.randomBytes(16).toString('hex');

const tokenId = (token) => crypto.createHash('sha256')
  .update(TOKEN_ID_SALT).update(String(token)).digest('hex').slice(0, 12);

const sha256 = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

/** Caller-supplied strings are capped, so nobody can write unbounded data into an operator's log. */
const cap = (value, limit = 256) => (typeof value === 'string' ? value.slice(0, limit) : undefined);

// Once records have been lost, that stays visible until the process restarts: a
// later successful write does not clear it. The risk was never "one write
// failed", it was "we cannot tell that our records are incomplete".
let auditDegraded = false;

/**
 * Write one audit record (SPEC-012). Fail-open with escalation: a logging
 * outage must not become a signing outage — that would hand anyone who can
 * break the write a lever to stop all signing — but the loss is surfaced on
 * /health rather than swallowed.
 */
function audit(record) {
  const line = JSON.stringify(record) + '\n';
  try {
    process.stdout.write(line);
  } catch {
    auditDegraded = true;
    try {
      process.stderr.write(line);
    } catch {
      // Nothing left to try; /health carries the flag.
    }
  }
}

process.stdout.on('error', () => { auditDegraded = true; });

// --- SPEC-015: rate limiting and concurrency bounds -------------------------
// On by default: a protection that ships off is one nobody turns on. Setting a
// limit to 0 disables it explicitly, and /health reports that, so an
// unprotected instance is visible rather than assumed safe.
const MAX_CONCURRENT_SIGNS = Number(process.env.MAX_CONCURRENT_SIGNS ?? 4);
const RATE_LIMIT_REQUESTS = Number(process.env.RATE_LIMIT_REQUESTS ?? 60);
const RATE_LIMIT_WINDOW_MS = Number(process.env.RATE_LIMIT_WINDOW_MS ?? 60_000);
// Slightly above the PHP client's own 10s request timeout (SPEC-008), so in
// normal operation the client gives up first and this only reclaims slots held
// by something that is not our client.
const REQUEST_TIMEOUT_MS = Number(process.env.REQUEST_TIMEOUT_MS ?? 15_000);
const HEADERS_TIMEOUT_MS = Number(process.env.HEADERS_TIMEOUT_MS ?? 10_000);

let inFlight = 0;

// Fixed window per token_id — the identifier SPEC-012 already derives, so this
// introduces no new way of naming a caller. Only authenticated requests reach
// here, so the map is bounded by the number of valid tokens; entries are pruned
// as they expire rather than accumulating.
const buckets = new Map();

/** @returns {number|null} seconds to wait, or null when within budget. */
function rateLimited(id, now) {
  if (RATE_LIMIT_REQUESTS <= 0) return null;

  const bucket = buckets.get(id);

  if (bucket === undefined || bucket.resetAt <= now) {
    buckets.set(id, { count: 1, resetAt: now + RATE_LIMIT_WINDOW_MS });

    for (const [key, value] of buckets) {
      if (value.resetAt <= now) buckets.delete(key);
    }

    return null;
  }

  bucket.count += 1;

  return bucket.count > RATE_LIMIT_REQUESTS
    ? Math.max(1, Math.ceil((bucket.resetAt - now) / 1000))
    : null;
}

// Constant-time bearer-token check (SPEC-009 #4): compare fixed-length SHA-256
// digests so timing does not leak how much of the token matched, and unequal
// lengths do not throw.
function tokenMatches(token, key) {
  if (!key) return false;
  const a = crypto.createHash('sha256').update(String(token)).digest();
  const b = crypto.createHash('sha256').update(String(key)).digest();
  return crypto.timingSafeEqual(a, b);
}

// Strict base64 (our PHP client always pads) — reject obvious garbage as a
// client error rather than letting it become a 500 (SPEC-009 #6).
function isValidBase64(value) {
  return typeof value === 'string' && value.length > 0 && value.length % 4 === 0 && /^[A-Za-z0-9+/]*={0,2}$/.test(value);
}

/**
 * The configured body limit in bytes, so `GET /health` can report a number an
 * operator can compute with rather than a string like "20mb" (SPEC-017 AC3).
 */
function maxBodyBytes() {
  const match = /^(\d+(?:\.\d+)?)\s*(b|kb|mb|gb)?$/i.exec(String(MAX_BODY).trim());
  if (!match) return null;

  const units = { b: 1, kb: 1024, mb: 1024 ** 2, gb: 1024 ** 3 };

  return Math.round(Number(match[1]) * (units[(match[2] ?? 'b').toLowerCase()] ?? 1));
}

const app = express();

// One correlation id per request, echoed to the client so a caller can quote it
// (SPEC-012 AC3). It is what makes a generic error message acceptable: the
// detail is recorded, and this is the key to find it by.
//
// This runs BEFORE the body parser deliberately. It used to run after, so a
// request that failed to parse -- an oversized body, malformed JSON -- was
// answered with no correlation id at all, which is precisely when a caller
// most needs one (SPEC-017 AC2).
app.use((req, res, next) => {
  req.cid = crypto.randomUUID();
  res.setHeader('X-Correlation-Id', req.cid);
  next();
});

app.use(express.json({ limit: MAX_BODY }));

/**
 * Body-parser failures (SPEC-017 AC2/AC4).
 *
 * Without this, an oversized body gets express's default error page and a
 * stack trace, and nothing is recorded. The refusal happens inside the parser,
 * before any route, so it can only be audited from here.
 *
 * Note what the record cannot say: auth runs after the parser, so there is no
 * verified caller to attribute this to. Recording an unverified token would let
 * anyone write arbitrary token_ids into the log, so the field is simply absent.
 */
app.use((err, req, res, next) => {
  if (!err || !err.type) return next(err);

  const tooLarge = err.type === 'entity.too.large';
  const status = tooLarge ? 413 : 400;
  const reason = tooLarge
    ? `request body too large (max ${MAX_BODY})`
    : 'request body is not valid JSON';

  audit({
    ts: new Date().toISOString(),
    cid: req.cid,
    event: req.path === '/v1/read' ? 'read' : 'sign',
    outcome: 'rejected',
    reason,
    // Deliberately no token_id and no body: see above.
  });

  return res.status(status).json({ error: reason, cid: req.cid });
});

// Bearer-token auth on /v1/* — mirrors the upstream service.
app.use('/v1', (req, res, next) => {
  const header = req.headers['authorization'] ?? '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : '';
  if (!tokenMatches(token, API_KEY)) {
    return res.status(401).json({ error: 'Unauthorized', cid: req.cid });
  }
  // Kept only to derive the one-way token_id for audit records; never logged.
  req.token = token;
  next();
});

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    signing_alg: SIGN_ALG,
    timestamping: Boolean(TSA_URL),
    trust_verification: Boolean(trustSettings),
    require_ai_marking: REQUIRE_AI_MARKING,
    audit_degraded: auditDegraded,
    // SPEC-015 AC4: signing does not block the event loop, so this endpoint
    // stays fast however saturated the service is — which is exactly why it has
    // to say how busy it is. Otherwise an orchestrator cannot tell a saturated
    // instance from an idle one and keeps routing to it.
    in_flight: inFlight,
    limits: {
      max_concurrent_signs: MAX_CONCURRENT_SIGNS,
      rate_limit_requests: RATE_LIMIT_REQUESTS,
      rate_limit_window_ms: RATE_LIMIT_WINDOW_MS,
      request_timeout_ms: REQUEST_TIMEOUT_MS,
      headers_timeout_ms: HEADERS_TIMEOUT_MS,
      max_body_bytes: maxBodyBytes(),
    },
  });
});

/**
 * POST /v1/sign
 * Body: { content (base64), mime_type, creator_name?, extra_assertions? }
 * Resp: { signed_content (base64), manifest_url: null }
 */
/**
 * SPEC-015: refuse rather than queue. Queueing looks friendlier under a burst,
 * but the PHP client bounds a request at 10s (SPEC-008), so a queued request
 * can time out client-side while still holding a slot here — the caller has
 * given up and the service is still paying for it. Refusing keeps both ends
 * agreeing about what happened, and Retry-After says what to do next.
 */
app.post('/v1/sign', (req, res, next) => {
  const refuse = (reason, retryAfter) => {
    audit({
      ts: new Date().toISOString(),
      cid: req.cid,
      event: 'sign',
      outcome: 'rejected',
      token_id: tokenId(req.token ?? ''),
      reason,
    });

    return res.status(429)
      .set('Retry-After', String(retryAfter))
      .json({ error: reason, cid: req.cid });
  };

  const wait = rateLimited(tokenId(req.token ?? ''), Date.now());
  if (wait !== null) {
    return refuse('rate limit exceeded', wait);
  }

  if (MAX_CONCURRENT_SIGNS > 0 && inFlight >= MAX_CONCURRENT_SIGNS) {
    return refuse('too many signing requests in flight', 1);
  }

  // Release the slot however the response ends — handler return, thrown error,
  // or a client that hung up mid-request.
  inFlight += 1;
  res.on('close', () => { inFlight -= 1; });

  return next();
});

app.post('/v1/sign', async (req, res) => {
  const { content, mime_type, creator_name, extra_assertions } = req.body ?? {};

  // Every refusal is recorded and answered the same way: the reason names the
  // violated constraint (our own wording — never library internals, never an
  // echo of the payload) and the response carries the correlation id.
  const reject = (reason) => {
    audit({
      ts: new Date().toISOString(),
      cid: req.cid,
      event: 'sign',
      outcome: 'rejected',
      token_id: tokenId(req.token ?? ''),
      reason,
      mime_type: cap(mime_type, 64),
    });

    return res.status(400).json({ error: reason, cid: req.cid });
  };

  if (!content || !mime_type) return reject('content and mime_type are required');
  if (!SUPPORTED_MIME.has(mime_type)) {
    return reject(`unsupported mime_type "${cap(mime_type, 64)}" (supported: image/png, image/jpeg)`);
  }
  if (!isValidBase64(content)) return reject('content is not valid base64');

  if (creator_name !== undefined) {
    if (typeof creator_name !== 'string') return reject('creator_name must be a string');
    if (creator_name.length > MAX_CREATOR_NAME) {
      return reject(`creator_name too long (max ${MAX_CREATOR_NAME} characters)`);
    }
  }

  const assertionProblem = rejectAssertions(extra_assertions);
  if (assertionProblem !== null) return reject(assertionProblem);

  const fileBuffer = Buffer.from(content, 'base64');

  const manifestDefinition = {
    claim_generator_info: [
      { name: creator_name || 'c2pa-spike-signer', version: '0.1.0' },
    ],
    format: mime_type,
    assertions: Array.isArray(extra_assertions) ? extra_assertions : [],
  };

  // GOTCHA: builder.sign() RETURNS the C2PA manifest store bytes (JUMBF), NOT
  // the signed asset. The signed ASSET is written to the destination. So we
  // sign to a temp file and read that file back to get the signed image.
  const tmp = path.join(os.tmpdir(), `sign-${crypto.randomBytes(8).toString('hex')}`);
  try {
    const builder = Builder.withJson(manifestDefinition);
    const source = { buffer: fileBuffer, mimeType: mime_type };
    const dest = { path: tmp };

    if (TSA_URL) {
      // Timestamping needs the ASYNC path: fetching the RFC 3161 token is an
      // HTTP call the sync builder.sign() cannot make ("the sync http resolver
      // is not implemented"). We sign via a CallbackSigner whose callback signs
      // with the local key. A TSA failure rejects here -> the catch returns 5xx
      // (SPEC-007 AC5: fail closed, never an untimestamped fallback).
      const hash = hashForAlg(SIGN_ALG);
      const privateKeyObject = crypto.createPrivateKey({ key: privateKey, format: 'pem' });
      const signer = CallbackSigner.newSigner(
        { alg: SIGN_ALG, certs: [certificate], reserveSize: 20000, tsaUrl: TSA_URL, directCoseHandling: false },
        async (data) => {
          const s = crypto.createSign(hash);
          s.update(data);
          s.end();
          return s.sign(privateKeyObject);
        },
      );
      await builder.signAsync(signer, source, dest);
    } else {
      // No TSA: the simple synchronous local signer (unchanged behaviour).
      const signer = LocalSigner.newSigner(certificate, privateKey, SIGN_ALG);
      builder.sign(signer, source, dest);
    }

    const signedAsset = fs.readFileSync(tmp);

    audit({
      ts: new Date().toISOString(),
      cid: req.cid,
      event: 'sign',
      outcome: 'signed',
      token_id: tokenId(req.token ?? ''),
      mime_type: mime_type,
      input_sha256: sha256(fileBuffer),
      input_bytes: fileBuffer.length,
      output_sha256: sha256(signedAsset),
      creator_name: cap(creator_name, MAX_CREATOR_NAME),
      assertion_labels: (Array.isArray(extra_assertions) ? extra_assertions : [])
        .map((a) => cap(a?.label, 128)).filter(Boolean),
      digital_source_types: allSourceTypes(extra_assertions),
      timestamped: Boolean(TSA_URL),
    });

    res.json({
      signed_content: signedAsset.toString('base64'),
      manifest_url: null,
    });
  } catch (err) {
    // The detail goes to the record, never to the client: c2pa and fs errors
    // name the temp file and library internals (SPEC-012 AC4).
    audit({
      ts: new Date().toISOString(),
      cid: req.cid,
      event: 'sign',
      outcome: 'failed',
      token_id: tokenId(req.token ?? ''),
      reason: cap(String(err && err.message ? err.message : err), 512),
      mime_type: cap(mime_type, 64),
      input_sha256: sha256(fileBuffer),
      input_bytes: fileBuffer.length,
    });

    res.status(500).json({ error: 'signing failed', cid: req.cid });
  } finally {
    fs.rm(tmp, { force: true }, () => {});
  }
});

/**
 * POST /v1/read
 * Body: { content (base64), mime_type }
 * Resp: parsed manifest store JSON, or {} if no C2PA data.
 */
app.post('/v1/read', async (req, res) => {
  const { content, mime_type } = req.body ?? {};
  if (!content || !mime_type) {
    return res.status(400).json({ error: 'content and mime_type are required', cid: req.cid });
  }
  if (!SUPPORTED_MIME.has(mime_type)) {
    return res.status(400).json({
      error: `unsupported mime_type "${cap(mime_type, 64)}" (supported: image/png, image/jpeg)`,
      cid: req.cid,
    });
  }
  if (!isValidBase64(content)) {
    return res.status(400).json({ error: 'content is not valid base64', cid: req.cid });
  }
  const fileBuffer = Buffer.from(content, 'base64');
  try {
    // SPEC-014: with trust settings configured, c2pa-rs verifies the signing
    // certificate against the trust list and reports validation_state
    // "Trusted"; without them it stops at "Valid" + signingCredential.untrusted.
    const reader = trustSettings
      ? await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type }, trustSettings)
      : await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type });
    // SPEC-010: an asset with no C2PA manifest yields a null reader. Absence is
    // an empty manifest store (HTTP 200), never a 500 — the PHP client parses
    // {} into an empty ManifestReport (hasManifest() === false).
    if (!reader) {
      return res.json({});
    }
    const json = reader.json();
    res.json(typeof json === 'string' ? JSON.parse(json) : json);
  } catch (err) {
    // Reading is not an exercise of the signing key, so it gets no audit
    // record — but the same no-leak rule applies to its errors (SPEC-012 AC4).
    audit({
      ts: new Date().toISOString(),
      cid: req.cid,
      event: 'read',
      outcome: 'failed',
      token_id: tokenId(req.token ?? ''),
      reason: cap(String(err && err.message ? err.message : err), 512),
    });

    res.status(500).json({ error: 'read failed', cid: req.cid });
  }
});

// SPEC-015 AC5: a client that opens a request and then stalls must not hold a
// slot indefinitely. Node's defaults are 300s / 60s, long enough to exhaust the
// concurrency cap with a handful of idle sockets.
//
// GOTCHA: `requestTimeout` does NOT cover the case that matters here. Verified
// on node 20.20.2, including in a bare http.createServer with no express: a
// client that sends complete headers announcing a Content-Length and then never
// sends the body is left open indefinitely, whatever requestTimeout says.
// `server.setTimeout()` — the socket inactivity timeout — is what actually
// closes it, to the second. Both are set: requestTimeout/headersTimeout bound a
// request that is still trickling in, setTimeout catches one that has stopped.
const server = http.createServer(app);
server.requestTimeout = REQUEST_TIMEOUT_MS;
server.headersTimeout = HEADERS_TIMEOUT_MS;
server.setTimeout(REQUEST_TIMEOUT_MS);

server.listen(PORT, () => {
  console.log(
    `c2pa-spike signer listening on :${PORT} (alg=${SIGN_ALG}, `
    + `timestamping=${Boolean(TSA_URL)}, trust=${Boolean(trustSettings)}, `
    + `max_concurrent=${MAX_CONCURRENT_SIGNS}, rate=${RATE_LIMIT_REQUESTS}/${RATE_LIMIT_WINDOW_MS}ms)`,
  );
});