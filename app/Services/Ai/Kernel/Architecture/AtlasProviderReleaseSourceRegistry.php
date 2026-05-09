<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AtlasProviderReleaseSourceRegistry
{
    public function __construct(
        private readonly ProviderReleaseSourceTrustPolicy $trustPolicy = new ProviderReleaseSourceTrustPolicy,
        private readonly ProviderReleaseCandidateFingerprint $fingerprint = new ProviderReleaseCandidateFingerprint,
    ) {}

    /**
     * @param  array{provider?:string|null,tier?:string|null,cadence?:string|null,track?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function summary(array $filters = []): array
    {
        $sources = $this->sources($filters)->values();

        return [
            'schema_version' => 'atlas.provider_release.source_registry.v1',
            'status' => 'ok',
            'mode' => 'read_only_registry',
            'authority' => 'watchlist_only_no_release_ingestion',
            'source_count' => $sources->count(),
            'filters' => [
                'provider' => $this->cleanString($filters['provider'] ?? null),
                'tier' => $this->cleanString($filters['tier'] ?? null),
                'cadence' => $this->cleanString($filters['cadence'] ?? null),
                'track' => $this->cleanString($filters['track'] ?? null),
            ],
            'source_counts' => [
                'by_provider' => $sources->countBy(fn (ProviderReleaseSource $source): string => $source->provider)->all(),
                'by_tier' => $sources->countBy(fn (ProviderReleaseSource $source): string => $source->tier)->all(),
                'by_cadence' => $sources->countBy(fn (ProviderReleaseSource $source): string => $source->cadence)->all(),
            ],
            'sources' => $sources->map(fn (ProviderReleaseSource $source): array => $this->sourcePayload($source))->all(),
            'guardrails' => $this->guardrails(),
            'continuous_ingestion_contract' => $this->continuousIngestionContract(),
        ];
    }

    /**
     * @param  array{provider?:string|null,tier?:string|null,cadence?:string|null,track?:string|null}  $filters
     * @return Collection<int,ProviderReleaseSource>
     */
    public function sources(array $filters = []): Collection
    {
        $provider = $this->normalize($filters['provider'] ?? null);
        $tier = $this->cleanString($filters['tier'] ?? null);
        $cadence = $this->normalize($filters['cadence'] ?? null);
        $track = $this->normalize($filters['track'] ?? null);

        return collect($this->definitions())
            ->map(fn (array $definition): ProviderReleaseSource => new ProviderReleaseSource(...$definition))
            ->filter(fn (ProviderReleaseSource $source): bool => $provider === null || $source->provider === $provider)
            ->filter(fn (ProviderReleaseSource $source): bool => $tier === null || $source->tier === $this->trustPolicy->normalizeTier($tier))
            ->filter(fn (ProviderReleaseSource $source): bool => $cadence === null || $source->cadence === $cadence)
            ->filter(fn (ProviderReleaseSource $source): bool => $track === null || in_array($track, $source->tracks, true))
            ->values();
    }

    public function findById(string $id): ?ProviderReleaseSource
    {
        $normalized = $this->normalize($id);

        return $this->sources()
            ->first(fn (ProviderReleaseSource $source): bool => $source->id === $normalized);
    }

    public function sourceForUrl(string $url): ?ProviderReleaseSource
    {
        $normalizedUrl = $this->fingerprint->canonicalUrl($url);

        return $this->sources()
            ->first(fn (ProviderReleaseSource $source): bool => str_starts_with($normalizedUrl, $this->fingerprint->canonicalUrl($source->url)));
    }

    /**
     * @return array<string,mixed>
     */
    public function candidateFromDetection(string $url, string $title, ?string $contentHash = null, ?string $publishedAt = null): array
    {
        $source = $this->sourceForUrl($url);
        $tier = $source?->tier ?? ProviderReleaseSourceTrustPolicy::TIER_WEAK_SIGNAL;
        $trust = $this->trustPolicy->evaluate($tier);
        $releaseType = $this->inferReleaseType($title.' '.$url.' '.implode(' ', $source?->tracks ?? []));

        return [
            'schema_version' => 'atlas.provider_release.source_candidate.v1',
            'status' => $source === null ? 'unmatched_source' : 'candidate',
            'mode' => 'read_only_candidate',
            'source' => $source ? $this->sourcePayload($source) : [
                'id' => 'unmatched_source',
                'provider' => 'other',
                'url' => $url,
                'tier' => ProviderReleaseSourceTrustPolicy::TIER_WEAK_SIGNAL,
                'source_type' => 'unconfirmed',
            ],
            'source_trust' => $trust,
            'title' => trim($title) !== '' ? trim($title) : 'untitled-provider-release-candidate',
            'source_url' => $url,
            'published_at' => $publishedAt,
            'detected_at' => now()->toIso8601String(),
            'canonical_url' => $this->fingerprint->canonicalUrl($url),
            'content_hash' => $contentHash ?: $this->fingerprint->contentHash($title, $url, $publishedAt),
            'release_type' => $releaseType,
            'possible_capabilities' => $this->possibleCapabilities($releaseType, $title),
            'recommended_triage_action' => $trust['recommended_next_action'],
            'non_goals' => [
                'No code changes from source detection.',
                'No Atlas Decide routing changes from source detection.',
                'No memory promotion from source detection.',
                'No paid benchmark until human/provider release review approves it.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourcePayload(ProviderReleaseSource $source): array
    {
        return array_merge($source->toArray(), [
            'trust' => $this->trustPolicy->evaluate($source->tier),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function guardrails(): array
    {
        return [
            'network_fetching_enabled' => false,
            'writes_release_envelope' => false,
            'writes_policy' => false,
            'changes_routing' => false,
            'requires_primary_source_for_tier_2_or_3' => true,
            'crawler_contract_doc' => 'docs/ap/AP-172-provider-release-source-watchlist.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function continuousIngestionContract(): array
    {
        return [
            'schema_version' => 'atlas.provider_release.continuous_ingestion_contract.v1',
            'status' => 'planned_fail_closed',
            'mode' => 'proposal_only_no_network_no_writes',
            'network_fetching_enabled' => false,
            'writes_release_envelope' => false,
            'changes_decide_policy' => false,
            'allowed_until_activation' => [
                'list_watchlist_sources',
                'preview_manual_candidate_from_url',
                'run_provider_release_review',
                'emit_self_improvement_proposal',
            ],
            'activation_requires' => [
                'dedicated_AP_for_fetch_runtime',
                'source_rate_limits_and_robot_policy_review',
                'content_hash_and_canonical_url',
                'primary_source_gate',
                'Evidence Ledger event for each candidate',
                'Rivals/AP-99 gate before Decide promotion',
                'human_review',
            ],
            'forbidden_shortcuts' => [
                'background_web_crawler_without_AP',
                'secondary_news_to_decide_signal',
                'provider_release_to_routing_policy_patch',
                'memory_promotion_without_curator_review',
            ],
            'review_flow' => 'self_improvement.provider_release_review',
        ];
    }

    private function inferReleaseType(string $text): string
    {
        $text = Str::lower($text);

        return match (true) {
            str_contains($text, 'memory') || str_contains($text, 'dream') || str_contains($text, 'context') => 'memory',
            str_contains($text, 'finance') || str_contains($text, 'financial') || str_contains($text, 'kyc') => 'vertical_agents',
            str_contains($text, 'agent') || str_contains($text, 'managed') => 'vertical_agents',
            str_contains($text, 'realtime') || str_contains($text, 'voice') || str_contains($text, 'audio') => 'realtime',
            str_contains($text, 'connector') || str_contains($text, 'mcp') || str_contains($text, 'integration') => 'connector',
            str_contains($text, 'code') || str_contains($text, 'coding') || str_contains($text, 'copilot') || str_contains($text, 'cursor') => 'coding',
            str_contains($text, 'price') || str_contains($text, 'pricing') || str_contains($text, 'rate limit') || str_contains($text, 'limit') => 'pricing_or_limits',
            str_contains($text, 'deprecated') || str_contains($text, 'deprecation') || str_contains($text, 'sunset') => 'deprecation',
            str_contains($text, 'safety') || str_contains($text, 'policy') => 'safety_policy',
            str_contains($text, 'model') || str_contains($text, 'gpt') || str_contains($text, 'claude') || str_contains($text, 'gemini') => 'model',
            default => 'capability_update',
        };
    }

    /**
     * @return array<int,string>
     */
    private function possibleCapabilities(string $releaseType, string $title): array
    {
        $capabilities = [$releaseType];
        $title = Str::lower($title);

        if (str_contains($title, 'dream')) {
            $capabilities[] = 'provider_dream_memory';
        }
        if (str_contains($title, 'finance')) {
            $capabilities[] = 'finance_vertical_agent';
        }
        if (str_contains($title, 'copilot') || str_contains($title, 'cursor')) {
            $capabilities[] = 'coding_agent';
        }

        return array_values(array_unique($capabilities));
    }

    private function normalize(mixed $value): ?string
    {
        $value = Str::of((string) $value)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        return $value !== '' ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function definitions(): array
    {
        $tier1 = ProviderReleaseSourceTrustPolicy::TIER_PRIMARY;
        $tier2 = ProviderReleaseSourceTrustPolicy::TIER_TECHNICAL;
        $tier3 = ProviderReleaseSourceTrustPolicy::TIER_WEAK_SIGNAL;

        return [
            ['id' => 'anthropic_research', 'provider' => 'anthropic', 'name' => 'Anthropic Research', 'url' => 'https://www.anthropic.com/research', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['research', 'safety', 'evals', 'agents']],
            ['id' => 'anthropic_news', 'provider' => 'anthropic', 'name' => 'Anthropic News', 'url' => 'https://www.anthropic.com/news', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['launches', 'managed_agents', 'vertical_agents']],
            ['id' => 'anthropic_release_notes', 'provider' => 'anthropic', 'name' => 'Anthropic Release Notes', 'url' => 'https://docs.anthropic.com/en/release-notes/overview', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['api', 'models', 'tools', 'deprecations']],
            ['id' => 'anthropic_api_release_notes', 'provider' => 'anthropic', 'name' => 'Anthropic API Release Notes', 'url' => 'https://docs.anthropic.com/en/release-notes/api', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['api', 'models', 'pricing_or_limits']],
            ['id' => 'openai_news', 'provider' => 'openai', 'name' => 'OpenAI News', 'url' => 'https://openai.com/news', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['models', 'products', 'agents']],
            ['id' => 'openai_research_news', 'provider' => 'openai', 'name' => 'OpenAI Research News', 'url' => 'https://openai.com/newsroom/research', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['research', 'evals', 'safety']],
            ['id' => 'openai_api_changelog', 'provider' => 'openai', 'name' => 'OpenAI API Changelog', 'url' => 'https://platform.openai.com/docs/changelog', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['api', 'models', 'platform']],
            ['id' => 'openai_models_docs', 'provider' => 'openai', 'name' => 'OpenAI Models Docs', 'url' => 'https://platform.openai.com/docs/models', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['models', 'deprecations']],
            ['id' => 'google_ai_blog', 'provider' => 'google', 'name' => 'Google AI Blog', 'url' => 'https://blog.google/technology/ai', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['gemini', 'products', 'agents']],
            ['id' => 'google_deepmind_blog', 'provider' => 'google', 'name' => 'Google DeepMind Blog', 'url' => 'https://deepmind.google/blog', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['research', 'agents', 'science']],
            ['id' => 'google_gemini_api_changelog', 'provider' => 'google', 'name' => 'Gemini API Release Notes', 'url' => 'https://ai.google.dev/gemini-api/docs/changelog', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['gemini_api', 'models', 'api']],
            ['id' => 'microsoft_365_copilot_release_notes', 'provider' => 'microsoft', 'name' => 'Microsoft 365 Copilot Release Notes', 'url' => 'https://learn.microsoft.com/en-us/microsoft-365/copilot/release-notes', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['office', 'copilot', 'enterprise']],
            ['id' => 'microsoft_copilot_blog_releases', 'provider' => 'microsoft', 'name' => 'Microsoft Copilot Blog Release Notes', 'url' => 'https://www.microsoft.com/en-us/microsoft-copilot/blog/content-type/release-notes', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['copilot', 'products']],
            ['id' => 'github_copilot_changelog', 'provider' => 'github', 'name' => 'GitHub Copilot Changelog', 'url' => 'https://github.blog/changelog/label/copilot', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['coding', 'copilot', 'developer_workflows']],
            ['id' => 'cursor_changelog', 'provider' => 'cursor', 'name' => 'Cursor Changelog', 'url' => 'https://www.cursor.com/changelog', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['coding', 'ide', 'agent_modes']],
            ['id' => 'meta_ai_blog', 'provider' => 'meta', 'name' => 'Meta AI Blog', 'url' => 'https://ai.meta.com/blog', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['llama', 'open_models', 'agents']],
            ['id' => 'apple_machine_learning_research', 'provider' => 'apple', 'name' => 'Apple Machine Learning Research', 'url' => 'https://machinelearning.apple.com', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['on_device_ai', 'privacy', 'local_models']],
            ['id' => 'apple_developer_news', 'provider' => 'apple', 'name' => 'Apple Developer News', 'url' => 'https://developer.apple.com/news', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['apis', 'apple_intelligence', 'platform_changes']],
            ['id' => 'xai_news', 'provider' => 'xai', 'name' => 'xAI News', 'url' => 'https://x.ai/news', 'tier' => $tier1, 'cadence' => 'daily', 'tracks' => ['grok', 'models', 'platform']],
            ['id' => 'hugging_face_blog', 'provider' => 'huggingface', 'name' => 'Hugging Face Blog', 'url' => 'https://huggingface.co/blog', 'tier' => $tier2, 'cadence' => 'weekly', 'tracks' => ['open_models', 'ecosystem']],
            ['id' => 'papers_with_code', 'provider' => 'other', 'name' => 'Papers with Code', 'url' => 'https://paperswithcode.com', 'tier' => $tier2, 'cadence' => 'weekly', 'tracks' => ['benchmarks', 'research_signal']],
            ['id' => 'hacker_news_ai_signal', 'provider' => 'other', 'name' => 'Hacker News AI Signal', 'url' => 'https://news.ycombinator.com', 'tier' => $tier3, 'cadence' => 'optional', 'tracks' => ['unconfirmed_signal']],
        ];
    }
}
