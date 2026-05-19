<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows\Handlers;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;

/**
 * Atlas Personal Development specialist flow.
 *
 * Non-clinical boundary is hard. Never diagnoses, never replaces licensed
 * professional advice; emits referral language when the topic crosses the
 * boundary.
 */
final class AtlasPersonalDevelopmentFlowHandler extends BaseSpecialistFlowHandler
{
    public function flowId(): string
    {
        return RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function extend(array $base, array $router, array $payload): array
    {
        return array_merge($base, [
            'execution_mode' => 'non_clinical_advisory',
            'side_effect_policy' => SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY,
            'clinical_boundary' => 'non_clinical_only',
            'personal_development_invariants' => [
                'non_clinical_boundary_required',
                'no_medical_or_psychiatric_diagnosis',
                'refer_to_licensed_professional_when_topic_crosses_boundary',
                'never_promise_outcome',
            ],
        ]);
    }
}
