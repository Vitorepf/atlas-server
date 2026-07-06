<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/**
 * JSON canônico dos comandos atlas:* (Obra #8 R-08). Dois sabores byte-idênticos
 * aos helpers privados que substituem: encode() devolve '' em falha de encode
 * (semântica do cast (string)); encodeOrEmptyObject() preserva o fallback '{}'.
 * Não usar em sites com JSON_THROW_ON_ERROR (semântica de falha diferente).
 */
trait EmitsCanonicalJson
{
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function encodeOrEmptyObject(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function jsonLine(array $payload): void
    {
        $this->line($this->encodeOrEmptyObject($payload));
    }
}
