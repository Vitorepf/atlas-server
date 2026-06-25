<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ConversionCriticGate — the provider-independent generator+verifier inversion for conversion copy.
 *
 * The composer generates copy with a weak governed provider; today the conversion auditors only SCORE
 * it (telemetry the code itself labels "bússola, não verdade") and nothing REFUSES weak output, so the
 * page that ships is the best single weak-provider roll. This gate flips that: it composes the EXISTING
 * deterministic auditors into a single block|warn|ok verdict so the composer can re-roll or surface a
 * refusal. 100% deterministic (no LLM, no DB, no network, no clock/random) → it survives any provider
 * swap untouched (the M× of the antifragility thesis) and turns a pile of read-only auditors into one
 * live, enforced consumer.
 *
 * Anti-Goodhart by construction (the pétreo floor):
 *  - HARD BLOCKS fire ONLY on STRUCTURAL TRUTHS — facts true regardless of vocabulary, that cannot be
 *    gamed by token-stuffing: a reveal/hard-CTA leaked in the opening (WatchThroughLeakDetector, which
 *    itself is the honest sliver salvaged after a vocabulary-proxy scorer was killed), choice-overload /
 *    no-CTA at the decision point (DecisionClarityAuditor), and ZERO concrete proof anywhere
 *    (ProofSubstanceAuditor::has_concrete === false — the extreme case, not a density threshold).
 *  - The MARKER-DENSITY signals (value-equation coverage, awareness-stage match, the overall/persuasion
 *    SCORES) are demoted to WARN-only and can NEVER force a re-roll — flipping a density prior into a
 *    hard gate is the exact loop-not-proxy-cleanup Goodhart trap, so it is forbidden here. They inform.
 *  - The gate emits no quality number of its own; it only DECIDES ship/refuse on the auditors' boolean
 *    structural verdicts. When real CVR exists (≥30/niche, flywheel dormant today) the prior thresholds
 *    get replaced by measured lift; the structural floors stay.
 */
class ConversionCriticGate
{
    /** Prior-score floors per threshold (WARN only — never block). 'off' disables the prior tier entirely. */
    private const PRIOR_FLOORS = ['strong' => 70, 'decent' => 55, 'off' => null];

    public function __construct(
        private readonly WatchThroughLeakDetector $watch = new WatchThroughLeakDetector,
        private readonly DecisionClarityAuditor $decision = new DecisionClarityAuditor,
        private readonly ProofSubstanceAuditor $proof = new ProofSubstanceAuditor,
        private readonly ValueEquationAuditor $value = new ValueEquationAuditor,
        private readonly AwarenessRouter $awareness = new AwarenessRouter,
        private readonly ConversionAuditor $auditor = new ConversionAuditor,
        private readonly PersuasionScorer $persuasion = new PersuasionScorer,
    ) {}

    /**
     * @param  string  $copy  the FINAL flattened bridge copy (same text the telemetry audits — no drift)
     * @param  string  $awarenessLevel  the asset's target awareness (warn tier; '' to skip)
     * @param  string  $threshold  'strong' | 'decent' | 'off'
     * @return array{verdict:'block'|'warn'|'ok',structural_pass:bool,threshold:string,reasons:array<int,array{floor:string,kind:string,detail:string}>}
     */
    public function evaluate(string $copy, string $awarenessLevel = '', string $threshold = 'decent'): array
    {
        $threshold = array_key_exists($threshold, self::PRIOR_FLOORS) ? $threshold : 'decent';
        $structural = [];
        $priors = [];

        // ---- STRUCTURAL TRUTHS (hard block, threshold-independent, cannot be gamed) ----------
        $watchFlaws = $this->arr($this->watch->detect($copy)['flaws'] ?? []);
        if ($watchFlaws !== []) {
            $structural[] = ['floor' => 'watch_through_leak', 'kind' => 'structural',
                'detail' => (string) ($watchFlaws[0]['detail'] ?? 'A página vaza o reveal/CTA no topo — não dá pra reter quem já recebeu o payoff.')];
        }

        $decisionFlaws = $this->arr($this->decision->audit($copy)['flaws'] ?? []);
        if ($decisionFlaws !== []) {
            $structural[] = ['floor' => 'decision_clarity', 'kind' => 'structural',
                'detail' => (string) ($decisionFlaws[0]['detail'] ?? 'O ponto de decisão não tem UMA ação dominante clara (deveria ser só: assistir a VSL).')];
        }

        if (($this->proof->audit($copy)['has_concrete'] ?? false) !== true) {
            $structural[] = ['floor' => 'proof_substance', 'kind' => 'structural',
                'detail' => 'Zero prova concreta na página (nenhum número, nome real, ratio ou demonstração) — só claim vago. Ancore com prova concreta.'];
        }

        // ---- PRIOR / MARKER-DENSITY SIGNALS (warn only — never block) -------------------------
        if ($threshold !== 'off') {
            $floor = self::PRIOR_FLOORS[$threshold];

            $highLev = array_values(array_filter(
                $this->arr($this->value->audit($copy)['gaps'] ?? []),
                static fn ($g): bool => is_array($g) && ($g['high_leverage'] ?? false) === true,
            ));
            if ($highLev !== []) {
                $names = implode(', ', array_filter(array_map(static fn ($g): string => (string) ($g['name'] ?? $g['key'] ?? ''), $highLev)));
                $priors[] = ['floor' => 'value_equation', 'kind' => 'prior',
                    'detail' => 'Termo de alto impacto da equação de valor sem cobertura: '.$names.' (prior — reforce, não bloqueia).'];
            }

            if ($awarenessLevel !== '') {
                $match = $this->awareness->match($copy, $awarenessLevel);
                if (($match['aligned'] ?? true) !== true) {
                    $priors[] = ['floor' => 'awareness_alignment', 'kind' => 'prior',
                        'detail' => "Lead parece calibrado para '".(string) ($match['detected'] ?? '?')."' mas o tráfego é '".(string) ($match['target'] ?? $awarenessLevel)."' (prior por densidade — só aviso)."];
                }
            }

            if ($floor !== null) {
                $overall = (int) ($this->auditor->audit($copy)['overall_score'] ?? 0);
                if ($overall < $floor) {
                    $priors[] = ['floor' => 'overall_score', 'kind' => 'prior',
                        'detail' => "Score de conversão {$overall} < piso {$threshold} ({$floor}) — prior 'bússola, não verdade', só aviso."];
                }
                $pscore = (int) round((float) ($this->persuasion->score($copy)['score'] ?? 0));
                if ($pscore < $floor) {
                    $priors[] = ['floor' => 'persuasion', 'kind' => 'prior',
                        'detail' => "Persuasão {$pscore} < piso {$threshold} ({$floor}) — prior, só aviso."];
                }
            }
        }

        $reasons = array_merge($structural, $priors);
        $verdict = $structural !== [] ? 'block' : ($priors !== [] ? 'warn' : 'ok');

        return [
            'verdict' => $verdict,
            'structural_pass' => $structural === [],
            'threshold' => $threshold,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  mixed  $v
     * @return array<int,mixed>
     */
    private function arr(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }
}
