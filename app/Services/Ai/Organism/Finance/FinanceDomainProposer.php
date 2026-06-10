<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainProposer;

/**
 * AOBG N4.F1 — the FINANCE {@see DomainProposer} (the first real domain on the organism).
 *
 * Finance is SENSITIVE (per {@see \App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap}):
 * generation stays ON-MACHINE. This default proposer is DETERMINISTIC + cost-free — it
 * shapes a brain-anchored trade-IDEA proposal (NOT an order) from the intent + the
 * on-machine candidate `payload` (e.g. a candidate strategy's daily returns + scenarios
 * explored), the same inputs the strategy-evolution loop already produces. It NEVER calls
 * a provider and NEVER places a trade.
 *
 * A future provider-backed finance proposer would be GATED (flag + cost guard) and MUST
 * still run on-machine (finance is sensitive — never crossed to an external provider).
 * Either way the output is a propose-only {@see DomainProposal}: a description the operator
 * may choose to execute themselves. The honest validation is {@see FinanceDomainValidator}.
 */
final class FinanceDomainProposer implements DomainProposer
{
    public const DOMAIN = 'finance';

    public function propose(string $intent, array $brainContext = [], array $opts = []): DomainProposal
    {
        $payload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];

        $brainRefs = [];
        foreach ((array) ($brainContext['brain_refs'] ?? []) as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $brainRefs[] = trim($ref);
            }
        }

        // Cross-domain compounding signal (provider-safe labels): how many prior finance
        // proposals the brain already holds — folded into the rationale, never copied raw.
        $priorSeen = count((array) ($brainContext['prior_proposals'] ?? []));

        $instrument = trim((string) ($payload['instrument'] ?? 'BTC/USDT spot (daily)'));
        $rationale = 'Brain-anchored finance proposal'
            .($brainRefs !== [] ? ' citing '.count($brainRefs).' brain ref(s)' : ' (no brain refs)')
            .'; '.$priorSeen.' prior finance proposal(s) considered (cross-domain compounding).'
            .' Validated on the honest metric (N-deflated Sharpe / drawdown), NOT win-rate.';

        return DomainProposal::fromArray([
            'domain' => self::DOMAIN,
            'intent' => $intent,
            // PROVIDER-SAFE content: a trade IDEA description — never an order ticket,
            // never keys, never a position to send anywhere. Propose-only by construction.
            'content' => 'Trade idea on '.$instrument.': '.$intent
                .'. PROPOSE-ONLY — Atlas does not place orders or move money; review and, if you choose, execute it yourself.',
            'rationale' => $rationale,
            'brain_refs' => $brainRefs,
            'sensitive' => true, // finance is always sensitive — stays on-machine
            'payload' => $payload, // on-machine validator input only (e.g. daily_returns)
        ]);
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    public function label(): string
    {
        return 'finance.deterministic.on_machine.propose_only';
    }
}
