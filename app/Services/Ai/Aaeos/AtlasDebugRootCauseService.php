<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasDebugRootCauseService
{
    private const SERVICE_VERSION = 'atlas.aaeos.debug.root_cause.v1';

    public function getVersion(): string
    {
        return self::SERVICE_VERSION;
    }

    public function analyzeRootCause(array $context = []): array
    {
        return [
            'version' => self::SERVICE_VERSION,
            'status' => 'analyzed',
            'context' => $context,
            'root_cause' => $this->determineRootCause($context),
        ];
    }

    private function determineRootCause(array $context): string
    {
        if (empty($context)) {
            return 'no_data';
        }

        return $context['suspected_cause'] ?? 'unknown';
    }
}