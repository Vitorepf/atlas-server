<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Support\Clamp01;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Learning Proposals — pure decision runtime.
 *
 * Turns the documented Learning Proposals contract into deterministic, pure
 * decision logic over plain typed arrays. NO database, NO models, NO side
 * effects — every method is a pure function of its arguments.
 *
 * This is NOT the persistence entrypoint (that is
 * App\Services\Ai\Compounding\AtlasLearningProposalService, which materialises
 * AiLearningProposal rows and runs the approve/reject/apply state machine).
 * This service answers the *evaluation* questions the doc states BEFORE any
 * row exists: given raw execution signals (events, metrics, outcomes, errors),
 * does a proposal admit, what is its risk, what action is suggested, and may it
 * ever auto-apply? It makes the doc's invariants enforceable and unit-testable
 * independently of any pipeline or table.
 *
 * Concrete contract enforced (mapped to the doc sections):
 *
 *   1. Contratos — "Entrada: eventos, metricas, outcomes e erros. Saida:
 *      proposta com justificativa, risco e acao sugerida." evaluate() consumes
 *      a signal and emits exactly {justification, risk, suggested_action, ...}.
 *      A signal with no evidence refs NEVER produces an admitted proposal —
 *      "Aprendizado sem evidencia nao deve virar canon."
 *
 *   2. Invariante / Riscos — "proposta nao e aplicacao automatica" and
 *      "Aprendizado virar mutacao silenciosa". A proposal that targets critical
 *      behavior (policy/routing/gate/eval_gate/heuristic) is ALWAYS
 *      requires_review=true and may_auto_apply=false. The escalation is
 *      one-way: critical can never be downgraded to auto-apply.
 *
 *   3. Riscos — "Proposta baseada em sinal fraco". A signal whose strength is
 *      below the weak-signal floor is classified `weak` and is NOT admitted as
 *      a canon-bound proposal; it is held as `needs_more_evidence`. Strength is
 *      a deterministic function of sample size, effect size and evidence count.
 *
 *   4. Regras para IA — "IA deve separar sugestao, decisao e aplicacao." The
 *      verdict carries three distinct stages (suggestion / decision /
 *      application) and the application stage is NEVER `auto` for critical
 *      kinds. classifyStages() pins that separation.
 *
 *   5. Escopo de Implementacao — "Permitido: propostas, rankings e curadoria."
 *      rank() orders a batch of evaluated proposals by (admitted desc, risk
 *      asc, strength desc) so curation surfaces the safest strong signals
 *      first, and drops anything not admitted from the canon-ready set.
 *
 *   6. Fluxo — "Evidence Ledger registra. Learning avalia padroes e cria
 *      proposta. Output Renderer apresenta." A proposal's terminal stage is
 *      always `render_to_human` (or system), never `mutate`.
 *
 * @see docs/engineering-knowledge-base/system-graph/learning-proposals.md
 */
final class AtlasLearningProposalDecisionService
{
    /** Stable schema id stamped on every verdict. */
    public const SCHEMA_VERSION = 'atlas.aaeos.learning_proposals.v1';

    /**
     * Kinds whose change touches critical behavior. Mirrors the Compounding
     * persistence entrypoint's CRITICAL_KINDS so the two layers agree on what
     * "policy critica" means. These can NEVER auto-apply.
     */
    public const CRITICAL_KINDS = [
        self::FIELD_POLICY,
        self::FIELD_ROUTING,
        self::FIELD_GATE,
        self::FIELD_EVAL_GATE,
        self::FIELD_HEURISTIC,
    ];

    /** Kinds that are valid Learning outputs but do not gate critical behavior. */
    public const NON_CRITICAL_KINDS = [
        self::FIELD_RETRIEVAL_HINT,
        self::FIELD_MEMORY,
        self::FIELD_FAILURE_PATTERN,
        self::FIELD_DOCUMENTATION_HEALTH,
    ];

    /** Risk bands a proposal can carry. */
    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    /** Verdict statuses. */
    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';

    public const STATUS_REJECTED = 'rejected';

    /** Application stages — the doc's self::FIELD_SUGESTAO__DECISAO__APLICACAO split. */
    public const APPLY_AUTO = 'auto';

    public const APPLY_REVIEW = 'human_review';

