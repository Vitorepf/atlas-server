<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

/**
 * Foundry · Frontier adversarial judge port (AP-C, armor invariant I3).
 *
 * The judge that adjudicates a generated evolution proposal. It is OWNED by the
 * Foundry Frontier and is deliberately NOT any pre-existing arena/comparison
 * service: it carries its own resolved provider/model identity so the I3 gate
 * can assert that the judge differs from the generator on RESOLVED identity
 * (provider AND model), not on a free-form label.
 *
 * Contract:
 *  - adjudicate() is called once per seat. Each call MUST default to refute and
 *    survive only on an explicit accept from a real adjudication path.
 *  - If the judge's resolved provider AND model equal the generator's resolved
 *    provider AND model, the seat MUST refute with reason
 *    'judge_equals_generator_blocked' before any dispatch.
 *  - No real provider execution path => the seat refutes honestly (default_refute
 *    family), never fabricates an accept.
 */
interface FrontierJudgePort
{
    /**
     * Adjudicate a single proposal for one panel seat.
     *
     * $context carries the generator's resolved identity for the
     * model-difference invariant:
     *   - generator_provider_resolved: string
     *   - generator_model_resolved:    string
     *
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $context
     * @return array{
     *     decision:string,
     *     reason:string,
     *     judge_label?:string,
     *     judge_provider_resolved?:string,
     *     judge_model_resolved?:string
     * }
     */
    public function adjudicate(array $proposal, array $context): array;
}
