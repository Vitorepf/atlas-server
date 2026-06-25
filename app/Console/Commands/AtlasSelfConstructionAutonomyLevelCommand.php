<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyDegradationPolicy;
use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyLevelLadder;
use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyPromotionGate;
use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyRuntimeLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI for Atlas Self-Construction autonomy ladder. Verbs:
 *   levels   — list autonomy levels with policy facts.
 *   promote  — evaluate a promotion (from_level → to_level) against gate facts; appends ledger event.
 *   degrade  — run the degradation policy over facts; appends ledger event.
 *   history  — read ledger history (optionally filtered by lane).
 *
 * Facts-only inputs from a JSON file path; no broad shell-out, no provider call.
 */
final class AtlasSelfConstructionAutonomyLevelCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:autonomy-level {action : levels|promote|degrade|history} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Atlas Self-Construction autonomy ladder CLI: levels, promote, degrade, history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $result = match ($action) {
            'levels' => $this->levels(),
            'promote' => $this->promote($facts),
            'degrade' => $this->degrade($facts),
            'history' => $this->history($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($result['status'] ?? 'ok') === 'ok' || ! isset($result['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function levels(): array
    {
        $ladder = $this->app()->make(AtlasSelfConstructionAutonomyLevelLadder::class);

        return [
            'status' => 'ok',
            'order' => $ladder->order(),
            'levels' => $ladder->levels(),
        ];
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function promote(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['from_level'], $facts['to_level'], $facts['facts'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with from_level,to_level,facts{} required'];
        }
        $gate = $this->app()->make(AtlasSelfConstructionAutonomyPromotionGate::class);
        $verdict = $gate->evaluate((string) $facts['from_level'], (string) $facts['to_level'], (array) $facts['facts']);
        $reasons = $this->promotionReasons($verdict);

        $ledgerEvent = $this->maybeAppend($facts, [
            'kind' => $verdict['verdict'] === AtlasSelfConstructionAutonomyPromotionGate::VERDICT_PROMOTE
                ? AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED
                : ($verdict['verdict'] === AtlasSelfConstructionAutonomyPromotionGate::VERDICT_REFUSE
                    ? AtlasSelfConstructionAutonomyRuntimeLedger::KIND_REFUSED
                    : AtlasSelfConstructionAutonomyRuntimeLedger::KIND_REQUESTED),
            'level' => (string) $facts['to_level'],
            'decision' => (string) $verdict['verdict'],
            'reasons' => $reasons,
            'lane' => (string) ($facts['lane'] ?? ''),
            'created_at_unix' => (int) ($facts['created_at_unix'] ?? 0),
        ]);

        return [
            'status' => 'ok',
            'decision' => $verdict['verdict'],
            'level' => (string) $facts['to_level'],
            'reasons' => $reasons,
            'ledger_event_hash' => $ledgerEvent['evidence_hash'] ?? null,
        ];
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function degrade(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['facts'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with facts{} required'];
        }
        $policy = $this->app()->make(AtlasSelfConstructionAutonomyDegradationPolicy::class);
        $verdict = $policy->decide((array) $facts['facts']);
        $reasons = isset($verdict['reason']) && $verdict['reason'] !== '' ? [(string) $verdict['reason']] : [];

        $ledgerEvent = $this->maybeAppend($facts, [
            'kind' => $verdict['action'] === AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_NO_ACTION
                ? AtlasSelfConstructionAutonomyRuntimeLedger::KIND_REQUESTED
                : AtlasSelfConstructionAutonomyRuntimeLedger::KIND_DEGRADED,
            'level' => (string) ($facts['level'] ?? ''),
            'decision' => (string) ($verdict['action'] ?? ''),
            'reasons' => $reasons,
            'lane' => (string) ($facts['lane'] ?? ''),
            'created_at_unix' => (int) ($facts['created_at_unix'] ?? 0),
        ]);

        return [
            'status' => 'ok',
            'decision' => $verdict['action'],
            'level' => (string) ($facts['level'] ?? ''),
            'reasons' => $reasons,
            'ledger_event_hash' => $ledgerEvent['evidence_hash'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return list<string>
     */
    private function promotionReasons(array $verdict): array
    {
        $reasons = [];
        if (isset($verdict['refusal_reason']) && $verdict['refusal_reason'] !== '') {
            $reasons[] = 'refusal:'.(string) $verdict['refusal_reason'];
        }
        foreach ((array) ($verdict['missing_fact_keys'] ?? []) as $k) {
            $reasons[] = 'missing_fact:'.(string) $k;
        }
        foreach ((array) ($verdict['failing_fact_keys'] ?? []) as $k) {
            $reasons[] = 'failing_fact:'.(string) $k;
        }
        foreach ((array) ($verdict['satisfied_fact_keys'] ?? []) as $k) {
            $reasons[] = 'satisfied:'.(string) $k;
        }

        return array_values($reasons);
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function history(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['ledger_path'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with ledger_path required'];
        }
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger((string) $facts['ledger_path']);
        $lane = isset($facts['lane']) ? (string) $facts['lane'] : '';
        $events = $lane !== '' ? $ledger->historyForLane($lane) : $ledger->all();

        return [
            'status' => 'ok',
            'events' => $events,
            'latest' => $ledger->latest(),
            'count' => count($events),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>|null
     */
    private function maybeAppend(array $facts, array $event): ?array
    {
        $ledgerPath = (string) ($facts['ledger_path'] ?? '');
        if ($ledgerPath === '') {
            return null;
        }
        try {
            return (new AtlasSelfConstructionAutonomyRuntimeLedger($ledgerPath))->append($event);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
