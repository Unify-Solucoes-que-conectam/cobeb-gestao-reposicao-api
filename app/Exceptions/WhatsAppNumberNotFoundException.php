<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppNumberNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'O número informado não está registrado no WhatsApp.')
    {
        parent::__construct($message);
    }
}
