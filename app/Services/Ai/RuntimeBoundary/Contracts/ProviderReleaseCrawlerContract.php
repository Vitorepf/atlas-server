<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Provider Release crawler runtime — PHP-side contract.
 *
 * Block: provider_release_crawler · Runtime: external_crawler
 */
final class ProviderReleaseCrawlerContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'provider_release_crawler';
    }

    protected function targetRuntime(): string
    {
        return 'external_crawler';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        if (($payload['proposal_only'] ?? null) !== true) {
            $errors[] = 'crawler runtime is proposal_only (never auto-applies to Decide/Policy)';
        }
        $sources = (array) ($payload['source_ids'] ?? []);
        if ($sources === []) {
            $errors[] = 'source_ids required (must reference registered ProviderReleaseSourceRegistry entries)';
        }
        $robots = $payload['respects_robots_txt'] ?? null;
        if ($robots !== true) {
            $errors[] = 'respects_robots_txt must be true';
        }

        return $errors;
    }
}