    /**
     * Signal-strength floor. A signal scoring below this is "sinal fraco" and
     * cannot become a canon-bound proposal. Range 0.0–1.0.
     */
    public const WEAK_SIGNAL_FLOOR = 0.5;

    public const FIELD_STATUS = 'status';

    public const FIELD_EVIDENCE_REFS = 'evidence_refs';

    public const FIELD_KIND = 'kind';

    public const FIELD_RISK = 'risk';

    public const FIELD_ROUTING = 'routing';

    public const FIELD_SAMPLE_SIZE = 'sample_size';

    public const FIELD_STRENGTH = 'strength';

    public const FIELD_SUGGESTED_ACTION = 'suggested_action';

    public const FIELD_SUMMARY = 'summary';

    public const FIELD_CHALLENGER = 'challenger';

    public const FIELD_INCUMBENT = 'incumbent';

    public const FIELD_EFFECT_SIZE = 'effect_size';

    public const FIELD_JUSTIFICATION = 'justification';

    public const FIELD_MAY_AUTO_APPLY = 'may_auto_apply';

    public const FIELD_REQUIRES_REVIEW = 'requires_review';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_RETRIEVAL_HINT = 'retrieval_hint';

    public const FIELD_TASK = 'task';

    public const FIELD_APPLICATION = 'application';

    public const FIELD_CANON_READY = 'canon_ready';

    public const FIELD_CANON_READY_COUNT = 'canon_ready_count';

    public const FIELD_CRITICAL = 'critical';

    public const FIELD_DECISION = 'decision';

    public const FIELD_DOCUMENTATION_HEALTH = 'documentation_health';

    public const FIELD_GATE = 'gate';

    public const FIELD_EVAL_GATE = 'eval_gate';

    public const FIELD_HUMAN_OR_POLICY_DECIDES = 'human_or_policy_decides';

    public const FIELD_LEARNING_EMITS_PROPOSAL = 'learning_emits_proposal';

    public const FIELD_MEMORY = 'memory';

    public const FIELD_PROPOSE_CHANGE_FOR_REVIEW = 'propose_change_for_review';

    public const FIELD_PROPOSE_DEFAULT_ROUTE_CHANGE = 'propose_default_route_change';

    public const FIELD_RANKED = 'ranked';

    public const FIELD_RENDER_TO_HUMAN = 'render_to_human';

    public const FIELD_RETRIEVAL = 'retrieval';

    public const FIELD_FAILURE_PATTERN = 'failure_pattern';

    public const FIELD_HEURISTIC = 'heuristic';

    public const FIELD_RETRIEVAL_HINTS = 'retrieval_hints';

    public const FIELD_ROUTER = 'router';

    public const FIELD_SUGGESTION = 'suggestion';

    public const FIELD_TASK_CLASS = 'task_class';

    public const FIELD_TERMINAL_STAGE = 'terminal_stage';

    public const FIELD_TOTAL = 'total';

    public const FIELD_UNSPECIFIED_LEARNING_SIGNAL = 'unspecified learning signal';

    public const FIELD_WIN_RATE = 'win_rate';

    public const FIELD_ACCUMULATE_MORE_SIGNAL = 'accumulate_more_signal';

    public const FIELD_DISCARD = 'discard';

    public const FIELD_GATHER_EVIDENCE = 'gather_evidence';

    public const FIELD_NO_EVIDENCE_CANNOT_BECOME_CANON = 'no_evidence_cannot_become_canon';

    public const FIELD_PATTERN_MEETS_EVIDENCE_AND_STRENGTH_THRESHOLD = 'pattern_meets_evidence_and_strength_threshold';

    public const FIELD_POLICY = 'policy';

    public const FIELD_PROPOSE_CHANGE = 'propose_change';

    public const FIELD_ROUTE__S_DEFAULT_TO__S_OVER__S = 'route %s default to %s over %s';

    public const FIELD_SIGNAL_KIND_NOT_RECOGNISED = 'signal_kind_not_recognised';

    public const FIELD_SUGESTAO__DECISAO__APLICACAO = 'sugestao, decisao, aplicacao';

    public const FIELD_UNKNOWN = 'unknown';

    public const FIELD_WEAK_SIGNAL_BELOW_FLOOR = 'weak_signal_below_floor';

