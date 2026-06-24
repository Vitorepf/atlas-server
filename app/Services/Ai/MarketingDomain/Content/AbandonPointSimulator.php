<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\SalesMomentPatternIndex;

/**
 * AbandonPointSimulator — the faithful audience simulation that finds WHERE the tab closes (operator's
 * "sistema de simulação fiel do público para entender as falhas de conversão").
 *
 * The PersonaSimulator gives a per-copy aggregate (five reactions → a scalar). A reader does not abandon
 * "in general" — they abandon at a SPECIFIC point (the wall of text, the bare price, the unproven claim).
 * This walks the copy sentence by sentence, replays the persona panel on each cumulative prefix, and
 * records, per persona, the first point where will_close overtakes will_watch — the abandon point — and
 * labels its sales-moment ZONE (via SalesMomentPatternIndex). It turns "5 opinions in a scalar" into a
 * COORDINATE: which zone bleeds the most audience, and the objection at the moment they leave. Reuses
 * PersonaSimulator + SalesMomentPatternIndex (no new psychology). Deterministic, provider-free.
 */
class AbandonPointSimulator
{
    public function __construct(
        private readonly PersonaSimulator $personas = new PersonaSimulator,
        private readonly SalesMomentPatternIndex $moments = new SalesMomentPatternIndex,
    ) {}

    /**
     * @return array{abandon_by_persona:array<string,array<string,mixed>>,retained:array<int,string>,worst_zone:?string,lost_count:int,total:int,summary:string}
     */
    public function simulate(string $copy, string $niche = ''): array
    {
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<![0-9])[.!?]+(?![0-9])|\n+/u', $copy) ?: []), static fn ($s) => $s !== ''));
        if ($sentences === []) {
            return ['abandon_by_persona' => [], 'retained' => [], 'worst_zone' => null, 'lost_count' => 0, 'total' => 0, 'summary' => 'Copy vazia.'];
        }

        $abandon = [];          // persona => [sentence_index, zone, objection]
        $seen = [];             // persona keys ever seen
        $prefix = '';
        foreach ($sentences as $i => $s) {
            $prefix = trim($prefix.' '.$s);
            foreach ($this->personas->simulate($prefix, '', $niche) as $pkey => $p) {
                $seen[$pkey] = true;
                if (isset($abandon[$pkey])) {
                    continue;
                }
                $watch = (float) ($p['will_watch'] ?? 0);
                $close = (float) ($p['will_close'] ?? 0);
                if ($close > $watch) {
                    $abandon[$pkey] = [
                        'sentence_index' => $i,
                        'zone' => $this->zoneOf($s),
                        'objection' => (string) ($p['first_objection'] ?? ''),
                    ];
                }
            }
        }

        $retained = array_values(array_diff(array_keys($seen), array_keys($abandon)));

        // The zone that loses the most personas — the page's worst hemorrhage point.
        $zoneCounts = [];
        foreach ($abandon as $a) {
            $zoneCounts[$a['zone']] = ($zoneCounts[$a['zone']] ?? 0) + 1;
        }
        arsort($zoneCounts);
        $worstZone = array_key_first($zoneCounts);

        $lost = count($abandon);
        $total = count($seen);
        $summary = $lost === 0
            ? "Nenhuma persona abandona — o painel atravessa a página inteira ({$total}/{$total} retidas)."
            : "{$lost}/{$total} personas abandonam; pior zona = ".($worstZone ?? '—').' (onde mais gente fecha a aba).';

        return [
            'abandon_by_persona' => $abandon,
            'retained' => $retained,
            'worst_zone' => $worstZone,
            'lost_count' => $lost,
            'total' => $total,
            'summary' => $summary,
        ];
    }

    /** Dominant sales moment of a sentence (for labeling the abandon zone). */
    private function zoneOf(string $sentence): string
    {
        $cov = $this->moments->momentsCovered($sentence);
        arsort($cov);
        $top = array_key_first($cov);

        return ($top !== null && ($cov[$top] ?? 0) > 0) ? $top : 'body';
    }
}
