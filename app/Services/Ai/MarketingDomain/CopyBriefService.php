<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CopyBriefService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic VSL/copy brief skeleton — the vsl-architect + copy-framework +
     * awareness-router + persuasion-auditor skills, encoded. Given the awareness of the
     * traffic, it returns the lead to open with, the canonical VSL anatomy (each block + the
     * rule that makes it convert + the funnel symptom it causes when weak), and the persuasion
     * checklist to satisfy. The LLM fills the actual lines; the structure is decided here.
     *
     * @return array<string,mixed>
     */
    public function blueprint(?string $awareness = null): array
    {
        $route = $this->playbook->routeAwareness($awareness);

        return [
            'skill' => 'vsl-architect + copy-framework + awareness-router + persuasion-auditor',
            'awareness_routing' => $route,
            'vsl_anatomy' => $this->playbook->vslAnatomy(),
            'headline_formula' => '4U: Useful, Urgent, Unique, Ultra-specific — gerar 20, pontuar por especificidade',
            'copy_frameworks' => ['AIDA', 'PAS (Problem-Agitate-Solve)', 'PASTOR', 'BAB', 'SSO (Story-Solution-Offer) p/ frio'],
            'persuasion_checklist' => [
                'cialdini_7' => $this->playbook->persuasionPrinciples(),
                'life_force_8' => $this->playbook->lifeForce8(),
                'emotional_frames' => $this->playbook->emotionalFrames(),
            ],
            'reluctant_hero' => 'Jon Benson: o copy mostra que é igual à audiência + história dramática (reluctant hero).',
        ];
    }

    /**
     * Deterministic backend email-arc brief — the email-arc skill (was a 🔲 gap). Soap Opera
     * Sequence → daily Seinfeld + the deliverability setup that gets it to the inbox. Captures the
     * non-buyer and monetizes again — multiplies EPC without more traffic.
     *
     * @return array<string,mixed>
     */
    public function emailArcBlueprint(): array
    {
        return array_merge(['skill' => 'email-arc'], $this->playbook->emailArc());
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function draft(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['headline', 'audience', 'channel', 'message', 'call_to_action'] as $required) {
            if (empty($payload[$required])) {
                throw new InvalidArgumentException("copy brief requires field [{$required}]");
            }
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_COPY,
            'title' => (string) $payload['headline'],
            'payload' => array_merge($payload, [
                'no_unsubstantiated_claim' => true,
                'side_effect_policy' => ['auto_publish' => false],
            ]),
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_COPY,
                'payload' => $payload,
            ]),
        ]);
    }
}
