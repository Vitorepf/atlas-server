<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * QualifiedKeywordDossier — the launch decision over the scored keywords. The META is "cospe de PRIMEIRA
 * ≥5 keywords qualificadas"; this is the SELECTION that produces those ≥5, applying the gates the OS
 * already computes per keyword (KeywordQualityIndex output): it keeps only investible keywords
 * (investment.verdict ≠ gasto), routes account-death risks to a separate operator-decides bucket
 * (SINAL, never a freio — [[atlas-no-moral-limit-in-engine]]), and rejects the proven money-losers.
 * Provider-free, deterministic.
 *
 * Output buckets:
 *   • recommended — the launch set (investible, not high account-risk), best score first, ≥N target
 *   • high_risk   — investible BUT carries a high account-death flag (drug/celebrity) → operator decides
 *   • rejected    — investment.verdict = gasto (forecast/proven) → don't launch
 */
class QualifiedKeywordDossier
{
    private KeywordKnowledgeCore $knowledge;

    public function __construct(?KeywordKnowledgeCore $knowledge = null)
    {
        $this->knowledge = $knowledge ?? new KeywordKnowledgeCore;
    }

    /**
     * @param  array<int,array<string,mixed>>  $scored  KeywordQualityIndex::scoreEngineResult()['scored']
     * @return array{recommended:array<int,array<string,mixed>>,high_risk:array<int,array<string,mixed>>,rejected:array<int,array<string,mixed>>,enough:bool,summary:string}
     */
    public function select(array $scored, int $target = 5): array
    {
        // best score first (the input is usually pre-sorted, but don't rely on it).
        usort($scored, static fn ($a, $b): int => (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0));

        $recommended = [];
        $highRisk = [];
        $rejected = [];

        foreach ($scored as $s) {
            $verdict = (string) ($s['investment']['verdict'] ?? 'teste');
            $risk = (string) ($s['account_risk']['risk_level'] ?? 'none');

            if ($verdict === 'gasto') {
                $rejected[] = $this->row($s, 'investment.verdict=gasto');

                continue;
            }
            if ($risk === 'high') {
                $highRisk[] = $this->row($s, 'account_risk=high — '.$this->riskTypes($s));

                continue;
            }
            $recommended[] = $this->row($s, 'investible + low account-risk');
        }

        $enough = count($recommended) >= $target;
        $summary = $enough
            ? count($recommended).' keywords launch-ready (alvo '.$target.'); '.count($highRisk).' alto-risco-de-conta (você decide); '.count($rejected).' gasto'
            : 'só '.count($recommended).'/'.$target.' launch-ready — '.count($highRisk).' presas no risco-de-conta (libere se aceitar) ou aprofunde a oferta/mecanismo';

        return [
            'recommended' => array_slice($recommended, 0, max($target, count($recommended))),
            'high_risk' => $highRisk,
            'rejected' => $rejected,
            'enough' => $enough,
            'summary' => $summary,
            'knowledge' => $this->knowledgeBasis(), // proveniência: as leis canônicas (L0) em que a decisão se apoia
        ];
    }

    /** The canonical laws (KeywordKnowledgeCore) the launch decision rests on — provenance / Decision-Receipt seed. */
    private function knowledgeBasis(): array
    {
        $cites = array_values(array_filter(array_map(
            fn (string $id) => $this->knowledge->cite($id),
            ['breakeven-epc', 'rule-of-three', 'restricted-drug-suspension', 'exclusion-over-attraction'],
        )));

        return ['core_version' => KeywordKnowledgeCore::VERSION, 'laws' => $cites];
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function row(array $s, string $why): array
    {
        return [
            'keyword' => $s['keyword'] ?? '',
            'score' => $s['score'] ?? 0,
            'family' => $s['family'] ?? null,
            'match_type' => $s['match_type'] ?? null,
            'tier' => $s['intent']['tier'] ?? null,
            'investment' => $s['investment']['verdict'] ?? null,
            'investment_basis' => $s['investment']['basis'] ?? null,
            'mind_state' => $s['mind_state']['awareness'] ?? null,
            'page_angle' => $s['mind_state']['page_angle'] ?? null,
            'account_risk' => $s['account_risk']['risk_level'] ?? 'none',
            'why' => $why,
        ];
    }

    /** @param array<string,mixed> $s */
    private function riskTypes(array $s): string
    {
        $types = array_unique(array_column((array) ($s['account_risk']['flags'] ?? []), 'type'));

        return $types === [] ? 'risco' : implode('/', $types);
    }
}
