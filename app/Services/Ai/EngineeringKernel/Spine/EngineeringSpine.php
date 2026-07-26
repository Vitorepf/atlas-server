<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spine;

use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;

/**
 * ASDD S-ENG-SPINE: shared engineering excellence entry seam.
 *
 * Live mutative owner remains EliteExecutorKernel; this spine exercises the
 * shipped excellence law (R106 path-core) so mode adapters share one policy.
 */
final class EngineeringSpine
{
    public const SCHEMA = 'atlas.engineering.spine.v1';

    /**
     * @return array{schema:string,status:string,stages:list<string>,note:string}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'wired_qos_law',
            'stages' => ['prepare', 'verify', 'repair', 'promote'],
            'note' => 'Excellence depth/blockers via AgentQosExcellenceLaw; full court composition residual.',
        ];
    }

    /**
     * Resolve server excellence depth through the shipped R106 law (not a reimplementation).
     *
     * @param  array<string,mixed>  $context
     */
    public function resolveDepth(array $context): string
    {
        return AgentQosExcellenceLaw::resolveDepth($context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    public function blockers(array $context): array
    {
        return AgentQosExcellenceLaw::blockers($context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function evaluate(array $context): array
    {
        return AgentQosExcellenceLaw::evaluate($context);
    }
}
