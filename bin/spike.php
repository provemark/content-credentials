<?php

/**
 * C2PA signing spike — PHP client.
 *
 * Proves the end-to-end chain with PHP as the client:
 *   1. read tests/fixture.png
 *   2. build the manifest for an AI-GENERATED image (EU AI Act Art. 50 marking:
 *      c2pa.actions.v2 -> c2pa.created with digitalSourceType trainedAlgorithmicMedia)
 *   3. POST it to the signing service /v1/sign
 *   4. write out/signed.png
 *   5. read the manifest back via /v1/read and print who signed it + assertions
 *
 * One plain script, no framework, no package structure. curl only.
 * PHP 8.3+ (developed on 8.5).
 *
 * Config via env (with sensible defaults):
 *   SIGNER_URL       default http://localhost:3000
 *   CONTENTAUTH_API_KEY  Bearer token; must match the service
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$inputPng = "$root/tests/fixture.png";
$outputPng = "$root/out/signed.png";

$signerUrl = rtrim(getenv('SIGNER_URL') ?: 'http://localhost:3000', '/');
$apiKey = getenv('CONTENTAUTH_API_KEY') ?: '';

if ($apiKey === '') {
    fwrite(STDERR, "ERROR: CONTENTAUTH_API_KEY is not set (must match the service).\n");
    exit(1);
}
if (! is_file($inputPng)) {
    fwrite(STDERR, "ERROR: input fixture not found: $inputPng\n");
    exit(1);
}

// The IPTC DigitalSourceType URI for AI/ML-generated content. This is the
// value the EU AI Act Article 50 transparency marking relies on.
const AI_TRAINED_ALGORITHMIC_MEDIA =
    'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

/**
 * POST a JSON body to the signing service and return the decoded response.
 */
function postJson(string $url, string $apiKey, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer '.$apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // note: curl_close() is a no-op / deprecated on PHP 8.5

    if ($errno !== 0) {
        fwrite(STDERR, "ERROR: curl to $url failed: $error\n");
        exit(1);
    }
    $decoded = json_decode((string) $raw, true);
    if ($status >= 400) {
        $msg = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : $raw;
        fwrite(STDERR, "ERROR: $url returned HTTP $status: $msg\n");
        exit(1);
    }
    if (! is_array($decoded)) {
        fwrite(STDERR, "ERROR: $url returned non-JSON: $raw\n");
        exit(1);
    }

    return $decoded;
}

// --- 1. read fixture -------------------------------------------------------
$bytes = file_get_contents($inputPng);
echo "→ read fixture: $inputPng (".strlen($bytes)." bytes)\n";

// --- 2. build the AI-generated-image manifest ------------------------------
// The manifest's actions assertion marks the image as AI-generated. The first
// action MUST be c2pa.created (C2PA claim-v2 requirement) and carries the
// digitalSourceType that is the actual EU AI Act Article 50 marking.
$aiActionsAssertion = [
    'label' => 'c2pa.actions.v2',
    'data' => [
        'actions' => [
            [
                'action' => 'c2pa.created',
                'digitalSourceType' => AI_TRAINED_ALGORITHMIC_MEDIA,
                'softwareAgent' => [
                    'name' => 'ACME GenAI Image Model',
                    'version' => '3.1.0',
                ],
            ],
        ],
    ],
];

$signBody = [
    'content' => base64_encode($bytes),
    'mime_type' => 'image/png',
    'creator_name' => 'C2PA Spike (PHP client)',
    'extra_assertions' => [$aiActionsAssertion],
];

// --- 3. POST to /v1/sign ---------------------------------------------------
echo "→ POST $signerUrl/v1/sign …\n";
$signResp = postJson("$signerUrl/v1/sign", $apiKey, $signBody);
if (! isset($signResp['signed_content'])) {
    fwrite(STDERR, "ERROR: response missing signed_content\n");
    exit(1);
}
$signedBytes = base64_decode($signResp['signed_content'], true);
if ($signedBytes === false) {
    fwrite(STDERR, "ERROR: signed_content is not valid base64\n");
    exit(1);
}

// --- 4. write out/signed.png ----------------------------------------------
if (! is_dir(dirname($outputPng))) {
    mkdir(dirname($outputPng), 0755, true);
}
file_put_contents($outputPng, $signedBytes);
echo "✓ wrote signed image: $outputPng (".strlen($signedBytes)." bytes)\n";

// --- 5. read the manifest back and report ---------------------------------
echo "→ POST $signerUrl/v1/read (verify) …\n";
$readResp = postJson("$signerUrl/v1/read", $apiKey, [
    'content' => base64_encode($signedBytes),
    'mime_type' => 'image/png',
]);

$active = $readResp['active_manifest'] ?? null;
$manifests = $readResp['manifests'] ?? [];
$manifest = ($active !== null && isset($manifests[$active])) ? $manifests[$active] : null;

echo "\n===== MANIFEST SUMMARY =====\n";
if ($manifest === null) {
    echo "(no active manifest found in read-back)\n";
    echo json_encode($readResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$sig = $manifest['signature_info'] ?? [];
echo 'Signed by : '.($sig['issuer'] ?? '(unknown)')
   .(isset($sig['common_name']) ? ' / CN='.$sig['common_name'] : '')
   .(isset($sig['alg']) ? '  [alg '.$sig['alg'].']' : '')."\n";

$claimGen = $manifest['claim_generator_info'][0]['name'] ?? '(unknown)';
echo "Claim gen : $claimGen\n";

echo "Assertions:\n";
$foundAiMarking = false;
foreach (($manifest['assertions'] ?? []) as $a) {
    $label = $a['label'] ?? '(no label)';
    echo "  - $label\n";
    foreach (($a['data']['actions'] ?? []) as $act) {
        $dst = $act['digitalSourceType'] ?? null;
        echo '      action='.($act['action'] ?? '?')
           .($dst ? "  digitalSourceType=$dst" : '')."\n";
        if ($dst === AI_TRAINED_ALGORITHMIC_MEDIA) {
            $foundAiMarking = true;
        }
    }
}

echo "\nEU AI Act Art.50 marking (trainedAlgorithmicMedia): "
   .($foundAiMarking ? 'PRESENT ✓' : 'MISSING ✗')."\n";
echo 'Validation: '.json_encode($readResp['validation_status'] ?? $readResp['validation_results'] ?? [])."\n";
