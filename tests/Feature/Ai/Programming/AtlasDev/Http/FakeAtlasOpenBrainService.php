<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Services\Ai\AtlasOpenBrainService;

/**
 * Deterministic zero-context Open Brain service used by HTTP tests so the
 * plan layer never fans out to real DB / HTTP backends.
 */
final class FakeAtlasOpenBrainService extends AtlasOpenBrainService
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct()
    {
        // intentional: skip parent
    }

    public function contextPack(array $data, string $surface = 'api'): array
    {
        $this->calls[] = $data;

        return [
            'ok' => true,
            'context_refs' => [],
            'summary' => [
                'context_refs_count' => 0,
                'memory_refs_count' => 0,
                'recall_count' => 0,
                'registry_count' => 0,
                'verbatim_count' => 0,
                'semantic_count' => 0,
                'provider_safe' => true,
            ],
        ];
    }
}
