<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Support\YesNo;

/**
 * KeywordDecisionReceipt (L12 — governança/proveniência) — turns a keyword decision into an AUDITABLE,
 * REPRODUCIBLE record: the salient inputs, the verdict, the canonical laws (L0 KeywordKnowledgeCore) the
 * decision rests on, and a content HASH. The hash is the proof of "100% determinístico": same inputs →
 * same receipt_hash, bit-a-bit. This is the piece that makes "determinístico" VERIFICÁVEL (não só uma
 * palavra) e dá proveniência por decisão — o que o operador exige pra confiar despejando milhões.
 *
 * Provider-free, pure function. No I/O, no clock, no randomness — determinism by construction.
 */
class KeywordDecisionReceipt
{
    private KeywordKnowledgeCore $knowledge;

    public function __construct(?KeywordKnowledgeCore $knowledge = null)
    {
        $this->knowledge = $knowledge ?? new KeywordKnowledgeCore;
    }

    /**
     * @param  array<string,mixed>  $kw  a KeywordQualityIndex::score() row
     * @return array{keyword:string,decision:array<string,mixed>,provenance:array<int,array<string,mixed>>,core_version:string,receipt_hash:string}
     */
    public function issue(array $kw): array
    {
        $decision = [
            'keyword' => (string) ($kw['keyword'] ?? ''),
            'score' => $kw['score'] ?? null,
            'band' => $kw['band'] ?? null,
            'family' => $kw['family'] ?? null,
            'match_type' => $kw['match_type'] ?? null,
            'intent_tier' => $kw['intent']['tier'] ?? null,
            'intent_action' => $kw['intent']['action'] ?? null,
            'intent_polarity' => $kw['intent']['polarity'] ?? null,
            'intent_confidence' => $kw['intent']['confidence'] ?? null,
            'investment' => $kw['investment']['verdict'] ?? null,
            'investment_basis' => $kw['investment']['basis'] ?? null,
            'account_risk' => $kw['account_risk']['risk_level'] ?? 'none',
            'mind_awareness' => $kw['mind_state']['awareness'] ?? null,
        ];

        $outcomeApplied = isset($kw['outcome_weight']) && (float) $kw['outcome_weight'] !== 1.0;
        $provenance = array_values(array_filter(array_map(
            fn (string $id) => $this->knowledge->cite($id),
            $this->relevantLaws($decision, $outcomeApplied),
        )));

        return [
            'keyword' => $decision['keyword'],
            'decision' => $decision,
            'provenance' => $provenance,
            'core_version' => KeywordKnowledgeCore::VERSION,
            // pure content hash: same inputs -> same hash (repetibilidade bit-a-bit, anti-Goodhart auditável)
            'receipt_hash' => sha1($this->canonical($decision).'|'.KeywordKnowledgeCore::VERSION),
        ];
    }

    /**
     * Which canonical laws this decision rests on (provenance map). Deterministic from the decision facets.
     *
     * @param  array<string,mixed>  $d
     * @return array<int,string>
     */
    private function relevantLaws(array $d, bool $outcomeApplied = false): array
    {
        // Cada motor é a FONTE ÚNICA das leis que aplica (const LAWS) — o recibo não duplica ids soltos.
        $ids = ['expected-ctr-king']; // toda decisão de keyword repousa na economia QS/CTR
        if ($d['intent_tier'] !== null) {
            $ids = array_merge($ids, IntentLadderClassifier::LAWS);
        }
        if ($d['investment'] !== null) {
            $ids = array_merge($ids, KeywordInvestmentGate::LAWS);
        }
        if ($outcomeApplied) {
            $ids = array_merge($ids, KeywordOutcomeCalibrator::LAWS); // L10 flywheel: cita a venda real
        }
        if (($d['account_risk'] ?? 'none') !== 'none') {
            $ids = array_merge($ids, KeywordAccountRiskSignal::LAWS);
        }
        if (($d['match_type'] ?? null) !== null) {
            $ids = array_merge($ids, KeywordClusterer::LAWS); // estrutura/match-priority
        }

        return array_values(array_unique($ids));
    }

    /** Canonical, key-sorted serialization so equal decisions hash equal regardless of key order. */
    private function canonical(mixed $v): string
    {
        if (is_array($v)) {
            ksort($v);
            $parts = [];
            foreach ($v as $k => $val) {
                $parts[] = $k.':'.$this->canonical($val);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (is_bool($v)) {
            return YesNo::trueFalse($v);
        }

        return (string) ($v ?? 'null');
    }
}
