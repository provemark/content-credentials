<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Manifest\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** Thrown when the software agent required for the AI marking is missing or blank. */
final class InvalidSoftwareAgentException extends \InvalidArgumentException implements ContentCredentialsException {}
