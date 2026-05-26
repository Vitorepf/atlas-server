<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Open Brain Graph RAG — PHP-side runtime invocation contract.
 *
 * Block: open_brain_graph_rag · Runtime: python_ai_data
 * Per `atlas-ai-local-performance-memory-strategy.md` guardrail
 * "Não criar cerebro Python paralelo ao Kernel".
 */
final class OpenBrainGraphRagContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'open_brain_graph_rag';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['proposal_only'] ?? null) !== true) {
            $errors[] = 'open_brain graph rag is proposal_only — never promoted to context without review';
        }
        if (($payload['freshness_ms_max'] ?? null) === null) {
            $errors[] = 'freshness_ms_max required (no stale embeddings)';
        }
        if (($payload['privacy_gate_passed'] ?? null) !== true) {
            $errors[] = 'privacy_gate_passed must be true';
        }
        if (($payload['parallel_kernel_brain_forbidden'] ?? null) !== true) {
            $errors[] = 'parallel_kernel_brain_forbidden must be acknowledged true';
        }

        return $errors;
    }
}
