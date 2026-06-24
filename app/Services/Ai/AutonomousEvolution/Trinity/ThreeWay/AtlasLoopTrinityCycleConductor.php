<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

use App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

final class AtlasLoopTrinityCycleConductor
{
    /** @var array<string,array<string,mixed>> */
    private array $receipts = [];

    /** @var array<string,list<array<string,mixed>>> */
    private array $fuelByCycle = [];

    private int $cycleNumber = 0;

    public function __construct(
        private readonly AtlasLoopScopeComprehensionModel $model,
        private readonly ?AtlasLoopComprehensionOriginator $originator = null,
    ) {
    }

    public function runOneCycle(): TrinityCycleResult
    {
        $cycleId = $this->nextCycleId();
        $inputFuel = $this->fuelForLatestCycle();
        $loopFacts = $this->loopSeed($inputFuel);
        $loopReceiptId = $this->recordReceipt($cycleId, 'loop', [
            'input_fuel' => $inputFuel,
            'facts' => $loopFacts,
        ]);

        if ($loopFacts === []) {
            $abortFact = [
                'fact_kind' => 'aborted_no_seed',
                'cycle_id' => $cycleId,
                'loop_receipt_id' => $loopReceiptId,
                'previous_fuel' => $inputFuel,
            ];
            $this->fuelByCycle[$cycleId] = [$abortFact];

            return new TrinityCycleResult(
                cycleId: $cycleId,
                loopReceiptId: $loopReceiptId,
                cortexReceiptId: '',
                maestroReceiptId: '',
                newFactCount: 1,
                fuelGenerated: true,
            );
        }

        $cortexFacts = $this->cortexEnrich($loopFacts);
        $cortexReceiptId = $this->recordReceipt($cycleId, 'cortex', [
            'loop_receipt_id' => $loopReceiptId,
            'facts' => $cortexFacts,
        ]);
        if ($cortexFacts === []) {
            return $this->abortAfterPrimitive($cycleId, $loopReceiptId, $cortexReceiptId, 'aborted_no_cortex_facts');
        }

        $maestroFacts = $this->maestroReshape($loopFacts, $cortexFacts);
        $maestroReceiptId = $this->recordReceipt($cycleId, 'maestro', [
            'loop_receipt_id' => $loopReceiptId,
            'cortex_receipt_id' => $cortexReceiptId,
            'facts' => $maestroFacts,
        ]);
        if ($maestroFacts === []) {
            return $this->abortAfterPrimitive($cycleId, $loopReceiptId, $cortexReceiptId, 'aborted_no_maestro_facts');
        }

        $fuel = array_values(array_merge($loopFacts, $cortexFacts, $maestroFacts));
        $this->fuelByCycle[$cycleId] = $fuel;

        return new TrinityCycleResult(
            cycleId: $cycleId,
            loopReceiptId: $loopReceiptId,
            cortexReceiptId: $cortexReceiptId,
            maestroReceiptId: $maestroReceiptId,
            newFactCount: count($fuel),
            fuelGenerated: $fuel !== [],
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getFuelForNextCycle(string $cycleId): array
    {
        return $this->fuelByCycle[$cycleId] ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function receiptFor(string $receiptId): array
    {
        return $this->receipts[$receiptId] ?? [];
    }

    private function nextCycleId(): string
    {
        $this->cycleNumber++;

        return 'trinity-cycle-'.str_pad((string) $this->cycleNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array<string,mixed>>  $inputFuel
     * @return list<array<string,mixed>>
     */
    private function loopSeed(array $inputFuel): array
    {
        $originated = ($this->originator ?? new AtlasLoopComprehensionOriginator)->originate(
            $this->model,
            array_values(array_filter(array_map(
                static fn (array $fact): string => (string) ($fact['fact_kind'] ?? ''),
                $inputFuel,
            ))),
        );

        if (($originated['originated'] ?? false) !== true) {
            return [];
        }

        return [[
            'fact_kind' => 'loop_seed',
            'objective' => (string) $originated['objective'],
            'cited_symbols' => array_values((array) $originated['cited_symbols']),
            'input_fuel' => $inputFuel,
        ]];
    }

    /**
     * @param  list<array<string,mixed>>  $loopFacts
     * @return list<array<string,mixed>>
     */
    private function cortexEnrich(array $loopFacts): array
    {
        $rows = [];
        foreach ((array) ($loopFacts[0]['input_fuel'] ?? []) as $fuelFact) {
            if (is_array($fuelFact) && str_starts_with((string) ($fuelFact['fact_kind'] ?? ''), 'aborted_')) {
                $rows[] = [
                    'fact_kind' => 'cortex_drift_context',
                    'consumed_fact' => $fuelFact,
                ];
            }
        }
        foreach ((array) ($loopFacts[0]['cited_symbols'] ?? []) as $symbol) {
            $resolved = $this->resolveSymbol((string) $symbol);
            if ($resolved === null) {
                continue;
            }

            $rows[] = [
                'fact_kind' => 'cortex_grounded_symbol',
                'symbol' => $symbol,
                'fqcn' => $resolved['fqcn'],
                'rel_path' => $resolved['rel_path'],
                'descriptor' => $this->model->descriptorFor($resolved['rel_path']),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $loopFacts
     * @param  list<array<string,mixed>>  $cortexFacts
     * @return list<array<string,mixed>>
     */
    private function maestroReshape(array $loopFacts, array $cortexFacts): array
    {
        $allowedFiles = array_values(array_unique(array_map(
            static fn (array $fact): string => (string) ($fact['rel_path'] ?? ''),
            array_values(array_filter(
                $cortexFacts,
                static fn (array $fact): bool => ($fact['fact_kind'] ?? null) === 'cortex_grounded_symbol',
            )),
        )));
        $allowedFiles = array_values(array_filter($allowedFiles, static fn (string $path): bool => $path !== ''));
        if ($allowedFiles === []) {
            return [];
        }

        return [[
            'fact_kind' => 'maestro_structured_task',
            'objective' => (string) ($loopFacts[0]['objective'] ?? ''),
            'allowed_files' => $allowedFiles,
            'source_fact_count' => count($loopFacts) + count($cortexFacts),
        ]];
    }

    /**
     * @return array{fqcn:string,rel_path:string}|null
     */
    private function resolveSymbol(string $symbol): ?array
    {
        $symbol = ltrim(trim($symbol), '\\');
        foreach ($this->model->inventory as $item) {
            $fqcn = ltrim((string) ($item['fqcn'] ?? ''), '\\');
            $relPath = ltrim((string) ($item['rel_path'] ?? ''), '/');
            $short = str_contains($fqcn, '\\') ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1) : $fqcn;
            if ($symbol === $fqcn || $symbol === $relPath || $symbol === $short) {
                return ['fqcn' => $fqcn, 'rel_path' => $relPath];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordReceipt(string $cycleId, string $primitive, array $payload): string
    {
        $receipt = [
            'cycle_id' => $cycleId,
            'primitive' => $primitive,
            'payload' => $payload,
        ];
        $receiptId = 'receipt-'.hash('sha256', json_encode($this->sortKeys($receipt), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->receipts[$receiptId] = $receipt + ['receipt_id' => $receiptId];

        return $receiptId;
    }

    private function abortAfterPrimitive(string $cycleId, string $loopReceiptId, string $cortexReceiptId, string $kind): TrinityCycleResult
    {
        $abortFact = [
            'fact_kind' => $kind,
            'cycle_id' => $cycleId,
            'loop_receipt_id' => $loopReceiptId,
            'cortex_receipt_id' => $cortexReceiptId,
        ];
        $this->fuelByCycle[$cycleId] = [$abortFact];

        return new TrinityCycleResult(
            cycleId: $cycleId,
            loopReceiptId: $loopReceiptId,
            cortexReceiptId: $cortexReceiptId,
            maestroReceiptId: '',
            newFactCount: 1,
            fuelGenerated: true,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fuelForLatestCycle(): array
    {
        if ($this->fuelByCycle === []) {
            return [];
        }

        $lastKey = array_key_last($this->fuelByCycle);

        return is_string($lastKey) ? $this->fuelByCycle[$lastKey] : [];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = array_is_list($child)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->sortKeys($item) : $item, $child)
                    : $this->sortKeys($child);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

final readonly class TrinityCycleResult
{
    public function __construct(
        public string $cycleId,
        public string $loopReceiptId,
        public string $cortexReceiptId,
        public string $maestroReceiptId,
        public int $newFactCount,
        public bool $fuelGenerated,
    ) {
    }
}
