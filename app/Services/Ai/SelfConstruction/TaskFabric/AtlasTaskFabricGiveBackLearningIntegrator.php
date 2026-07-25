<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\Support\TaskFabricGiveBackLearningSupport;

/**
 * Pure service that turns REPEATED give_back facts into packet REPAIR or RESPEC recommendations. NEVER
 * marks a task completed; NEVER auto-reopens an 8-deep quarantine; NEVER mutates the queue.
 *
 * INPUT: list of give_back events, each:
 *   { task_packet_id, reason, blocking_deficiencies:list<string>, allowed_files:list<string>,
 *     worker_notes?:string, give_back_count?:int }
 *
 * GROUPING (failure class):
 *   - scope_repair_missing_impl    — reason mentions 'scope_repair' OR a deficiency declares missing impl file
 *   - contradictory_acceptance     — deficiency mentions 'contradiction' / 'goodhart' / 'scalar_score'
 *   - cli_clobber_or_petreo        — deficiency mentions 'cli_clobber' or 'forbidden_core'
 *   - generic                      — anything else
 *
 * RECOMMENDATIONS (per packet, derived from worst class observed):
 *   - quarantine_requires_respec   — any event with give_back_count >= 8 ⇒ NEVER reopened
 *   - add_missing_impl_file        — scope_repair_missing_impl with a named candidate file
 *   - respec                       — contradictory_acceptance
 *   - cancel                       — cli_clobber_or_petreo
 *   - split_packet                 — >= 3 deficiencies in a single event
 *   - operator_only                — generic with no actionable signal
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope. Recommendations sorted by task_packet_id.
 *   - NO scalar score / rank.
 */
final class AtlasTaskFabricGiveBackLearningIntegrator
{
    public const SCHEMA = 'atlas.taskfabric.giveback_learning.v1';

    public const QUARANTINE_THRESHOLD = 8;

    public const REC_QUARANTINE = 'quarantine_requires_respec';

    public const REC_ADD_IMPL = 'add_missing_impl_file';

    public const REC_RESPEC = 'respec';

    public const REC_CANCEL = 'cancel';

    public const REC_SPLIT = 'split_packet';

    public const REC_OPERATOR = 'operator_only';

    public const CHAIN_ACTION_RESPEC_OR_ADD_IMPL = 'respec_or_add_impl';

    public const CHAIN_ACTION_BLOCK_DEPENDENT_CHAIN = 'block_dependent_chain';

    public const CHAIN_ACTION_CANCEL = 'cancel';

    public const CHAIN_ACTION_SPLIT = 'split';

    public const CHAIN_ACTION_REROUTE = 'reroute';

    /** Worker+task_shape give_back count at/above which a reroute hint fires. */
    private const REROUTE_GIVE_BACK_THRESHOLD = 3;

