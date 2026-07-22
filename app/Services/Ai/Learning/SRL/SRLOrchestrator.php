<?php

namespace App\Services\Ai\Cognitive\SRL;

class SRLOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.cognitive.srl_orchestrator.v1';

    public function __construct(
        private readonly SRLPreferenceService $preferences,
        private readonly SRLEpisodeRepository $episodes,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function beginIfEnabled(string $targetFlow, array $input = []): array
    {
        $domain = (string) ($input['domain'] ?? str($targetFlow)->before('.')->toString() ?: 'learning');
        $preference = $this->preferences->resolve($domain);

        if (! (bool) ($preference['enabled'] ?? false)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'skipped',
                'reason' => 'srl_overlay_disabled',
                'target_flow' => $targetFlow,
                'domain' => $domain,
                'preference' => $preference,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'started',
            'target_flow' => $targetFlow,
            'domain' => $domain,
            'preference' => $preference,
            'episode' => $this->episodes->start([
                ...$input,
                'target_flow' => $targetFlow,
                'domain' => $domain,
            ]),
        ];
    }
}
