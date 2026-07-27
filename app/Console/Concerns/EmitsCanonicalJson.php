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

    /**
     * Was copied byte-identically into 14 commands. PHP resolves a class-defined
     * method over a trait method, so a command that still declares its own keeps
     * winning and this can be adopted one file at a time.
     */
    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * The `--json` fork, once. 13 commands carried this exact body; the rest of the
     * 139 hand-written emit() variants differ for real and are left where they are.
     *
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return;
        }

        $human();
    }
}
