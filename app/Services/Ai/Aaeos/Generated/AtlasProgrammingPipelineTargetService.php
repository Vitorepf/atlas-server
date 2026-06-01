<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Programming Pipeline Target decider.
 *
 * Pure, deterministic runtime for the unified programming pipeline described in
 * the Programming Pipeline Target audit doc. The doc's thesis is that Atlas Dev,
 * Forge, Fix and Continue are surfaces/flows of ONE Programming pipeline — not
 * separate products — and that they must share one context, quality, repair and
 * evidence model. This service is the extracted decision surface the doc's "P0:
 * stop adding business logic inside commands" demands: it answers, purely, the
 * four decisions the documented Target Flow makes between raw input and the
 * final evidence packet. It enforces the concrete rules the doc states:
 *
 *   1. Intent classification (Target Flow -> ProgrammingIntentClassifier,
 *      Components row): from a raw prompt detect the documented intents
 *      "implementation, bugfix, repair, review, refactor, db, ui, security and
 *      harness needs". `classifyIntent()` returns the dominant intent plus every
 *      signal it matched, deterministically (priority order is fixed, never
 *      random), defaulting to `implementation` when nothing else matches.
 *
 *   2. Executor selection (Target Flow -> ProgrammingExecutorSelector ->
 *      "simple_provider | dev_repair_executor | engineering_harness"; Components:
 *      "Selects simple, dev-repair or harness execution using policy, risk and
 *      intent"). `selectExecutor()` returns exactly one of those three executors:
 *      a repair/bugfix intent routes to `dev_repair_executor`; a harness need OR
 *      high risk OR a security/db intent routes to `engineering_harness`
 *      (the heavier lane); otherwise `simple_provider`. A hard policy override
 *      (`force_executor`) wins, and `engineering_harness` always dominates
 *      `dev_repair_executor` when both are implied (heaviest lane wins).
 *
 *   3. Repair loop cap (Target Flow -> ProgrammingRepairLoop; Components:
 *      "Controls repair capsule and iteration limits"; P4 ProgrammingRepairCapsule).
 *      `repairLoopStep()` caps repair iterations at a fixed maximum and flips the
 *      decision to `escalate` once the cap is reached instead of looping forever;
 *      a green gate set short-circuits to `done` before the cap.
 *
 *   4. Quality matrix (Target Flow -> ProgrammingQualityMatrix; Components:
 *      "Selects and evaluates gates by task type"). `qualityGates()` returns the
 *      gate set required for a given intent — every task gets the baseline gates,
 *      and db/security/ui/harness intents add their documented extra gates.
 *
 *   5. Surface unification + bypass detection (Success Criteria:
 *      "atlas dev interactive and one-shot share the same internal pipeline",
 *      "atlas forge is a heavier Programming intensity, not a second product",
 *      "atlas fix is a repair intent/flow inside Programming",
 *      "Architecture validation can detect a new surface bypass").
 *      `normalizeSurface()` collapses the known surfaces (dev / dev one-shot /
 *      forge / fix / continue / app / workers) onto the single pipeline with the
 *      correct intensity and repair flag, and flags any unknown surface as a
 *      bypass that must be rejected rather than silently run.
 *
 * The service NEVER calls a provider, runs a gate, mutates state, issues a
 * receipt or touches a database. It only decides intent / executor / repair
 * step / gate set / surface routing. Callers (the real pipeline) enforce.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md
 */
final class AtlasProgrammingPipelineTargetService
{
    /** Stable schema id for the verdict envelopes this service emits. */
    public const SCHEMA = 'atlas.programming.pipeline_target.v1';

    /** The exact, closed executor set the doc's Target Flow allows. */
    public const EXECUTOR_SIMPLE = 'simple_provider';

    public const EXECUTOR_DEV_REPAIR = 'dev_repair_executor';

    public const EXECUTOR_HARNESS = 'engineering_harness';

    /**
     * Documented repair iteration limit (ProgrammingRepairLoop "iteration
     * limits" / ProgrammingRepairCapsule). After this many repair attempts the
     * loop must escalate instead of looping forever.
     */
    public const MAX_REPAIR_ITERATIONS = 3;

    /** Baseline gates every programming task must clear (ProgrammingQualityMatrix). */
    private const BASELINE_GATES = ['lint', 'build', 'test'];

    /**
     * Intent keyword table. Order here is the deterministic priority order used
     * to pick the dominant intent when several match. Repair/security/db sit
     * above generic implementation so the heavier, riskier signal wins.
     *
     * @var array<string,list<string>>
     */
    private const INTENT_SIGNALS = [
        'security' => ['security', 'vuln', 'cve', 'auth', 'secret', 'sanitize', 'exploit'],
        'repair' => ['repair', 'fix loop', 'green the', 'make tests pass', 'recover', 'self-heal'],
        'bugfix' => ['bug', 'fix', 'broken', 'error', 'regression', 'failing'],
        'db' => ['migration', 'schema', 'database', 'sql', 'index', 'table', 'column'],
        'review' => ['review', 'audit', 'inspect', 'critique'],
        'refactor' => ['refactor', 'rename', 'extract', 'cleanup', 'restructure'],
        'ui' => ['ui', 'component', 'screen', 'frontend', 'layout', 'view'],
        'harness' => ['harness', 'multi-step', 'large refactor', 'orchestrate', 'forge', 'obra'],
        'implementation' => ['implement', 'add', 'build', 'create', 'feature', 'write'],
    ];

    /** Intents that, on their own, demand the heavier engineering harness lane. */
    private const HARNESS_INTENTS = ['security', 'db', 'harness'];

    /** Intents that route to the dev repair executor. */
    private const REPAIR_INTENTS = ['repair', 'bugfix'];

    /**
     * Extra gates the quality matrix layers on top of the baseline per intent.
     *
     * @var array<string,list<string>>
     */
    private const EXTRA_GATES = [
        'security' => ['security_scan', 'secret_scan'],
        'db' => ['migration_safety', 'schema_drift'],
        'ui' => ['ui_snapshot', 'a11y'],
        'harness' => ['integration', 'evidence_packet'],
    ];

    /**
     * Classify a raw programming prompt into the documented intent set.
     *
     * Deterministic: lowercases the prompt, walks INTENT_SIGNALS in fixed
     * priority order, and the first intent with a matching keyword wins.
     * Every matched intent is reported in `signals` for auditability. Empty /
     * unmatched input defaults to `implementation`.
     *
     * @return array{
     *     schema:string,
     *     intent:string,
     *     signals:list<string>,
     *     matched:bool
     * }
     */
    public function classifyIntent(string $prompt): array
    {
        $haystack = strtolower(trim($prompt));

        $signals = [];
        $dominant = null;

        foreach (self::INTENT_SIGNALS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if ($haystack !== '' && str_contains($haystack, $keyword)) {
                    $signals[] = $intent;
                    $dominant ??= $intent;

                    break;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'intent' => $dominant ?? 'implementation',
            'signals' => array_values(array_unique($signals)),
            'matched' => $dominant !== null,
        ];
    }

    /**
     * Select exactly one executor from the doc's closed set, using intent, risk
     * and policy — mirroring "ProgrammingExecutorSelector -> simple_provider |
     * dev_repair_executor | engineering_harness".
     *
     * Precedence (heaviest lane wins, so a risky task is never under-served):
     *   0. a hard policy `force_executor` (must be one of the three) wins outright;
     *   1. harness need OR high risk OR a security/db/harness intent => engineering_harness;
     *   2. a repair/bugfix intent => dev_repair_executor;
     *   3. otherwise => simple_provider.
     *
     * @param  array{
     *     intent?:string,
     *     risk?:string,
     *     harness_required?:bool,
     *     force_executor?:string
     * }  $signals
     * @return array{
     *     schema:string,
     *     executor:string,
     *     reason:string,
     *     intent:string,
     *     risk:string,
     *     repair_enabled:bool
     * }
     */
    public function selectExecutor(array $signals): array
    {
        $intent = strtolower(trim((string) ($signals['intent'] ?? 'implementation'))) ?: 'implementation';
        $risk = strtolower(trim((string) ($signals['risk'] ?? 'medium'))) ?: 'medium';
        $harnessRequired = (bool) ($signals['harness_required'] ?? false);
        $force = strtolower(trim((string) ($signals['force_executor'] ?? '')));

        // 0. Hard policy override, only if it names a real executor.
        if (in_array($force, [self::EXECUTOR_SIMPLE, self::EXECUTOR_DEV_REPAIR, self::EXECUTOR_HARNESS], true)) {
            return $this->executorEnvelope($force, 'policy_force_executor', $intent, $risk);
        }

        // 1. Heaviest lane: explicit harness need, high/critical risk, or a
        //    harness-class intent. This dominates a repair signal on purpose.
        if ($harnessRequired
            || in_array($risk, ['high', 'critical'], true)
            || in_array($intent, self::HARNESS_INTENTS, true)
        ) {
            $reason = $harnessRequired
                ? 'harness_required'
                : (in_array($risk, ['high', 'critical'], true) ? 'high_risk' : 'harness_class_intent');

            return $this->executorEnvelope(self::EXECUTOR_HARNESS, $reason, $intent, $risk);
        }

        // 2. Repair lane.
        if (in_array($intent, self::REPAIR_INTENTS, true)) {
            return $this->executorEnvelope(self::EXECUTOR_DEV_REPAIR, 'repair_intent', $intent, $risk);
        }

        // 3. Default simple provider lane.
        return $this->executorEnvelope(self::EXECUTOR_SIMPLE, 'default_simple', $intent, $risk);
    }

    /**
     * One step of the repair loop. Caps iterations at MAX_REPAIR_ITERATIONS and
     * escalates instead of looping forever; a fully-green gate set ends as `done`.
     *
     * @param  int  $iteration  the attempt number about to run (1-based)
     * @param  bool  $gatesGreen  whether the current gate set is fully passing
     * @return array{
     *     schema:string,
     *     decision:string,
     *     iteration:int,
     *     max_iterations:int,
     *     remaining:int,
     *     escalated:bool
     * }
     */
    public function repairLoopStep(int $iteration, bool $gatesGreen): array
    {
        $iteration = max(1, $iteration);
        $max = self::MAX_REPAIR_ITERATIONS;

        if ($gatesGreen) {
            $decision = 'done';
        } elseif ($iteration >= $max) {
            $decision = 'escalate';
        } else {
            $decision = 'retry';
        }

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'iteration' => $iteration,
            'max_iterations' => $max,
            'remaining' => max(0, $max - $iteration),
            'escalated' => $decision === 'escalate',
        ];
    }

    /**
     * The quality matrix: gate set required for a given intent. Baseline gates
     * always apply; db/security/ui/harness intents add their extra gates.
     *
     * @return array{
     *     schema:string,
     *     intent:string,
     *     gates:list<string>,
     *     baseline:list<string>,
     *     extra:list<string>
     * }
     */
    public function qualityGates(string $intent): array
    {
        $intent = strtolower(trim($intent)) ?: 'implementation';
        $extra = self::EXTRA_GATES[$intent] ?? [];

        return [
            'schema' => self::SCHEMA,
            'intent' => $intent,
            'gates' => array_values(array_merge(self::BASELINE_GATES, $extra)),
            'baseline' => self::BASELINE_GATES,
            'extra' => array_values($extra),
        ];
    }

    /**
     * Collapse a surface name onto the single Programming pipeline, with the
     * documented intensity and repair flag — and flag unknown surfaces as a
     * bypass (Success Criteria: detect a new surface bypass).
     *
     *   dev / dev:one-shot  -> standard intensity, same pipeline
     *   forge / obra        -> heavy intensity (NOT a second product)
     *   fix                 -> repair flow, repair_enabled
     *   continue            -> resume, standard intensity
     *   app / workers       -> standard intensity (programmatic surfaces)
     *   anything else       -> bypass=true, allowed=false (reject)
     *
     * @return array{
     *     schema:string,
     *     surface:string,
     *     pipeline:string,
     *     intensity:string,
     *     repair_enabled:bool,
     *     bypass:bool,
     *     allowed:bool,
     *     reason:string
     * }
     */
    public function normalizeSurface(string $surface): array
    {
        $key = strtolower(trim($surface));

        $map = [
            'dev' => ['standard', false],
            'dev:one-shot' => ['standard', false],
            'dev:oneshot' => ['standard', false],
            'forge' => ['heavy', false],
            'obra' => ['heavy', false],
            'fix' => ['standard', true],
            'continue' => ['standard', false],
            'app' => ['standard', false],
            'workers' => ['standard', false],
        ];

        if (! array_key_exists($key, $map)) {
            return [
                'schema' => self::SCHEMA,
                'surface' => $key,
                'pipeline' => 'programming',
                'intensity' => 'unknown',
                'repair_enabled' => false,
                'bypass' => true,
                'allowed' => false,
                'reason' => 'unknown_surface_bypass',
            ];
        }

        [$intensity, $repair] = $map[$key];

        return [
            'schema' => self::SCHEMA,
            'surface' => $key,
            'pipeline' => 'programming',
            'intensity' => $intensity,
            'repair_enabled' => $repair,
            'bypass' => false,
            'allowed' => true,
            'reason' => 'unified_programming_pipeline',
        ];
    }

    /**
     * End-to-end deterministic plan for one raw request: classify -> select
     * executor -> pick gates -> normalize surface. This is the single decision
     * surface the doc's P0 ("stop adding business logic inside commands") wants
     * commands to call instead of re-deriving routing themselves.
     *
     * @param  array{risk?:string,harness_required?:bool,force_executor?:string}  $policy
     * @return array{
     *     schema:string,
     *     surface:array<string,mixed>,
     *     intent:array<string,mixed>,
     *     executor:array<string,mixed>,
     *     quality:array<string,mixed>,
     *     allowed:bool
     * }
     */
    public function plan(string $prompt, string $surface = 'dev', array $policy = []): array
    {
        $surfaceDecision = $this->normalizeSurface($surface);
        $intentDecision = $this->classifyIntent($prompt);

        $executorDecision = $this->selectExecutor([
            'intent' => $intentDecision['intent'],
            'risk' => (string) ($policy['risk'] ?? 'medium'),
            'harness_required' => (bool) ($policy['harness_required'] ?? false),
            'force_executor' => (string) ($policy['force_executor'] ?? ''),
        ]);

        $qualityDecision = $this->qualityGates($intentDecision['intent']);

        return [
            'schema' => self::SCHEMA,
            'surface' => $surfaceDecision,
            'intent' => $intentDecision,
            'executor' => $executorDecision,
            'quality' => $qualityDecision,
            'allowed' => $surfaceDecision['allowed'],
        ];
    }

    /**
     * @return array{
     *     schema:string,
     *     executor:string,
     *     reason:string,
     *     intent:string,
     *     risk:string,
     *     repair_enabled:bool
     * }
     */
    private function executorEnvelope(string $executor, string $reason, string $intent, string $risk): array
    {
        return [
            'schema' => self::SCHEMA,
            'executor' => $executor,
            'reason' => $reason,
            'intent' => $intent,
            'risk' => $risk,
            'repair_enabled' => $executor === self::EXECUTOR_DEV_REPAIR,
        ];
    }
}
