<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the base that makes real-world actuation IMPOSSIBLE BY CONSTRUCTION.
 *
 * The propose-only ceiling is enforced here STRUCTURALLY rather than by discipline:
 *
 *   - {@see actuate()} is `final`. A domain actuator MUST extend this base, and CANNOT
 *     override the act path. There is no subclass hook on the decision to act.
 *   - The only thing the act path does is RECORD (a pure-data record builder) + RETURN
 *     {status:'requires_operator'}. It contains no I/O: no HTTP client, no order/trade
 *     API, no payment call, no publish, no filesystem write, no DB write of an action.
 *   - A subclass may ONLY customize the human-readable {@see operatorInstructions()} —
 *     a STRING describing what the OPERATOR should do. It cannot return a side effect;
 *     its return type is `string`.
 *
 * Therefore every concrete actuator, for every domain, can only ever return
 * "requires_operator". The {@see \Tests\Feature\Ai\Organism\AtlasOrganismServiceTest}
 * proves this with a hostile subclass that TRIES to act through every hook and a spy
 * that fails the test if any external/side-effect call is reached.
 */
abstract class AbstractDomainActuator implements DomainActuator
{
    public const CEILING = 'propose_only:requires_operator';

    /**
     * FINAL — the propose-only actuation. Records + returns requires_operator. A domain
     * CANNOT override this to act. No real-world side effect is reachable from here.
     *
     * @return array{status:string, recorded:bool, proposal_ref:string, domain:string, instructions:string, ceiling:string}
     */
    final public function actuate(DomainProposal $proposal): array
    {
        // The ONLY subclass-customizable bit is a human-readable instruction STRING.
        // It cannot perform or return a side effect (its return type is string), and we
        // hard-cast it to a string here so even a misbehaving override is contained.
        $instructions = (string) $this->operatorInstructions($proposal);
        if (trim($instructions) === '') {
            $instructions = $this->defaultInstructions($proposal);
        }

        // RECORD = build a pure-data record. No I/O. The organism is responsible for any
        // brain persistence (itself provider-safe + never-auto-promote); the actuator
        // never reaches a network, an exchange, a wallet, a publisher, or a buy button.
        return [
            'status' => 'requires_operator',
            'recorded' => true,
            'proposal_ref' => $proposal->ref(),
            'domain' => $proposal->domain,
            'instructions' => mb_substr($instructions, 0, 2000),
            'ceiling' => self::CEILING,
        ];
    }

    public function domain(): string
    {
        return static::DOMAIN;
    }

    /**
     * Subclass hook — customize the operator-facing instruction STRING only. It MUST be
     * a description of what the OPERATOR does in the real world; it may NOT execute the
     * action. The base default already returns a safe instruction; override to make it
     * domain-specific (e.g. "review this trade idea and place it yourself in your broker").
     */
    protected function operatorInstructions(DomainProposal $proposal): string
    {
        return $this->defaultInstructions($proposal);
    }

    private function defaultInstructions(DomainProposal $proposal): string
    {
        return 'Atlas has prepared a '.$proposal->domain.' proposal and recorded it for you. '
            .'It is PROPOSE-ONLY: Atlas does not execute real-world actions (no money, orders, '
            .'ad spend, purchases, or publishing). Review the proposal and, if you choose, '
            .'execute it yourself in the real world.';
    }

    /** Each concrete actuator declares its canonical domain id. */
    protected const DOMAIN = '';
}
