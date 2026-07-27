<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * Facade over {@see ContentCredentialsManager}.
 *
 * @method static \Provemark\ContentCredentials\Core\Signing\SignedAsset sign(\Provemark\ContentCredentials\Core\Signing\Asset $asset, \Provemark\ContentCredentials\Core\Manifest\Manifest $manifest)
 * @method static \Provemark\ContentCredentials\Core\Reading\ManifestReport read(\Provemark\ContentCredentials\Core\Signing\Asset $asset)
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
