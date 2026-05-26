<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Local Graph RAG — PHP-side runtime invocation contract.
 * Canon explicitly blocks promotion without AP-683 review.
 */
final class LocalGraphRagContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'local_graph_rag';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['ap683_review_passed'] ?? null) !== true) {
            $errors[] = 'AP-683 Local RAG promotion review must be passed';
        }
        if (($payload['local_rag_graph_promotion_blocked_flag'] ?? null) !== false) {
            $errors[] = 'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED must be explicitly false (operator override)';
        }
        if (($payload['operator_decision_receipt_ref'] ?? null) === null) {
            $errors[] = 'operator_decision_receipt_ref required (override is operator-authorised only)';
        }

        return $errors;
    }
}
