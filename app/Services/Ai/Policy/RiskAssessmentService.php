<?php

namespace App\Services\Ai\Policy;

use App\Models\AiRiskAssessment;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class RiskAssessmentService
{
    /**
     * @param  array<int,array<string,mixed>>  $riskFactors
     * @param  array<int,array<string,mixed>>  $mitigations
     * @param  array<string,mixed>  $context
     */
    public function assess(
        string $targetType,
        ?string $targetRef,
        array $riskFactors,
        array $mitigations = [],
        array $context = [],
    ): AiRiskAssessment {
        $level = $this->deriveLevel($riskFactors);
        $residual = $this->deriveResidual($level, $mitigations);
        $payload = [
            'target_type' => $targetType,
            'target_ref' => $targetRef,
            'risk_level' => $level,
            'risk_factors' => $riskFactors,
            'mitigations' => $mitigations,
            'residual_risk' => $residual,
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
        ];

        return AiRiskAssessment::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'target_type' => $targetType,
            'target_ref' => $targetRef,
            'risk_level' => $level,
            'risk_factors' => $riskFactors,
            'mitigations' => $mitigations,
            'residual_risk' => $residual,
            'assessor_type' => $context['assessor_type'] ?? 'system',
            'assessment_hash' => MissionCanonicalHash::sha256($payload),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $factors
     */
    private function deriveLevel(array $factors): string
    {
        $max = 0;
        foreach ($factors as $factor) {
            $level = (string) ($factor['risk_level'] ?? PolicyCanon::RISK_LOW);
            $rank = PolicyCanon::RISK_RANK[$level] ?? 0;
            if ($rank > $max) {
                $max = $rank;
            }
        }
        if ($max === 0) {
            return PolicyCanon::RISK_LOW;
        }
        $byRank = array_flip(PolicyCanon::RISK_RANK);

        return (string) ($byRank[$max] ?? PolicyCanon::RISK_LOW);
    }

    /**
     * @param  array<int,array<string,mixed>>  $mitigations
     */
    private function deriveResidual(string $level, array $mitigations): string
    {
        if ($mitigations === []) {
            return $level;
        }
        $rank = PolicyCanon::RISK_RANK[$level] ?? 0;
        $reduction = 0;
        foreach ($mitigations as $mitigation) {
            $strength = (string) ($mitigation['strength'] ?? 'minor');
            $reduction += match ($strength) {
                'major' => 2,
                'moderate' => 1,
                default => 0,
            };
        }
        $remaining = max(1, $rank - $reduction);
        $byRank = array_flip(PolicyCanon::RISK_RANK);

        return (string) ($byRank[$remaining] ?? PolicyCanon::RISK_LOW);
    }
}
