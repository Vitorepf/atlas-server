<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

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

        return [
            'schema'                => self::SCHEMA,
            'recommendations'       => $recommendations,
            'grouped_by_class'      => $classCounts,
            'worker_shape_learning' => $this->buildWorkerShapeLearning($events),
        ];
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

            $class = $this->classify($reason, $defs);
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
                $missingImplCandidate = $this->guessMissingImpl($ev);
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

        $doNotRequeueReason = match ($recommendation) {
            self::REC_QUARANTINE => 'give_back_count_reached_quarantine_threshold:operator_respec_required_before_any_requeue',
            self::REC_CANCEL     => 'cli_clobber_or_petreo:task_permanently_blocked:never_requeue',
            default              => null,
        };

        return [
            'task_packet_id'        => $taskId,
            'give_back_count'       => $totalGiveBacks,
            'failure_class'         => $maxClass,
            'recommendation'        => $recommendation,
            'evidence'              => [
                'event_count'            => count($events),
                'sample_deficiencies'    => array_slice($sampleDeficiencies, 0, 10),
                'missing_impl_candidate' => $missingImplCandidate,
                'reasons'                => array_values(array_unique($allReasons)),
            ],
            'respec_contract_draft' => $respecContractDraft,
            'do_not_requeue_reason' => $doNotRequeueReason,
        ];
    }

    /**
     * Group events by (task_shape, worker_client_id) and emit deterministic learning facts.
     * Skips events where both task_shape and worker_client_id are absent.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array{task_shape:string, worker_client_id:string, give_back_count:int, quarantine_count:int, success_count:int}>
     */
    private function buildWorkerShapeLearning(array $events): array
    {
        $groups = [];
        foreach ($events as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $taskShape = (string) ($ev['task_shape'] ?? '');
            $workerId = (string) ($ev['worker_client_id'] ?? '');
            if ($taskShape === '' && $workerId === '') {
                continue;
            }
            $key = $taskShape.'||'.$workerId;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'task_shape'       => $taskShape,
                    'worker_client_id' => $workerId,
                    'give_back_count'  => 0,
                    'quarantine_count' => 0,
                    'success_count'    => 0,
                ];
            }
            $outcome = (string) ($ev['outcome'] ?? 'give_back');
            if ($outcome === 'success') {
                $groups[$key]['success_count']++;
            } else {
                $groups[$key]['give_back_count']++;
                if ((int) ($ev['give_back_count'] ?? 1) >= self::QUARANTINE_THRESHOLD) {
                    $groups[$key]['quarantine_count']++;
                }
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @param  list<string>  $defs
     */
    private function classify(string $reason, array $defs): string
    {
        $haystack = strtolower($reason.' '.implode(' ', $defs));
        if (preg_match('/cli_clobber|forbidden_core|petreo|pétreo/i', $haystack)) {
            return 'cli_clobber_or_petreo';
        }
        if (preg_match('/contradict|goodhart|scalar_score|impossible_accept/i', $haystack)) {
            return 'contradictory_acceptance';
        }
        if (preg_match('/scope_repair|missing_impl|missing_file|allowed_files_missing/i', $haystack)) {
            return 'scope_repair_missing_impl';
        }

        return 'generic';
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function guessMissingImpl(array $event): ?string
    {
        $defs = is_array($event['blocking_deficiencies'] ?? null) ? array_map('strval', $event['blocking_deficiencies']) : [];
        foreach ($defs as $d) {
            if (preg_match('/missing_impl(?:_file)?:?\s*([^\s,]+)/i', $d, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
