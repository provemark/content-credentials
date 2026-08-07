#!/usr/bin/env bash
# Authoritative verification of the signed asset with c2patool, WITH trust
# verification enabled against the c2pa-rs test trust anchors.
#
# Usage: bin/verify.sh [path-to-asset]   (defaults to out/signed.png)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ASSET="${1:-$ROOT/out/signed.png}"
C2PATOOL="$ROOT/tools/c2patool"
SETTINGS="$ROOT/certs/c2pa-trust.settings.json"

if [[ ! -x "$C2PATOOL" ]]; then echo "c2patool not found at $C2PATOOL" >&2; exit 1; fi
if [[ ! -f "$ASSET" ]]; then echo "asset not found: $ASSET" >&2; exit 1; fi

# --settings enables verify_trust=true and loads the test trust anchors + EKU
# config, so the test signing cert is reported as trusted (not "untrusted").
"$C2PATOOL" --settings "$SETTINGS" "$ASSET" | python3 -c "
import sys, json
d = json.load(sys.stdin)
am = d['active_manifest']; m = d['manifests'][am]
vr = d.get('validation_results', {}).get('activeManifest', {})
succ = [s['code'] for s in vr.get('success', [])]
fail = [s['code'] for s in vr.get('failure', [])]
status = [s['code'] for s in d.get('validation_status', [])]

sig_valid = 'claimSignature.validated' in succ
trusted   = 'signingCredential.trusted' in succ

# Article 50(2) covers content that is 'generated OR manipulated', and the two
# are different manifests: generation rides on c2pa.created with
# trainedAlgorithmicMedia, manipulation on c2pa.edited with
# compositeWithTrainedAlgorithmicMedia (SPEC-028). Checking only the first
# reported a correctly marked manipulated asset as FAIL.
PREFIX = 'http://cv.iptc.org/newscodes/digitalsourcetype/'
MARKS = {PREFIX + 'trainedAlgorithmicMedia': 'generated',
         PREFIX + 'compositeWithTrainedAlgorithmicMedia': 'manipulated'}

mark = None
for a in m.get('assertions', []):
    for act in a.get('data', {}).get('actions', []):
        if act.get('digitalSourceType') in MARKS:
            mark = MARKS[act['digitalSourceType']]
ai = mark is not None

si = m.get('signature_info', {})
print('Signed by      :', si.get('issuer'), '/ CN=' + str(si.get('common_name')), '[' + str(si.get('alg')) + ']')
print('Signature valid:', 'PASS' if sig_valid else 'FAIL', '(claimSignature.validated)')
print('Cert trusted   :', 'PASS' if trusted else 'FAIL', '(signingCredential.trusted)')
print('AI Art.50 mark :', 'PASS' if ai else 'FAIL', '(' + (mark or 'none') + ')')
print('Remaining status/failures:', status or fail or 'none')
sys.exit(0 if (sig_valid and trusted and ai) else 2)
"