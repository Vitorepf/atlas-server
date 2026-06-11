<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 7 — two deciders for the
 * documented "Fatia 7" entry gate and the "Regra Final".
 *
 * This slice of the dev flow map carries two pieces of concrete, enforceable
 * decision logic. They are implemented here as pure, deterministic methods.
 *
 * ── Decider 1: comparison-arm entry gate ("Fatia 7: entrada") ──────────────
 * The doc lists, as an ORDERED, blocking checklist, what must hold before a
 * product-comparison arm may be entered:
 *   - "so depois dos resultados locais"  → local results MUST exist first.
 *   - "criar/ativar arm real"            → a real arm must be created/activated.
 *   - "congelar contrato"                → the contract must be frozen.
 *   - "rodar contra Sonnet puro e Opus puro" → both pure baselines must be run.
 *   - "registrar custo, tempo, qualidade e falhas" → all four metrics recorded.
 * The first item is a HARD ordering precondition: with no local results the gate
 * is `blocked` and no later step can unblock it. Every missing step is reported.
 *
 * ── Decider 2: Atlas Dev Light success / continuation classifier ("Regra Final") ─
 * The doc states the success criterion of "Atlas Dev Light" is explicitly NOT
 * "fazer tudo que Forge faz mais barato". It is three things:
 *   1. resolver muito bem o trabalho comum,
 *   2. detectar cedo quando nao deve continuar,
 *   3. escalar para Forge antes de virar risco.
 * So given a task signal the runtime must choose one of three controlled
 * outcomes — `proceed`, `stop_early`, `escalate_to_forge` — and it must escalate
 * BEFORE a task becomes a risk, never after. The "cheaper-Forge" framing is
 * rejected outright.
 *
 * ── Mode taxonomy ("Regra Final") ──────────────────────────────────────────
 * Atlas Dev is the efficient daily mode; Forge is the maximum-governance mode;
 * a comparison must measure them as DIFFERENT products. The taxonomy is exposed
 * so callers cannot conflate the two.
 *
 * The service NEVER executes a command, calls a provider, creates an arm,
 * mutates a workspace or touches a database. It only decides whether the gate is
 * open and which controlled outcome a task warrants. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
 */
final class AtlasDevFlowMapProductOptionsV1Part07Service
{
    /** Stable schema id for the entry-gate verdict. */
    public const GATE_SCHEMA = 'atlas.dev.flow_map_part07.arm_entry_gate.v1';

    /** Stable schema id for the success/continuation verdict. */
    public const RULE_SCHEMA = 'atlas.dev.flow_map_part07.final_rule.v1';

    /** Gate states (closed set). */
    public const GATE_OPEN = 'open';
    public const GATE_BLOCKED = 'blocked';

    /** Continuation outcomes (closed set, from the three-part "Regra Final"). */
    public const OUTCOME_PROCEED = 'proceed';
    public const OUTCOME_STOP_EARLY = 'stop_early';
    public const OUTCOME_ESCALATE = 'escalate_to_forge';

    /**
     * The documented entry checklist, in order. The first key is the hard
     * ordering precondition ("so depois dos resultados locais").
     *
     * @var list<string>
     */
    public const ENTRY_STEPS = [
        'local_results_present',   // "so depois dos resultados locais"
        'real_arm_active',         // "criar/ativar arm real"
        'contract_frozen',         // "congelar contrato"
        'pure_baselines_run',      // "rodar contra Sonnet puro e Opus puro"
        'metrics_registered',      // "registrar custo, tempo, qualidade e falhas"
    ];

    /**
     * The four metrics the doc requires to be registered, verbatim.
     *
     * @var list<string>
     */
    public const REQUIRED_METRICS = ['cost', 'time', 'quality', 'failures'];

    /**
     * The two pure baselines a real arm must be run against, verbatim.
     *
     * @var list<string>
     */
    public const REQUIRED_BASELINES = ['sonnet_pure', 'opus_pure'];

