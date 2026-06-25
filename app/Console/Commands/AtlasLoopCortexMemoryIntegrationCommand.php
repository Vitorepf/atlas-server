<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryFactGrounder;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryReader;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryWriteProposalGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface for the Cortex memory integration loop.
 *   read     — normalized memory entries via AtlasCortexMemoryReader
 *   ground   — grounded vs ungrounded via AtlasCortexMemoryFactGrounder
 *   propose  — operator-approved write-back via AtlasCortexMemoryWriteProposalGate
 *   history  — receipt history via AtlasCortexMemoryReceiptLedger
 *
 * Thin shell: all business logic lives in the services.
 */
final class AtlasLoopCortexMemoryIntegrationCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex:memory:integration
        {action : read|ground|propose|history}
        {--facts=}
        {--approve=}
        {--memory-id=}
        {--since=}
        {--until=}
        {--json}';

    /** @var string */
    protected $description = 'Cortex memory integration CLI: read | ground | propose | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'read' => $this->read(),
            'ground' => $this->ground(),
            'propose' => $this->propose(),
            'history' => $this->history(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function read(): int
    {
        try {
            $reader = $this->getLaravel()->make(AtlasCortexMemoryReader::class);
            $entries = $reader->read();
        } catch (Throwable $e) {
            return $this->emit(['action' => 'read', 'entries' => [], 'error' => $e->getMessage()]);
        }

        return $this->emit(['action' => 'read', 'entries' => $entries]);
    }

    private function ground(): int
    {
        $facts = $this->readFacts();
        $entries = (array) ($facts['memory_entries'] ?? []);
        $inventory = (array) ($facts['inventory'] ?? []);
        $grounder = $this->getLaravel()->make(AtlasCortexMemoryFactGrounder::class);

        return $this->emit(['action' => 'ground', 'verdict' => $grounder->ground($entries, $inventory)]);
    }

    private function propose(): int
    {
        $facts = $this->readFacts();
        $proposal = is_array($facts['proposal'] ?? null) ? $facts['proposal'] : [];
        $token = $this->option('approve') !== null && (string) $this->option('approve') !== ''
            ? (string) $this->option('approve')
            : null;
        $gate = $this->getLaravel()->make(AtlasCortexMemoryWriteProposalGate::class);
        $verdict = $gate->evaluate($proposal, $token);

        return $this->emit(['action' => 'propose', 'verdict' => $verdict]);
    }

    private function history(): int
    {
        try {
            $ledger = $this->getLaravel()->make(AtlasCortexMemoryReceiptLedger::class);
            $memoryId = (string) ($this->option('memory-id') ?? '');
            if ($memoryId !== '') {
                return $this->emit(['action' => 'history', 'rows' => $ledger->queryByMemoryEntryId($memoryId)]);
            }
            $since = (string) ($this->option('since') ?? '');
            $until = (string) ($this->option('until') ?? '');
            if ($since !== '' && $until !== '') {
                return $this->emit(['action' => 'history', 'rows' => $ledger->queryByTimeRange($since, $until)]);
            }

            return $this->emit(['action' => 'history', 'rows' => $ledger->all()]);
        } catch (Throwable $e) {
            return $this->emit(['action' => 'history', 'rows' => [], 'error' => $e->getMessage()]);
        }
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
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
