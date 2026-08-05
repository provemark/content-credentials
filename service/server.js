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
const MAX_BODY = process.env.MAX_BODY_SIZE ?? '50mb';
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

const app = express();
app.use(express.json({ limit: MAX_BODY }));

// Bearer-token auth on /v1/* — mirrors the upstream service.
app.use('/v1', (req, res, next) => {
  const header = req.headers['authorization'] ?? '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : '';
  if (!tokenMatches(token, API_KEY)) {
    return res.status(401).json({ error: 'Unauthorized' });
  }
  next();
});

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    signing_alg: SIGN_ALG,
    timestamping: Boolean(TSA_URL),
    trust_verification: Boolean(trustSettings),
  });
});

/**
 * POST /v1/sign
 * Body: { content (base64), mime_type, creator_name?, extra_assertions? }
 * Resp: { signed_content (base64), manifest_url: null }
 */
app.post('/v1/sign', async (req, res) => {
  const { content, mime_type, creator_name, extra_assertions } = req.body ?? {};
  if (!content || !mime_type) {
    return res.status(400).json({ error: 'content and mime_type are required' });
  }
  if (!SUPPORTED_MIME.has(mime_type)) {
    return res.status(400).json({ error: `unsupported mime_type "${mime_type}" (supported: image/png, image/jpeg)` });
  }
  if (!isValidBase64(content)) {
    return res.status(400).json({ error: 'content is not valid base64' });
  }

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
    res.json({
      signed_content: signedAsset.toString('base64'),
      manifest_url: null,
    });
  } catch (err) {
    console.error('[sign] error:', err);
    res.status(500).json({ error: String(err && err.message ? err.message : err) });
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
    return res.status(400).json({ error: 'content and mime_type are required' });
  }
  if (!SUPPORTED_MIME.has(mime_type)) {
    return res.status(400).json({ error: `unsupported mime_type "${mime_type}" (supported: image/png, image/jpeg)` });
  }
  if (!isValidBase64(content)) {
    return res.status(400).json({ error: 'content is not valid base64' });
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
    console.error('[read] error:', err);
    res.status(500).json({ error: String(err && err.message ? err.message : err) });
  }
});

app.listen(PORT, () => {
  console.log(`c2pa-spike signer listening on :${PORT} (alg=${SIGN_ALG}, timestamping=${Boolean(TSA_URL)})`);
});