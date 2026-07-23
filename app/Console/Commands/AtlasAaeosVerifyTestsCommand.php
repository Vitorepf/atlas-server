<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * B3 / criterion C2 — the OPT-IN, EXPENSIVE step that turns an existence-only test
 * match into a real GREEN-RUN RECEIPT. For each capability whose evidence_refs name a
 * test, this ACTUALLY RUNS that test (spawns a real PHPUnit process per ref) and writes
 * a receipt row (passed / tests_run / exit_code / commit_stamp / output_tail). Re-runs
 * are idempotent (update in place).
 *
 * This is NOT run on a maturity read — `atlas:aeos:maturity` stays cheap and READS the
 * receipts this command writes. Cost: one PHPUnit process per named test (seconds each),
 * so run it deliberately for the capability you are promoting to `verified`.
 *
 * @see app/Services/Ai/Aaeos/AtlasImplementationTruthService.php
 */
class AtlasAaeosVerifyTestsCommand extends Command
{
    protected $signature = 'atlas:aeos:verify-tests
        {--capability= : Restrict to a single doc id/slug or path substring (recommended — running ALL is expensive)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the named test(s) of capabilities for REAL and record GREEN-RUN RECEIPTS that gate the verified tier. [was atlas:aaeos:*; TRI-HYGIENE rename]';

    public function handle(
        AtlasImplementationTruthService $truth,
        AtlasCapabilityTestExecutionService $execution,
    ): int {
        $capability = $this->stringOption('capability');
        $capabilities = $truth->capabilityTestRefs($capability);

        $results = [];
        $ran = 0;
        $green = 0;
        $failed = 0;

        foreach ($capabilities as $cap) {
            $capabilityId = (string) $cap['capability_id'];
            $evidenceRefs = (array) ($cap['evidence_refs'] ?? []);
            foreach ($cap['test_refs'] as $testRef) {
                $ref = (string) $testRef['ref'];
                // B3 freshness — stamp the receipt with the CURRENT code+test content
                // hashes so the green run is bound to exactly what it proved; a later
                // edit makes the stored hash stale and the capability drops from verified.
                $hashes = $truth->freshnessHashes($evidenceRefs, $ref);
                $receipt = $execution->runAndRecord(
                    $capabilityId,
                    $ref,
                    null,
                    $hashes['test_file_hash'],
                    $hashes['impl_files_hash'],
                );
                $ran++;
                if (($receipt['passed'] ?? false) === true) {
                    $green++;
                } else {
                    $failed++;
                }
                $results[] = [
                    'capability_id' => $capabilityId,
                    'owner_doc' => (string) $cap['owner_doc'],
                    'test_ref' => $ref,
                    'index_resolved' => (bool) $testRef['index_resolved'],
                    'filter' => (string) ($receipt['filter'] ?? ''),
                    'passed' => (bool) ($receipt['passed'] ?? false),
                    'tests_run' => (int) ($receipt['tests_run'] ?? 0),
                    'exit_code' => $receipt['exit_code'] ?? null,
                    'commit_stamp' => $receipt['commit_stamp'] ?? null,
                    'ran' => (bool) ($receipt['ran'] ?? false),
                    'output_tail' => (string) ($receipt['output_tail'] ?? ''),
                ];
            }
        }

        $payload = [
            'schema_version' => 'atlas.aaeos.verify_tests.v1',
            'summary' => [
                'capabilities' => count($capabilities),
                'tests_run' => $ran,
                'green' => $green,
                'failed_or_unrun' => $failed,
                'filter' => $capability,
            ],
            'results' => $results,
            'generated_at' => now()->toJSON(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        if ($results === []) {
            $this->warn($capability !== null
                ? "No capability matching '{$capability}' declares a test evidence_ref to run."
                : 'No capabilities declare a test evidence_ref to run.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('capabilities', (string) count($capabilities));
        $this->components->twoColumnDetail('tests run (real PHPUnit)', (string) $ran);
        $this->components->twoColumnDetail('green', (string) $green);
        $this->components->twoColumnDetail('failed / unrun', (string) $failed);

        $this->table(
            ['capability', 'test ref', 'filter', 'green', 'tests', 'exit'],
            collect($results)->map(fn (array $row): array => [
                Str::limit($row['capability_id'], 34),
                Str::limit($row['test_ref'], 30),
                Str::limit($row['filter'], 24),
                $row['passed'] ? 'GREEN' : ($row['index_resolved'] ? 'NOT-GREEN' : 'NO-SYMBOL'),
                (string) $row['tests_run'],
                $row['exit_code'] === null ? '-' : (string) $row['exit_code'],
            ])->all(),
        );

        if ($green > 0) {
            $this->info("{$green} test(s) ran GREEN — those capabilities now have a green-run receipt and may reach the verified tier in atlas:aeos:maturity.");
        }
        if ($failed > 0) {
            $this->warn("{$failed} test(s) did NOT run green — those capabilities stay partial (existence-only is not enough). Fix the test or the claim.");
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
