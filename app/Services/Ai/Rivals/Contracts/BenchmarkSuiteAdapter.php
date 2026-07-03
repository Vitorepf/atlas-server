<?php

namespace App\Services\Ai\Rivals\Contracts;

use App\Services\Ai\Rivals\Core\RunPlan;

/**
 * Rivals 2.0 suite adapter. Suites externas (Senior SWE-Bench, aider, Harbor...)
 * e internas (AtlasBench, LocalFake) plugam aqui. O adapter NUNCA adjudica e
 * NUNCA decide claim — ele lista cases, planeja comandos e ingere resultados
 * como RunReceipts. O juiz final é sempre o núcleo Rivals 2.0.
 */
interface BenchmarkSuiteAdapter
{
    public function suiteId(): string;

    /** @return array<int, array{case_id: string, task_type: string, title: string}> */
    public function listCases(array $filters = []): array;

    /** @return array<int, array{case_id: string, arm_id: string, repetition: int, command: string}> */
    public function planCommands(RunPlan $plan): array;

    /** @return array<int, \App\Services\Ai\Rivals\Core\RunReceipt> */
    public function ingestResults(string $runDir): array;
}
