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
 *   }>
 *
 * Output:
 *   {schema_version, task_packet_id, eligible:list<{worker_id, reasons:list<string>}>, ineligible:list<{worker_id, reasons:list<string>}>}
 *
 * Pure — NEVER starts workers, claims tasks, or writes ledgers.
 */
final class AtlasSelfConstructionWorkerAssignmentMatcher
{
    public const SCHEMA = 'atlas.worker_swarm.assignment_match.v1';

    private const RISK_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

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

        // Deterministic tie ordering — eligible list sorted by worker_id ASC.
        usort($eligible, static fn (array $a, array $b): int => strcmp($a['worker_id'], $b['worker_id']));
        usort($ineligible, static fn (array $a, array $b): int => strcmp($a['worker_id'], $b['worker_id']));

        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => (string) ($task['task_packet_id'] ?? ''),
            'eligible' => $eligible,
            'ineligible' => $ineligible,
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
}
