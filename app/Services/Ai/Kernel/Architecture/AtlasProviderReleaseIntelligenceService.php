<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Str;

final class AtlasProviderReleaseIntelligenceService
{
    private const PROVIDERS = [
        'anthropic',
        'openai',
        'google',
        'gemini',
        'codex',
        'cursor',
        'apple',
        'meta',
        'xai',
    ];

    private const RELEASE_TYPES = [
        'vertical_agents',
        'model',
        'connector',
        'tool_use',
        'realtime',
        'memory',
        'coding',
        'design',
        'marketing',
        'finance',
        'capability_update',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function review(array $input): array
    {
        $title = $this->cleanString($input['title'] ?? null) ?: 'untitled-provider-release';
        $url = $this->cleanString($input['url'] ?? null);
        $provider = $this->provider($input['provider'] ?? null, $title.' '.$url);
        $releaseType = $this->releaseType($input['type'] ?? null, $title.' '.$url);
        $domains = $this->domains((array) ($input['domains'] ?? []), $title.' '.$url.' '.$releaseType);
        $capabilities = $this->uniqueStrings((array) ($input['capabilities'] ?? []));
        $connectors = $this->uniqueStrings((array) ($input['connectors'] ?? []));
        $releaseId = $this->releaseId($provider, $title);
        $recommendedAction = $this->recommendedAction($releaseType, $domains, $capabilities, $connectors);
        $secondaryActions = $this->secondaryActions($recommendedAction, $releaseType);
        $ownerDocs = $this->ownerDocs($domains, $releaseType);

        return [
            'schema_version' => 'atlas.provider_release_review.v1',
            'status' => 'ok',
            'generated_at' => now()->toIso8601String(),
            'release_envelope' => [
                'schema_version' => 'atlas.provider_release.v1',
                'provider' => $provider,
                'release_id' => $releaseId,
                'title' => $title,
                'url' => $url,
                'release_type' => $releaseType,
                'affected_domains' => $domains,
                'affected_surfaces' => $this->affectedSurfaces($releaseType, $domains),
                'affected_runtimes' => $this->affectedRuntimes($releaseType),
                'capabilities' => $capabilities,
                'connectors' => $connectors,
                'threat_to_wrappers' => $this->threatToWrappers($releaseType),
                'threat_to_atlas' => $this->threatToAtlas($releaseType, $domains),
                'potential_multiplier' => $this->potentialMultiplier($releaseType, $domains, $capabilities, $connectors),
                'recommended_action' => $recommendedAction,
            ],
            'classification' => [
                'provider_category' => $provider === 'other' ? 'unknown_or_emerging_lab' : 'known_provider',
                'release_family' => $this->releaseFamily($releaseType),
                'atlas_positioning' => 'provider_capability_becomes_signal_adapter_skill_pack_or_benchmark_never_direct_channel',
                'wrapper_market_impact' => $this->wrapperMarketImpact($releaseType),
            ],
            'recommended_action' => $recommendedAction,
            'secondary_actions' => $secondaryActions,
            'owner_docs' => $ownerDocs,
            'suggested_aps' => $this->suggestedAps($provider, $releaseId, $releaseType, $domains, $recommendedAction),
            'rivals_required' => $this->rivalsRequired($releaseType, $domains),
            'decide_signal' => [
                'schema_version' => 'atlas.decide.provider_release_signal.v1',
                'signal_only' => true,
                'changes_routing' => false,
                'manual_override_required_for_critical_use' => true,
                'promotion_requires' => ['AP-99 evidence', 'Rivals benchmark', 'owner doc update', 'human review'],
            ],
            'risks' => $this->risks($releaseType, $recommendedAction),
            'required_validation' => [
                'php artisan atlas:ai:architecture-validate --json',
                'atlas engineering knowledge docs-health',
                'atlas engineering knowledge sync --prune',
                'Rivals benchmark when rivals_required=true',
            ],
            'non_goals' => [
                'No direct provider channel outside Atlas.',
                'No hardcoded routing policy from a press release.',
                'No domain maturity promotion without evidence.',
                'No connector credential storage in this review command.',
            ],
        ];
    }

    private function provider(mixed $raw, string $haystack): string
    {
        $candidate = Str::of((string) $raw)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value();
        if (in_array($candidate, self::PROVIDERS, true)) {
            return $candidate === 'gemini' ? 'google' : $candidate;
        }

        $normalizedHaystack = Str::lower($haystack);
        foreach (self::PROVIDERS as $provider) {
            if (str_contains($normalizedHaystack, $provider)) {
                return $provider === 'gemini' ? 'google' : $provider;
            }
        }

        return 'other';
    }

    private function releaseType(mixed $raw, string $haystack): string
    {
        $candidate = Str::of((string) $raw)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value();
        if (in_array($candidate, self::RELEASE_TYPES, true)) {
            return $candidate;
        }

        $text = Str::lower($haystack);

        return match (true) {
            str_contains($text, 'finance') || str_contains($text, 'financial') => 'vertical_agents',
            str_contains($text, 'agent') || str_contains($text, 'vertical') => 'vertical_agents',
            str_contains($text, 'realtime') || str_contains($text, 'voice') || str_contains($text, 'audio') => 'realtime',
            str_contains($text, 'connector') || str_contains($text, 'mcp') || str_contains($text, 'integration') => 'connector',
            str_contains($text, 'tool') || str_contains($text, 'computer use') => 'tool_use',
            str_contains($text, 'memory') || str_contains($text, 'context') => 'memory',
            str_contains($text, 'code') || str_contains($text, 'coding') || str_contains($text, 'developer') => 'coding',
            str_contains($text, 'design') || str_contains($text, 'frontend') || str_contains($text, 'front end') => 'design',
            str_contains($text, 'marketing') || str_contains($text, 'ads') || str_contains($text, 'creative') => 'marketing',
            str_contains($text, 'model') || str_contains($text, 'gpt') || str_contains($text, 'claude') || str_contains($text, 'gemini') => 'model',
            default => 'capability_update',
        };
    }

    /**
     * @param  array<int,mixed>  $rawDomains
     * @return array<int,string>
     */
    private function domains(array $rawDomains, string $haystack): array
    {
        $domains = $this->uniqueStrings($rawDomains);
        $text = Str::lower($haystack);

        $keywordDomains = [
            'finance' => ['finance', 'financial', 'bank', 'market', 'risk', 'kyc'],
            'marketing' => ['marketing', 'ads', 'creative', 'copy', 'campaign'],
            'programming' => ['code', 'coding', 'developer', 'frontend', 'software', 'bug'],
            'learning' => ['learning', 'study', 'cognitive', 'education'],
            'personal_development' => ['habit', 'health', 'productivity', 'routine'],
            'self_improvement' => ['curator', 'self-improvement', 'self improvement', 'autonomous'],
        ];

        foreach ($keywordDomains as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $domains[] = $domain;
                    break;
                }
            }
        }

