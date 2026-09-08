<?php

namespace App\Support;

use InvalidArgumentException;

class BrazilianPhoneNumber
{
    public function national(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $digits = substr($digits, 2);
        }

        if (!in_array(strlen($digits), [10, 11], true)) {
            throw new InvalidArgumentException('Informe um telefone brasileiro com DDD e 10 ou 11 dígitos.');
        }

        return $digits;
    }

    public function international(string $phone): string
    {
        return '55' . $this->national($phone);
    }

    public function tryNational(?string $phone): ?string
    {
        if (!filled($phone)) {
            return null;
        }

        try {
            return $this->national((string) $phone);
        }
        catch (InvalidArgumentException) {
            return null;
        }
    }
}
