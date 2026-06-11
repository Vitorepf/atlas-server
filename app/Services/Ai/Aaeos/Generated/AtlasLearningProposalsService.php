<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

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
final class AtlasLearningProposalsService
{
    /** Stable schema id stamped on every verdict. */
    public const SCHEMA_VERSION = 'atlas.aaeos.learning_proposals.v1';

    /**
     * Kinds whose change touches critical behavior. Mirrors the Compounding
     * persistence entrypoint's CRITICAL_KINDS so the two layers agree on what
     * "policy critica" means. These can NEVER auto-apply.
     */
    public const CRITICAL_KINDS = [
        'policy',
        'routing',
        'gate',
        'eval_gate',
        'heuristic',
    ];

    /** Kinds that are valid Learning outputs but do not gate critical behavior. */
    public const NON_CRITICAL_KINDS = [
        'retrieval_hint',
        'memory',
        'failure_pattern',
        'documentation_health',
    ];

    /** Risk bands a proposal can carry. */
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    /** Verdict statuses. */
    public const STATUS_ADMITTED = 'admitted';
    public const STATUS_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';
    public const STATUS_REJECTED = 'rejected';

    /** Application stages — the doc's "sugestao, decisao, aplicacao" split. */
    public const APPLY_AUTO = 'auto';
    public const APPLY_REVIEW = 'human_review';

    /**
     * Signal-strength floor. A signal scoring below this is "sinal fraco" and
     * cannot become a canon-bound proposal. Range 0.0–1.0.
     */
    public const WEAK_SIGNAL_FLOOR = 0.5;