        $domains = $this->uniqueStrings($domains);

        return $domains === [] ? ['general'] : $domains;
    }

    /**
     * @return array<int,string>
     */
    private function affectedSurfaces(string $releaseType, array $domains): array
    {
        $surfaces = ['cli', 'api'];
        if ($releaseType === 'realtime') {
            $surfaces[] = 'mobile';
            $surfaces[] = 'voice_realtime';
        }
        if (in_array('finance', $domains, true)) {
            $surfaces[] = 'office';
        }
        if (in_array('marketing', $domains, true) || $releaseType === 'design') {
            $surfaces[] = 'app';
        }

        return $this->uniqueStrings($surfaces);
    }

    /**
     * @return array<int,string>
     */
    private function affectedRuntimes(string $releaseType): array
    {
        $runtimes = ['provider_driver', 'atlas_decide'];
        if (in_array($releaseType, ['connector', 'vertical_agents', 'tool_use'], true)) {
            $runtimes[] = 'connector_registry';
            $runtimes[] = 'super_tool_runtime';
        }
        if (in_array($releaseType, ['realtime', 'memory'], true)) {
            $runtimes[] = 'python_ai_data';
        }
        if ($releaseType === 'realtime') {
            $runtimes[] = 'swift_native_mac';
            $runtimes[] = 'mobile_native_edge';
        }

        return $this->uniqueStrings($runtimes);
    }

    private function recommendedAction(string $releaseType, array $domains, array $capabilities, array $connectors): string
    {
        if ($releaseType === 'capability_update' && $capabilities === [] && $connectors === []) {
            return 'bypass';
        }

        if (in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing'], true)) {
            return 'benchmark';
        }

        if ($releaseType === 'connector' || $connectors !== []) {
            return 'absorb';
        }

        if (in_array('general', $domains, true) && $capabilities === []) {
            return 'bypass';
        }

        return 'exploit_gap';
    }

    /**
     * @return array<int,string>
     */
    private function secondaryActions(string $primary, string $releaseType): array
    {
        $actions = match ($primary) {
            'benchmark' => ['absorb', 'exploit_gap'],
            'absorb' => ['benchmark'],
            'replace' => ['benchmark'],
            'exploit_gap' => ['benchmark'],
            default => ['archive_source_material'],
        };

        if ($releaseType === 'vertical_agents') {
            $actions[] = 'create_domain_skill_pack';
        }

        return $this->uniqueStrings($actions);
    }

    /**
     * @return array<int,array{path:string,exists:bool,reason:string}>
     */
    private function ownerDocs(array $domains, string $releaseType): array
    {
        $paths = [
            'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md' => 'release protocol owner',
            'docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md' => 'channel and multiplier thesis',
            'docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md' => 'decide signal owner',
            'docs/engineering-knowledge-base/atlas-ai-governed-backlog.md' => 'AP/backlog owner',
        ];

        foreach ($domains as $domain) {
            $path = 'docs/engineering-knowledge-base/domains/'.str_replace('_', '-', $domain).'.md';
            $paths[$path] = 'affected domain owner';
        }

        if ($releaseType === 'realtime') {
            $paths['docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md'] = 'realtime surface owner';
        }

        return collect($paths)
            ->map(fn (string $reason, string $path): array => [
                'path' => $path,
                'exists' => is_file(base_path($path)),
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function suggestedAps(string $provider, string $releaseId, string $releaseType, array $domains, string $action): array
    {
        $slug = Str::of($releaseId)->after($provider.'-')->slug('-')->value();
        $domain = $domains[0] ?? 'general';

        return [
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-RIVALS',
                'title' => 'Rivals benchmark for '.$releaseId,
                'kind' => 'benchmark',
                'owner' => 'docs/ap/',
            ],
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-SKILL-PACK',
                'title' => 'Domain Skill Pack extraction for '.$domain,
                'kind' => $releaseType === 'vertical_agents' ? 'domain_skill_pack' : 'capability_absorption',
                'owner' => 'docs/ap/',
            ],
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-DECIDE-SIGNAL',
                'title' => 'Atlas Decide signal and AP-99 calibration',
                'kind' => $action === 'bypass' ? 'archive_decision' : 'decide_signal',
                'owner' => 'docs/ap/',
            ],
        ];
    }

    private function threatToWrappers(string $releaseType): string
    {
        return in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing', 'connector'], true) ? 'high' : 'medium';
    }

    private function threatToAtlas(string $releaseType, array $domains): string
    {
        if ($releaseType === 'realtime') {
            return 'medium';
        }

        if (in_array('general', $domains, true)) {
            return 'medium';
        }

        return 'low';
    }

    private function potentialMultiplier(string $releaseType, array $domains, array $capabilities, array $connectors): string
    {
        if ($capabilities !== [] || $connectors !== []) {
            return 'high';
        }

        if (in_array($releaseType, ['vertical_agents', 'realtime', 'connector', 'coding', 'design', 'marketing', 'finance'], true)) {
            return 'high';
        }

        return in_array('general', $domains, true) ? 'medium' : 'high';
    }

    private function releaseFamily(string $releaseType): string
    {
        return match ($releaseType) {
            'vertical_agents', 'finance', 'marketing' => 'vertical_specialization',
            'realtime' => 'ambient_realtime_surface',
            'connector', 'tool_use' => 'tool_and_connector_expansion',
            'coding', 'design' => 'creation_workflow_acceleration',
            'memory' => 'context_memory_expansion',
            'model' => 'base_model_capability',
            default => 'capability_update',
        };
    }

    private function wrapperMarketImpact(string $releaseType): string
    {
        return $this->threatToWrappers($releaseType) === 'high'
            ? 'fragile_wrappers_may_die_at_this_layer'
            : 'monitor_for_absorption_or_archive';
    }

    private function rivalsRequired(string $releaseType, array $domains): bool
    {
        return in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing', 'finance'], true)
            || ! in_array('general', $domains, true);
    }

    /**
     * @return array<int,string>
     */
    private function risks(string $releaseType, string $recommendedAction): array
    {
        $risks = ['press_release_hype_without_evidence', 'hardcoded_provider_routing', 'surface_bypass_of_kernel'];

        if ($recommendedAction === 'benchmark') {
            $risks[] = 'benchmark_debt_if_no_rivals_suite_is_created';
        }
        if ($releaseType === 'realtime') {
            $risks[] = 'privacy_regression_if_audio_raw_is_persisted';
        }
        if ($releaseType === 'vertical_agents') {
            $risks[] = 'domain_maturity_lie_if_skill_pack_is_declared_implemented_without_evidence';
        }

        return $this->uniqueStrings($risks);
    }

    private function releaseId(string $provider, string $title): string
    {
        $titleSlug = Str::of($title)->slug('-')->value();
        $titleSlug = Str::of($titleSlug)->startsWith($provider.'-')
            ? Str::of($titleSlug)->after($provider.'-')->value()
            : $titleSlug;

        return Str::of($provider.'-'.$titleSlug.'-'.now()->format('Y-m'))->slug('-')->value();
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function uniqueStrings(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => Str::of((string) $value)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
