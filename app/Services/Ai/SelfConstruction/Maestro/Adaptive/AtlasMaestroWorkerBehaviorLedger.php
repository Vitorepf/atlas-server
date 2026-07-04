<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger;
use Throwable;

/**
 * Durable ledger that records and recalls outcome rates by client, task family
 * and root cause. Gives Maestro a compact source of truth for routing and
 * poison avoidance.
 *
 * Persistence: append-only jsonl sibling of the learning-transfer admission
 * ledger (`admission.jsonl` → `admission.behavior.jsonl`), so the phpunit env
 * pin covers it with zero new config — the same convention as the resolved
 * exemplar ledger. Before this the ledger was pure in-memory: every process
 * (each `atlas:task` invocation is one) started empty, so recall() ALWAYS
 * returned defaults and every reader read a vacuum.
 *
 * Recall returns conservative defaults for unseen workers — never optimistic.
 * All file I/O is fail-open: an unreadable/unwritable ledger degrades to the
 * old in-memory behavior, it never breaks a report or a claim.
 *
 * NO network I/O, NO provider calls.
 */
final class AtlasMaestroWorkerBehaviorLedger
{
    public const SCHEMA = 'atlas.maestro.worker_behavior_ledger.v1';

    /** @var array<string,array{success:int,give_back:int,weak_green:int}> indexed by client_id|task_family */
    private array $stats = [];

    /** @var array<string,int> indexed by root_cause_family */
    private array $giveBackRootCauses = [];

    private bool $hydrated = false;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Sibling of the admission ledger (`.jsonl` → `.behavior.jsonl`) so the
     * hermetic test pin (ATLAS_LEARNING_TRANSFER_ADMISSION_LEDGER_PATH) covers
     * this ledger too. Falls back to the env pin directly when the Laravel
     * container is not booted (pure-PHPUnit unit tests).
     */
    public static function defaultPath(): string
    {
        try {
            $admission = AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath();
        } catch (Throwable) {
            $admission = (string) (getenv('ATLAS_LEARNING_TRANSFER_ADMISSION_LEDGER_PATH') ?: '');
        }
        if ($admission === '') {
            return sys_get_temp_dir().'/atlas-maestro-worker-behavior.jsonl';
        }

        return (string) preg_replace('/\.jsonl$/', '.behavior.jsonl', $admission);
    }

    private function resolvedPath(): string
    {
        return $this->path ?? self::defaultPath();
    }

