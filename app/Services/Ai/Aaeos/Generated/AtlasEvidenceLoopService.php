<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Evidence Loop signal-governance decider.
 *
 * Pure, deterministic implementation of the Evidence Loop contract: the lateral
 * plane that turns execution, telemetry, gates and outcomes into calibrated
 * signals that Decide and Learning may consume on the NEXT cycle.
 *
 * The doc states a single hard invariant and a closed scope:
 *   - Contratos / Invariante: "sinal sem evidencia nao governa decisao"
 *     (a signal with no registered evidence does NOT govern a decision).
 *   - Regras para IA: "IA deve tratar evidencia como requisito para promover
 *     aprendizado, policy ou mudanca de default" (evidence is a REQUIREMENT to
 *     promote learning / policy / default change).
 *   - Escopo de Implementacao — Permitido: "agregacao de sinais e metricas".
 *     Proibido: "inferir sucesso sem gate ou outcome" (never infer success
 *     without a gate result OR a recorded outcome).
 *   - Riscos: "Metricas contaminadas reforcarem decisao ruim" and "Evidence
 *     incompleta parecer sucesso" — both must be detected, never promoted.
 *   - Fluxo / Exemplos: cost, latency, gate failures and repair attempts (AP-99)
 *     flow back from the ledger into Decide.
 *
 * This decider performs NO IO: it does not read the ledger, query telemetry,
 * call a provider or touch the database. A caller collects the raw per-signal
 * facts (does it carry an evidence-ledger reference? is there a gate verdict or
 * outcome? is the metric within sane bounds?) and this service turns them into
 * the single auditable governance decision plus, for a batch, the calibrated
 * aggregate that Decide/Learning are allowed to consume.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-loop.md
 */
final class AtlasEvidenceLoopService
{
    /** Stable receipt schema id for the per-signal decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.evidence.loop.signal.v1';

    /** Stable receipt schema id for the aggregate bundle. */
    public const AGGREGATE_SCHEMA = 'atlas.evidence.loop.aggregate.v1';

    /** Closed set of governance verdicts for a single signal. */
    public const ACTION_GOVERN = 'govern';      // evidence-backed → may influence Decide/Learning.
    public const ACTION_QUARANTINE = 'quarantine'; // recoverable gap → withhold until repaired.
    public const ACTION_REJECT = 'reject';      // contaminated / invalid → never governs.

    /**
     * Signal kinds the loop folds back into Decide (from the doc's Fluxo /
     * Exemplos: cost, latency, gate failures, repair attempts).
     *
     * @var list<string>
     */
    private const KNOWN_KINDS = ['cost', 'latency', 'gate_failure', 'repair_attempt', 'outcome'];

