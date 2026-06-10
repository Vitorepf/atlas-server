<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\DomainProposal;

/**
 * AOBG N4.F1 — the FINANCE {@see \App\Services\Ai\Organism\DomainActuator}.
 *
 * Extends {@see AbstractDomainActuator}, whose `actuate()` is FINAL: this class CANNOT
 * place a trade, sign an order, move money, or call any exchange/broker/wallet API — the
 * act path is sealed in the base and only ever RECORDS + returns 'requires_operator'.
 *
 * The single thing this subclass customizes is the human-readable instruction STRING the
 * OPERATOR reads to execute the trade idea themselves. It performs no I/O.
 *
 * This is the propose-only trading ceiling made STRUCTURAL: even a future contributor
 * cannot turn the finance actuator into an order placer without deleting the `final`
 * keyword in the base (which the test battery would catch).
 */
final class FinanceDomainActuator extends AbstractDomainActuator
{
    protected const DOMAIN = 'finance';

    protected function operatorInstructions(DomainProposal $proposal): string
    {
        return 'PROPOSE-ONLY trade idea recorded. Atlas does NOT place orders or move money. '
            .'Review the idea and its honest-metric validation (N-deflated Sharpe / drawdown — '
            .'win-rate is never used). If you choose to act, place the order yourself in your own '
            .'broker/exchange. Atlas will record only the decision, never execute it.';
    }
}