    /** Replays the durable jsonl into memory exactly once per instance. Fail-open. */
    private function ensureHydrated(): void
    {
        if ($this->hydrated) {
            return;
        }
        $this->hydrated = true;

        try {
            $path = $this->resolvedPath();
            if (! is_file($path)) {
                return;
            }
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (is_array($event)) {
                    $this->apply($event);
                }
            }
        } catch (Throwable) {
            // Fail-open: unreadable ledger degrades to in-memory-only behavior.
        }
    }

    /**
     * Record an outcome event.
     *
     * @param  array{
     *   client_id?:string,
     *   task_family?:string,
     *   outcome?:string,
     *   root_cause_family?:string,
     * }  $event
     */
    public function record(array $event): void
    {
        $this->ensureHydrated();
        $this->apply($event);

        try {
            $path = $this->resolvedPath();
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Fail-open: a write hiccup never breaks the caller's report.
        }
    }

    /** @param array<string,mixed> $event */
    private function apply(array $event): void
    {
        $clientId = (string) ($event['client_id'] ?? 'unknown');
        $family = (string) ($event['task_family'] ?? 'unknown');
        $outcome = (string) ($event['outcome'] ?? '');
        $key = json_encode([$clientId, $family], JSON_THROW_ON_ERROR);

        if (! isset($this->stats[$key])) {
            $this->stats[$key] = ['success' => 0, 'give_back' => 0, 'weak_green' => 0];
        }

        switch ($outcome) {
            case 'success':
            case 'resolved':
            case 'completed_dry_run':
                $this->stats[$key]['success']++;
                break;
            case 'give_back':
                $this->stats[$key]['give_back']++;
                $rootCause = (string) ($event['root_cause_family'] ?? 'unspecified');
                $rcKey = json_encode([$family, $rootCause], JSON_THROW_ON_ERROR);
                $this->giveBackRootCauses[$rcKey] = ($this->giveBackRootCauses[$rcKey] ?? 0) + 1;
                break;
            case 'weak_green':
                $this->stats[$key]['weak_green']++;
                break;
        }
    }

    /**
     * Recall outcome rates for a client+family.
     * Returns conservative defaults for unseen workers.
     *
     * @return array{
     *   client_id:string,
     *   task_family:string,
     *   success_rate:float,
     *   give_back_rate:float,
     *   weak_green_rate:float,
     *   total_events:int,
     *   seen:bool,
     * }
     */
    public function recall(string $clientId, string $family): array
    {
        $this->ensureHydrated();
        $key = json_encode([$clientId, $family], JSON_THROW_ON_ERROR);
        $row = $this->stats[$key] ?? null;

        if ($row === null) {
            // Conservative defaults for unseen workers — never optimistic.
            return [
                'client_id' => $clientId,
                'task_family' => $family,
                'success_rate' => 0.0,
                'give_back_rate' => 0.0,
                'weak_green_rate' => 0.0,
                'total_events' => 0,
                'seen' => false,
            ];
        }

        $total = $row['success'] + $row['give_back'] + $row['weak_green'];
        $total = max(1, $total);

        return [
            'client_id' => $clientId,
            'task_family' => $family,
            'success_rate' => round($row['success'] / $total, 4),
            'give_back_rate' => round($row['give_back'] / $total, 4),
            'weak_green_rate' => round($row['weak_green'] / $total, 4),
            'total_events' => $row['success'] + $row['give_back'] + $row['weak_green'],
            'seen' => true,
        ];
    }

    /**
     * Get top give_back classes with root cause family counts.
     *
     * @return list<array{root_cause_key:string,count:int}>
     */
    public function topGiveBackCauses(int $limit = 10): array
    {
        $this->ensureHydrated();
        $causes = [];
        foreach ($this->giveBackRootCauses as $key => $count) {
            $causes[] = ['root_cause_key' => $key, 'count' => $count];
        }
        usort($causes, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($causes, 0, $limit);
    }

    /**
     * Get aggregated stats for all recorded client+family combinations.
     *
     * @return array<string,array{success:int,give_back:int,weak_green:int}>
     */
    public function allStats(): array
    {
        $this->ensureHydrated();

        return $this->stats;
    }

    /**
     * Evidence-weighted worker profile: discounts success without runnable proof.
     *
     * Success rows without `tests_or_gates_result` or `implementation_notes` evidence
     * are down-weighted (counted as 0.5 instead of 1.0).
     *
     * @return array{worker_reliability:float, family_fit:float, evidence_weighted_success_rate:float, routing_notes:list<string>}
     */
    public function evidenceWeightedProfile(string $clientId, string $family): array
    {
        $this->ensureHydrated();
        $key = json_encode([$clientId, $family], JSON_THROW_ON_ERROR);
        $row = $this->stats[$key] ?? null;

        $routingNotes = [];

        if ($row === null) {
            $routingNotes[] = 'unseen_worker_conservative_defaults';
            return [
                'worker_reliability' => 0.0,
                'family_fit' => 0.0,
                'evidence_weighted_success_rate' => 0.0,
                'routing_notes' => $routingNotes,
            ];
        }

        $total = $row['success'] + $row['give_back'] + $row['weak_green'];
        $total = max(1, $total);

        // Evidence-weighted success: weak_green outcomes are down-weighted to 0.5
        // because they represent self-reported success without runnable proof
        $evidenceWeightedSuccess = $row['success'] * 1.0 + $row['weak_green'] * 0.5;
        $evidenceWeightedSuccessRate = round($evidenceWeightedSuccess / $total, 4);

        // Worker reliability: 1 - give_back_rate, penalized by weak_green ratio
        $giveBackRate = $row['give_back'] / $total;
        $weakGreenRatio = $row['weak_green'] / $total;
        $workerReliability = round(max(0.0, 1.0 - $giveBackRate - ($weakGreenRatio * 0.3)), 4);

        // Family fit: evidence-weighted success rate adjusted by give_back penalty
        $familyFit = round(max(0.0, $evidenceWeightedSuccessRate - ($giveBackRate * 0.5)), 4);

        // Generate routing notes
        if ($giveBackRate > 0.5) {
            $routingNotes[] = 'high_give_back_rate_exceeds_threshold';
        }
        if ($weakGreenRatio > 0.3) {
            $routingNotes[] = 'excessive_weak_green_outcomes_lack_runnable_proof';
        }
        if ($evidenceWeightedSuccessRate < 0.3 && $total >= 3) {
            $routingNotes[] = 'low_evidence_weighted_success_rate';
        }
        if ($row['weak_green'] > 0) {
            $routingNotes[] = 'discounted_weak_green_outcomes_without_tests_or_gates_result';
        }

        return [
            'worker_reliability' => $workerReliability,
            'family_fit' => $familyFit,
            'evidence_weighted_success_rate' => $evidenceWeightedSuccessRate,
            'routing_notes' => $routingNotes,
        ];
    }
}
