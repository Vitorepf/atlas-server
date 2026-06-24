<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Scoring\MessageMatchScorer;

/**
 * FunnelCongruenceAuditor — the whole-funnel structural X-ray (Eixo 6, composed).
 *
 * The OS could audit ONE page (ConversionAuditor) and, separately, check promise continuity. But a funnel
 * lives or dies on the CHAIN: ad → bridge → page → checkout. This composes the three STRUCTURAL-TRUTH
 * instruments — message-match congruence per hop (MessageMatchScorer Jaccard), promise continuity
 * (FunnelContinuityAuditor: dropped hero claims + price bait-and-switch), and per-stage watch-through
 * leaks (WatchThroughLeakDetector) — into a single cross-asset report that names the WEAKEST HOP and the
 * concrete structural defects across the whole chain. Every signal it aggregates is a fact, not a quality
 * proxy (per the cycles 43-45 meta-lesson). Provider-free, deterministic, niche-agnostic.
 */
class FunnelCongruenceAuditor
{
    /** Below this adjacent-hop keyword overlap, the scent is too thin between stages. */
    private const WEAK_HOP = 20;

    public function __construct(
        private readonly MessageMatchScorer $messageMatch = new MessageMatchScorer,
        private readonly FunnelContinuityAuditor $continuity = new FunnelContinuityAuditor,
        private readonly WatchThroughLeakDetector $leaks = new WatchThroughLeakDetector,
    ) {}

    /**
     * @param  array<string,string>  $stages  ordered [label => copy]; first = top of funnel (ad)
     * @return array{verdict:string,hops:array<int,array{from:string,to:string,congruence:int}>,weakest_hop:?array{from:string,to:string,congruence:int},continuity:array<string,mixed>,leaks:array<int,array{stage:string,flaws:array<int,mixed>}>,defects:array<int,string>,assessed:bool}
     */
    public function audit(array $stages): array
    {
        $stages = array_filter($stages, static fn ($t) => is_string($t) && trim($t) !== '');
        if (count($stages) < 2) {
            return ['verdict' => 'not_assessed', 'hops' => [], 'weakest_hop' => null,
                'continuity' => [], 'leaks' => [], 'defects' => [], 'assessed' => false];
        }

        $labels = array_keys($stages);
        $texts = array_values($stages);
        $defects = [];

        // 1. Per-hop congruence (adjacent stages must echo each other's promise keywords).
        $hops = [];
        for ($i = 0; $i < count($texts) - 1; $i++) {
            $congruence = (int) ($this->messageMatch->score($texts[$i], $texts[$i + 1], $texts[$i + 1])['dimension_scores']['message_match'] ?? 0);
            $hop = ['from' => $labels[$i], 'to' => $labels[$i + 1], 'congruence' => $congruence];
            $hops[] = $hop;
            if ($congruence < self::WEAK_HOP) {
                $defects[] = "scent fraco no hop {$labels[$i]}→{$labels[$i + 1]} (congruência {$congruence}%): o estágio seguinte não ecoa a promessa do anterior.";
            }
        }
        $weakestHop = $hops === [] ? null : array_reduce($hops, static fn ($a, $b) => $a === null || $b['congruence'] < $a['congruence'] ? $b : $a);

        // 2. Promise continuity across the chain (dropped hero claim / price bait-and-switch).
        $continuity = $this->continuity->audit($stages);
        foreach (($continuity['breaks'] ?? []) as $b) {
            $defects[] = $b['name'].' — '.$b['detail'];
        }

        // 3. Per-stage watch-through leaks (reveal/CTA leaked in the opening).
        $leaks = [];
        foreach ($stages as $label => $copy) {
            $flaws = $this->leaks->detect($copy)['flaws'];
            if ($flaws !== []) {
                $leaks[] = ['stage' => $label, 'flaws' => $flaws];
                foreach ($flaws as $f) {
                    $defects[] = "[{$label}] ".$f['name'].' — '.$f['detail'];
                }
            }
        }

        return [
            'verdict' => $defects === [] ? 'sound' : 'has_defects',
            'hops' => $hops,
            'weakest_hop' => $weakestHop,
            'continuity' => $continuity,
            'leaks' => $leaks,
            'defects' => $defects,
            'assessed' => true,
        ];
    }
}
