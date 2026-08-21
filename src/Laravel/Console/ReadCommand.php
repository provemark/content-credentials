<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Illuminate\Console\Command;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\ReaderFactory;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ReadCommand extends Command
{
    use InfersMediaType;

    /** @var string */
    protected $signature = '';

    /** @var string */
    protected $description = '';

    public function __construct()
    {
        // Derived from InfersMediaType::EXTENSIONS rather than restated
        // (SPEC-032 AC1); "asset", not "image", since SPEC-021 and SPEC-023.
        $this->signature = sprintf(
            'content-credentials:read {file : Path to the signed asset (%s)}',
            $this->supportedExtensions(),
        );

        $this->description = 'Read and report the C2PA credential of an asset.';

        parent::__construct();
    }

    public function handle(ReaderInterface $reader, ReaderFactory $factory): int
    {
        $file = $this->argument('file');
        if (! is_string($file)) {
            $this->error('file path is required.');

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error('File not found: '.OutputFormatter::escape($file));

            return self::FAILURE;
        }

        try {
            $mediaType = $this->mediaTypeFromPath($file);
        } catch (UnsupportedMediaTypeException $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        $bytes = file_get_contents($file);
        if ($bytes === false) {
            $this->error('Cannot read file: '.OutputFormatter::escape($file));

            return self::FAILURE;
        }

        $report = $reader->read(new Asset($bytes, $mediaType));
        $signer = $report->signer();

        // SPEC-020 AC6: two c2pa-rs versions are in play — 0.89.0 in the
        // extension, 0.90.15 in the service — so which engine produced this report
        // has to be visible where someone is already standing when they wonder.
        // Everything below that came out of a manifest is escaped before it is
        // written. `line()` goes through Symfony's OutputFormatter, which reads
        // `<...>` as markup — and a manifest is untrusted input, which CLAUDE.md
        // requires be treated as such wherever it is parsed.
        //
        // Measured 2026-08-13: an issuer of `Acme <fg=black;bg=black>` renders
        // every FOLLOWING line black-on-black, so a signer name chosen by
        // whoever produced the asset can hide the `isTrusted` verdict from the
        // operator reading it. escape() neutralises the angle brackets and
        // leaves ordinary names byte-identical, so AC3 still sees the issuer.
        // AC8: the engine AND what the configuration asked for. `mode()` alone
        // resolves `auto`, so `extension` could mean chosen or detected — and
        // "was this deliberate?" is the question a bug report about engine
        // drift starts from.
        //
        // Always annotated, never only when it was auto. If the suffix appeared
        // conditionally, an explicit `extension` and an older build's output
        // would be identical, and absence would mean two different things.
        $this->line('reader             : '.OutputFormatter::escape(
            sprintf('%s (configured: %s)', $factory->mode(), $factory->configuredMode()),
        ));
        $this->line('hasManifest        : '.($report->hasManifest() ? 'true' : 'false'));
        $this->line('isAiGenerated      : '.($report->isAiGenerated() ? 'true' : 'false'));
        $this->line('digitalSourceTypes : '.self::fromManifest(
            implode(', ', $report->digitalSourceTypes()) ?: '(none)',
        ));
        $this->line('signer             : '.($signer !== null
            ? self::fromManifest(
                $signer->issuer.($signer->commonName !== null ? ' / CN='.$signer->commonName : ''),
            )
            : '(none)'));
        $state = $report->validationState();
        $this->line('validationState    : '.($state !== null ? $state->value : '(none)'));
        $this->line('isSignatureValid   : '.($report->isSignatureValid() ? 'true' : 'false'));
        $this->line('isTrusted          : '.($report->isTrusted() ? 'true' : 'false'));
        // SPEC-006 AC7. Reported as a STATE rather than as a bare `true`,
        // because the criterion forbids exactly that: hasTimestamp() means the
        // RFC 3161 token is present and structurally parseable, and trust of
        // the timestamp authority's own certificate is a separate concern this
        // package does not check (SPEC-007 D3, docs/production.md). A bare
        // `true` sitting under `isTrusted: false` reads as "the time is proven",
        // which is the absence-of-evidence-as-trust conflation SPEC-013 exists
        // to prevent.
        $this->line('timestamp          : '.($report->hasTimestamp()
            ? 'present (unverified)'
            : 'absent'));

        return self::SUCCESS;
    }

    /**
     * Neutralise a value that came out of a manifest, for terminal output.
     *
     * SPEC-006 AC8. Two separate hazards, and `escape()` only covers one:
     *
     * - **Symfony markup.** `line()` goes through `OutputFormatter`, which
     *   reads `<...>` as markup. Closed on 2026-08-13 by `escape()`.
     * - **Control characters.** `escape()` is one `preg_replace` over `<`, `>`
     *   and a trailing backslash, so a raw `ESC` (0x1B) survives it in both the
     *   decorated and the undecorated formatter. Measured through the running
     *   service: a `digitalSourceType` ending in `ESC[30;40m` round-trips
     *   through c2pa-rs intact, and — because this line prints ABOVE the
     *   verdict lines and nothing writes an SGR reset — colours `isTrusted`
     *   out of the operator's view. `CR` and backspace overwrite it instead.
     *
     * Stripped, not escaped: the printable tail is kept (`ESC[30;40m` becomes
     * the literal `[30;40m`), so the value stays legible and AC3 still sees the
     * issuer, while nothing in it can move a cursor. `\n` and `\t` are outside
     * the class on purpose — they are the formatting this report itself uses.
     *
     * This belongs here and not in `ManifestStoreParser`: SPEC-033 AC4 requires
     * accessors to return the value byte-for-byte, so filtering at the reader
     * would violate an implemented criterion and change what every caller sees,
     * not just what this command prints.
     */
    private static function fromManifest(string $value): string
    {
        // C0 except tab and newline, DEL, and the C1 range in its UTF-8 form.
        $stripped = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $value);

        return OutputFormatter::escape($stripped ?? '');
    }
}
