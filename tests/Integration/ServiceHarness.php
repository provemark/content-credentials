<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Tests\Integration;

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;

/**
 * Shared wiring for the integration suite (the `integration` group, excluded
 * from `composer check`; run with `vendor/bin/pest --group=integration`).
 *
 * Reads the service URL and API key from the environment, falling back to the
 * repo `.env`, and builds real HTTP-backed signer/reader against the running
 * service. Every consumer skips cleanly when `reachable()` is false.
 */
final class ServiceHarness
{
    public static function apiKey(): string
    {
        $key = getenv('CONTENTAUTH_API_KEY') ?: '';
        $envFile = dirname(__DIR__, 2).'/.env';

        if ($key === '' && is_file($envFile)) {
            foreach ((array) file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                if (is_string($line) && str_starts_with($line, 'CONTENTAUTH_API_KEY=')) {
                    $key = substr($line, strlen('CONTENTAUTH_API_KEY='));
                }
            }
        }

        return $key;
    }

    public static function baseUrl(): string
    {
        return getenv('CONTENTAUTH_SERVICE_URL') ?: 'http://localhost:3000';
    }

    public static function reachable(): bool
    {
        if (self::apiKey() === '') {
            return false;
        }

        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);

        return @file_get_contents(self::baseUrl().'/health', false, $context) !== false;
    }

    /** @return array{SignerInterface, ReaderInterface} */
    public static function signerAndReader(): array
    {
        $factory = new Psr17Factory;
        $config = new SigningServiceConfig(self::baseUrl(), self::apiKey());

        return [
            new SigningServiceSigner(new Client, $factory, $factory, $config),
            new SigningServiceReader(new Client, $factory, $factory, $config),
        ];
    }

    public static function fixtureBytes(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/fixture.png');
    }

    /**
     * The decoded `GET /health` document, or an empty array when unreachable.
     *
     * @return array<array-key, mixed>
     */
    public static function health(): array
    {
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $body = @file_get_contents(self::baseUrl().'/health', false, $context);

        if (! is_string($body)) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether the running service reports trust-list verification as active
     * (SPEC-014 AC6). Null when the service does not report the flag at all —
     * i.e. a service predating SPEC-014, which is distinct from "inactive".
     */
    public static function trustVerificationActive(): ?bool
    {
        $flag = self::health()['trust_verification'] ?? null;

        return is_bool($flag) ? $flag : null;
    }

    /** Absolute path to the committed test trust-settings document. */
    public static function trustSettingsPath(): string
    {
        return dirname(__DIR__, 2).'/certs/c2pa-trust.settings.json';
    }
}
