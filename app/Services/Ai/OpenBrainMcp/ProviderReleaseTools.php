<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;

/**
 * GOD-DEBULK: provider-release intelligence tool family extracted verbatim from
 * AtlasOpenBrainMcpService — atlas_provider_release_review (shadow review of a
 * detected release) and atlas_provider_release_sources (read-only source registry
 * summary + candidate preview). Both are read-only (`writes => false`). Bodies are
 * byte-identical to the pre-split service; the façade delegates here.
 */
class ProviderReleaseTools
{
    public function __construct(
        private readonly AtlasProviderReleaseIntelligenceService $providerReleaseIntelligence,
        private readonly AtlasProviderReleaseSourceRegistry $providerReleaseSources,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function providerReleaseReview(array $arguments): array
    {
        $title = $this->string($arguments['title'] ?? null);
        if ($title === null) {
            return [
                'ok' => false,
                'tool' => 'atlas_provider_release_review',
                'error' => 'title_required',
                'writes' => false,
            ];
        }

        $payload = $this->providerReleaseIntelligence->review([
            'provider' => $this->string($arguments['provider'] ?? null),
            'title' => $title,
            'url' => $this->string($arguments['url'] ?? null),
            'published_at' => $this->string($arguments['published_at'] ?? null),
            'content_hash' => $this->string($arguments['content_hash'] ?? null),
            'type' => $this->string($arguments['type'] ?? null),
            'domains' => $this->stringList($arguments['domain'] ?? ($arguments['domains'] ?? [])),
            'capabilities' => $this->stringList($arguments['capability'] ?? ($arguments['capabilities'] ?? [])),
            'connectors' => $this->stringList($arguments['connector'] ?? ($arguments['connectors'] ?? [])),
        ]);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_provider_release_review',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function providerReleaseSources(array $arguments): array
    {
        $payload = $this->providerReleaseSources->summary([
            'provider' => $this->string($arguments['provider'] ?? null),
            'tier' => $this->string($arguments['tier'] ?? null),
            'cadence' => $this->string($arguments['cadence'] ?? null),
            'track' => $this->string($arguments['track'] ?? null),
        ]);

        $url = $this->string($arguments['url'] ?? null);
        if ($url !== null) {
            $payload = array_merge($payload, [
                'mode' => 'read_only_candidate_preview',
                'candidate' => $this->providerReleaseSources->candidateFromDetection(
                    url: $url,
                    title: $this->string($arguments['title'] ?? null) ?? 'untitled-provider-release-candidate',
                    contentHash: $this->string($arguments['content_hash'] ?? null),
                    publishedAt: $this->string($arguments['published_at'] ?? null),
                ),
            ]);
        }

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_provider_release_sources',
            ...$payload,
            'writes' => false,
        ];
    }

    // ponytail: string/stringList copied verbatim from the façade (which keeps its
    // own pinned copies). Matches the existing per-Tools-class primitive convention.

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $values,
        ))));
    }
}
