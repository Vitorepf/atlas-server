<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proves whether an originator batch advances DIVERSE structural capabilities instead of many
 * tasks that all improve the same narrow mechanism (the "everything is task_fabric" trap).
 *
 * IMPACT CLASSES (the 8 structural capability families the brain must keep advancing):
 *   task_fabric                  — task spec/packet/chain quality and acceptance contracts
 *   outcome_learning              — calibration of forecast vs. delivered muscle outcomes
 *   queue_self_healing            — backlog/lease/replenishment/quarantine self-repair
 *   model_amplifier               — model-tier routing, distillation, provider skill scoring
 *   autonomy_governor              — stop/go, budget, ambition, regression-sentinel decisions
 *   simplification                 — dedupe/consolidation/complexity-debt burn-down
 *   evidence_integrity              — evidence/receipt/journal/certification proof chains
 *   provider_optional_acceleration — frontier/breakthrough/research work that does not require
 *                                    a provider in steady state but may use one to go faster
 *
 * CLASSIFICATION: a task's `impact_class` field is trusted verbatim when present and valid
 * (explicit, authoritative). Otherwise the task is classified by keyword heuristic against its
 * objective/target_path/task_family text. A task matching no keyword is `unclassified` — it is
 * never silently forced into a structural class, and never counts toward the diversity score.
 *
 * OUTPUT:
 *   { schema, classified_tasks, class_counts, impact_diversity_score, missing_high_priority_classes,
 *     dominant_class, advances_more_than_one_structural_capability, recommendation, recommended_actions }
 *
 *   impact_diversity_score = distinct structural classes present / total structural classes (8).
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainOriginatorImpactDiversityReport
{
    public const SCHEMA = 'atlas.external_brain.originator_impact_diversity_report.v1';

    public const CLASS_TASK_FABRIC = 'task_fabric';
    public const CLASS_OUTCOME_LEARNING = 'outcome_learning';
    public const CLASS_QUEUE_SELF_HEALING = 'queue_self_healing';
    public const CLASS_MODEL_AMPLIFIER = 'model_amplifier';
    public const CLASS_AUTONOMY_GOVERNOR = 'autonomy_governor';
    public const CLASS_SIMPLIFICATION = 'simplification';
    public const CLASS_EVIDENCE_INTEGRITY = 'evidence_integrity';
    public const CLASS_PROVIDER_OPTIONAL_ACCELERATION = 'provider_optional_acceleration';

    public const CLASS_UNCLASSIFIED = 'unclassified';

    /** @var list<string> */
    public const IMPACT_CLASSES = [
        self::CLASS_TASK_FABRIC,
        self::CLASS_OUTCOME_LEARNING,
        self::CLASS_QUEUE_SELF_HEALING,
        self::CLASS_MODEL_AMPLIFIER,
        self::CLASS_AUTONOMY_GOVERNOR,
        self::CLASS_SIMPLIFICATION,
        self::CLASS_EVIDENCE_INTEGRITY,
        self::CLASS_PROVIDER_OPTIONAL_ACCELERATION,
    ];

    /** Dominant-class share at/above this ratio triggers a "replace" recommendation. */
    private const DOMINANT_SHARE_REPLACE_THRESHOLD = 0.60;

    /** keyword => impact class, checked in this priority order (first match wins). */
    private const KEYWORD_MAP = [
        // evidence_integrity (checked early — "proof"/"evidence" appear in many other classes' text)
        'receipt' => self::CLASS_EVIDENCE_INTEGRITY,
        'journal' => self::CLASS_EVIDENCE_INTEGRITY,
        'certification' => self::CLASS_EVIDENCE_INTEGRITY,
        'evidence integrity' => self::CLASS_EVIDENCE_INTEGRITY,
        'integrity audit' => self::CLASS_EVIDENCE_INTEGRITY,

        // queue_self_healing
        'replenish' => self::CLASS_QUEUE_SELF_HEALING,
        'backlog' => self::CLASS_QUEUE_SELF_HEALING,
        'quarantine' => self::CLASS_QUEUE_SELF_HEALING,
        'lease' => self::CLASS_QUEUE_SELF_HEALING,
        'self-heal' => self::CLASS_QUEUE_SELF_HEALING,
        'self_heal' => self::CLASS_QUEUE_SELF_HEALING,
        'reap' => self::CLASS_QUEUE_SELF_HEALING,
        'stale evidence' => self::CLASS_QUEUE_SELF_HEALING,
        'queue health' => self::CLASS_QUEUE_SELF_HEALING,

        // outcome_learning
        'outcome learn' => self::CLASS_OUTCOME_LEARNING,
        'calibrat' => self::CLASS_OUTCOME_LEARNING,
        'give_back' => self::CLASS_OUTCOME_LEARNING,
        'give back' => self::CLASS_OUTCOME_LEARNING,
        'muscle outcome' => self::CLASS_OUTCOME_LEARNING,
        'impact forecast' => self::CLASS_OUTCOME_LEARNING,
        'overclaim' => self::CLASS_OUTCOME_LEARNING,

        // model_amplifier
        'amplifier' => self::CLASS_MODEL_AMPLIFIER,
        'model tier' => self::CLASS_MODEL_AMPLIFIER,
        'provider skill' => self::CLASS_MODEL_AMPLIFIER,
        'small model' => self::CLASS_MODEL_AMPLIFIER,
        'distill' => self::CLASS_MODEL_AMPLIFIER,

        // autonomy_governor
        'autonomy regression' => self::CLASS_AUTONOMY_GOVERNOR,
        'stop_go' => self::CLASS_AUTONOMY_GOVERNOR,
        'stop/go' => self::CLASS_AUTONOMY_GOVERNOR,
        'ambition budget' => self::CLASS_AUTONOMY_GOVERNOR,
        'governor' => self::CLASS_AUTONOMY_GOVERNOR,
        'autonomy' => self::CLASS_AUTONOMY_GOVERNOR,

        // simplification
        'simplif' => self::CLASS_SIMPLIFICATION,
        'dedupe' => self::CLASS_SIMPLIFICATION,
        'dedup' => self::CLASS_SIMPLIFICATION,
        'consolidat' => self::CLASS_SIMPLIFICATION,
        'complexity debt' => self::CLASS_SIMPLIFICATION,
        'wave planner' => self::CLASS_SIMPLIFICATION,

        // provider_optional_acceleration
        'provider-optional' => self::CLASS_PROVIDER_OPTIONAL_ACCELERATION,
        'frontier' => self::CLASS_PROVIDER_OPTIONAL_ACCELERATION,
        'breakthrough' => self::CLASS_PROVIDER_OPTIONAL_ACCELERATION,
        'research grounding' => self::CLASS_PROVIDER_OPTIONAL_ACCELERATION,

        // task_fabric (checked last — most generic vocabulary)
        'task fabric' => self::CLASS_TASK_FABRIC,
        'task_fabric' => self::CLASS_TASK_FABRIC,
        'task chain' => self::CLASS_TASK_FABRIC,
        'task packet' => self::CLASS_TASK_FABRIC,
        'acceptance criteria' => self::CLASS_TASK_FABRIC,
        'spec quality' => self::CLASS_TASK_FABRIC,
    ];

    /**
     * @param  array{tasks?:list<array<string,mixed>>, high_priority_classes?:list<string>}  $facts
     * @return array<string,mixed>
     */
    public function report(array $facts): array
    {
        $tasks = is_array($facts['tasks'] ?? null) ? $facts['tasks'] : [];
        $highPriorityClasses = is_array($facts['high_priority_classes'] ?? null) && $facts['high_priority_classes'] !== []
            ? array_values(array_intersect(self::IMPACT_CLASSES, array_map('strval', $facts['high_priority_classes'])))
            : self::IMPACT_CLASSES;

        $classifiedTasks = [];
        $classCounts = array_fill_keys(array_merge(self::IMPACT_CLASSES, [self::CLASS_UNCLASSIFIED]), 0);

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            [$class, $source] = $this->classify($task);
            $classCounts[$class]++;

            $classifiedTasks[] = [
                'task_packet_id' => (string) ($task['task_packet_id'] ?? ''),
                'impact_class' => $class,
                'classification_source' => $source,
            ];
        }

        $totalTasks = count($classifiedTasks);

        $presentStructuralClasses = array_values(array_filter(
            self::IMPACT_CLASSES,
            static fn (string $class): bool => $classCounts[$class] > 0,
        ));

        $impactDiversityScore = round(count($presentStructuralClasses) / count(self::IMPACT_CLASSES), 4);

        $missingHighPriorityClasses = array_values(array_diff($highPriorityClasses, $presentStructuralClasses));

        $dominantClass = $this->dominantClass($classCounts, $presentStructuralClasses);

        $advancesMoreThanOne = count($presentStructuralClasses) > 1;

        [$recommendation, $recommendedActions] = $this->recommend(
            $totalTasks,
            $classCounts,
            $dominantClass,
            $missingHighPriorityClasses,
            $advancesMoreThanOne,
        );

        return [
            'schema' => self::SCHEMA,
            'classified_tasks' => $classifiedTasks,
            'class_counts' => $classCounts,
            'impact_diversity_score' => $impactDiversityScore,
            'missing_high_priority_classes' => $missingHighPriorityClasses,
            'dominant_class' => $dominantClass,
            'advances_more_than_one_structural_capability' => $advancesMoreThanOne,
            'recommendation' => $recommendation,
            'recommended_actions' => $recommendedActions,
        ];
    }

    /** @return array{string,string} [class, source] */
    private function classify(array $task): array
    {
        $explicit = (string) ($task['impact_class'] ?? '');
        if (in_array($explicit, self::IMPACT_CLASSES, true)) {
            return [$explicit, 'explicit'];
        }

        $haystack = strtolower(implode(' ', array_filter([
            (string) ($task['objective'] ?? ''),
            (string) ($task['target_path'] ?? ''),
            (string) ($task['task_family'] ?? ''),
        ])));

        foreach (self::KEYWORD_MAP as $keyword => $class) {
            if (str_contains($haystack, $keyword)) {
                return [$class, 'heuristic'];
            }
        }

        return [self::CLASS_UNCLASSIFIED, 'heuristic'];
    }

    /**
     * @param  array<string,int>  $classCounts
     * @param  list<string>  $presentStructuralClasses
     */
    private function dominantClass(array $classCounts, array $presentStructuralClasses): string
    {
        if ($presentStructuralClasses === []) {
            return self::CLASS_UNCLASSIFIED;
        }

        $best = $presentStructuralClasses[0];
        foreach ($presentStructuralClasses as $class) {
            if ($classCounts[$class] > $classCounts[$best]
                || ($classCounts[$class] === $classCounts[$best] && strcmp($class, $best) < 0)
            ) {
                $best = $class;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,int>  $classCounts
     * @param  list<string>  $missingHighPriorityClasses
     * @return array{array{action:string,reasons:list<string>}, list<array<string,string>>}
     */
    private function recommend(
        int $totalTasks,
        array $classCounts,
        string $dominantClass,
        array $missingHighPriorityClasses,
        bool $advancesMoreThanOne,
    ): array {
        $recommendedActions = [];
        foreach ($missingHighPriorityClasses as $missingClass) {
            $recommendedActions[] = [
                'action' => 'add',
                'target_class' => $missingClass,
                'reason' => "no task in this batch advances {$missingClass}",
            ];
        }

        $dominantShare = $totalTasks > 0 ? round(($classCounts[$dominantClass] ?? 0) / $totalTasks, 4) : 0.0;
        $dominantOverloaded = $dominantShare >= self::DOMINANT_SHARE_REPLACE_THRESHOLD && $totalTasks > 1;

        if ($dominantOverloaded) {
            $recommendedActions[] = [
                'action' => 'replace',
                'target_class' => $dominantClass,
                'reason' => sprintf(
                    'dominant_class=%s accounts for %.0f%% of the batch; replace surplus %s tasks with tasks from missing high-priority classes',
                    $dominantClass,
                    $dominantShare * 100,
                    $dominantClass,
                ),
            ];
        }

        if ($totalTasks === 0) {
            $action = 'add';
            $reasons = ['batch is empty; add tasks covering the missing high-priority classes'];
        } elseif (! $advancesMoreThanOne) {
            $action = 'replace';
            $reasons = ["batch only advances a single structural capability ({$dominantClass}); replace some {$dominantClass} tasks with tasks from a different impact class"];
        } elseif ($dominantOverloaded) {
            $action = 'replace';
            $reasons = [sprintf('dominant_class=%s is overrepresented at %.0f%% of the batch', $dominantClass, $dominantShare * 100)];
        } elseif ($missingHighPriorityClasses !== []) {
            $action = 'add';
            $reasons = ['missing_high_priority_classes present; add tasks to cover them'];
        } else {
            $action = 'none';
            $reasons = ['batch already advances diverse structural capabilities covering all high-priority classes'];
        }

        return [['action' => $action, 'reasons' => $reasons], $recommendedActions];
    }
}
