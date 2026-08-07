<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Tests\Unit\Property;

use Eris\Generator;
use Eris\Generators;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * Shared generators for the property-based suite.
 *
 * These describe the *input space* the properties quantify over. They are
 * deliberately hostile: real callers pass unicode display names, versions from
 * upstream model metadata, and MIME strings straight off an HTTP header.
 */
final class Gen
{
    /** Every supported asset format — derived from the enum (SPEC-021). */
    public static function mediaType(): Generator
    {
        return Generators::elements(MediaType::cases());
    }

    /**
     * Non-blank software-agent names, including the awkward ones: unicode,
     * emoji, embedded quotes/newlines, JSON-ish and markup-ish payloads, and
     * a long name. Blank names are excluded because ManifestBuilder::build()
     * rejects them by contract (SPEC-001 AC4) — that path has its own test.
     */
    public static function softwareAgentName(): Generator
    {
        $pieces = Generators::elements([
            'A', 'é', '漢字', '🎨', '"quoted"', "line\nbreak", '  ',
            '</assertion>', '{"json":1}', '0', ' padded ', str_repeat('x', 300),
        ]);

        return Generators::suchThat(
            static fn (string $name): bool => trim($name) !== '',
            Generators::map(
                static fn (array $parts): string => implode('', $parts),
                Generators::vector(3, $pieces),
            ),
        );
    }

    /** An optional version string — absent as often as present. */
    public static function optionalVersion(): Generator
    {
        return Generators::oneOf(
            Generators::constant(null),
            Generators::elements(['1.0.0', '3.1.0', '2026.07', 'v2-beta', '', '0']),
        );
    }

    /**
     * Assertions that are NOT an AI marking: other labels, actions without a
     * digitalSourceType, and shapes with no actions key at all. Used to prove
     * the reader picks the real marking out of arbitrary noise.
     */
    public static function junkAssertion(): Generator
    {
        return Generators::associative([
            'label' => Generators::elements([
                'c2pa.hash.data',
                'stds.schema-org',
                'com.example.custom',
                'c2pa.actions.v2',      // right label, wrong content — the tricky case
                'c2pa.actions.custom',
            ]),
            'data' => Generators::elements([
                ['actions' => [['action' => 'c2pa.opened']]],           // action, no digitalSourceType
                ['actions' => [['action' => 'c2pa.edited', 'x' => 1]]],
                ['actions' => 'not-an-array'],                          // malformed
                ['actions' => ['scalar-instead-of-action']],            // malformed member
                ['nonsense' => 'zzz'],                                  // no actions at all
                [],                                                     // empty
            ]),
        ]);
    }

    /** Whitespace and `;`-parameter noise that MIME normalisation must absorb (SPEC-001 D2). */
    public static function mimeNoise(): Generator
    {
        return Generators::associative([
            'lead' => Generators::elements(['', ' ', '  ', "\t", "\n"]),
            'param' => Generators::elements(['', ';charset=binary', '; charset=binary', ';q=0.9', ';a=1;b=2', ';']),
            'trail' => Generators::elements(['', ' ', "\t"]),
        ]);
    }
}
