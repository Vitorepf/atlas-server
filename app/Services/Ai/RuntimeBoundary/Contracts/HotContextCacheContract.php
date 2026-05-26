<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Hot Context Pack Cache — PHP-side runtime invocation contract.
 */
final class HotContextCacheContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'hot_context_cache';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['freshness_seconds_max'] ?? null) === null) {
            $errors[] = 'freshness_seconds_max required — cache must not serve stale context';
        }
        $hashes = (array) ($payload['content_hashes'] ?? []);
        if ($hashes === []) {
            $errors[] = 'content_hashes required for cache validity';
        }
        if (($payload['privacy_gate_passed'] ?? null) !== true) {
            $errors[] = 'privacy_gate_passed must be true';
        }

        return $errors;
    }
}