    /**
     * Evaluate the "Fatia 7" comparison-arm entry gate.
     *
     * Reads a candidate-readiness payload and returns whether the arm may be
     * entered. Ordering is enforced: when `local_results_present` is false the
     * gate is BLOCKED regardless of any later step (the doc's "so depois dos
     * resultados locais"); the later steps are still reported as missing so the
     * caller sees the full picture, but `blocked_on` leads with the ordering
     * breach. Missing optional sub-evidence (an incomplete metric set, only one
     * baseline run) is treated as the step being unmet, never silently passed.
     *
     * @param  array<string,mixed>  $readiness
     * @return array{
     *   schema:string,
     *   gate:string,
     *   can_enter:bool,
     *   ordering_ok:bool,
     *   satisfied:list<string>,
     *   missing:list<string>,
     *   blocked_on:list<string>,
     *   metrics_missing:list<string>,
     *   baselines_missing:list<string>,
     *   checked:list<string>
     * }
     */
    public function evaluateEntryGate(array $readiness): array
    {
        $metricsMissing = $this->missingMetrics($readiness);
        $baselinesMissing = $this->missingBaselines($readiness);

        $stepState = [
            'local_results_present' => $this->boolFlag($readiness, 'local_results_present'),
            'real_arm_active' => $this->boolFlag($readiness, 'real_arm_active'),
            'contract_frozen' => $this->boolFlag($readiness, 'contract_frozen'),
            // a step that depends on sub-evidence is only met when that evidence is complete
            'pure_baselines_run' => $this->boolFlag($readiness, 'pure_baselines_run') && $baselinesMissing === [],
            'metrics_registered' => $this->boolFlag($readiness, 'metrics_registered') && $metricsMissing === [],
        ];

        $satisfied = [];
        $missing = [];
        foreach (self::ENTRY_STEPS as $step) {
            if ($stepState[$step]) {
                $satisfied[] = $step;
            } else {
                $missing[] = $step;
            }
        }

        // Ordering gate: local results MUST exist first. Without them the gate is
        // blocked even if every other box were ticked.
        $orderingOk = $stepState['local_results_present'];

        // blocked_on leads with the ordering breach, then the remaining gaps.
        $blockedOn = $missing;
        if (! $orderingOk) {
            $blockedOn = array_values(array_unique(array_merge(
                ['ordering:local_results_required_first'],
                $missing
            )));
        }

        $canEnter = $orderingOk && $missing === [];

        return [
            'schema' => self::GATE_SCHEMA,
            'gate' => $canEnter ? self::GATE_OPEN : self::GATE_BLOCKED,
            'can_enter' => $canEnter,
            'ordering_ok' => $orderingOk,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'blocked_on' => $blockedOn,
            'metrics_missing' => $metricsMissing,
            'baselines_missing' => $baselinesMissing,
            'checked' => self::ENTRY_STEPS,
        ];
    }

    /**
     * Classify a task under the three-part "Regra Final".
     *
     * Inputs (all optional, degrade safely):
     *   - risk_imminent (bool)   : the task is about to become a risk → MUST escalate.
     *   - governance_max (bool)  : the task explicitly needs maximum governance → Forge.
     *   - should_not_continue (bool) : an early signal that work should stop.
     *   - common_work (bool)     : it is ordinary, well-understood work.
     *   - confidence ('high'|'medium'|'low') : execution confidence.
     *
     * Precedence (the doc's ordering: escalate BEFORE it becomes a risk, detect
     * EARLY when it should not continue, otherwise resolve common work well):
     *   1. risk_imminent OR governance_max               → escalate_to_forge
     *   2. should_not_continue OR confidence == low       → stop_early
     *   3. common_work (and not blocked above)            → proceed
     *   4. neither common nor blocked                     → stop_early (conservative:
     *      Light is not the place for non-common, non-escalated work).
     *
     * @param  array<string,mixed>  $signal
     * @return array{
     *   schema:string,
     *   outcome:string,
     *   reason:string,
     *   escalated_before_risk:bool,
     *   factors:array<string,bool|string|null>
     * }
     */
    public function classifyContinuation(array $signal): array
    {
        $riskImminent = $this->boolFlag($signal, 'risk_imminent');
        $governanceMax = $this->boolFlag($signal, 'governance_max');
        $shouldNotContinue = $this->boolFlag($signal, 'should_not_continue');
        $commonWork = $this->boolFlag($signal, 'common_work');
        $confidence = is_string($signal['confidence'] ?? null)
            ? strtolower(trim((string) $signal['confidence']))
            : null;

        if ($riskImminent || $governanceMax) {
            $outcome = self::OUTCOME_ESCALATE;
            $reason = $riskImminent
                ? 'risk imminent — escalate to Forge before it becomes a risk'
                : 'task requires maximum governance — Forge';
        } elseif ($shouldNotContinue || $confidence === 'low') {
            $outcome = self::OUTCOME_STOP_EARLY;
            $reason = $shouldNotContinue
                ? 'early signal to stop — Light must detect when it should not continue'
                : 'execution confidence is low — stop early rather than push through';
        } elseif ($commonWork) {
            $outcome = self::OUTCOME_PROCEED;
            $reason = 'ordinary well-understood work — Light resolves the common case well';
        } else {
            $outcome = self::OUTCOME_STOP_EARLY;
            $reason = 'not common work and not escalated — Light stops rather than overreach';
        }

        return [
            'schema' => self::RULE_SCHEMA,
            'outcome' => $outcome,
            'reason' => $reason,
            // The invariant: when a risk is imminent the decision is to escalate,
            // i.e. the escalation happens BEFORE the risk materialises, not after.
            'escalated_before_risk' => $outcome === self::OUTCOME_ESCALATE,
            'factors' => [
                'risk_imminent' => $riskImminent,
                'governance_max' => $governanceMax,
                'should_not_continue' => $shouldNotContinue,
                'common_work' => $commonWork,
                'confidence' => $confidence,
            ],
        ];
    }

