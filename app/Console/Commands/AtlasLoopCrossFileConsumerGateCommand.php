<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossFileConsumerGateService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AtlasLoopCrossFileConsumerGateCommand extends Command
{
    protected $signature = 'atlas:loop:cross-file-consumer-gate
        {--workspace= : Git workspace containing the approved candidate}
        {--acceptance= : Acceptance JSON object}
        {--acceptance-file= : Path to acceptance JSON object}
        {--changed-file=* : Changed file to inspect; omitted means discover from git}
        {--consumer-command=* : Explicit consumer contract command}
        {--consumer-contract=* : Explicit consumer contract JSON object}
        {--code-graph-workspace= : Workspace path/id to use for Code Intelligence lookup}
        {--fixture= : Built-in fixture to run: safe or consumer-break}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless the cross-file gate certifies}
        {--json : Print canonical JSON}';

    protected $description = 'L6-6 cross-file semantic gate: replay code-graph-discovered consumer contracts for changed symbols.';

    public function handle(AtlasLoopCrossFileConsumerGateService $gate): int
    {
        try {
            [$workspace, $acceptance, $changedFiles, $options, $cleanup] = $this->inputs();
            $receipt = $gate->evaluate($workspace, $acceptance, $changedFiles, array_merge($options, [
                'enabled' => true,
                'timeout_seconds' => (int) config('atlas.loop.cross_file_consumer_gate.timeout_seconds', 120),
            ]));
        } catch (JsonException | RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($cleanup)) {
                $cleanup();
            }
        }

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            $this->writeJson($receiptPath, $receipt);
            $receipt['receipt_path'] = $receiptPath;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json($receipt, pretty: true));
        } else {
            $this->components->twoColumnDetail('Cross-file consumer gate', (string) ($receipt['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certified', (bool) ($receipt['certified'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Consumer contracts', (string) ($receipt['consumer_contracts_passed'] ?? 0).'/'.(string) ($receipt['consumer_contract_count'] ?? 0).' passed');
        }

        return (bool) ($this->option('strict') ?? false) && ! (bool) ($receipt['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:list<string>,3:array<string,mixed>,4:callable():void}
     *
     * @throws JsonException
     */
    private function inputs(): array
    {
        $fixture = trim((string) ($this->option('fixture') ?: ''));
        if ($fixture !== '') {
            return $this->fixture($fixture);
        }

        $workspace = rtrim(trim((string) ($this->option('workspace') ?: '')), '/');
        if ($workspace === '' || ! is_dir($workspace)) {
            throw new RuntimeException('Missing or invalid --workspace.');
        }

        return [
            $workspace,
            $this->acceptance(),
            $this->stringOptionList('changed-file'),
            [
                'code_graph_workspace' => trim((string) ($this->option('code-graph-workspace') ?: '')),
                'consumer_commands' => $this->stringOptionList('consumer-command'),
                'consumer_contracts' => $this->consumerContractsFromOptions(),
            ],
            static function (): void {},
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    private function acceptance(): array
    {
        $json = trim((string) ($this->option('acceptance') ?: ''));
        $file = trim((string) ($this->option('acceptance-file') ?: ''));
        if ($json === '' && $file !== '' && is_file($file)) {
            $json = (string) file_get_contents($file);
        }
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string,mixed>>
     *
     * @throws JsonException
     */
    private function consumerContractsFromOptions(): array
    {
        $contracts = [];
        foreach ($this->stringOptionList('consumer-contract') as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $contracts[] = $decoded;
            }
        }

        return $contracts;
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:list<string>,3:array<string,mixed>,4:callable():void}
     */
    private function fixture(string $kind): array
    {
        if (! in_array($kind, ['safe', 'consumer-break'], true)) {
            throw new RuntimeException('--fixture must be "safe" or "consumer-break".');
        }

        $dir = sys_get_temp_dir().'/atlas-loop-cross-file-gate-'.bin2hex(random_bytes(5));
        @mkdir($dir.'/src', 0o755, true);
        @mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Producer.php', $this->producer('old'));
        file_put_contents($dir.'/src/Consumer.php', $this->consumer());
        file_put_contents($dir.'/tests/ProducerTest.php', $this->producerTest($kind === 'safe' ? 'old' : 'new'));
        file_put_contents($dir.'/tests/ConsumerContractTest.php', $this->consumerTest('old'));
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        if ($kind === 'consumer-break') {
            file_put_contents($dir.'/src/Producer.php', $this->producer('new'));
        }

        $seed = $this->seedFixtureCodeGraph($dir);
        $fallbackContracts = $seed['seeded']
            ? []
            : [[
                'source' => 'fixture_code_graph_contract',
                'changed_symbol' => 'Producer',
                'changed_file' => 'src/Producer.php',
                'consumer_file' => 'tests/ConsumerContractTest.php',
                'test_path' => 'tests/ConsumerContractTest.php',
                'relation_kind' => 'test_symbol_reference_fixture',
                'command' => 'php tests/ConsumerContractTest.php',
            ]];

        return [
            $dir,
            [
                'commands' => ['php tests/ProducerTest.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'gate',
                'timeout_seconds' => 30,
            ],
            ['src/Producer.php'],
            [
                'code_graph_workspace' => $seed['workspace_id'],
                'code_graph_consumer_contracts' => $fallbackContracts,
            ],
            function () use ($dir, $seed): void {
                ($seed['cleanup'])();
                (new Process(['rm', '-rf', $dir], null, null, null, 30.0))->run();
            },
        ];
    }

    /**
     * @return array{seeded:bool,workspace_id:string,cleanup:callable():void}
     */
    private function seedFixtureCodeGraph(string $workspace): array
    {
        $workspaceId = 'l6-6-fixture-'.substr(hash('sha256', $workspace), 0, 12);
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')
            || ! DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots')) {
            return ['seeded' => false, 'workspace_id' => $workspaceId, 'cleanup' => static function (): void {}];
        }

        $now = now();
        $hasSymbolWorkspace = DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id');
        $hasSnapshotWorkspace = DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id');
        $symbolId = (string) Str::uuid();
        $symbolRow = [
            'id' => $symbolId,
            'module_id' => null,
            'symbol_type' => 'class',
            'symbol_name' => 'Producer',
            'file_path' => 'src/Producer.php',
            'line_start' => 3,
            'line_end' => 9,
            'language' => 'php',
            'signature' => 'final class Producer',
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'status' => 'active',
            'docs_status' => 'fixture',
            'source_hash' => hash('sha256', $workspace.'|Producer'),
            'related_doc_ids_json' => $this->json([]),
            'metadata' => $this->json(['fixture' => 'l6-6-cross-file-consumer']),
            'indexed_at' => $now,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($hasSymbolWorkspace) {
            $symbolRow['workspace_id'] = $workspaceId;
        }

        $snapshotRow = [
            'id' => (string) Str::uuid(),
            'file_path' => 'tests/ConsumerContractTest.php',
            'module_slug' => 'tests',
            'language' => 'php',
            'source_hash' => hash('sha256', $workspace.'|consumer-contract'),
            'file_size' => 1,
            'symbols_json' => $this->json([]),
            'relations_json' => $this->json([
                'test_targets' => [[
                    'kind' => 'test_symbol_reference_fixture',
                    'symbol' => 'Producer',
                    'target_module' => 'src',
                    'test_path' => 'tests/ConsumerContractTest.php',
                    'line' => 4,
                    'contract_command' => 'php tests/ConsumerContractTest.php',
                ]],
            ]),
            'status' => 'active',
            'indexed_at' => $now,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($hasSnapshotWorkspace) {
            $snapshotRow['workspace_id'] = $workspaceId;
        }

        DB::table('atlas_engineering_code_symbols')->insert($symbolRow);
        DB::table('atlas_engineering_code_file_snapshots')->insert($snapshotRow);

        return [
            'seeded' => true,
            'workspace_id' => $workspaceId,
            'cleanup' => static function () use ($workspaceId, $hasSymbolWorkspace, $hasSnapshotWorkspace, $symbolRow, $snapshotRow): void {
                $symbolQuery = DB::table('atlas_engineering_code_symbols');
                $hasSymbolWorkspace
                    ? $symbolQuery->where('workspace_id', $workspaceId)->delete()
                    : $symbolQuery->where('id', $symbolRow['id'])->delete();

                $snapshotQuery = DB::table('atlas_engineering_code_file_snapshots');
                $hasSnapshotWorkspace
                    ? $snapshotQuery->where('workspace_id', $workspaceId)->delete()
                    : $snapshotQuery->where('id', $snapshotRow['id'])->delete();
            },
        ];
    }

    private function producer(string $value): string
    {
        return "<?php\n\nfinal class Producer\n{\n    public function value(): string\n    {\n        return '".$value."';\n    }\n}\n";
    }

    private function consumer(): string
    {
        return <<<'PHP'
<?php

require_once __DIR__.'/Producer.php';

final class Consumer
{
    public function label(): string
    {
        return (new Producer())->value();
    }
}
PHP;
    }

    private function producerTest(string $expected): string
    {
        return <<<PHP
<?php
require __DIR__.'/../src/Producer.php';
\$producer = new Producer();
exit(\$producer->value() === '{$expected}' ? 0 : 1);
PHP;
    }

    private function consumerTest(string $expected): string
    {
        return <<<PHP
<?php
require __DIR__.'/../src/Consumer.php';
\$consumer = new Consumer();
exit(\$consumer->label() === '{$expected}' ? 0 : 1);
PHP;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput() ?: $process->getOutput());
        }
    }

    /**
     * @return list<string>
     */
    private function stringOptionList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            (array) $this->option($key),
        ), static fn (string $value): bool => $value !== ''));
    }

    private function receiptPath(): string
    {
        $path = trim((string) ($this->option('receipt') ?: ''));
        if ($path !== '') {
            return $path;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.loop.cross_file_consumer_gate.receipt_path', storage_path('app/atlas/evidence/cross-file-consumer-gate.json'))
            : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create receipt directory '.$dir);
        }
        file_put_contents($path, $this->json($payload, pretty: true)."\n");
    }

    private function json(mixed $payload, bool $pretty = false): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR,
        );
    }
}
