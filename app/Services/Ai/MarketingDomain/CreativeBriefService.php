<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreativeBriefService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic video-creative brief — the video-ad-architect + creative-pipeline skills.
     * The YouTube AD (not the whole VSL): hook(0-5s)→amplify→bridge→CTA with the retention rules
     * that stop the skip, plus the AI tooling for test velocity (the #1 scale lever on YouTube).
     *
     * @return array<string,mixed>
     */
    public function blueprint(): array
    {
        return [
            'skill' => 'video-ad-architect + creative-pipeline',
            'ad_structure' => $this->playbook->videoAdStructure(),
            'creative_tools' => $this->playbook->creativeTools(),
            'test_velocity' => 'gerar N hooks/ângulos por semana (Arcads 50+/sem) — velocidade de criativo é a alavanca nº1 de escala no YouTube',
            'ugc_note' => 'UGC-style (handheld, 1ª pessoa) bate polido em até 3× de completion; nativo não dispara ad-blindness',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function draft(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['concept', 'format', 'audience', 'visual_direction'] as $required) {
            if (empty($payload[$required])) {
                throw new InvalidArgumentException("creative brief requires field [{$required}]");
            }
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_CREATIVE,
            'title' => (string) $payload['concept'],
            'payload' => array_merge($payload, [
                'production_required' => true,
                'side_effect_policy' => ['auto_publish' => false],
            ]),
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_CREATIVE,
                'payload' => $payload,
            ]),
        ]);
    }
}
