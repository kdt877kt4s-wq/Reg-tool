<?php

namespace App\Ai;

use RuntimeException;
use Throwable;

/** The model could not be reached or did not return a usable result. */
class AiRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
