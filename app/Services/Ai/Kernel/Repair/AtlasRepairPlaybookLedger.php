<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Throwable;

/**
 * T4-S3 (Obra #17) — repair OUTCOME corpus + procedural playbook (FOUNDATION).
 *
 * The missing substrate for "procedural memory": until now the repair flow only
 * recorded that a strategy was PLANNED (RepairResult carries `executed`, never a
 * success signal — the "did the fix work?" is a DOWNSTREAM re-run). This ledger
 * captures the real outcome: a task that got a repair decision and LATER passes
 * = that domain's repair resolved. Keyed by a stable task key (not the per-run
 * envelope id), so a fail→…→pass across attempts correlates.
 *
 * Honest, not a fake recipe-book: because strategyFor(domain) is deterministic,
 * the real learnable signal is the per-DOMAIN resolution RATE (how often repairs
 * of that class actually get resolved), not "which fixed strategy to pick". When
 * strategies become variable, the same corpus captures which resolves better.
 * Degrade-safe: zero corpus → `unmeasured`, never a fabricated rate.
 *
 * Storage: on-disk append-only JSONL (survives the DB, like the Diary/tick log).
 *   {"event":"decision|resolved","task_key":"atlas_task:ID","domain":"...",
 *    "strategy":"...","at":"ISO-8601"}
 */
final class AtlasRepairPlaybookLedger
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (function_exists('storage_path')
            ? storage_path('atlas/kernel/repair_playbook.jsonl')
            : sys_get_temp_dir().'/atlas/kernel/repair_playbook.jsonl');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** A repair strategy was PLANNED for a failing task of this domain. */
    public function recordDecision(string $taskKey, string $domain, string $strategy): void
    {
        if (trim($taskKey) === '') {
            return;
        }
        $this->append([
            'event' => 'decision',
            'task_key' => $taskKey,
            'domain' => $domain,
            'strategy' => $strategy,
        ]);
    }

    /**
     * The task later PASSED. If it had an OPEN repair decision (last event for
     * the task is a decision, not yet resolved), credit that decision's domain
     * + strategy as resolved. No-op otherwise — never credits a phantom repair.
     */
    public function recordResolved(string $taskKey): void
    {
        if (trim($taskKey) === '') {
            return;
        }
        $open = $this->openDecisionFor($taskKey);
        if ($open === null) {
            return;
        }
        $this->append([
            'event' => 'resolved',
            'task_key' => $taskKey,
            'domain' => (string) ($open['domain'] ?? ''),
            'strategy' => (string) ($open['strategy'] ?? ''),
        ]);
    }

    /**
     * Per-domain procedural playbook resolved from the corpus. Degrade-safe:
     * no attempts → status `unmeasured` (never a fabricated rate).
     *
     * @return array{schema_version:string,domain:string,status:string,attempts:int,resolved:int,resolve_rate:float,by_strategy:array<int,array<string,mixed>>}
     */
    public function playbookFor(string $domain): array
    {
        $attempts = 0;
        $resolved = 0;
        /** @var array<string,array{attempts:int,resolved:int}> $byStrategy */
        $byStrategy = [];

        foreach ($this->readAll() as $row) {
            if ((string) ($row['domain'] ?? '') !== $domain) {
                continue;
            }
            $strategy = (string) ($row['strategy'] ?? 'unknown');
            $byStrategy[$strategy] ??= ['attempts' => 0, 'resolved' => 0];
            $event = (string) ($row['event'] ?? '');
            if ($event === 'decision') {
                $attempts++;
                $byStrategy[$strategy]['attempts']++;
            } elseif ($event === 'resolved') {
                $resolved++;
                $byStrategy[$strategy]['resolved']++;
            }
        }

        $strategies = [];
        foreach ($byStrategy as $strategy => $counts) {
            $strategies[] = [
                'strategy' => $strategy,
                'attempts' => $counts['attempts'],
                'resolved' => $counts['resolved'],
                'resolve_rate' => $counts['attempts'] > 0 ? round($counts['resolved'] / $counts['attempts'], 3) : 0.0,
            ];
        }

        return [
            'schema_version' => 'atlas.kernel.repair_playbook.v1',
            'domain' => $domain,
            'status' => $attempts > 0 ? 'measured' : 'unmeasured',
            'attempts' => $attempts,
            'resolved' => $resolved,
            'resolve_rate' => $attempts > 0 ? round($resolved / $attempts, 3) : 0.0,
            'by_strategy' => $strategies,
        ];
    }

    /**
     * @return array<string,mixed>|null the domain/strategy of the task's open
     *                                  (unresolved) decision, or null if none
     */
    private function openDecisionFor(string $taskKey): ?array
    {
        $last = null;
        foreach ($this->readAll() as $row) {
            if ((string) ($row['task_key'] ?? '') !== $taskKey) {
                continue;
            }
            $last = $row;
        }

        return ($last !== null && (string) ($last['event'] ?? '') === 'decision') ? $last : null;
    }

    /** @return list<array<string,mixed>> */
    private function readAll(): array
    {
        try {
            return AppendOnlyJsonlStore::read($this->path);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function append(array $payload): void
    {
        try {
            $payload['at'] = now()->toIso8601String();
            AppendOnlyJsonlStore::append($this->path, $payload);
        } catch (Throwable) {
            // fail-open: the corpus never blocks a repair flow.
        }
    }
}
