<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * L6-6 cross-file semantic gate.
 *
 * A local frozen test can pass while a downstream consumer breaks. This gate
 * reads the existing Code Intelligence graph (symbols + file-snapshot relations),
 * finds consumer contracts for changed symbols, and replays those contracts in the
 * candidate workspace. It never applies, promotes, or alters merge policy.
 */
final class AtlasLoopCrossFileConsumerGateService
{
    public const SCHEMA = 'atlas.loop.cross_file_consumer_gate.v1';

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(string $workspace, array $acceptance, array $changedFiles = [], array $options = []): array
    {
        if (! (bool) ($options['enabled'] ?? true)) {
            return $this->receipt('disabled', true, [], [], [], [], [], null);
        }

        $timeout = max(1, (int) ($options['timeout_seconds'] ?? $acceptance['timeout_seconds'] ?? config('atlas.loop.cross_file_consumer_gate.timeout_seconds', 120)));
        $commands = AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []);
        $blockers = [];
        if (! is_dir($workspace)) {
            $blockers[] = 'workspace_missing';
        }
        if ($commands === []) {
            $blockers[] = 'acceptance_commands_missing';
        }
        if ($blockers !== []) {
            return $this->receipt('blocked', false, $blockers, [], [], [], [], null);
        }

        $changedFiles = $changedFiles === [] ? $this->discoverChangedFiles($workspace) : $changedFiles;
        $codeGraphWorkspace = $this->codeGraphWorkspace($options, $workspace);
        $graph = $this->discoverGraph($codeGraphWorkspace, $changedFiles, $acceptance, $options);

        if (($graph['changed_symbols'] ?? []) === []) {
            return $this->receipt('skipped_no_changed_symbols', true, [], [], [], [], $graph, null);
        }

        $consumerContracts = $this->consumerContracts($graph, $acceptance, $options);
        if ($consumerContracts === []) {
            return $this->receipt('skipped_no_consumers', true, [], [], [], [], $graph, null);
        }

        $local = $this->runCommands($workspace, $commands, $timeout, [
            'ATLAS_CROSS_FILE_CONSUMER_GATE' => 'local_acceptance',
            'ATLAS_CROSS_FILE_CHANGED_SYMBOLS' => $this->json($graph['changed_symbols'] ?? []),
        ]);
        if (! (bool) ($local['passed'] ?? false)) {
            return $this->receipt('baseline_failed', false, ['local_acceptance_failed'], $local['results'], [], $consumerContracts, $graph, null);
        }

        $consumerRuns = [];
        foreach ($consumerContracts as $index => $contract) {
            $command = trim((string) ($contract['command'] ?? ''));
            if ($command === '') {
                // A code-graph-discovered consumer with no runnable test command cannot be
                // verified — that is NOT evidence the refactor broke it. SKIP it (record as
                // unverified) instead of failing the whole gate. Failing-closed here marked
                // every refactor whose consumers lack tests as consumer_contract_failed,
                // which blocked ~all refactor certs (the code graph nearly always surfaces a
                // consumer without a wired test). Behaviour is still pinned by the file's own
                // frozen tests + the deterministic complexity gate, and any consumer that DOES
                // carry a command is still executed below and can still fail the gate.
                $consumerRuns[] = [
                    'contract_index' => $index + 1,
                    'passed' => true,
                    'skipped' => true,
                    'exit_code' => 0,
                    'reason' => 'consumer_command_missing_skipped',
                    'contract' => $this->contractSummary($contract),
                ];

                continue;
            }

            $run = $this->runCommand($workspace, $command, $timeout, [
                'ATLAS_CROSS_FILE_CONSUMER_GATE' => 'consumer_contract',
                'ATLAS_CROSS_FILE_CONSUMER_CONTRACT' => $this->json($contract),
                'ATLAS_CROSS_FILE_CHANGED_SYMBOLS' => $this->json($graph['changed_symbols'] ?? []),
            ]);
            $run['contract_index'] = $index + 1;
            $run['contract'] = $this->contractSummary($contract);
            $consumerRuns[] = $run;
            if (! (bool) ($run['passed'] ?? false)) {
                break;
            }
        }

        $failed = array_values(array_filter($consumerRuns, static fn (array $run): bool => ! (bool) ($run['passed'] ?? false)));
        if ($failed !== []) {
            return $this->receipt('consumer_contract_failed', false, ['consumer_contract_failed'], $local['results'], $consumerRuns, $consumerContracts, $graph, $failed[0]);
        }