    /**
     * Judge a proposed success criterion against the "Regra Final".
     *
     * The doc is explicit: the success criterion of Atlas Dev Light is NOT
     * "fazer tudo que Forge faz mais barato". A criterion that frames Light as a
     * cheaper Forge is rejected; the three legitimate criteria are accepted.
     *
     * @param  string  $criterion  free-text criterion to judge
     * @return array{
     *   schema:string,
     *   accepted:bool,
     *   classification:string,
     *   legitimate_criteria:list<string>
     * }
     */
    public function judgeSuccessCriterion(string $criterion): array
    {
        $normalized = mb_strtolower(trim($criterion));

        // The rejected framing: doing everything Forge does, but cheaper.
        $mentionsForge = str_contains($normalized, 'forge');
        $mentionsCheaper = str_contains($normalized, 'mais barato')
            || str_contains($normalized, 'cheaper')
            || str_contains($normalized, 'cheap');
        $mentionsEverything = str_contains($normalized, 'tudo')
            || str_contains($normalized, 'everything')
            || str_contains($normalized, 'all of');

        $isCheaperForgeFraming = $mentionsForge && $mentionsCheaper && $mentionsEverything;

        return [
            'schema' => self::RULE_SCHEMA,
            'accepted' => ! $isCheaperForgeFraming,
            'classification' => $isCheaperForgeFraming ? 'rejected_cheaper_forge_framing' : 'allowed',
            'legitimate_criteria' => [
                'resolve common work very well',
                'detect early when it should not continue',
                'escalate to Forge before becoming a risk',
            ],
        ];
    }

    /**
     * The documented mode taxonomy. Atlas Dev and Forge are DIFFERENT products
     * and a comparison must measure them as such.
     *
     * @return array<string,mixed>
     */
    public function modeTaxonomy(): array
    {
        return [
            'schema' => self::RULE_SCHEMA,
            'modes' => [
                'atlas_dev' => [
                    'role' => 'efficient daily mode',
                    'governance' => 'lightweight',
                ],
                'forge' => [
                    'role' => 'maximum-governance mode',
                    'governance' => 'maximum',
                ],
            ],
            'measured_as_different_products' => true,
        ];
    }

    /**
     * Which of the four required metrics are absent from the readiness payload.
     *
     * @param  array<string,mixed>  $readiness
     * @return list<string>
     */
    private function missingMetrics(array $readiness): array
    {
        $registered = AtlasAaeosStringListNormalizer::trimmedStrings($readiness['metrics_registered_set'] ?? null);
        $normalized = array_map(static fn (string $m): string => strtolower($m), $registered);

        $missing = [];
        foreach (self::REQUIRED_METRICS as $metric) {
            if (! in_array($metric, $normalized, true)) {
                $missing[] = $metric;
            }
        }

        return $missing;
    }

    /**
     * Which of the two required pure baselines are absent from the readiness payload.
     *
     * @param  array<string,mixed>  $readiness
     * @return list<string>
     */
    private function missingBaselines(array $readiness): array
    {
        $run = AtlasAaeosStringListNormalizer::trimmedStrings($readiness['baselines_run_set'] ?? null);
        $normalized = array_map(static fn (string $b): string => strtolower($b), $run);

        $missing = [];
        foreach (self::REQUIRED_BASELINES as $baseline) {
            if (! in_array($baseline, $normalized, true)) {
                $missing[] = $baseline;
            }
        }

        return $missing;
    }

    /**
     * Read a strict boolean flag (only literal true counts as satisfied).
     *
     * @param  array<string,mixed>  $payload
     */
    private function boolFlag(array $payload, string $key): bool
    {
        return ($payload[$key] ?? null) === true;
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'gate_schema' => self::GATE_SCHEMA,
            'rule_schema' => self::RULE_SCHEMA,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md',
            'slice' => 'fatia_7_entry_and_regra_final',
            'entry_steps' => self::ENTRY_STEPS,
            'required_metrics' => self::REQUIRED_METRICS,
            'required_baselines' => self::REQUIRED_BASELINES,
            'continuation_outcomes' => [
                self::OUTCOME_PROCEED,
                self::OUTCOME_STOP_EARLY,
                self::OUTCOME_ESCALATE,
            ],
        ];
    }
}
