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
const { LocalSigner, Builder, Reader } = require('@contentauth/c2pa-node');

const PORT = parseInt(process.env.PORT ?? '3000', 10);
const API_KEY = process.env.CONTENTAUTH_API_KEY ?? '';
const SIGN_ALG = (process.env.CONTENTAUTH_SIGN_ALG ?? 'es256').toLowerCase();
const CERT_PATH = process.env.SIGNING_CERT_PATH;
const KEY_PATH = process.env.SIGNING_KEY_PATH;
const MAX_BODY = process.env.MAX_BODY_SIZE ?? '50mb';

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

const app = express();
app.use(express.json({ limit: MAX_BODY }));

// Bearer-token auth on /v1/* — mirrors the upstream service.
app.use('/v1', (req, res, next) => {
  const header = req.headers['authorization'] ?? '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : '';
  if (!API_KEY || token !== API_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }
  next();
});

app.get('/health', (_req, res) => {
  res.json({ status: 'ok', signing_alg: SIGN_ALG });
});

/**
 * POST /v1/sign
 * Body: { content (base64), mime_type, creator_name?, extra_assertions? }
 * Resp: { signed_content (base64), manifest_url: null }
 */
app.post('/v1/sign', (req, res) => {
  const { content, mime_type, creator_name, extra_assertions } = req.body ?? {};
  if (!content || !mime_type) {
    return res.status(400).json({ error: 'content and mime_type are required' });
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
    const signer = LocalSigner.newSigner(certificate, privateKey, SIGN_ALG);
    const builder = Builder.withJson(manifestDefinition);
    builder.sign(
      signer,
      { buffer: fileBuffer, mimeType: mime_type },
      { path: tmp }
    );
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
  const fileBuffer = Buffer.from(content, 'base64');
  try {
    const reader = await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type });
    const json = reader.json();
    res.json(typeof json === 'string' ? JSON.parse(json) : json);
  } catch (err) {
    console.error('[read] error:', err);
    res.status(500).json({ error: String(err && err.message ? err.message : err) });
  }
});

app.listen(PORT, () => {
  console.log(`c2pa-spike signer listening on :${PORT} (alg=${SIGN_ALG})`);
});