<?php

namespace App\Exceptions;

use RuntimeException;

class EvolutionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'EVOLUTION_ERROR',
        public readonly int $httpStatus = 502,
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $upstreamMessage = null,
    ) {
        parent::__construct($message);
    }
}
