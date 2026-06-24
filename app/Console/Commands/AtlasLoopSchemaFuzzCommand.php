<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzPayloadGenerator;
use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzReporter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AtlasLoopSchemaFuzzCommand extends Command
{
    protected $signature = 'atlas:loop:schema:fuzz
        {action : run|report|history}
        {--seed=}
        {--schema=*}
        {--run-id=}
        {--json}';

    protected $description = 'Run, inspect, and list deterministic Loop schema-fuzz facts without mutating Loop state.';

    public function handle(
        AtlasLoopSchemaFuzzPayloadGenerator $generator,
        AtlasLoopSchemaFuzzReporter $reporter,
        AtlasLoopSchemaFuzzReceiptLedger $ledger,
    ): int {
        try {
            return match ((string) $this->argument('action')) {
                'run' => $this->runAction($generator, $reporter, $ledger),
                'report' => $this->reportAction($ledger),
                'history' => $this->historyAction($ledger),
                default => $this->unknownAction(),
            };
        } catch (\Throwable $e) {
            $this->emit([
                'schema' => 'atlas.loop.schema_fuzz.error.v1',
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    private function runAction(
        AtlasLoopSchemaFuzzPayloadGenerator $generator,
        AtlasLoopSchemaFuzzReporter $reporter,
        AtlasLoopSchemaFuzzReceiptLedger $ledger,
    ): int {
        $seed = $this->seed();
        $selectedSchemas = $this->selectedSchemas($generator);
        $startedAt = $this->utcNow();

        $payloads = [];
        foreach ($selectedSchemas as $schemaName) {
            array_push($payloads, ...$generator->variants($seed, $schemaName));
        }

        $validators = $this->selectedValidators($selectedSchemas);
        $rows = $reporter->report($payloads, $validators);

        $schemaVersions = array_values(array_map(
            static fn (array $validator): string => (string) $validator['version'],
            $validators,
        ));

        $receipt = $ledger->append([
            'run_id' => 'schema_fuzz_'.Str::lower((string) Str::ulid()),
            'seed' => (string) $seed,
            'generator_version' => 'atlas.loop.schema_fuzz_payload_generator.v1',
            'reporter_version' => 'atlas.loop.schema_fuzz_reporter.v1',
            'schema_versions' => $schemaVersions,
            'started_at_utc' => $startedAt,
            'finished_at_utc' => $this->utcNow(),
        ], $rows);

        $payload = [
            'schema' => 'atlas.loop.schema_fuzz.run.v1',
            'receipt' => $receipt,
            'rows' => $rows,
        ];

        $this->emit($payload);

        return self::SUCCESS;
    }

    private function reportAction(AtlasLoopSchemaFuzzReceiptLedger $ledger): int
    {
        $receipt = $this->targetReceipt($ledger);
        if ($receipt === null) {
            $this->emit([
                'schema' => 'atlas.loop.schema_fuzz.report.v1',
                'rows' => [],
                'run_id' => null,
            ]);

            return self::SUCCESS;
        }

        $rows = $this->loadRows((string) $receipt['raw_rows_path']);
        $grouped = $this->groupRows($rows);

        $payload = [
            'schema' => 'atlas.loop.schema_fuzz.report.v1',
            'run_id' => $receipt['run_id'],
            'rows' => $grouped,
        ];

        $this->emit($payload);

        return self::SUCCESS;
    }

    private function historyAction(AtlasLoopSchemaFuzzReceiptLedger $ledger): int
    {
        $history = $this->historyReceipts($ledger);

        $payload = [
            'schema' => 'atlas.loop.schema_fuzz.history.v1',
            'receipts' => array_values(array_map(static fn (array $receipt): array => [
                'run_id' => $receipt['run_id'],
                'seed' => $receipt['seed'],
                'payload_count' => $receipt['payload_count'],
                'schema_versions' => $receipt['schema_versions'],
            ], $history)),
        ];

        $this->emit($payload);

        return self::SUCCESS;
    }

    private function unknownAction(): int
    {
        $this->emit([
            'schema' => 'atlas.loop.schema_fuzz.error.v1',
            'error' => 'unknown_action',
        ]);

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function selectedSchemas(AtlasLoopSchemaFuzzPayloadGenerator $generator): array
    {
        $all = $generator->schemaNames();
        $selected = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $this->option('schema')),
            static fn (string $value): bool => $value !== '',
        ));

        if ($selected === []) {
            return $all;
        }

        foreach ($selected as $schemaName) {
            if (! in_array($schemaName, $all, true)) {
                throw new InvalidArgumentException(sprintf('Unknown schema [%s].', $schemaName));
            }
        }

        sort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * @param  list<string>  $schemaNames
     * @return array<string,array{engine_id:string,version:string,validate:callable(array<string,mixed>): array{outcome:string,reject_reason_code:?string}}>
     */
    private function selectedValidators(array $schemaNames): array
    {
        $reporter = new AtlasLoopSchemaFuzzReporter;
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('defaultValidators');
        $method->setAccessible(true);
        /** @var array<string,array{engine_id:string,version:string,validate:callable(array<string,mixed>): array{outcome:string,reject_reason_code:?string}}> $validators */
        $validators = $method->invoke($reporter);

        return array_intersect_key($validators, array_flip($schemaNames));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function targetReceipt(AtlasLoopSchemaFuzzReceiptLedger $ledger): ?array
    {
        $runId = trim((string) ($this->option('run-id') ?? ''));
        if ($runId !== '') {
            return $ledger->queryByRunId($runId);
        }

        $history = $this->historyReceipts($ledger);

        return $history === [] ? null : $history[array_key_last($history)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function historyReceipts(AtlasLoopSchemaFuzzReceiptLedger $ledger): array
    {
        $basePath = $this->ledgerBasePath($ledger);
        $path = $basePath.'/receipts.jsonl';
        if (! is_file($path)) {
            return [];
        }

        $receipts = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $receipts[] = $decoded;
            }
        }

        return $receipts;
    }

    /**
     * @return list<array{schema_name:string,groups:list<array{reject_reason_code:?string,rows:list<array<string,mixed>>}>}>
     */
    private function groupRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $schemaName = trim((string) ($row['schema_name'] ?? ''));
            $rejectReasonCode = (string) ($row['reject_reason_code'] ?? '');
            if ($schemaName === '') {
                continue;
            }

            $grouped[$schemaName] ??= [];
            $grouped[$schemaName][$rejectReasonCode] ??= [];
            $grouped[$schemaName][$rejectReasonCode][] = $row;
        }

        ksort($grouped, SORT_STRING);
        $normalized = [];
        foreach ($grouped as $schemaName => $groups) {
            ksort($groups, SORT_STRING);
            $normalizedGroups = [];
            foreach ($groups as $rejectReasonCode => $groupRows) {
                $normalizedGroups[] = [
                    'reject_reason_code' => $rejectReasonCode !== '' ? $rejectReasonCode : null,
                    'rows' => $groupRows,
                ];
            }
            $normalized[] = [
                'schema_name' => $schemaName,
                'groups' => $normalizedGroups,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    private function seed(): int
    {
        $raw = trim((string) ($this->option('seed') ?? ''));
        if ($raw !== '') {
            if (preg_match('/^-?\d+$/', $raw) === 1) {
                return (int) $raw;
            }

            return abs(crc32($raw));
        }

        return abs(crc32((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d')));
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function ledgerBasePath(AtlasLoopSchemaFuzzReceiptLedger $ledger): string
    {
        $reflection = new \ReflectionClass($ledger);
        $method = $reflection->getMethod('basePath');
        $method->setAccessible(true);

        return (string) $method->invoke($ledger);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $schema = (string) ($payload['schema'] ?? 'atlas.loop.schema_fuzz.v1');
        if (array_key_exists('error', $payload)) {
            $this->error($schema.': '.(string) $payload['error']);

            return;
        }

        if (isset($payload['receipt']) && is_array($payload['receipt'])) {
            $this->info(sprintf(
                '%s run_id=%s seed=%s payloads=%d root=%s',
                $schema,
                (string) $payload['receipt']['run_id'],
                (string) $payload['receipt']['seed'],
                (int) $payload['receipt']['payload_count'],
                (string) $payload['receipt']['per_row_digest_root'],
            ));

            return;
        }

        if (isset($payload['receipts']) && is_array($payload['receipts'])) {
            foreach ($payload['receipts'] as $receipt) {
                $this->line(sprintf(
                    '%s %s %d %s',
                    (string) ($receipt['run_id'] ?? ''),
                    (string) ($receipt['seed'] ?? ''),
                    (int) ($receipt['payload_count'] ?? 0),
                    implode(',', array_values((array) ($receipt['schema_versions'] ?? []))),
                ));
            }

            return;
        }

        if (isset($payload['rows']) && is_array($payload['rows'])) {
            foreach ($payload['rows'] as $schemaGroup) {
                $this->line((string) ($schemaGroup['schema_name'] ?? ''));
                foreach ((array) ($schemaGroup['groups'] ?? []) as $group) {
                    $label = (string) ($group['reject_reason_code'] ?? '');
                    $this->line('  '.($label !== '' ? $label : 'accepted'));
                    foreach ((array) ($group['rows'] ?? []) as $row) {
                        $this->line('    '.(string) ($row['payload_id'] ?? ''));
                    }
                }
            }
        }
    }
}
