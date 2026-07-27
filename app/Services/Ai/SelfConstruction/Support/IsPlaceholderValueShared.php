<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Extraido pela limpeza-bruta 05/07 (censo de metodos duplicados): isPlaceholderValue() era
 * clonado byte a byte em 5 classes desta area. Copia divergente permanece
 * local (metodo de classe vence trait).
 */
trait IsPlaceholderValueShared
{
    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || str_starts_with($normalized, '<') || str_starts_with($normalized, '__')) {
            return true;
        }

        foreach ([
            'seu_nome',
            'seu nome',
            'operador',
            'motivo real',
            'pelo menos 32 caracteres',
            'substitua',
            'placeholder',
            'todo',
            'synthetic',
            'fixture-only',
            'fixture_only',
            'test_only',
            'test-only',
            'fake',
            'simulated',
            'mock-',
            'dummy',
        ] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