    /**
     * @param  list<array{task_packet_id?:string, reason?:string, blocking_deficiencies?:list<string>, allowed_files?:list<string>, worker_notes?:string, give_back_count?:int}>  $events
     * @return array{schema:string, recommendations:list<array<string,mixed>>, grouped_by_class:array<string,int>}
     */
    public function integrate(array $events): array
    {
        $byPacket = [];
        $classCounts = [];

        foreach ($events as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $taskId = (string) ($ev['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $byPacket[$taskId][] = $ev;
        }

        $recommendations = [];
        foreach ($byPacket as $taskId => $packetEvents) {
            $rec = $this->recommendFor($taskId, $packetEvents);
            $recommendations[] = $rec;
            $classCounts[$rec['failure_class']] = ($classCounts[$rec['failure_class']] ?? 0) + 1;
        }

        usort($recommendations, static fn (array $a, array $b): int => strcmp($a['task_packet_id'], $b['task_packet_id']));
        ksort($classCounts);

        // Group by packet_shape_key (structural, not free-text) to surface repeated patterns.
        $patternGroups = [];
        foreach ($recommendations as $rec) {
            $key = $rec['packet_shape_key'];
            $patternGroups[$key]['packet_shape_key'] = $key;
            $patternGroups[$key]['failure_class']    = $rec['failure_class'];
            $patternGroups[$key]['packet_ids'][]     = $rec['task_packet_id'];
            $patternGroups[$key]['count']            = count($patternGroups[$key]['packet_ids'] ?? []);
        }
        $defectPatterns = array_values(array_filter($patternGroups, static fn (array $p): bool => ($p['count'] ?? 0) >= 2));
        usort($defectPatterns, static fn (array $a, array $b): int => strcmp($a['packet_shape_key'], $b['packet_shape_key']));

        $workerShapeLearning = TaskFabricGiveBackLearningSupport::buildWorkerShapeLearning(
            $events,
            self::QUARANTINE_THRESHOLD,
        );

        // AC2/AC3/AC4: policy_updates — convert give_back events into concrete Task Fabric
        // policy changes. Repeated give_backs in the same family increase penalty without
        // duplicating identical entries. Low-confidence (single event) give_backs are retained
        // as observations and do not become hard policy.
        $policyUpdates = $this->buildPolicyUpdates($events, $recommendations);

        return [
            'schema'                => self::SCHEMA,
            'recommendations'       => $recommendations,
            'grouped_by_class'      => $classCounts,
            'defect_patterns'       => $defectPatterns,
            'worker_shape_learning' => $workerShapeLearning,
            'chain_repair_hints'    => $this->buildChainRepairHints($recommendations, $workerShapeLearning),
            'policy_updates'        => $policyUpdates,
        ];
    }

    /**
     * Task-graph-ready repair hints: tells the NEXT chain build whether to respec, split, cancel,
     * reroute, or block a dependent chain — so a planner never blindly requeues the same failure
     * shape into a fresh dependent chain. Built from the same per-packet recommendations (never
     * reorders or mutates them) plus worker-shape learning.
     *
     * @param  list<array<string,mixed>>  $recommendations
     * @param  list<array{task_shape:string, worker_client_id:string, give_back_count:int, quarantine_count:int, success_count:int}>  $workerShapeLearning
     * @return list<array<string,mixed>>
     */
    private function buildChainRepairHints(array $recommendations, array $workerShapeLearning): array
    {
        $hints = [];

        foreach ($recommendations as $rec) {
            $action = match ($rec['recommendation']) {
                self::REC_ADD_IMPL => self::CHAIN_ACTION_RESPEC_OR_ADD_IMPL,
                self::REC_RESPEC => self::CHAIN_ACTION_BLOCK_DEPENDENT_CHAIN,
                self::REC_CANCEL, self::REC_QUARANTINE => self::CHAIN_ACTION_CANCEL,
                self::REC_SPLIT => self::CHAIN_ACTION_SPLIT,
                default => null, // operator_only: a human decision, no automated chain hint
            };
            if ($action === null) {
                continue;
            }

            $hints[] = [
                'kind'           => 'packet',
                'task_packet_id' => $rec['task_packet_id'],
                'action'         => $action,
                'reason'         => $rec['failure_class'],
            ];
        }

        foreach ($workerShapeLearning as $shape) {
            if ($shape['give_back_count'] >= self::REROUTE_GIVE_BACK_THRESHOLD && $shape['success_count'] === 0) {
                $hints[] = [
                    'kind'             => 'worker_shape',
                    'task_shape'       => $shape['task_shape'],
                    'worker_client_id' => $shape['worker_client_id'],
                    'action'           => self::CHAIN_ACTION_REROUTE,
                    'reason'           => 'repeated_give_back_with_zero_success_for_worker_shape',
                ];
            }
        }

        usort($hints, static function (array $a, array $b): int {
            $idA = $a['kind'].'|'.($a['task_packet_id'] ?? ($a['task_shape'].'||'.$a['worker_client_id']));
            $idB = $b['kind'].'|'.($b['task_packet_id'] ?? ($b['task_shape'].'||'.$b['worker_client_id']));

            return strcmp($idA, $idB);
        });

        return $hints;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array{task_packet_id:string, give_back_count:int, failure_class:string, recommendation:string, evidence:array<string,mixed>}
     */
    private function recommendFor(string $taskId, array $events): array
    {
        $totalGiveBacks = 0;
        $maxClass = 'generic';
        $sampleDeficiencies = [];
        $maxDeficiencyCount = 0;
        $missingImplCandidate = null;
        $allReasons = [];

        foreach ($events as $ev) {
            $totalGiveBacks = max($totalGiveBacks, (int) ($ev['give_back_count'] ?? 1));
            $reason = (string) ($ev['reason'] ?? '');
            $allReasons[] = $reason;
            $defs = is_array($ev['blocking_deficiencies'] ?? null) ? array_map('strval', $ev['blocking_deficiencies']) : [];
            $sampleDeficiencies = array_merge($sampleDeficiencies, $defs);
            $maxDeficiencyCount = max($maxDeficiencyCount, count($defs));

            $class = TaskFabricGiveBackLearningSupport::classify($reason, $defs);
            if ($class === 'cli_clobber_or_petreo' || $class === 'contradictory_acceptance' || $class === 'scope_repair_missing_impl') {
                if ($maxClass === 'generic') {
                    $maxClass = $class;
                }
                // Prefer the most actionable class in this priority order:
                if ($class === 'cli_clobber_or_petreo') {
                    $maxClass = $class;
                } elseif ($class === 'contradictory_acceptance' && $maxClass !== 'cli_clobber_or_petreo') {
                    $maxClass = $class;
                } elseif ($class === 'scope_repair_missing_impl' && in_array($maxClass, ['generic'], true)) {
                    $maxClass = $class;
                }
            }
            if ($maxClass === 'scope_repair_missing_impl' && $missingImplCandidate === null) {
                $missingImplCandidate = TaskFabricGiveBackLearningSupport::guessMissingImpl($ev);
            }
        }

        $sampleDeficiencies = array_values(array_unique($sampleDeficiencies));

        $recommendation = match (true) {
            $totalGiveBacks >= self::QUARANTINE_THRESHOLD => self::REC_QUARANTINE,
            $maxClass === 'cli_clobber_or_petreo' => self::REC_CANCEL,
            $maxClass === 'contradictory_acceptance' => self::REC_RESPEC,
            $maxClass === 'scope_repair_missing_impl' => self::REC_ADD_IMPL,
            $maxDeficiencyCount >= 3 => self::REC_SPLIT,
            default => self::REC_OPERATOR,
        };

        $respecContractDraft = null;
        if ($recommendation === self::REC_ADD_IMPL && $missingImplCandidate !== null) {
            $evidenceToPreserve = array_values(array_filter(
                array_slice($sampleDeficiencies, 0, 10),
                static fn (string $d): bool => ! preg_match('/missing_impl|missing_file|allowed_files_missing/i', $d)
            ));
            $respecContractDraft = [
                'allowed_files_delta'    => [$missingImplCandidate],
                'acceptance_repair_hint' => 'Add the missing implementation file to allowed_files and re-scope acceptance criteria to match the newly targeted implementation.',
                'evidence_to_preserve'   => $evidenceToPreserve,
            ];
        }

        // Concrete respec_patch_fields — only populated when the evidence actually supports a
        // repair path (add_missing_impl_file / respec). Quarantine/cancel/split/operator_only
        // never get one, so a caller can never mistake a quarantined packet for a direct reopen.
        $respecPatchFields = match (true) {
            $recommendation === self::REC_ADD_IMPL && $missingImplCandidate !== null => [
                'objective' => null,
                'allowed_files' => [$missingImplCandidate],
                'acceptance_criteria' => null,
                'required_evidence' => null,
            ],
            $recommendation === self::REC_RESPEC => [
                'objective' => null,
                'allowed_files' => null,
                'acceptance_criteria' => 'Remove the contradictory/impossible acceptance criterion and replace it with a single runnable, non-contradictory assertion.',
                'required_evidence' => ['tests_or_gates_result'],
            ],
            default => null,
        };

        $doNotRequeueReason = match ($recommendation) {
            self::REC_QUARANTINE => 'give_back_count_reached_quarantine_threshold:operator_respec_required_before_any_requeue',
            self::REC_CANCEL     => 'cli_clobber_or_petreo:task_permanently_blocked:never_requeue',
            default              => null,
        };

        // Stable structural fingerprint: failure_class + sorted allowed_files across all events.
        $allAllowedFiles = [];
        foreach ($events as $ev) {
            $files = is_array($ev['allowed_files'] ?? null) ? array_map('strval', $ev['allowed_files']) : [];
            $allAllowedFiles = array_unique(array_merge($allAllowedFiles, $files));
        }
        sort($allAllowedFiles);
        $packetShapeKey = substr(hash('sha256', $maxClass.'|'.implode(',', $allAllowedFiles)), 0, 20);

        $nextPacketRequirements = $this->buildNextPacketRequirements($recommendation, $missingImplCandidate, $maxDeficiencyCount);

        return [
            'task_packet_id'           => $taskId,
            'give_back_count'          => $totalGiveBacks,
            'failure_class'            => $maxClass,
            'recommendation'           => $recommendation,
            'packet_shape_key'         => $packetShapeKey,
            'next_packet_requirements' => $nextPacketRequirements,
            'evidence'                 => [
                'event_count'            => count($events),
                'sample_deficiencies'    => array_slice($sampleDeficiencies, 0, 10),
                'missing_impl_candidate' => $missingImplCandidate,
                'reasons'                => array_values(array_unique($allReasons)),
            ],
            'respec_contract_draft'    => $respecContractDraft,
            'respec_patch_fields'      => $respecPatchFields,
            'do_not_requeue_reason'    => $doNotRequeueReason,
        ];
    }

    /** @return array<string,mixed>|null */
    private function buildNextPacketRequirements(string $recommendation, ?string $missingImplCandidate, int $maxDeficiencyCount): ?array
    {
        return match ($recommendation) {
            self::REC_ADD_IMPL    => [
                'add_to_allowed_files'         => $missingImplCandidate !== null ? [$missingImplCandidate] : [],
                'recheck_acceptance_criteria'  => true,
            ],
            self::REC_RESPEC      => [
                'remove_contradictory_constraints'       => true,
                'ensure_acceptance_criteria_runnable'    => true,
                'required_evidence_fields'               => ['tests_or_gates_result'],
            ],
            self::REC_SPLIT       => [
                'split_into_packets'         => (int) ceil($maxDeficiencyCount / 2),
                'max_deficiencies_per_packet' => 2,
            ],
            self::REC_OPERATOR    => [
                'escalate_to_operator'         => true,
                'operator_fields_required'     => ['operator_approval', 'manual_review_notes'],
            ],
            default               => null,  // QUARANTINE, CANCEL: do not requeue
        };
    }

    /**
     * Convert give_back events into concrete Task Fabric policy updates.
     *
     * @param  list<array<string,mixed>>  $events
     * @param  list<array<string,mixed>>  $recommendations
     * @return list<array<string,mixed>>
     */
    private function buildPolicyUpdates(array $events, array $recommendations): array
    {
        // Group events by family (derived from task_packet_id prefix before the last '-'.
        $families = [];
        foreach ($events as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $taskId = (string) ($ev['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $family = (string) ($ev['family'] ?? '');
            if ($family === '') {
                $firstDash = strpos($taskId, '-');
                $family = $firstDash !== false ? substr($taskId, 0, $firstDash) : $taskId;
            }
            $class = TaskFabricGiveBackLearningSupport::classify(
                (string) ($ev['reason'] ?? ''),
                is_array($ev['blocking_deficiencies'] ?? null) ? array_map('strval', $ev['blocking_deficiencies']) : [],
            );
            if (! isset($families[$family])) {
                $families[$family] = ['event_count' => 0, 'failure_classes' => [], 'deficiencies' => []];
            }
            $families[$family]['event_count']++;
            $families[$family]['failure_classes'][$class] = true;
            $defs = is_array($ev['blocking_deficiencies'] ?? null) ? array_map('strval', $ev['blocking_deficiencies']) : [];
            foreach ($defs as $d) {
                $families[$family]['deficiencies'][$d] = true;
            }
        }

        $updates = [];
        foreach ($families as $family => $info) {
            // AC4: single-event families are low-confidence observations, not hard policy.
            if ($info['event_count'] < 2) {
                continue;
            }

            $penaltyLevel = min(1.0, $info['event_count'] * 0.15);
            $hasImplIssue = isset($info['failure_classes']['scope_repair_missing_impl']);
            $hasContradiction = isset($info['failure_classes']['contradictory_acceptance']);
            $hasCliClobber = isset($info['failure_classes']['cli_clobber_or_petreo']);

            // Scope repair hint: if missing_impl is the dominant pattern.
            $scopeRepairHint = $hasImplIssue
                ? 'Add missing implementation file(s) to allowed_files before respecing packets in this family.'
                : null;

            // Routing signal: route away from this family if cli_clobber or contradictions dominate.
            $routingSignal = ($hasCliClobber || $hasContradiction)
                ? 'route_away_from_family'
                : null;

            // Exclusion rule: exclude from future batches if multiple cli_clobbers or very high penalty.
            $exclusionRule = null;
            if ($hasCliClobber && $penaltyLevel >= 0.45) {
                $exclusionRule = 'exclude_from_future_batches';
            }

            $updates[] = [
                'family'           => $family,
                'event_count'      => $info['event_count'],
                'family_penalty'   => round($penaltyLevel, 2),
                'scope_repair_hint' => $scopeRepairHint,
                'routing_signal'   => $routingSignal,
                'exclusion_rule'   => $exclusionRule,
                'failure_classes'  => array_keys($info['failure_classes']),
            ];
        }

        // Sort deterministically.
        usort($updates, static fn (array $a, array $b): int => strcmp($a['family'], $b['family']));

        return $updates;
    }
}
