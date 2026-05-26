<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * External Graph Harness (Graphify AP-684) — PHP-side contract.
 */
final class ExternalGraphHarnessContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'external_graph_harness';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['review_only_constraints_acknowledged'] ?? null) !== true) {
            $errors[] = 'review_only_constraints_acknowledged must be true (AP-684 fail-closed)';
        }
        if (($payload['private_docs_excluded'] ?? null) !== true) {
            $errors[] = 'private_docs_excluded must be true (Graphify never runs on private memory)';
        }
        if (($payload['graph_promotion_to_context_blocked'] ?? null) !== true) {
            $errors[] = 'graph_promotion_to_context_blocked must remain true until future AP';
        }

        return $errors;
    }
}
