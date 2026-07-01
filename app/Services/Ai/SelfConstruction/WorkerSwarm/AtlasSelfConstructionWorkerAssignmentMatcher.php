<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\WorkerSwarm;

/**
 * Pure matcher — given one task packet + a list of worker capability facts, returns a deterministic
 * ranked list of eligible assignments + the rejection reasons for ineligible workers.
 *
 * Inputs:
 *   task: {
 *     task_packet_id: string,
 *     required_capabilities: list<string>,
 *     risk_class: 'low'|'medium'|'high'|'critical',
 *     allowed_files: list<string>,
 *     required_evidence_kinds: list<string>,
 *   }
 *   workers: list<{
 *     worker_id: string,
 *     capabilities: list<string>,
 *     max_risk_class: 'low'|'medium'|'high'|'critical',
 *     allowed_scope_prefixes: list<string>,
 *     evidence_kinds_supported: list<string>,
 *     readiness: {ready:bool, freshness_unix?:int},
 *     success_families?: list<string>,
 *     give_back_families?: list<string>,
 *     poison_families?: list<string>,
 *   }>
 *   task.task_family (optional) activates outcome_fit_hints.
 *
 * Output:
 *   {schema_version, task_packet_id, eligible:list<{worker_id, reasons:list<string>}>,
 *    ineligible:list<{worker_id, reasons:list<string>}>,
 *    outcome_fit_hints:list<{worker_id, fit:'preferred'|'caution'|'rejected_by_history'|'neutral', reasons:list<string>}>}
 * outcome_fit_hints is advisory — does NOT affect eligible/ineligible split.
 *
 * Pure — NEVER starts workers, claims tasks, or writes ledgers.
 */
final class AtlasSelfConstructionWorkerAssignmentMatcher
{
    public const SCHEMA = 'atlas.worker_swarm.assignment_match.v1';

    private const RISK_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    // Lower rank sorts first — preferred outranks neutral, which outranks caution,
    // which outranks rejected_by_history (poison never eligible-ranks above neutral).
    private const FIT_RANK = ['preferred' => 0, 'neutral' => 1, 'caution' => 2, 'rejected_by_history' => 3];

    public const DEFAULT_FRESHNESS_WINDOW_SECONDS = 3600;

