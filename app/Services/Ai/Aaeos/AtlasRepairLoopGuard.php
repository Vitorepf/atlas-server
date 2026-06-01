<?php

namespace App\Services\Ai\Aaeos;

/**
 * Repair-loop guard named by the AAEOS Cross-Department Choreography doc. Tracks
 * the repair iteration for a (from -> to) review cycle and enforces the max-3
 * contract: the 4th iteration escalates to Architect + Operator. Stateless per
 * call (the caller supplies the running count) but emits the canonical decision.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
 */
class AtlasRepairLoopGuard
{
    public function __construct(
        private readonly AtlasCrossDepartmentChoreographyService $choreography,
    ) {}

    /**
     * Guard the next repair attempt: returns the choreography decision plus
     * whether the attempt is admitted (repair) or must escalate.
     *
     * @return array<string,mixed>
     */
    public function guard(int $currentIteration): array
    {
        $next = max(1, $currentIteration + 1);
        $decision = $this->choreography->evaluateRepairLoop($next);

        return [
            'schema_version' => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'attempt' => $next,
            'admitted' => ($decision['decision'] ?? null) === 'repair',
            'escalated' => (bool) ($decision['escalate'] ?? false),
            'escalate_to' => $decision['escalate_to'] ?? [],
            'remaining_repairs' => $decision['remaining_repairs'] ?? 0,
        ];
    }
}
