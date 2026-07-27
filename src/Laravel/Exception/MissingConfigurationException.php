<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** A required piece of `content-credentials` configuration is missing or blank. */
final class MissingConfigurationException extends \RuntimeException implements ContentCredentialsException {}
