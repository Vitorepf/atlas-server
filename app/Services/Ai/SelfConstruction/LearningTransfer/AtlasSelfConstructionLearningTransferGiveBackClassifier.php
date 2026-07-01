<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure classifier — bins give_back / blocked-task facts into REUSABLE failure classes.
 *
 * Classes (FACTS only; no narrative invention):
 *   - duplicate_capability        : the work asked for already exists / would re-implement an existing organ.
 *   - scope_gap                   : allowed_files insufficient to deliver acceptance (missing files outside allow-list).
 *   - forbidden_target            : target sits in a frozen/forbidden core path.
 *   - contradictory_acceptance    : acceptance criteria mutually exclusive against the existing fixture (e.g.
 *                                    the "missing acceptance_contract" packet that breaks 9 sibling tests).
 *   - missing_dependency          : a needed extractor / migration / collaborator does not yet exist.
 *   - stale_context               : context-pack / code-index / docs out of date for this lane.
 *   - insufficient_evidence       : verification was inconclusive (amber); not enough proof to act.
 *
 * Output: {schema_version, class, packet_id, allowed_files, blocking_facts, evidence_refs}
 * Class = 'unknown' when no signal lights. NO narrative invention — every field on the output comes
 * from the input.
 */
final class AtlasSelfConstructionLearningTransferGiveBackClassifier
{
    public const SCHEMA = 'atlas.learning_transfer.give_back_class.v1';

    public const CLASS_DUPLICATE_CAPABILITY = 'duplicate_capability';

    public const CLASS_SCOPE_GAP = 'scope_gap';

    public const CLASS_FORBIDDEN_TARGET = 'forbidden_target';

    public const CLASS_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';

    public const CLASS_MISSING_DEPENDENCY = 'missing_dependency';

    public const CLASS_STALE_CONTEXT = 'stale_context';

    public const CLASS_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const CLASS_FORBIDDEN_SCOPE = 'forbidden_scope';

    public const CLASS_MISSING_IMPLEMENTATION = 'missing_implementation';

    public const CLASS_SCHEMA_DRIFT = 'schema_drift';

    public const CLASS_WORKER_ERROR = 'worker_error';

    public const CLASS_UNKNOWN = 'unknown';

    /**
     * Separate learning class for queue STARVATION give_backs (worker had nothing claimable) —
     * never lumped together with bad scope, bad acceptance, or provider failure, since the
     * repair action is "replenish the queue", not "respec the packet".
     */
    public const CLASS_NO_CLAIMABLE_TASK = 'queue_starvation';

    public const CLASS_QUEUE_STARVATION = self::CLASS_NO_CLAIMABLE_TASK;

    public const CLASS_STALE_CLAIMABLE_BACKLOG = 'stale_claimable_backlog';

    /** claimable_per_active_worker at/below this is a worker-feed floor breach. */
    public const WORKER_FLOOR_THRESHOLD = 2.0;

    /**
     * no_claimable outcomes WITH a proven-low claimable_per_active_worker are worker-feed
     * starvation (repair = top up the task fabric), distinct from generic queue_starvation.
     */
    public const CLASS_WORKER_FEED_STARVATION = 'worker_feed_starvation';

    /**
     * give_back outcomes carrying malformed/underspecified task evidence — a bad packet
     * shape, never a queue-supply problem.
     */
    public const CLASS_PACKET_SHAPE_DEFECT = 'packet_shape_defect';

    /** Classes that are structurally unfixable poison — never requeue, high poison_confidence. */
    private const POISON_CLASSES = [
        self::CLASS_DUPLICATE_CAPABILITY,
        self::CLASS_FORBIDDEN_TARGET,
        self::CLASS_FORBIDDEN_SCOPE,
    ];

    /** Classes whose repair is a scope/acceptance respec, not a worker/queue fix. */
    private const SCOPE_REPAIR_CLASSES = [
        self::CLASS_SCOPE_GAP,
        self::CLASS_FORBIDDEN_SCOPE,
        self::CLASS_CONTRADICTORY_ACCEPTANCE,
        self::CLASS_PACKET_SHAPE_DEFECT,
    ];

