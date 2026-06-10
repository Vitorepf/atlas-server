<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Marketing;

use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\DomainActuator;
use App\Services\Ai\Organism\DomainProposal;

/**
 * AOBG N4.F4 — the MARKETING {@see DomainActuator}.
 *
 * Extends {@see AbstractDomainActuator}, whose `actuate()` is FINAL: this class CANNOT publish
 * a post, send an email/campaign, spend ad budget, or call any platform/ad/CMS API — the act
 * path is sealed in the base and only ever RECORDS + returns 'requires_operator'.
 *
 * The single thing this subclass customizes is the human-readable instruction STRING the
 * OPERATOR reads to publish/run the campaign themselves. It performs no I/O.
 *
 * This proves the propose-only ceiling is DOMAIN-AGNOSTIC: a marketing actuator is just as
 * unable to perform a real-world action as the finance one — same sealed base, same gate. Even
 * a future contributor cannot turn it into a publisher without deleting the `final` keyword in
 * the base (which the test battery would catch).
 */
final class MarketingDomainActuator extends AbstractDomainActuator
{
    protected const DOMAIN = 'marketing';

    protected function operatorInstructions(DomainProposal $proposal): string
    {
        return 'PROPOSE-ONLY campaign DRAFT recorded. Atlas does NOT publish, send, or spend ad '
            .'budget. Review the draft and its content-quality validation (clarity + CTA + audience '
            .'fit — never vanity impressions/likes). If you choose to run it, publish it yourself in '
            .'your own marketing platform. Atlas will record only the decision, never execute it.';
    }
}