    /**
     * Evaluate a single execution signal into a learning proposal verdict.
     *
     * The doc's Contratos: input = an execution signal (event/metric/outcome/
     * error), output = a proposal carrying justification, risk and a suggested
     * action — and the hard invariant that a proposal is never an application.
     *
     * @param  array<string,mixed>  $signal {
     *     kind: string,                  // one of CRITICAL_KINDS|NON_CRITICAL_KINDS
     *     summary?: string,
     *     evidence_refs?: list<string>,  // empty => never canon
     *     sample_size?: int,             // observations behind the signal
     *     effect_size?: float,           // 0.0–1.0 magnitude of the observed delta
     *     suggested_action?: string,
     * }
     * @return array<string,mixed>
     */
    public function evaluate(array $signal): array
    {
        $kind = $this->normalizeKind($signal['kind'] ?? null);
        $evidence = AtlasAaeosStringListNormalizer::trimmedScalarValues($signal['evidence_refs'] ?? []);
        $sampleSize = max(0, (int) ($signal['sample_size'] ?? 0));
        $effect = $this->clamp01((float) ($signal['effect_size'] ?? 0.0));
        $summary = $this->string($signal['summary'] ?? null) ?? 'unspecified learning signal';

        $strength = $this->signalStrength($evidence, $sampleSize, $effect);
        $critical = $this->isCriticalKind($kind);

        // Rule 1: a kind we do not recognise is rejected outright — Learning
        // outputs are a closed vocabulary.
        if ($kind === null) {
            return $this->verdict(
                status: self::STATUS_REJECTED,
                kind: 'unknown',
                summary: $summary,
                justification: 'signal_kind_not_recognised',
                risk: self::RISK_HIGH,
                strength: $strength,
                critical: false,
                suggestedAction: 'discard',
            );
        }

        // Rule 1 (cont.): "Aprendizado sem evidencia nao deve virar canon."
        // No evidence => never admitted, regardless of strength.
        if ($evidence === []) {
            return $this->verdict(
                status: self::STATUS_REJECTED,
                kind: $kind,
                summary: $summary,
                justification: 'no_evidence_cannot_become_canon',
                risk: self::RISK_HIGH,
                strength: $strength,
                critical: $critical,
                suggestedAction: 'gather_evidence',
            );
        }

        // Rule 3: "Proposta baseada em sinal fraco." Below the floor it is held
        // for more evidence — it is NOT admitted as a canon-bound proposal.
        if ($strength < self::WEAK_SIGNAL_FLOOR) {
            return $this->verdict(
                status: self::STATUS_NEEDS_MORE_EVIDENCE,
                kind: $kind,
                summary: $summary,
                justification: 'weak_signal_below_floor',
                risk: $critical ? self::RISK_HIGH : self::RISK_MEDIUM,
                strength: $strength,
                critical: $critical,
                suggestedAction: 'accumulate_more_signal',
            );
        }

        // Admitted. Risk is driven by criticality first, then strength.
        return $this->verdict(
            status: self::STATUS_ADMITTED,
            kind: $kind,
            summary: $summary,
            justification: $this->string($signal['justification'] ?? null)
                ?? 'pattern_meets_evidence_and_strength_threshold',
            risk: $this->riskFor($critical, $strength),
            strength: $strength,
            critical: $critical,
            suggestedAction: $this->string($signal['suggested_action'] ?? null)
                ?? ($critical ? 'propose_change_for_review' : 'propose_change'),
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
            'suggestion' => 'learning_emits_proposal',
            'decision' => 'human_or_policy_decides',
            // Terminal stage is presentation, never direct mutation (Fluxo:
            // Output Renderer apresenta). Critical kinds force human review.
            'application' => $critical ? self::APPLY_REVIEW : self::APPLY_AUTO,
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
        $riskOrder = [self::RISK_LOW => 0, self::RISK_MEDIUM => 1, self::RISK_HIGH => 2];

        $sorted = $verdicts;
        usort($sorted, function (array $a, array $b) use ($riskOrder): int {
            // Admitted first.
            $aAdmitted = ($a['status'] ?? '') === self::STATUS_ADMITTED ? 0 : 1;
            $bAdmitted = ($b['status'] ?? '') === self::STATUS_ADMITTED ? 0 : 1;
            if ($aAdmitted !== $bAdmitted) {
                return $aAdmitted <=> $bAdmitted;
            }
            // Then lower risk.
            $aRisk = $riskOrder[$a['risk'] ?? self::RISK_HIGH] ?? 2;
            $bRisk = $riskOrder[$b['risk'] ?? self::RISK_HIGH] ?? 2;
            if ($aRisk !== $bRisk) {
                return $aRisk <=> $bRisk;
            }
            // Then higher strength.
            return ($b['strength'] ?? 0.0) <=> ($a['strength'] ?? 0.0);
        });

        $canonReady = array_values(array_filter(
            $sorted,
            static fn (array $v): bool => ($v['status'] ?? '') === self::STATUS_ADMITTED,
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total' => count($sorted),
            'ranked' => $sorted,
            'canon_ready' => $canonReady,
            'canon_ready_count' => count($canonReady),
        ];
    }

    /**
     * The doc's Exemplo, made executable: when a challenger provider out-scores
     * the incumbent on a task class, Atlas may propose a default-route change.
     * A provider-comparison signal is a `routing` (critical) proposal — so even
     * a strong, well-evidenced result CANNOT auto-apply; it must be reviewed.
     *
     * @param  array<string,mixed>  $comparison {
     *     challenger: string, incumbent: string, task_class: string,
     *     win_rate?: float, sample_size?: int, evidence_refs?: list<string>,
     * }
     * @return array<string,mixed>
     */
    public function evaluateProviderComparison(array $comparison): array
    {
        $winRate = $this->clamp01((float) ($comparison['win_rate'] ?? 0.0));
        // Effect size = how far the win rate is from a coin flip.
        $effect = $this->clamp01(abs($winRate - 0.5) * 2.0);

        $verdict = $this->evaluate([
            'kind' => 'routing',
            'summary' => sprintf(
                'route %s default to %s over %s',
                (string) ($comparison['task_class'] ?? 'task'),
                (string) ($comparison['challenger'] ?? 'challenger'),
                (string) ($comparison['incumbent'] ?? 'incumbent'),
            ),
            'evidence_refs' => $comparison['evidence_refs'] ?? [],
            'sample_size' => $comparison['sample_size'] ?? 0,
            'effect_size' => $effect,
            'suggested_action' => 'propose_default_route_change',
        ]);

        // Routing is critical: assert the invariant regardless of strength.
        $verdict['may_auto_apply'] = false;
        $verdict['requires_review'] = true;

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
        $effectScore = $this->clamp01($effectSize);

        $strength = ($evidenceScore + $sampleScore + $effectScore) / 3.0;

        return round($this->clamp01($strength), 4);
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
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'kind' => $kind,
            'critical' => $critical,
            'summary' => $summary,
            // The doc's three required output fields:
            'justification' => $justification,
            'risk' => $risk,
            'suggested_action' => $suggestedAction,
            'strength' => $strength,
            // The hard invariant: a proposal is never an application.
            // Critical kinds can never auto-apply even once admitted.
            'may_auto_apply' => $admitted && ! $critical,
            'requires_review' => $critical || ! $admitted,
            'terminal_stage' => 'render_to_human',
        ];
    }

    private function riskFor(bool $critical, float $strength): string
    {
        if ($critical) {
            // Critical changes are never low risk.
            return $strength >= 0.8 ? self::RISK_MEDIUM : self::RISK_HIGH;
        }

        if ($strength >= 0.8) {
            return self::RISK_LOW;
        }

        return $strength >= 0.6 ? self::RISK_MEDIUM : self::RISK_HIGH;
    }

    private function normalizeKind(mixed $value): ?string
    {
        $kind = $this->string($value);
        if ($kind === null) {
            return null;
        }
        $kind = strtolower($kind);

        if (in_array($kind, self::CRITICAL_KINDS, true) || in_array($kind, self::NON_CRITICAL_KINDS, true)) {
            return $kind;
        }

        // A couple of documented aliases.
        return match ($kind) {
            'router' => 'routing',
            'retrieval', 'retrieval_hints' => 'retrieval_hint',
            default => null,
        };
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
