<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingExperiment;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GrowthExperimentPlanService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic experiment/scale brief — cro-tester + scale-operator. The statistical decision
     * math (≥95% confidence, sample sizes, never cut early) and the safe scale ramp (10-20%/7-14d).
     * Anti-Goodhart: optimize profit per unit-economics, never a proxy — and never act on noise.
     *
     * @return array<string,mixed>
     */
    public function blueprint(): array
    {
        return [
            'skill' => 'cro-tester + scale-operator',
            'test_math' => $this->playbook->testMath(),
            'scale_rules' => $this->playbook->scaleRules(),
            'anti_goodhart' => 'Decida por LUCRO por unidade econômica (Max CPA), nunca por proxy (CTR/QS/cliques). Não corte o teste antes da significância.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function plan(AiMarketingRun $run, array $payload): AiMarketingExperiment
    {
        foreach (['name', 'hypothesis', 'primary_metric', 'success_criterion', 'decision_rule'] as $required) {
            if (empty($payload[$required])) {
                throw new InvalidArgumentException("experiment requires field [{$required}]");
            }
        }
        $variants = (array) ($payload['variants'] ?? []);
        if (count($variants) < 2) {
            throw new InvalidArgumentException('experiment requires at least 2 variants (control + treatment)');
        }
        foreach ($variants as $i => $variant) {
            if (empty($variant['name'])) {
                throw new InvalidArgumentException("experiment variant[{$i}] requires name");
            }
        }

        return AiMarketingExperiment::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'name' => (string) $payload['name'],
            'hypothesis' => (string) $payload['hypothesis'],
            'primary_metric' => (string) $payload['primary_metric'],
            'success_criterion' => (string) $payload['success_criterion'],
            'decision_rule' => (string) $payload['decision_rule'],
            'variants' => $variants,
            'guardrails' => (array) ($payload['guardrails'] ?? []),
            'status' => MarketingDomainCanon::EXPERIMENT_PROPOSED,
            'experiment_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'name' => $payload['name'],
                'hypothesis' => $payload['hypothesis'],
                'primary_metric' => $payload['primary_metric'],
                'variants' => $variants,
            ]),
            'metadata' => array_merge((array) ($payload['metadata'] ?? []), [
                'requires_approval_to_launch' => true,
            ]),
        ]);
    }
}