    /**
     * Govern ONE signal: decide whether it is allowed to influence a future
     * decision, and why.
     *
     * Input (all keys optional; safe-failing defaults applied):
     *   kind                 string  cost|latency|gate_failure|repair_attempt|outcome.
     *   evidence_ref         string  id/path of the evidence-ledger record. The
     *                                invariant: empty/blank → cannot govern.
     *   evidence_complete    bool    the evidence record is whole (not partial).
     *   gate_result          string  pass|fail|'' — the gate verdict, if any.
     *   has_outcome          bool    a real outcome was recorded.
     *   claims_success       bool    the signal asserts a success result.
     *   metric_value         float   the raw metric (cost/latency/...).
     *   metric_min           float   lower sane bound (default 0).
     *   metric_max           float   upper sane bound (default +INF).
     *   contaminated         bool    caller already flagged the metric tainted.
     *
     * @param  array<string,mixed>  $signal
     * @return array<string,mixed>
     */
    public function governSignal(array $signal): array
    {
        $s = $this->normalize($signal);

        $rejects = $this->rejectReasons($s);
        $holds = $this->quarantineReasons($s);

        if ($rejects !== []) {
            $action = self::ACTION_REJECT;
        } elseif ($holds !== []) {
            $action = self::ACTION_QUARANTINE;
        } else {
            $action = self::ACTION_GOVERN;
        }

        // The doc invariant in one boolean: a signal may govern a future
        // decision ONLY when it is evidence-backed and clean (no reject, no hold).
        $mayGovern = $action === self::ACTION_GOVERN;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'action' => $action,
            'kind' => $s['kind'],
            'may_govern' => $mayGovern,
            'evidence_backed' => $s['evidence_ref'] !== '' && $s['evidence_complete'],
            'reject_reasons' => $rejects,
            'quarantine_reasons' => $holds,
            'claims_success' => $s['claims_success'],
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate over governSignal(): true only when the signal is
     * cleared to influence Decide/Learning.
     *
     * @param  array<string,mixed>  $signal
     */
    public function mayGovern(array $signal): bool
    {
        return $this->governSignal($signal)['action'] === self::ACTION_GOVERN;
    }

    /**
     * Hard-reject reasons. A signal hitting ANY of these is contaminated or
     * invalid and must NEVER govern a decision (doc Riscos + the "no success
     * without gate/outcome" prohibition).
     *
     * @param  array<string,mixed>  $s  normalized signal
     * @return list<string>
     */
    public function rejectReasons(array $s): array
    {
        $r = [];

        // Riscos: "Metricas contaminadas reforcarem decisao ruim."
        if ($s['contaminated']) {
            $r[] = 'metric_contaminated';
        }

        // A metric outside its sane bounds is contamination we can detect here.
        if ($s['metric_value'] < $s['metric_min'] || $s['metric_value'] > $s['metric_max']) {
            $r[] = 'metric_out_of_bounds';
        }

        // Escopo Proibido: "inferir sucesso sem gate ou outcome." A signal that
        // claims success while NO gate passed AND NO outcome was recorded is an
        // inferred success — rejected outright.
        if ($s['claims_success'] && $s['gate_result'] !== 'pass' && ! $s['has_outcome']) {
            $r[] = 'success_inferred_without_gate_or_outcome';
        }

        return AiStringListNormalizer::uniqueStrings($r);
    }

    /**
     * Recoverable gaps. A signal here is not contaminated, but it is not yet
     * eligible to govern: the evidence is missing or incomplete. It is held
     * (quarantined) until the ledger record is attached/completed.
     *
     * @param  array<string,mixed>  $s  normalized signal
     * @return list<string>
     */
    public function quarantineReasons(array $s): array
    {
        $q = [];

        // THE invariant: "sinal sem evidencia nao governa decisao."
        if ($s['evidence_ref'] === '') {
            $q[] = 'missing_evidence_ref';
        }

        // Riscos: "Evidence incompleta parecer sucesso." Partial evidence is
        // withheld rather than trusted.
        if ($s['evidence_ref'] !== '' && ! $s['evidence_complete']) {
            $q[] = 'incomplete_evidence';
        }

        return AiStringListNormalizer::uniqueStrings($q);
    }

    /**
     * Aggregate a batch of signals into the calibrated bundle Decide/Learning may
     * consume. Per the Escopo: aggregation of signals/metrics is PERMITTED, but
     * only evidence-backed, clean signals contribute to the governing aggregate.
     * Quarantined/rejected signals are surfaced for audit but excluded from the
     * numbers that influence the next cycle.
     *
     * @param  list<array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    public function aggregate(array $signals): array
    {
        $governing = [];
        $quarantined = 0;
        $rejected = 0;

        /** @var array<string,list<float>> $byKind */
        $byKind = [];

        foreach ($signals as $signal) {
            $decision = $this->governSignal(is_array($signal) ? $signal : []);

            if ($decision['action'] === self::ACTION_QUARANTINE) {
                $quarantined++;

                continue;
            }
            if ($decision['action'] === self::ACTION_REJECT) {
                $rejected++;

                continue;
            }

            // Only governing signals fold into the calibrated metrics.
            $governing[] = $decision;
            $norm = $this->normalize(is_array($signal) ? $signal : []);
            $byKind[$norm['kind']][] = $norm['metric_value'];
        }

        $calibrated = [];
        foreach ($byKind as $kind => $values) {
            $calibrated[$kind] = [
                'count' => count($values),
                'sum' => array_sum($values),
                'mean' => $values === [] ? 0.0 : array_sum($values) / count($values),
                'max' => $values === [] ? 0.0 : max($values),
            ];
        }
        ksort($calibrated);

        $total = count($signals);
        $governCount = count($governing);

        return [
            'schema' => self::AGGREGATE_SCHEMA,
            'total_signals' => $total,
            'governing_signals' => $governCount,
            'quarantined_signals' => $quarantined,
            'rejected_signals' => $rejected,
            // The aggregate may only feed Decide when at least one clean,
            // evidence-backed signal contributed. An all-empty/dirty batch
            // produces no governing signal.
            'feeds_decide' => $governCount > 0,
            'calibrated' => $calibrated,
            'auditable' => true,
        ];
    }

    /**
     * Coerce arbitrary input into the strict, fully-defaulted signal shape. Each
     * default is chosen so a MISSING fact never silently counts as "trustworthy":
     * no evidence_ref, no gate pass, no outcome.
     *
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    private function normalize(array $in): array
    {
        $kind = is_string($in['kind'] ?? null) ? (string) $in['kind'] : 'outcome';
        if (! in_array($kind, self::KNOWN_KINDS, true)) {
            $kind = 'outcome';
        }

        $gate = is_string($in['gate_result'] ?? null) ? (string) $in['gate_result'] : '';
        if (! in_array($gate, ['pass', 'fail', ''], true)) {
            $gate = '';
        }

        $min = isset($in['metric_min']) && is_numeric($in['metric_min']) ? (float) $in['metric_min'] : 0.0;
        $max = isset($in['metric_max']) && is_numeric($in['metric_max']) ? (float) $in['metric_max'] : INF;

        return [
            'kind' => $kind,
            'evidence_ref' => is_string($in['evidence_ref'] ?? null) ? trim((string) $in['evidence_ref']) : '',
            'evidence_complete' => (bool) ($in['evidence_complete'] ?? false),
            'gate_result' => $gate,
            'has_outcome' => (bool) ($in['has_outcome'] ?? false),
            'claims_success' => (bool) ($in['claims_success'] ?? false),
            'metric_value' => isset($in['metric_value']) && is_numeric($in['metric_value']) ? (float) $in['metric_value'] : 0.0,
            'metric_min' => $min,
            'metric_max' => $max,
            'contaminated' => (bool) ($in['contaminated'] ?? false),
        ];
    }
}