    /**
     * @param  array<string,mixed>  $task
     * @param  list<array<string,mixed>>  $workers
     * @return array<string,mixed>
     */
    public function match(array $task, array $workers, int $nowUnix = 0, int $freshnessWindowSeconds = self::DEFAULT_FRESHNESS_WINDOW_SECONDS): array
    {
        $taskCapabilities = array_values((array) ($task['required_capabilities'] ?? []));
        $taskRisk = strtolower((string) ($task['risk_class'] ?? 'low'));
        $allowedFiles = array_values((array) ($task['allowed_files'] ?? []));
        $requiredEvidence = array_values((array) ($task['required_evidence_kinds'] ?? []));
        $taskFamily = (string) ($task['task_family'] ?? '');

        $eligible = [];
        $ineligible = [];

        foreach ($workers as $worker) {
            if (! is_array($worker)) {
                continue;
            }
            $reasons = [];

            $wCaps = array_values((array) ($worker['capabilities'] ?? []));
            $missingCaps = array_values(array_diff($taskCapabilities, $wCaps));
            if ($missingCaps !== []) {
                $reasons[] = 'missing_capabilities:'.implode(',', $missingCaps);
            }

            $maxRisk = strtolower((string) ($worker['max_risk_class'] ?? 'low'));
            if ((self::RISK_ORDER[$maxRisk] ?? 0) < (self::RISK_ORDER[$taskRisk] ?? 0)) {
                $reasons[] = 'risk_class_too_high_for_worker:'.$maxRisk.'<'.$taskRisk;
            }

            $allowedPrefixes = array_values((array) ($worker['allowed_scope_prefixes'] ?? []));
            $outOfScope = [];
            foreach ($allowedFiles as $f) {
                if (! $this->inAnyPrefix((string) $f, $allowedPrefixes)) {
                    $outOfScope[] = $f;
                }
            }
            if ($outOfScope !== []) {
                $reasons[] = 'scope_out_of_worker_allowlist:'.implode(',', $outOfScope);
            }

            $supportedEvidence = array_values((array) ($worker['evidence_kinds_supported'] ?? []));
            $missingEvidence = array_values(array_diff($requiredEvidence, $supportedEvidence));
            if ($missingEvidence !== []) {
                $reasons[] = 'missing_evidence_kinds:'.implode(',', $missingEvidence);
            }

            $readiness = (array) ($worker['readiness'] ?? []);
            if (! (bool) ($readiness['ready'] ?? false)) {
                $reasons[] = 'worker_not_ready';
            } elseif (isset($readiness['freshness_unix']) && is_int($readiness['freshness_unix']) && $nowUnix > 0) {
                if ($nowUnix - (int) $readiness['freshness_unix'] > $freshnessWindowSeconds) {
                    $reasons[] = 'worker_readiness_stale';
                }
            }

            $workerId = (string) ($worker['worker_id'] ?? '');
            if ($reasons === []) {
                $eligible[] = ['worker_id' => $workerId, 'reasons' => ['matched']];
            } else {
                $ineligible[] = ['worker_id' => $workerId, 'reasons' => $reasons];
            }
        }

        usort($ineligible, static fn (array $a, array $b): int => strcmp($a['worker_id'], $b['worker_id']));

        // outcome_fit_hints — computed here so the eligible ranking below can use them; the
        // eligible/ineligible SPLIT itself never depends on outcome history, only ordering does.
        $outcomeHints = [];
        $fitByWorkerId = [];
        if ($taskFamily !== '') {
            foreach ($workers as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $hint = $this->outcomeHint($taskFamily, $w);
                $outcomeHints[] = $hint;
                $fitByWorkerId[$hint['worker_id']] = $hint['fit'];
            }
            usort($outcomeHints, static fn (array $a, array $b): int => strcmp($a['worker_id'], $b['worker_id']));
        }

        // Eligible ranking: outcome affinity first (preferred > neutral > caution >
        // rejected_by_history), worker_id ASC as the deterministic tiebreak.
        usort($eligible, static function (array $a, array $b) use ($fitByWorkerId): int {
            $ra = self::FIT_RANK[$fitByWorkerId[$a['worker_id']] ?? 'neutral'] ?? 1;
            $rb = self::FIT_RANK[$fitByWorkerId[$b['worker_id']] ?? 'neutral'] ?? 1;

            return $ra <=> $rb ?: strcmp($a['worker_id'], $b['worker_id']);
        });

        return [
            'schema_version'      => self::SCHEMA,
            'task_packet_id'      => (string) ($task['task_packet_id'] ?? ''),
            'eligible'            => $eligible,
            'ineligible'          => $ineligible,
            'outcome_fit_hints'   => $outcomeHints,
        ];
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function inAnyPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            $p = (string) $p;
            if ($p !== '' && (str_starts_with($path, $p))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $worker
     * @return array{worker_id:string, fit:string, reasons:list<string>}
     */
    private function outcomeHint(string $taskFamily, array $worker): array
    {
        $workerId       = (string) ($worker['worker_id'] ?? '');
        $poisonFamilies = array_map('strval', (array) ($worker['poison_families'] ?? []));
        $giveBackFams   = array_map('strval', (array) ($worker['give_back_families'] ?? []));
        $successFams    = array_map('strval', (array) ($worker['success_families'] ?? []));

        // Worst-signal-first: poison > give_back > success.
        if (in_array($taskFamily, $poisonFamilies, true)) {
            return ['worker_id' => $workerId, 'fit' => 'rejected_by_history', 'reasons' => ["poison_family_match:{$taskFamily}"]];
        }
        if (in_array($taskFamily, $giveBackFams, true)) {
            return ['worker_id' => $workerId, 'fit' => 'caution', 'reasons' => ["give_back_family_match:{$taskFamily}"]];
        }
        if (in_array($taskFamily, $successFams, true)) {
            return ['worker_id' => $workerId, 'fit' => 'preferred', 'reasons' => ["success_family_match:{$taskFamily}"]];
        }

        return ['worker_id' => $workerId, 'fit' => 'neutral', 'reasons' => []];
    }
}