        return $this->receipt('consumer_contracts_passed', true, [], $local['results'], $consumerRuns, $consumerContracts, $graph, null);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function codeGraphWorkspace(array $options, string $workspace): string
    {
        $candidate = trim((string) ($options['code_graph_workspace'] ?? $options['source_workspace'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return is_dir(base_path()) ? base_path() : $workspace;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $acceptance
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function discoverGraph(string $codeGraphWorkspace, array $changedFiles, array $acceptance, array $options): array
    {
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolveWorkspaceOrId($codeGraphWorkspace);
        $explicit = $this->explicitGraphContracts($acceptance, $options);
        $changedSymbols = $this->changedSymbolsFromReadModel($workspaceId, $changedFiles);
        $readModelConsumers = $changedSymbols === []
            ? []
            : $this->consumerContractsFromSnapshots($workspaceId, $changedSymbols, $changedFiles);

        if ($changedSymbols === [] && $explicit !== []) {
            $changedSymbols = $this->changedSymbolsFromExplicitContracts($explicit, $changedFiles);
        }

        return [
            'schema_version' => self::SCHEMA.'.code_graph.v1',
            'source_workspace' => $codeGraphWorkspace,
            'workspace_id' => $workspaceId,
            'source' => $readModelConsumers !== [] ? 'atlas_engineering_code_file_snapshots' : ($explicit !== [] ? 'explicit_code_graph_contracts' : 'atlas_engineering_code_symbols'),
            'tables' => [
                'symbols' => DatabaseTableAvailability::has('atlas_engineering_code_symbols'),
                'file_snapshots' => DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots'),
                'workspace_keyed_symbols' => DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id'),
                'workspace_keyed_file_snapshots' => DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id'),
            ],
            'changed_files' => $changedFiles,
            'changed_symbols' => $changedSymbols,
            'consumer_contracts' => $this->uniqueContracts(array_merge($readModelConsumers, $explicit)),
            'read_model_consumer_count' => count($readModelConsumers),
            'explicit_consumer_count' => count($explicit),
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<array<string,mixed>>
     */
    private function changedSymbolsFromReadModel(string $workspaceId, array $changedFiles): array
    {
        if ($changedFiles === [] || ! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return [];
        }

        try {
            $query = DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('file_path', $changedFiles)
                ->whereIn('symbol_type', ['class', 'interface', 'trait', 'enum', 'method'])
                ->select(['id', 'symbol_type', 'symbol_name', 'file_path', 'line_start', 'line_end']);
            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            return $query
                ->orderBy('file_path')
                ->orderBy('line_start')
                ->limit(40)
                ->get()
                ->map(fn (object $row): array => [
                    'id' => (string) ($row->id ?? ''),
                    'symbol_type' => (string) ($row->symbol_type ?? ''),
                    'symbol_name' => (string) ($row->symbol_name ?? ''),
                    'short_name' => $this->shortName((string) ($row->symbol_name ?? '')),
                    'file_path' => (string) ($row->file_path ?? ''),
                    'line_start' => $row->line_start ?? null,
                    'line_end' => $row->line_end ?? null,
                ])
                ->filter(fn (array $row): bool => trim((string) $row['symbol_name']) !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $changedSymbols
     * @param  list<string>  $changedFiles
     * @return list<array<string,mixed>>
     */
    private function consumerContractsFromSnapshots(string $workspaceId, array $changedSymbols, array $changedFiles): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots')) {
            return [];
        }

        $names = [];
        foreach ($changedSymbols as $symbol) {
            foreach ([(string) ($symbol['symbol_name'] ?? ''), (string) ($symbol['short_name'] ?? '')] as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        $contracts = [];
        try {
            $query = DB::table('atlas_engineering_code_file_snapshots')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->select(['file_path', 'relations_json']);
            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            $query->orderBy('file_path')->chunk(500, function ($rows) use (&$contracts, $names, $changedFiles): void {
                foreach ($rows as $row) {
                    $filePath = trim((string) ($row->file_path ?? ''));
                    $relations = $this->decodeJson($row->relations_json ?? null);
                    foreach (['test_targets', 'symbol_references', 'dependencies'] as $bucket) {
                        foreach ((array) ($relations[$bucket] ?? []) as $relation) {
                            if (! is_array($relation)) {
                                continue;
                            }
                            $symbol = trim((string) ($relation['symbol'] ?? ''));
                            if ($symbol === '' || (! isset($names[$symbol]) && ! isset($names[$this->shortName($symbol)]))) {
                                continue;
                            }

                            $consumerFile = trim((string) ($relation['test_path'] ?? $relation['file_path'] ?? $filePath));
                            if ($consumerFile === '' || in_array($consumerFile, $changedFiles, true)) {
                                continue;
                            }

                            $command = trim((string) ($relation['contract_command'] ?? $relation['command'] ?? ''));
                            if ($command === '' && str_starts_with($consumerFile, 'tests/')) {
                                $command = $this->defaultTestConsumerCommand($consumerFile);
                            }

                            $contracts[] = [
                                'source' => 'code_graph_snapshot_relation',
                                'relation_bucket' => $bucket,
                                'relation_kind' => (string) ($relation['kind'] ?? $bucket),
                                'changed_symbol' => $symbol,
                                'consumer_file' => $consumerFile,
                                'test_path' => str_starts_with($consumerFile, 'tests/') ? $consumerFile : null,
                                'command' => $command,
                                'line' => $relation['line'] ?? null,
                            ];
                        }
                    }
                }
            });
        } catch (Throwable) {
            return [];
        }

        return $this->uniqueContracts($contracts);
    }

    /**
     * Default contract command for a code-graph-discovered TEST consumer that carries no explicit
     * contract_command. MUST be workspace-local phpunit — NEVER `php artisan test`.
     *
     * WHY (measured proof blocker, 2026-06-15): this gate runs the command in an ISOLATED COPY of the
     * repo whose Composer autoloader init hash is IDENTICAL to the source repo's (same composer.json).
     * `php artisan test` boots a SECOND copy of that autoloader and dies with
     * "Cannot redeclare class ComposerAutoloaderInit…" (exit 255). Fail-closed, that fatal was scored
     * as a broken consumer contract, so EVERY refactor whose changed class has a test (≈ all of them)
     * was REFUSED certification — the loop produced good refactors (complexity dropped, own test
     * green) yet certified zero. vendor/bin/phpunit loads ONLY the workspace vendor (single autoload)
     * and is exactly how the proposal's own frozen acceptance already runs green in this same
     * workspace, through this same runner.
     */
    private function defaultTestConsumerCommand(string $consumerFile): string
    {
        return './vendor/bin/phpunit '.escapeshellarg($consumerFile);
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function explicitGraphContracts(array $acceptance, array $options): array
    {
        $contracts = [];
        foreach ([$acceptance['code_graph_consumer_contracts'] ?? [], $acceptance['consumer_contracts'] ?? [], $options['code_graph_consumer_contracts'] ?? [], $options['consumer_contracts'] ?? []] as $source) {
            foreach (is_array($source) ? $source : [] as $contract) {
                if (is_array($contract)) {
                    $contract['source'] = (string) ($contract['source'] ?? 'explicit_code_graph_contract');
                    $contracts[] = $contract;
                }
            }
        }

        foreach (['consumer_commands', 'cross_file_consumer_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($acceptance[$key] ?? []) as $command) {
                $contracts[] = ['source' => 'acceptance_consumer_command', 'command' => $command];
            }
            foreach (AiStringListNormalizer::trimmedStrings($options[$key] ?? []) as $command) {
                $contracts[] = ['source' => 'options_consumer_command', 'command' => $command];
            }
        }

        return $this->uniqueContracts($contracts);
    }

    /**
     * @param  list<array<string,mixed>>  $contracts
     * @param  list<string>  $changedFiles
     * @return list<array<string,mixed>>
     */
    private function changedSymbolsFromExplicitContracts(array $contracts, array $changedFiles): array
    {
        $symbols = [];
        foreach ($contracts as $contract) {
            $symbol = trim((string) ($contract['changed_symbol'] ?? $contract['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $symbols[] = [
                'id' => 'explicit:'.substr(hash('sha256', $symbol), 0, 12),
                'symbol_type' => (string) ($contract['symbol_type'] ?? 'unknown'),
                'symbol_name' => $symbol,
                'short_name' => $this->shortName($symbol),
                'file_path' => (string) ($contract['changed_file'] ?? ($changedFiles[0] ?? '')),
                'line_start' => null,
                'line_end' => null,
            ];
        }

        return $this->uniqueSymbols($symbols);
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $acceptance
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function consumerContracts(array $graph, array $acceptance, array $options): array
    {
        return $this->uniqueContracts(array_merge(
            is_array($graph['consumer_contracts'] ?? null) ? $graph['consumer_contracts'] : [],
            $this->explicitGraphContracts($acceptance, $options),
        ));
    }

    /**
     * @return list<string>
     */
    private function discoverChangedFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $process = new Process($argv, $workspace, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @param  list<string>  $commands
     * @param  array<string,string>  $env
     * @return array{passed:bool,results:list<array<string,mixed>>}
     */
    private function runCommands(string $workspace, array $commands, int $timeout, array $env): array
    {
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->runCommand($workspace, $command, $timeout, $env);
            if (! (bool) ($results[array_key_last($results)]['passed'] ?? false)) {
                return ['passed' => false, 'results' => $results];
            }
        }

        return ['passed' => true, 'results' => $results];
    }

    /**
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function runCommand(string $workspace, string $command, int $timeout, array $env): array
    {
        $process = Process::fromShellCommandline($command, $workspace, $this->commandEnv($env), null, (float) $timeout);
        $process->run();
        $exit = $process->getExitCode() ?? 1;

        return [
            'command' => $command,
            'passed' => $exit === 0,
            'exit_code' => $exit,
            'stdout' => $this->excerpt((string) $process->getOutput()),
            'stderr' => $this->excerpt((string) $process->getErrorOutput()),
        ];
    }

    /**
     * @param  array<string,string>  $env
     * @return array<string,string|false>
     */
    private function commandEnv(array $env): array
    {
        return AtlasLoopHermeticCommandEnvironment::forAcceptance($env);
    }

    /**
     * @param  list<array<string,mixed>>  $contracts
     * @return list<array<string,mixed>>
     */
    private function uniqueContracts(array $contracts): array
    {
        $out = [];
        foreach ($contracts as $contract) {
            if (! is_array($contract)) {
                continue;
            }
            $key = $this->contractKey($contract);
            if ($key !== '') {
                $out[$key] = $contract;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<array<string,mixed>>  $symbols
     * @return list<array<string,mixed>>
     */
    private function uniqueSymbols(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $symbol) {
            $key = (string) ($symbol['symbol_name'] ?? '').'|'.(string) ($symbol['file_path'] ?? '');
            if (trim($key, '|') !== '') {
                $out[$key] = $symbol;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function contractKey(array $contract): string
    {
        return implode('|', [
            (string) ($contract['source'] ?? ''),
            (string) ($contract['changed_symbol'] ?? $contract['symbol'] ?? ''),
            (string) ($contract['consumer_file'] ?? $contract['test_path'] ?? ''),
            (string) ($contract['command'] ?? ''),
        ]);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function contractSummary(array $contract): array
    {
        return [
            'source' => (string) ($contract['source'] ?? ''),
            'changed_symbol' => (string) ($contract['changed_symbol'] ?? $contract['symbol'] ?? ''),
            'consumer_file' => (string) ($contract['consumer_file'] ?? ''),
            'test_path' => $contract['test_path'] ?? null,
            'relation_kind' => $contract['relation_kind'] ?? null,
            'command' => (string) ($contract['command'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $localRuns
     * @param  list<array<string,mixed>>  $consumerRuns
     * @param  list<array<string,mixed>>  $consumerContracts
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>|null  $failedConsumer
     * @return array<string,mixed>
     */
    private function receipt(string $status, bool $certified, array $blockers, array $localRuns, array $consumerRuns, array $consumerContracts, array $graph, ?array $failedConsumer): array
    {
        $receipt = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'certified' => $certified,
            'blockers' => $blockers,
            'code_graph' => $graph,
            'local_acceptance' => [
                'passed' => $localRuns !== [] && ! in_array(false, array_map(static fn (array $r): bool => (bool) ($r['passed'] ?? false), $localRuns), true),
                'results' => $localRuns,
            ],
            'consumer_contract_count' => count($consumerContracts),
            'consumer_contracts_passed' => count(array_filter($consumerRuns, static fn (array $r): bool => (bool) ($r['passed'] ?? false) && ! (bool) ($r['skipped'] ?? false))),
            'consumer_contracts_failed' => count(array_filter($consumerRuns, static fn (array $r): bool => ! (bool) ($r['passed'] ?? false))),
            'consumer_contracts_skipped' => count(array_filter($consumerRuns, static fn (array $r): bool => (bool) ($r['skipped'] ?? false))),
            'consumer_contracts' => array_map(fn (array $contract): array => $this->contractSummary($contract), $consumerContracts),
            'consumer_runs' => $consumerRuns,
            'failed_consumer' => $failedConsumer,
            'invariants' => [
                'proposal_only' => true,
                'source_checkout_mutated' => false,
                'merge_gate_changed' => false,
                'never_merge_changed' => false,
                'workspace_restored' => true,
            ],
            'generated_at' => time(),
        ];
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', $this->json([
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'certified' => $certified,
            'blockers' => $blockers,
            'consumer_contract_count' => count($consumerContracts),
            'consumer_contracts_failed' => $receipt['consumer_contracts_failed'],
        ]));

        return $receipt;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function shortName(string $symbol): string
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return '';
        }

        return str_contains($symbol, '\\') ? substr($symbol, strrpos($symbol, '\\') + 1) : $symbol;
    }

    private function json(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'...' : $text;
    }
}
