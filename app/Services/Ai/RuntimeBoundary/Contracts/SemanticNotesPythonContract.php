<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Semantic notes Python — PHP-side runtime invocation contract.
 */
final class SemanticNotesPythonContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'semantic_notes_python';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['python_ai_data_ap_approved'] ?? null) !== true) {
            $errors[] = 'python_ai_data AP must be approved before this runtime starts';
        }
        if (($payload['php_adapter_only'] ?? null) !== true) {
            $errors[] = 'PHP layer remains adapter-only — no embeddings/clustering in Laravel';
        }
        if (($payload['embeddings_engine_in_python'] ?? null) !== true) {
            $errors[] = 'embeddings_engine_in_python must be true (boundary)';
        }

        return $errors;
    }
}
