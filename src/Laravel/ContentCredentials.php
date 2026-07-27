<?php

declare(strict_types=1);

namespace ContentCredentials\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * Facade over {@see ContentCredentialsManager}.
 *
 * @method static \ContentCredentials\Core\Signing\SignedAsset sign(\ContentCredentials\Core\Signing\Asset $asset, \ContentCredentials\Core\Manifest\Manifest $manifest)
 * @method static \ContentCredentials\Core\Reading\ManifestReport read(\ContentCredentials\Core\Signing\Asset $asset)
 *
 * @see ContentCredentialsManager
 */
final class ContentCredentials extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContentCredentialsManager::class;
    }
}
