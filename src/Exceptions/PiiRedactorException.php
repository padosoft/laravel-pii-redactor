<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Exceptions;

use RuntimeException;

/**
 * Base exception for the package. Non-final so applications can extend it.
 */
class PiiRedactorException extends RuntimeException {}
