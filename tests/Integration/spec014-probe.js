// SPEC-014 Amendment 1 — start a second service process with a given trust
// document, inside the already-running container, and report what it did.
//
// Runs as `docker exec -i <container> node -e "<this file>"` from
// TrustDocumentShapeTest.php. Input on stdin, one JSON object:
//
//   { "settings": <document, object or raw string>,
//     "asset": <base64 of a signed asset, optional>, "mime": "image/png" }
//
// Output on stdout, one JSON object:
//
//   { "started": bool,      // /health answered: the process is serving
//     "exit": int|null,     // its exit code when it refused to start
//     "output": string,     // everything it wrote to stdout and stderr
//     "read": object|null } // the /v1/read body for `asset`, when given
//
// Why a process and not the HTTP API: AC5 and AC9 are about startup, and AC10
// needs trust documents that cannot coexist in one process. The port is
// overridden so the child never collides with the live service and fails for
// the wrong reason (EADDRINUSE); the document goes to /tmp, the one writable
// path in the read-only container, and is removed afterwards.
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');
const crypto = require('crypto');

// Not 3999 or 3998: AuditLoggingTest leaves a server on 3999 running, and
// SPEC-029/SPEC-043 use 3998. `started` also requires THIS child's own
// listening line, so a stray server on the port cannot answer for it.
const PORT = 3996;
const DEADLINE_MS = 15000;

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const path = `/tmp/spec014-${crypto.randomBytes(6).toString('hex')}.json`;
fs.writeFileSync(path, typeof input.settings === 'string' ? input.settings : JSON.stringify(input.settings));

const child = spawn('node', ['server.js'], {
  cwd: '/app',
  env: { ...process.env, PORT: String(PORT), CONTENTAUTH_TRUST_SETTINGS: path },
  stdio: ['ignore', 'pipe', 'pipe'],
});

let output = '';
let exit = null;
child.stdout.on('data', (d) => { output += d; });
child.stderr.on('data', (d) => { output += d; });
const exited = new Promise((resolve) => child.on('exit', (code) => { exit = code; resolve(); }));

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function healthy() {
  try {
    const res = await fetch(`http://127.0.0.1:${PORT}/health`);
    return res.ok;
  } catch {
    return false;
  }
}

async function main() {
  const until = Date.now() + DEADLINE_MS;
  let started = false;

  while (Date.now() < until && exit === null) {
    if (output.includes(`listening on :${PORT}`) && await healthy()) {
      started = true;
      break;
    }
    await sleep(100);
  }

  let read = null;
  if (started && input.asset) {
    const res = await fetch(`http://127.0.0.1:${PORT}/v1/read`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${process.env.CONTENTAUTH_API_KEY}`,
      },
      body: JSON.stringify({ content: input.asset, mime_type: input.mime ?? 'image/png' }),
    });
    read = { status: res.status, body: await res.json() };
  }

  if (exit === null) {
    child.kill('SIGTERM');
    await Promise.race([exited, sleep(2000)]);
  }
  fs.rmSync(path, { force: true });

  process.stdout.write(JSON.stringify({ started, exit: started ? null : exit, output, read }));
}

main().catch((err) => {
  child.kill('SIGKILL');
  fs.rmSync(path, { force: true });
  process.stdout.write(JSON.stringify({ started: false, exit: -1, output: `${output}\nprobe: ${err.message}`, read: null }));
});