    /** Key-name-free string markers (case-insensitive) that make an evidence/blocking string provider-unsafe. */
    private const SENSITIVE_STRING_MARKERS = ['secret', 'token', 'api_key', 'apikey', 'credential', 'password', 'bearer'];

    /**
     * @param  array<string,mixed>  $giveBackFact  the give_back payload as recorded by the worker
     * @return array<string,mixed>
     */
    public function classify(array $giveBackFact): array
    {
        $reason = strtolower((string) ($giveBackFact['reason'] ?? ''));
        $blockingFacts = array_values((array) ($giveBackFact['blocking_facts'] ?? []));
        $evidenceRefs = array_values((array) ($giveBackFact['evidence_refs'] ?? []));
        $packetId = (string) ($giveBackFact['task_packet_id'] ?? '');
        $allowedFiles = array_values((array) ($giveBackFact['allowed_files'] ?? []));

        $class = $this->resolveClass($reason, $giveBackFact);

        // No-claimable outcome with a proven-low worker floor is worker-feed starvation,
        // not generic queue starvation — the repair action differs (top up vs replenish).
        if ($class === self::CLASS_NO_CLAIMABLE_TASK && array_key_exists('claimable_per_active_worker', $giveBackFact)
            && $giveBackFact['claimable_per_active_worker'] !== null
            && (float) $giveBackFact['claimable_per_active_worker'] <= self::WORKER_FLOOR_THRESHOLD) {
            $class = self::CLASS_WORKER_FEED_STARVATION;
        }

        $actionHint = $this->actionHint($class);

        $result = [
            'schema_version' => self::SCHEMA,
            'class' => $class,
            'action_hint' => $actionHint,
            'packet_id' => $packetId,
            'allowed_files' => $allowedFiles,
            'blocking_facts' => $blockingFacts,
            'evidence_refs' => $evidenceRefs,
            'root_cause' => $class,
            'next_action' => $actionHint,
            'requeue_eligible' => ! in_array($class, self::POISON_CLASSES, true) && $class !== self::CLASS_UNKNOWN,
            'scope_repair_needed' => in_array($class, self::SCOPE_REPAIR_CLASSES, true),
            'poison_confidence' => in_array($class, self::POISON_CLASSES, true) ? 0.9 : 0.0,
            'evidence_snippets' => $this->redactedSnippets($evidenceRefs, $blockingFacts),
        ];

        // Queue starvation carries its own repair signal — claimable depth and active worker
        // count — so the learning class never gets lumped together with bad scope/acceptance.
        if ($class === self::CLASS_QUEUE_STARVATION || $class === self::CLASS_WORKER_FEED_STARVATION) {
            $result['queue_floor_facts'] = [
                'claimable_depth' => isset($giveBackFact['claimable_depth']) ? (int) $giveBackFact['claimable_depth'] : null,
                'active_worker_count' => isset($giveBackFact['active_worker_count']) ? (int) $giveBackFact['active_worker_count'] : null,
                'claimable_per_active_worker' => isset($giveBackFact['claimable_per_active_worker']) ? (float) $giveBackFact['claimable_per_active_worker'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $evidenceRefs
     * @param  list<mixed>  $blockingFacts
     * @return list<string>
     */
    private function redactedSnippets(array $evidenceRefs, array $blockingFacts): array
    {
        $snippets = [];
        foreach (array_merge($evidenceRefs, $blockingFacts) as $item) {
            $s = (string) $item;
            $lower = strtolower($s);
            $isSensitive = false;
            foreach (self::SENSITIVE_STRING_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $isSensitive = true;
                    break;
                }
            }
            $snippets[] = $isSensitive ? '[redacted]' : $s;
        }

        return array_values(array_unique($snippets));
    }

    private function actionHint(string $class): string
    {
        return match ($class) {
            self::CLASS_FORBIDDEN_SCOPE, self::CLASS_SCOPE_GAP => 'respec_allowed_files',
            self::CLASS_SCHEMA_DRIFT => 'fix_schema_contract',
            self::CLASS_DUPLICATE_CAPABILITY, self::CLASS_FORBIDDEN_TARGET => 'quarantine_poison',
            self::CLASS_WORKER_ERROR => 'worker_prompt_repair',
            self::CLASS_MISSING_DEPENDENCY, self::CLASS_MISSING_IMPLEMENTATION => 'enqueue_dependency_packet',
            self::CLASS_CONTRADICTORY_ACCEPTANCE => 'revise_acceptance_contract',
            self::CLASS_STALE_CONTEXT => 'refresh_context_pack',
            self::CLASS_INSUFFICIENT_EVIDENCE => 'add_evidence_ref',
            self::CLASS_NO_CLAIMABLE_TASK => 'originator_top_up',
            self::CLASS_WORKER_FEED_STARVATION => 'originator_top_up',
            self::CLASS_STALE_CLAIMABLE_BACKLOG => 'rotate_or_refresh_claimable_queue',
            self::CLASS_PACKET_SHAPE_DEFECT => 'respec_packet_shape',
            default => 'investigate',
        };
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function resolveClass(string $reason, array $fact): string
    {
        // Reason-string match takes precedence over signal heuristics so the classifier is deterministic.
        $rules = [
            self::CLASS_DUPLICATE_CAPABILITY => ['duplicate_capability', 'already_exists', 'reimplements_existing'],
            self::CLASS_FORBIDDEN_SCOPE => ['forbidden_scope', 'files_outside_allowed_scope', 'scope_forbidden'],
            self::CLASS_SCOPE_GAP => ['scope_gap', 'allowed_files_insufficient', 'requires_files_outside_scope'],
            self::CLASS_FORBIDDEN_TARGET => ['forbidden_target', 'pétreo', 'petreo', 'forbidden_core'],
            self::CLASS_CONTRADICTORY_ACCEPTANCE => ['contradictory_acceptance', 'breaks_sibling_tests', 'acceptance_contradicts_fixture', 'contradiction'],
            self::CLASS_MISSING_DEPENDENCY => ['missing_extractor', 'missing_dependency', 'missing_migration', 'missing_collaborator'],
            self::CLASS_MISSING_IMPLEMENTATION => ['missing_implementation', 'method_not_found', 'class_not_implemented', 'not_yet_implemented'],
            self::CLASS_SCHEMA_DRIFT => ['schema_drift', 'schema_mismatch', 'schema_version_mismatch', 'contract_drift'],
            self::CLASS_WORKER_ERROR => ['worker_error', 'worker_crash', 'worker_bug', 'execution_error'],
            self::CLASS_STALE_CONTEXT => ['stale_context', 'context_pack_stale', 'code_index_stale', 'docs_stale'],
            self::CLASS_INSUFFICIENT_EVIDENCE => ['insufficient_evidence', 'verification_amber'],
            self::CLASS_NO_CLAIMABLE_TASK => ['no_claimable_task'],
            self::CLASS_STALE_CLAIMABLE_BACKLOG => ['stale_claimable_backlog'],
            self::CLASS_PACKET_SHAPE_DEFECT => ['malformed', 'underspecified', 'malformed_packet', 'underspecified_evidence'],
        ];
        foreach ($rules as $class => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($reason, $needle)) {
                    return $class;
                }
            }
        }

        // Structural signals (when reason isn't named).
        if (! empty($fact['target_is_forbidden_core'])) {
            return self::CLASS_FORBIDDEN_TARGET;
        }
        if (! empty($fact['allowed_files_insufficient'])) {
            return self::CLASS_SCOPE_GAP;
        }
        if (! empty($fact['missing_dependency_path'])) {
            return self::CLASS_MISSING_DEPENDENCY;
        }

        return self::CLASS_UNKNOWN;
    }
}
