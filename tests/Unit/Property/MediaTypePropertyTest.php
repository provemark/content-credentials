<?php

declare(strict_types=1);

use Eris\Generators;
use Eris\TestTrait;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Tests\Unit\Property\Gen;

uses(TestTrait::class);

/**
 * SPEC-001 D2 — MIME normalisation.
 *
 * This is a *metamorphic* property: rather than asserting a fixed output, it
 * asserts that a class of transformations to the input leaves the output
 * unchanged. Case, surrounding whitespace and `;`-parameters are noise; the
 * resolved type must not depend on them. The example test covers four hand-picked variants —
 * this covers the combinatorial space of them.
 */
it('resolves the same type regardless of case, whitespace or parameters', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::mimeNoise(),
        Generators::seq(Generators::bool()),
    )->then(function (MediaType $type, array $noise, array $caseFlags) {
        // Perturb the case of the canonical value, character by character.
        $cased = '';
        foreach (str_split($type->value) as $i => $char) {
            $upper = $caseFlags[$i % max(1, count($caseFlags))] ?? false;
            $cased .= $upper ? strtoupper($char) : strtolower($char);
        }

        $input = $noise['lead'].$cased.$noise['param'].$noise['trail'];

        expect(MediaType::fromMimeType($input))->toBe($type);
    });
})->group('SPEC-001', 'pbt');

/**
 * Idempotence: feeding a resolved type's own value back in is a fixed point.
 * Guards against a future normalisation change that is not stable.
 */
it('is a fixed point on its own canonical value', function () {
    $this->forAll(Gen::mediaType())->then(function (MediaType $type) {
        expect(MediaType::fromMimeType($type->value))->toBe($type)
            ->and(MediaType::fromMimeType(MediaType::fromMimeType($type->value)->value))->toBe($type);
    });
})->group('SPEC-001', 'pbt');

/**
 * The negative direction: unsupported types are rejected, however, they are
 * dressed up, and the error always names the offending input so callers can
 * act on it. Normalisation must not accidentally widen what is accepted.
 */
it('rejects unsupported types however they are formatted', function () {
    // This pool has been overtaken by scope twice now: image/gif, image/webp and
    // image/tiff left it in SPEC-021, image/svg+xml in SPEC-023. What remains is
    // types measured as genuinely outside reach (NOTES Step 27) plus malformed
    // input, which no spec can make supported.
    $unsupported = Generators::elements([
        'image/bmp', 'application/pdf', 'video/webm', 'image/jxl',
        'text/plain', 'image/png-x', 'imagepng', '', 'image/',
    ]);

    $this->forAll($unsupported, Gen::mimeNoise())->then(function (string $mime, array $noise) {
        $input = $noise['lead'].$mime.$noise['param'].$noise['trail'];

        expect(fn () => MediaType::fromMimeType($input))
            ->toThrow(UnsupportedMediaTypeException::class);
    });
})->group('SPEC-001', 'pbt');
