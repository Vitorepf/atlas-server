<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Extraido pela limpeza-bruta 05/07 (censo de metodos duplicados): isPlaceholderReason() era
 * clonado byte a byte em 4 classes. Copia divergente permanece local.
 */
trait IsPlaceholderReasonShared
{
    private function isPlaceholderReason(string $reason): bool
    {
        $normalized = strtolower(trim($reason));
        if ($normalized === '' || str_starts_with($normalized, '<')) {
            return true;
        }

        foreach ([
            'operator reason',
            'minimum_32_chars',
            'pelo menos 32 caracteres',
            'motivo real',
            'substitua',
            'placeholder',
            'todo',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