    public const FLOAT_0_8 = 0.8;

    public const FLOAT_0_6 = 0.6;

    public const INT_2 = 2;

    /**
     * Evaluate a single execution signal into a learning proposal verdict.
     *
     * The doc's Contratos: input = an execution signal (event/metric/outcome/
     * error), output = a proposal carrying justification, risk and a suggested
     * action — and the hard invariant that a proposal is never an application.
     *
     * @param  array<string,mixed>  $signal  {
     *                                       kind: string,                  // one of CRITICAL_KINDS|NON_CRITICAL_KINDS
     *                                       summary?: string,
     *                                       evidence_refs?: list<string>,  // empty => never canon
     *                                       sample_size?: int,             // observations behind the signal
     *                                       effect_size?: float,           // 0.0–1.0 magnitude of the observed delta
     *                                       suggested_action?: string,
     *                                       }
     * @return array<string,mixed>
     */
    public function evaluate(array $signal): array
    {
        $kind = $this->normalizeKind($signal[self::FIELD_KIND] ?? null);
        $evidence = AtlasStringListNormalizer::trimmedScalarValues($signal[self::FIELD_EVIDENCE_REFS] ?? []);
        $sampleSize = max(0, (int) ($signal[self::FIELD_SAMPLE_SIZE] ?? 0));
        $effect = Clamp01::of((float) ($signal[self::FIELD_EFFECT_SIZE] ?? 0.0));
        $summary = $this->string($signal[self::FIELD_SUMMARY] ?? null) ?? self::FIELD_UNSPECIFIED_LEARNING_SIGNAL;

        $strength = $this->signalStrength($evidence, $sampleSize, $effect);
        $critical = $this->isCriticalKind($kind);

        // Rule 1: a kind we do not recognise is rejected outright — Learning
        // outputs are a closed vocabulary.
        if ($kind === null) {
            return $this->verdict(
                status: self::STATUS_REJECTED,
                kind: self::FIELD_UNKNOWN,
                summary: $summary,
                justification: self::FIELD_SIGNAL_KIND_NOT_RECOGNISED,
                risk: self::RISK_HIGH,
                strength: $strength,
                critical: false,
                suggestedAction: self::FIELD_DISCARD,
            );
        }

        // Rule 1 (cont.): "Aprendizado sem evidencia nao deve virar canon."
        // No evidence => never admitted, regardless of strength.
        if ($evidence === []) {
            return $this->verdict(
                status: self::STATUS_REJECTED,
                kind: $kind,
                summary: $summary,
                justification: self::FIELD_NO_EVIDENCE_CANNOT_BECOME_CANON,
                risk: self::RISK_HIGH,
                strength: $strength,
                critical: $critical,
                suggestedAction: self::FIELD_GATHER_EVIDENCE,
            );
        }

        // Rule 3: "Proposta baseada em sinal fraco." Below the floor it is held
        // for more evidence — it is NOT admitted as a canon-bound proposal.
        if ($strength < self::WEAK_SIGNAL_FLOOR) {
            return $this->verdict(
                status: self::STATUS_NEEDS_MORE_EVIDENCE,
                kind: $kind,
                summary: $summary,
                justification: self::FIELD_WEAK_SIGNAL_BELOW_FLOOR,
                risk: $critical ? self::RISK_HIGH : self::RISK_MEDIUM,
                strength: $strength,
                critical: $critical,
                suggestedAction: self::FIELD_ACCUMULATE_MORE_SIGNAL,
            );
        }

        // Admitted. Risk is driven by criticality first, then strength.
        return $this->verdict(
            status: self::STATUS_ADMITTED,
            kind: $kind,
            summary: $summary,
            justification: $this->string($signal[self::FIELD_JUSTIFICATION] ?? null)
                ?? self::FIELD_PATTERN_MEETS_EVIDENCE_AND_STRENGTH_THRESHOLD,
            risk: $this->riskFor($critical, $strength),
            strength: $strength,
            critical: $critical,
            suggestedAction: $this->string($signal[self::FIELD_SUGGESTED_ACTION] ?? null)
                ?? ($critical ? self::FIELD_PROPOSE_CHANGE_FOR_REVIEW : self::FIELD_PROPOSE_CHANGE),
        );
    }

