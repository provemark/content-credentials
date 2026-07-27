<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** A 2xx read response whose body could not be parsed as a manifest store. */
final class ReadResponseException extends \RuntimeException implements ContentCredentialsException {}
