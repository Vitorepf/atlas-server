<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

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
    public const FIELD_ESCALATE = 'escalate';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ESCALATE_TO = 'escalate_to';
    public const FIELD_REMAINING_REPAIRS = 'remaining_repairs';
    public const FIELD_ATTEMPT = 'attempt';
    public const FIELD_ADMITTED = 'admitted';
    public const FIELD_ESCALATED = 'escalated';
    public const FIELD_DECISION = 'decision';
    public const FIELD_REPAIR = 'repair';
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
            self::FIELD_SCHEMA_VERSION => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            self::FIELD_ATTEMPT => $next,
            self::FIELD_ADMITTED => ($decision[self::FIELD_DECISION] ?? null) === self::FIELD_REPAIR,
            self::FIELD_ESCALATED => (AiValueNormalizer::boolOrNull($decision[self::FIELD_ESCALATE] ?? null) ?? false),
            self::FIELD_ESCALATE_TO => $decision[self::FIELD_ESCALATE_TO] ?? [],
            self::FIELD_REMAINING_REPAIRS => $decision[self::FIELD_REMAINING_REPAIRS] ?? 0,
        ];
    }
}
