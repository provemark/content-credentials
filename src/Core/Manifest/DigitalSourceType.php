<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

/**
 * IPTC DigitalSourceType values. SPEC-001 emits only trainedAlgorithmicMedia
 * (the EU AI Act, Article 50 marking for AI-generated content); further values
 * are deferred to a later spec.
 */
enum DigitalSourceType: string
{
    case TrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
}