    /**
     * Classify the doc's three stages: sugestao -> decisao -> aplicacao.
     *
     * "IA deve separar sugestao, decisao e aplicacao." The application stage is
     * NEVER `auto` for a critical kind — that is the silent-mutation guard.
     *
     * @return array<string,string>
     */
    public function classifyStages(string $kind): array
    {
        $normalized = $this->normalizeKind($kind);
        $critical = $normalized !== null && $this->isCriticalKind($normalized);

        return [
            self::FIELD_SUGGESTION => self::FIELD_LEARNING_EMITS_PROPOSAL,
            self::FIELD_DECISION => self::FIELD_HUMAN_OR_POLICY_DECIDES,
            // Terminal stage is presentation, never direct mutation (Fluxo:
            // Output Renderer apresenta). Critical kinds force human review.
            self::FIELD_APPLICATION => $critical ? self::APPLY_REVIEW : self::APPLY_AUTO,
        ];
    }

    /**
     * Rank + curate a batch of evaluated verdicts.
     *
     * "Permitido: propostas, rankings e curadoria." Admitted proposals sort
     * ahead of held/rejected ones; within a status, lower risk and higher
     * strength surface first. The canon-ready set excludes anything not
     * admitted — weak or evidence-less signals never reach canon.
     *
     * @param  list<array<string,mixed>>  $verdicts  output of evaluate()
     * @return array<string,mixed>
     */
    public function rank(array $verdicts): array
    {
        $riskOrder = [self::RISK_LOW => 0, self::RISK_MEDIUM => 1, self::RISK_HIGH => self::INT_2];

        $sorted = $verdicts;
        usort($sorted, function (array $a, array $b) use ($riskOrder): int {
            // Admitted first.
            $aAdmitted = ($a[self::FIELD_STATUS] ?? '') === self::STATUS_ADMITTED ? 0 : 1;
            $bAdmitted = ($b[self::FIELD_STATUS] ?? '') === self::STATUS_ADMITTED ? 0 : 1;
            if ($aAdmitted !== $bAdmitted) {
                return $aAdmitted <=> $bAdmitted;
            }
            // Then lower risk.
            $aRisk = $riskOrder[$a[self::FIELD_RISK] ?? self::RISK_HIGH] ?? 2;
            $bRisk = $riskOrder[$b[self::FIELD_RISK] ?? self::RISK_HIGH] ?? 2;
            if ($aRisk !== $bRisk) {
                return $aRisk <=> $bRisk;
            }

            // Then higher strength.
            return ($b[self::FIELD_STRENGTH] ?? 0.0) <=> ($a[self::FIELD_STRENGTH] ?? 0.0);
        });

        $canonReady = array_values(array_filter(
            $sorted,
            static fn (array $v): bool => ($v[self::FIELD_STATUS] ?? '') === self::STATUS_ADMITTED,
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_TOTAL => count($sorted),
            self::FIELD_RANKED => $sorted,
            self::FIELD_CANON_READY => $canonReady,
            self::FIELD_CANON_READY_COUNT => count($canonReady),
        ];
    }

    /**
     * The doc's Exemplo, made executable: when a challenger provider out-scores
     * the incumbent on a task class, Atlas may propose a default-route change.
     * A provider-comparison signal is a `routing` (critical) proposal — so even
     * a strong, well-evidenced result CANNOT auto-apply; it must be reviewed.
     *
     * @param  array<string,mixed>  $comparison  {
     *                                           challenger: string, incumbent: string, task_class: string,
     *                                           win_rate?: float, sample_size?: int, evidence_refs?: list<string>,
     *                                           }
     * @return array<string,mixed>
     */
    public function evaluateProviderComparison(array $comparison): array
    {
        $winRate = Clamp01::of((float) ($comparison[self::FIELD_WIN_RATE] ?? 0.0));
        // Effect size = how far the win rate is from a coin flip.
        $effect = Clamp01::of(abs($winRate - 0.5) * 2.0);

        $verdict = $this->evaluate([
            self::FIELD_KIND => self::FIELD_ROUTING,
            self::FIELD_SUMMARY => sprintf(
                self::FIELD_ROUTE__S_DEFAULT_TO__S_OVER__S,
                AiValueNormalizer::trimmedString($comparison[self::FIELD_TASK_CLASS] ?? self::FIELD_TASK) ?: self::FIELD_TASK,
                AiValueNormalizer::trimmedString($comparison[self::FIELD_CHALLENGER] ?? self::FIELD_CHALLENGER) ?: self::FIELD_CHALLENGER,
                AiValueNormalizer::trimmedString($comparison[self::FIELD_INCUMBENT] ?? self::FIELD_INCUMBENT) ?: self::FIELD_INCUMBENT,
            ),
            self::FIELD_EVIDENCE_REFS => $comparison[self::FIELD_EVIDENCE_REFS] ?? [],
            self::FIELD_SAMPLE_SIZE => $comparison[self::FIELD_SAMPLE_SIZE] ?? 0,
            self::FIELD_EFFECT_SIZE => $effect,
            self::FIELD_SUGGESTED_ACTION => self::FIELD_PROPOSE_DEFAULT_ROUTE_CHANGE,
        ]);

        // Routing is critical: assert the invariant regardless of strength.
        $verdict[self::FIELD_MAY_AUTO_APPLY] = false;
        $verdict[self::FIELD_REQUIRES_REVIEW] = true;

        return $verdict;
    }

    public function isCriticalKind(string $kind): bool
    {
        return in_array($kind, self::CRITICAL_KINDS, true);
    }

    /**
     * Deterministic signal strength in 0.0–1.0 from the three observable
     * inputs. Each contributes a third; sample size saturates at 30 obs.
     */
    public function signalStrength(array $evidenceRefs, int $sampleSize, float $effectSize): float
    {
        $evidenceScore = count($evidenceRefs) > 0 ? min(1.0, count($evidenceRefs) / 3.0) : 0.0;
        $sampleScore = min(1.0, max(0, $sampleSize) / 30.0);
        $effectScore = Clamp01::of($effectSize);

        $strength = ($evidenceScore + $sampleScore + $effectScore) / 3.0;

        return round(Clamp01::of($strength), 4);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function verdict(
        string $status,
        string $kind,
        string $summary,
        string $justification,
        string $risk,
        float $strength,
        bool $critical,
        string $suggestedAction,
    ): array {
        $admitted = $status === self::STATUS_ADMITTED;

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $status,
            self::FIELD_KIND => $kind,
            self::FIELD_CRITICAL => $critical,
            self::FIELD_SUMMARY => $summary,
            // The doc's three required output fields:
            self::FIELD_JUSTIFICATION => $justification,
            self::FIELD_RISK => $risk,
            self::FIELD_SUGGESTED_ACTION => $suggestedAction,
            self::FIELD_STRENGTH => $strength,
            // The hard invariant: a proposal is never an application.
            // Critical kinds can never auto-apply even once admitted.
            self::FIELD_MAY_AUTO_APPLY => $admitted && ! $critical,
            self::FIELD_REQUIRES_REVIEW => $critical || ! $admitted,
            self::FIELD_TERMINAL_STAGE => self::FIELD_RENDER_TO_HUMAN,
        ];
    }

    private function riskFor(bool $critical, float $strength): string
    {
        if ($critical) {
            // Critical changes are never low risk.
            return $strength >= self::FLOAT_0_8 ? self::RISK_MEDIUM : self::RISK_HIGH;
        }

        if ($strength >= self::FLOAT_0_8) {
            return self::RISK_LOW;
        }

        return $strength >= self::FLOAT_0_6 ? self::RISK_MEDIUM : self::RISK_HIGH;
    }

    private function normalizeKind(mixed $value): ?string
    {
        $kind = $this->string($value);
        if ($kind === null) {
            return null;
        }
        $kind = AiValueNormalizer::lowerTrimmedString($kind);

        if (in_array($kind, self::CRITICAL_KINDS, true) || in_array($kind, self::NON_CRITICAL_KINDS, true)) {
            return $kind;
        }

        // A couple of documented aliases.
        return match ($kind) {
            self::FIELD_ROUTER => self::FIELD_ROUTING,
            self::FIELD_RETRIEVAL, self::FIELD_RETRIEVAL_HINTS => self::FIELD_RETRIEVAL_HINT,
            default => null,
        };
    }

    private function string(mixed $value): ?string
    {
        return AiValueNormalizer::trimmedScalarStringOrNull($value);
    }

}
