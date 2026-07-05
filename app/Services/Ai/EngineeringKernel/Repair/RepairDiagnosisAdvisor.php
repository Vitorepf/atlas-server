<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Repair;

/**
 * Engineering Kernel port (OBRA #4 S3): o seam do advisor de diagnóstico — CONTEST-only, padrão
 * Obra #2. Uma implementação com modelo pode CONTESTAR um UNKNOWN sugerindo uma classe; nunca
 * sobrescreve regra determinística e nunca pode devolver test_wrong/spec_wrong (essas exigem
 * sinal explícito). Sem implementação bound, o estágio segue determinístico puro.
 */
interface RepairDiagnosisAdvisor
{
    /**
     * @param  array<string,mixed>  $context
     * @return string|null uma classe da FailureTaxonomy, ou null para abster
     */
    public function contest(array $context): ?string;
}
