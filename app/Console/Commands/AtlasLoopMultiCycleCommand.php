<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleCoordinationProtocol;
use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleSubScopePartitioner;
use App\Services\Ai\AutonomousEvolution\MultiCycle\ClaimDeniedException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class AtlasLoopMultiCycleCommand extends Command
{
    protected $signature = 'atlas:loop:multi-cycle
        {action : partition|claim|release|history}
        {--cycle-id=}
        {--scope=*}
        {--cycle-count=2}
        {--salt=default}
        {--sub-scope-hash=}
        {--outcome=}
        {--json}';

    protected $description = 'Partition, claim, release, and audit multi-cycle Loop sub-scopes.';

    public function __construct(
        private readonly ?AtlasLoopMultiCycleSubScopePartitioner $partitioner = null,
        private readonly ?AtlasLoopMultiCycleCoordinationProtocol $protocol = null,
        private readonly ?AtlasLoopMultiCycleReceiptLedger $ledger = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'partition' => $this->handlePartition(),
                'claim' => $this->handleClaim(),
                'release' => $this->handleRelease(),
                'history' => $this->handleHistory(),
                default => $this->emit(['status' => 'invalid_action', 'action' => $this->argument('action')], self::FAILURE),
            };
        } catch (ClaimDeniedException $e) {
            $payload = $e->payload();
            $this->ledger()->record('claim_denied', (string) ($payload['cycle_id'] ?? $this->cycleId()), (string) ($payload['sub_scope_hash'] ?? $this->subScopeHash($this->subScopeInput())), $payload);

            return $this->emit(array_merge(['status' => 'claim_denied'], $payload), self::FAILURE);
        } catch (Throwable $e) {
            return $this->emit([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], self::FAILURE);
        }
    }

    private function handlePartition(): int
    {
        $cycleCount = (int) $this->option('cycle-count');
        $subScopes = $this->partitioner()->partition($this->scopeOption(), $cycleCount, (string) $this->option('salt'));

        foreach ($subScopes as $subScope) {
            $this->ledger()->record('partition_emitted', (string) $subScope['cycle_id'], $this->subScopeHash($subScope), $subScope);
        }

        return $this->emit($subScopes, self::SUCCESS);
    }

    private function handleClaim(): int
    {
        $claim = $this->protocol()->claim($this->cycleId(), $this->subScopeInput());
        $this->ledger()->record('claim_granted', $this->cycleId(), (string) $claim['sub_scope_hash'], $claim);

        return $this->emit($claim, self::SUCCESS);
    }

    private function handleRelease(): int
    {
        $outcome = trim((string) $this->option('outcome'));
        if ($outcome === '') {
            throw new InvalidArgumentException('The --outcome option is required for release.');
        }

        $release = $this->protocol()->release($this->cycleId(), $this->subScopeInput(), $outcome);
        $this->ledger()->record('release', $this->cycleId(), (string) $release['sub_scope_hash'], $release);
        $this->ledger()->record('outcome', $this->cycleId(), (string) $release['sub_scope_hash'], ['outcome' => $outcome]);

        return $this->emit($release, self::SUCCESS);
    }

    private function handleHistory(): int
    {
        $cycleId = trim((string) ($this->option('cycle-id') ?? ''));
        $rows = array_values(iterator_to_array($this->ledger()->history($cycleId !== '' ? $cycleId : null)));

        return $this->emit($rows, self::SUCCESS);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        if (array_is_list($payload)) {
            foreach ($payload as $row) {
                $this->line((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            return $exitCode;
        }

        foreach ($payload as $key => $value) {
            $this->line($key.': '.(is_scalar($value) || $value === null
                ? (string) ($value ?? 'null')
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        }

        return $exitCode;
    }

    /**
     * @return list<string>
     */
    private function scopeOption(): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), (array) $this->option('scope')),
            static fn (string $scope): bool => $scope !== '',
        ));
    }

    /**
     * @return array{files:list<string>}
     */
    private function subScopeInput(): array
    {
        $scope = $this->scopeOption();
        if ($scope !== []) {
            return ['files' => $scope];
        }

        $hash = trim((string) ($this->option('sub-scope-hash') ?? ''));
        if ($hash === '') {
            throw new InvalidArgumentException('Provide either --scope or --sub-scope-hash.');
        }

        return ['files' => ['hash:'.$hash]];
    }

    private function cycleId(): string
    {
        $cycleId = trim((string) ($this->option('cycle-id') ?? ''));
        if ($cycleId === '') {
            throw new InvalidArgumentException('The --cycle-id option is required.');
        }

        return $cycleId;
    }

    /**
     * @param  array<string,mixed>  $subScope
     */
    private function subScopeHash(array $subScope): string
    {
        $files = array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => ltrim(str_replace('\\', '/', trim((string) $path)), '/'),
            is_array($subScope['files'] ?? null) ? $subScope['files'] : [],
        ), static fn (string $path): bool => $path !== '')));
        sort($files, SORT_STRING);

        return sha1(json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function partitioner(): AtlasLoopMultiCycleSubScopePartitioner
    {
        return $this->partitioner ?? app(AtlasLoopMultiCycleSubScopePartitioner::class);
    }

    private function protocol(): AtlasLoopMultiCycleCoordinationProtocol
    {
        return $this->protocol ?? app(AtlasLoopMultiCycleCoordinationProtocol::class);
    }

    private function ledger(): AtlasLoopMultiCycleReceiptLedger
    {
        return $this->ledger ?? app(AtlasLoopMultiCycleReceiptLedger::class);
    }
}
