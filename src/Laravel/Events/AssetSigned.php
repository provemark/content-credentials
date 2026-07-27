<?php

declare(strict_types=1);

namespace ContentCredentials\Laravel\Events;

/** Dispatched after SignAssetJob successfully writes a signed asset. */
final class AssetSigned
{
    public function __construct(public string $destinationPath) {}
}
