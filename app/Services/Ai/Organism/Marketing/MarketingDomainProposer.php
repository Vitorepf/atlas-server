<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Marketing;

use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainProposer;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;

/**
 * AOBG N4.F4 — the MARKETING {@see DomainProposer}: the 2nd domain that PROVES the organism
 * abstraction generalizes (it is domain-agnostic, not finance-special).
 *
 * Marketing is NON-finance, LOW-STAKES, and NON-sensitive (per
 * {@see CrossDomainTaxonomyMap} — marketing is not in the
 * sensitive set). It proposes a CAMPAIGN / CONTENT DRAFT — a provider-safe description of a
 * campaign the operator could run — anchored to the brain context (cross-domain compounding
 * folded into the rationale via prior proposals seen).
 *
 * COST: cost-free + DETERMINISTIC by construction here. It composes the draft from the intent +
 * the brain refs using a fixed template — NO provider, NO network, NO publish. (A FUTURE
 * provider-backed copywriter would sit behind this SAME seam, GATED behind a flag + cost guard
 * and stubbed in tests — the organism never knows how the draft was produced.)
 *
 * PROPOSE-ONLY: the output is a {@see DomainProposal} DRAFT. It is NEVER published, NEVER an ad
 * spend, NEVER a send. The actuate boundary ({@see MarketingDomainActuator}) only ever returns
 * requires_operator — the operator publishes/runs it themselves. The honest validation is
 * {@see MarketingDomainValidator} (a deterministic content-quality heuristic, never a vanity
 * metric like raw impressions/likes).
 */
final class MarketingDomainProposer implements DomainProposer
{
    public const DOMAIN = 'marketing';

    public function propose(string $intent, array $brainContext = [], array $opts = []): DomainProposal
    {
        $payload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];

        $brainRefs = [];
        foreach ((array) ($brainContext['brain_refs'] ?? []) as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $brainRefs[] = trim($ref);
            }
        }

        // Cross-domain compounding signal (provider-safe labels only): how many prior proposals
        // the brain already holds — folded into the rationale, never copied raw.
        $priorSeen = count((array) ($brainContext['prior_proposals'] ?? []));

        $channel = trim((string) ($payload['channel'] ?? 'multi-channel (email + social + landing page)'));
        $audience = trim((string) ($payload['audience'] ?? 'the target audience'));

        // DETERMINISTIC DRAFT composition (no provider). A real campaign brief: a headline,
        // a value angle, a call-to-action, and the channel — all PROPOSE-ONLY copy.
        $headline = $this->headline($intent);
        $content = 'Campaign DRAFT for '.$audience.' on '.$channel.'. '
            .'Headline: "'.$headline.'". '
            .'Angle: '.$this->angle($intent).' '
            .'Call-to-action: "Learn more". '
            .'PROPOSE-ONLY — Atlas does NOT publish, send, or spend ad budget; review the draft and, if you choose, run it yourself.';

        $rationale = 'Brain-anchored marketing draft'
            .($brainRefs !== [] ? ' citing '.count($brainRefs).' brain ref(s)' : ' (no brain refs)')
            .'; '.$priorSeen.' prior proposal(s) considered (cross-domain compounding).'
            .' Validated on a deterministic content-quality heuristic (clarity + CTA + audience fit),'
            .' NOT a vanity metric (no raw impressions/likes/click-bait).';

        return DomainProposal::fromArray([
            'domain' => self::DOMAIN,
            'intent' => $intent,
            'content' => $content,
            'rationale' => $rationale,
            'brain_refs' => $brainRefs,
            'sensitive' => false, // marketing is low-stakes, non-sensitive
            // On-machine validator input only (the draft fields the heuristic scores). The
            // DomainProposal drops `payload` from the provider-safe view by construction.
            'payload' => [
                'headline' => $headline,
                'body' => $content,
                'audience' => $audience,
                'channel' => $channel,
                'has_cta' => true,
            ],
        ]);
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    public function label(): string
    {
        return 'marketing.deterministic_draft.on_machine.propose_only';
    }

    /** A concise provider-safe headline derived from the intent (deterministic, no provider). */
    private function headline(string $intent): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $intent) ?? $intent);
        $clean = ucfirst(mb_strtolower($clean));

        return mb_substr($clean, 0, 80);
    }

    /** A deterministic value angle (never click-bait; a plain benefit framing). */
    private function angle(string $intent): string
    {
        return 'lead with the concrete benefit for '.($intent !== '' ? mb_substr(mb_strtolower($intent), 0, 60) : 'the reader').'.';
    }
}
