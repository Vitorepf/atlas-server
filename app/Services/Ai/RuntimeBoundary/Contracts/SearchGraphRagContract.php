<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Search Graph RAG — PHP-side runtime invocation contract.
 */
final class SearchGraphRagContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'search_graph_rag';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['review_only'] ?? null) !== true) {
            $errors[] = 'review_only must be true until Curator/AP-99 promotes';
        }
        if (! isset($payload['query_hash']) || ! is_string($payload['query_hash'])) {
            $errors[] = 'query_hash required (provider-safe — query content stays in Laravel)';
        }
        if (($payload['rollback_to_keyword_search_available'] ?? null) !== true) {
            $errors[] = 'rollback_to_keyword_search_available must be true';
        }

        return $errors;
    }
}
