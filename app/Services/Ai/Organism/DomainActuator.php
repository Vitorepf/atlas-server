<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the ACTUATE boundary. THE LOAD-BEARING SAFETY OF N4.
 *
 * HONEST CEILING (declared to the operator; NON-NEGOTIABLE): N4 is PROPOSE-ONLY. The
 * framework NEVER executes a real-world side effect — no real money, no real trade/order,
 * no real ad spend, no real purchase, no real publish, no outbound HTTP that acts. Those
 * are PROHIBITED by assistant safety rules AND gated to propose-only by Atlas's own canon
 * (the trading loop is propose-only, no real money).
 *
 * By construction, "actuate" means: RECORD the proposal + RETURN an operator instruction.
 * It returns {status:'requires_operator', recorded:true, proposal_ref, instructions} and
 * performs ZERO real-world side effect. The operator executes in the real world themselves.
 *
 * STRUCTURAL, not advisory: the concrete act path lives in {@see AbstractDomainActuator}
 * as a FINAL method. A domain implementor extends the base and can describe operator
 * instructions, but CANNOT override the method that decides whether to act — so no domain
 * can ever flip the boundary into doing the side effect. "requires_operator" is the only
 * reachable outcome.
 *
 * Never claim "operating companies live". The organism substitutes the DECISION work
 * (brain-anchored, honestly-validated proposals) and hands the irreversible real-world
 * action back to the human.
 */
interface DomainActuator
{
    /**
     * The propose-only actuation: record + return an operator instruction. NEVER acts.
     *
     * @return array{
     *     status: string,            // ALWAYS 'requires_operator'
     *     recorded: bool,            // the proposal was recorded for the operator
     *     proposal_ref: string,      // stable reference to the recorded proposal
     *     domain: string,
     *     instructions: string,      // what the OPERATOR must do to execute (human action)
     *     ceiling: string            // the declared propose-only label
     * }
     */
    public function actuate(DomainProposal $proposal): array;

    /** The canonical domain id this actuator serves. */
    public function domain(): string;
}
