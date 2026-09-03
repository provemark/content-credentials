<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Provemark\ContentCredentials\Core\Reading\Exception\ExtensionMissingException;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException;

/**
 * Decides which `ReaderInterface` the container binds (SPEC-020).
 *
 * Three modes, and the default is **`service`**. Autodetection is offered as
 * `auto` but is deliberately not the default: the two readers run different
 * c2pa-rs versions — 0.89.0 in `ext-c2pa`, 0.90.16 in the signing service — so an
 * application that installs the extension for an unrelated reason must not
 * silently change which engine decides its trust verdicts. That choice is made
 * by a person, once, visibly.
 *
 * The accepted cost: installing the extension does nothing until `reader` is
 * set. `mode()` and the README are what stop that reading as a bug.
 *
 * The decision lives here rather than inline in the provider so it is testable
 * without resolving a container and comparing class names — which is also what
 * AC6 needs, since "which engine answered?" has to be answerable in a bug report.
 */
final readonly class ReaderFactory
{
    private const MODES = ['auto', 'service', 'extension'];

    public function __construct(
        private ConfigRepository $config,
        private SigningServiceReader $serviceReader,
    ) {}

    /**
     * The mode actually in force: never `auto`, always what `auto` resolved to.
     *
     * @throws MissingConfigurationException on an unrecognised mode
     */
    public function mode(): string
    {
        $mode = $this->configuredMode();

        if ($mode !== 'auto') {
            return $mode;
        }

        return ExtC2paReader::isAvailable() ? 'extension' : 'service';
    }

    /**
     * What the configuration asked for, including `auto` — never resolved.
     *
     * SPEC-020 AC6 asks for the active mode AND the engine behind it, and
     * `mode()` alone answers only the second: it resolves `auto` before
     * returning, so `extension` could mean either a deliberate choice or a
     * detection. That distinction is the decision this spec is built on —
     * `auto` is not the default precisely because an engine must not change
     * itself — and a bug report that cannot separate the two has lost the
     * evidence for the failure the design guards against (AC8, amended
     * 2026-08-13).
     *
     * Validation lives here rather than in `mode()` so a typo cannot reach
     * either accessor by a second path.
     *
     * @throws MissingConfigurationException on an unrecognised mode
     */
    public function configuredMode(): string
    {
        $configured = $this->config->get('content-credentials.reader', 'service');
        $mode = is_string($configured) ? $configured : '';

        if (! in_array($mode, self::MODES, true)) {
            // Not defaulted. A typo that quietly becomes `auto` is the
            // silent-degradation shape this project keeps meeting; an empty
            // string — what an unset env var produces — is included in that.
            throw new MissingConfigurationException(sprintf(
                'Unrecognised "content-credentials.reader" value %s. Accepted modes are: %s.',
                var_export($configured, true),
                implode(', ', self::MODES),
            ));
        }

        return $mode;
    }

    /**
     * @throws MissingConfigurationException on an unrecognised mode
     * @throws ExtensionMissingException
     *                                   when `extension` is requested and ext-c2pa is not loaded — never a
     *                                   fallback to HTTP, because a caller who asked for in-process reading
     *                                   and silently got a network call cannot tell (SPEC-019 AC5)
     */
    public function make(): ReaderInterface
    {
        return $this->mode() === 'extension'
            ? new ExtC2paReader($this->trustAnchors())
            : $this->serviceReader;
    }

    /**
     * Trust anchors as PEM contents, accepting either contents or a path.
     *
     * Absorbing the path case here is deliberate. Every trust surface underneath
     * this one takes PEM *contents*: c2patool's settings file, c2pa-node's
     * settings object and the extension's `withTrustAnchors()` all either throw
     * or — worse — verify nothing at all when handed a path (NOTES.md Step 11).
     * A path is what people reach for, so it is resolved here rather than
     * discovered there.
     *
     * Affects the extension reader only. The service reader's trust verification
     * is configured on the *service*, through `CONTENTAUTH_TRUST_SETTINGS`, and
     * an application cannot influence it from here.
     */
    private function trustAnchors(): ?string
    {
        $configured = $this->config->get('content-credentials.trust_anchors');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        if (str_contains($configured, 'BEGIN CERTIFICATE')) {
            return $configured;
        }

        if (is_file($configured) && is_readable($configured)) {
            $contents = file_get_contents($configured);

            if (is_string($contents) && $contents !== '') {
                return $contents;
            }
        }

        // Neither PEM nor a readable file. Failing here beats passing it on:
        // the extension would either throw with a message about TOML parsing, or
        // accept it and verify nothing.
        throw new MissingConfigurationException(
            'Configuration "content-credentials.trust_anchors" is neither PEM contents '
            .'(no "BEGIN CERTIFICATE" found) nor a readable file path.'
        );
    }
}
