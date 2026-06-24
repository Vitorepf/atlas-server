<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractAuditor;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractDriftDetector;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractRegistry;
use Illuminate\Console\Command;
use Throwable;

final class AtlasLoopFrozenContractCommand extends Command
{
    protected $signature = 'atlas:loop:frozen {action : registry|audit|drift}
        {--json : Print machine-readable JSON}';

    protected $description = 'Frozen contract subsystem CLI: registry, audit and drift facts only.';

    public function handle(
        AtlasLoopFrozenContractRegistry $registry,
        AtlasLoopFrozenContractAuditor $auditor,
        AtlasLoopFrozenContractDriftDetector $driftDetector,
        AtlasLoopFrozenContractReceiptLedger $ledger,
    ): int {
        try {
            $payload = match ((string) $this->argument('action')) {
                'registry' => $this->registryFacts($registry),
                'audit' => $this->auditFacts($registry, $auditor, $ledger),
                'drift' => $this->driftFacts($registry, $driftDetector, $ledger),
                default => ['ok' => false, 'error' => 'invalid_action', 'action' => (string) $this->argument('action')],
            };
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'error' => 'frozen_contract_cli_failed', 'message' => $e->getMessage()];
        }

        $exitCode = (($payload['ok'] ?? true) === false) ? self::FAILURE : self::SUCCESS;
        $this->emit($payload, $exitCode);

        return $exitCode;
    }

    /**
     * @return array{schema_version:string,registered_count:int,missing:list<string>}
     */
    private function registryFacts(AtlasLoopFrozenContractRegistry $registry): array
    {
        $contracts = json_decode($registry->toJson(), true, flags: JSON_THROW_ON_ERROR);

        return [
            'schema_version' => 'atlas.ai.loop_frozen_contract_cli.registry.v1',
            'registered_count' => is_array($contracts) ? count($contracts) : 0,
            'missing' => $registry->missing(),
        ];
    }

    /**
     * @return array{
     *     schema_version:string,
     *     verdict:'ALLOW'|'BLOCK',
     *     offending_pairs:list<array{fqcn:string,frozen_test_path:string}>,
     *     checked_pairs:list<array{fqcn:string,frozen_test_path:string,covered_by:string}>
     * }
     */
    private function auditFacts(
        AtlasLoopFrozenContractRegistry $registry,
        AtlasLoopFrozenContractAuditor $auditor,
        AtlasLoopFrozenContractReceiptLedger $ledger,
    ): array {
        $diff = $this->stdinDiff();
        $verdict = $auditor->audit(
            $diff['changed_paths'],
            $registry,
            $diff['rerun_green_asserted_paths'],
        );
        $ledger->recordAudit($verdict);

        return $verdict;
    }

    /**
     * @return list<array{fqcn:string,test_path:string,expected_sha:string,actual_sha:string,drift:bool}>
     */
    private function driftFacts(
        AtlasLoopFrozenContractRegistry $registry,
        AtlasLoopFrozenContractDriftDetector $driftDetector,
        AtlasLoopFrozenContractReceiptLedger $ledger,
    ): array {
        $facts = $driftDetector->detect($registry);
        $ledger->recordDrift($facts);

        return $facts;
    }

    /**
     * @return array{changed_paths:list<string>,rerun_green_asserted_paths:list<string>}
     */
    private function stdinDiff(): array
    {
        $raw = stream_get_contents(STDIN);
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return ['changed_paths' => [], 'rerun_green_asserted_paths' => []];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                return ['changed_paths' => $this->stringList($decoded), 'rerun_green_asserted_paths' => []];
            }

            return [
                'changed_paths' => $this->stringList((array) ($decoded['changed_paths'] ?? $decoded['paths'] ?? [])),
                'rerun_green_asserted_paths' => $this->stringList((array) ($decoded['rerun_green_asserted_paths'] ?? [])),
            ];
        }

        return [
            'changed_paths' => $this->stringList(preg_split('/\R/', $raw) ?: []),
            'rerun_green_asserted_paths' => [],
        ];
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                $strings[] = $value;
            }
        }

        return array_values($strings);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        if ((bool) $this->option('json')) {
            $this->line($encoded);

            return;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error((string) ($payload['error'] ?? 'frozen contract command failed'));

            return;
        }

        $this->line($encoded);
    }
}
