<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** Thrown when a MIME type is not one of the supported asset formats. */
final class UnsupportedMediaTypeException extends \InvalidArgumentException implements ContentCredentialsException {}
