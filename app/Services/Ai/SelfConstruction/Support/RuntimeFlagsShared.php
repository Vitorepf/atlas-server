<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Extraido pela limpeza-bruta 05/07 (censo de metodos duplicados): runtimeFlags() era
 * clonado byte a byte em 7 classes desta area. Copia divergente permanece
 * local (metodo de classe vence trait).
 */
trait RuntimeFlagsShared
{
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }
}
