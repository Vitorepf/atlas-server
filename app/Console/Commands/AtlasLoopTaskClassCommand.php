<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassMiner;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassProposer;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassReceipt;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassReceiptLedger;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator CLI over the task-class discovery surface. Thin shell — ZERO business logic; the four
 * services hold all logic.
 */
final class AtlasLoopTaskClassCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:task-class
        {action : mine|propose|register|history}
        {--class-id=}
        {--proposal-id=}
        {--operator-token=}
        {--facts=}
        {--json}';

    /** @var string */
    protected $description = 'Task-class discovery CLI: mine | propose | register | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'mine' => $this->mine(),
            'propose' => $this->propose(),
            'register' => $this->register(),
            'history' => $this->history(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function mine(): int
    {
        $facts = $this->readFacts();
        $campaignId = (string) ($facts['campaign_id'] ?? '');
        $records = (array) ($facts['records'] ?? []);
        $miner = $this->getLaravel()->make(AtlasLoopTaskClassMiner::class);
        try {
            $clusters = $miner->mine($campaignId, $records);
        } catch (Throwable $e) {
            return $this->emit(['error' => 'mine_failed', 'reason' => $e->getMessage()], self::FAILURE);
        }

        return $this->emit(['action' => 'mine', 'clusters' => $clusters]);
    }

    private function propose(): int
    {
        $facts = $this->readFacts();
        $cluster = is_array($facts['cluster'] ?? null) ? $facts['cluster'] : [];
        $proposer = $this->getLaravel()->make(AtlasLoopTaskClassProposer::class);
        try {
            $proposal = $proposer->propose($cluster);
        } catch (Throwable $e) {
            return $this->emit(['error' => 'propose_failed', 'reason' => $e->getMessage()], self::FAILURE);
        }

        return $this->emit(['action' => 'propose', 'proposal' => $proposal]);
    }

    private function register(): int
    {
        $token = (string) ($this->option('operator-token') ?? '');
        if ($token === '') {
            return $this->emit(['error' => 'operator_token_required'], self::FAILURE);
        }
        $proposalId = (string) ($this->option('proposal-id') ?? '');
        if ($proposalId === '') {
            return $this->emit(['error' => 'proposal_id_required'], self::FAILURE);
        }
        $registry = $this->getLaravel()->make(AtlasLoopTaskClassRegistry::class);
        try {
            $entry = $registry->approve($proposalId, $token);
        } catch (Throwable $e) {
            return $this->emit(['error' => 'register_failed', 'reason' => $e->getMessage()], self::FAILURE);
        }

        return $this->emit(['action' => 'register', 'entry' => $entry->toArray()]);
    }

    private function history(): int
    {
        $classId = (string) ($this->option('class-id') ?? '');
        if ($classId === '') {
            return $this->emit(['error' => 'class_id_required'], self::FAILURE);
        }
        $ledger = $this->getLaravel()->make(AtlasLoopTaskClassReceiptLedger::class);
        $rows = [];
        foreach ($ledger->history($classId) as $receipt) {
            $rows[] = $receipt instanceof AtlasLoopTaskClassReceipt ? $receipt->toArray() : $receipt;
        }

        return $this->emit(['action' => 'history', 'class_id' => $classId, 'rows' => $rows]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readFacts(): array
    {
        $path = (string) ($this->option('facts') ?? '');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        ksort($payload);
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
