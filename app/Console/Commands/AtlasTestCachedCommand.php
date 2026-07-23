<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use Illuminate\Console\Command;

/**
 * P3 (Obra #19, Frente P) — the receipt-cached test gate. Before spending a test
 * run on a WO's command, check the (test-file hash, impl-files hash) pair against a
 * GREEN-CURRENT receipt: a fresh green ⇒ SKIP (~0s); otherwise runAndRecord — which
 * is anti-fake-green by construction ({@see AtlasCapabilityTestExecutionService}: passed
 * only when tests_run>=1 AND exit 0). In a multi-slice obra 60-80% of commands
 * repeat unchanged, so the cache turns most re-runs into ~0s.
 *
 * ponytail: thin wrapper over the ready service (hasGreenReceipt + runAndRecord);
 * the freshness key is the SAME hash on both the check and the record, so a hit next
 * time is exact. No new storage — reuses the test_run_receipts table.
 */
class AtlasTestCachedCommand extends Command
{
    protected $signature = 'atlas:test:cached
        {capability : receipt scope (capability id)}
        {test : test ref (Class or Class::method)}
        {--impl=* : impl file paths hashed together as the freshness key}
        {--test-path= : explicit test file path (hashed as the test-file dimension + used for the run)}
        {--check-only : report the cache decision without running on a miss}
        {--json : machine-readable output}';

    protected $description = 'P3 · skip a test run when a fresh green receipt matches (hash test,impl); else run + record (Obra #19).';

    public function handle(AtlasCapabilityTestExecutionService $svc): int
    {
        $capability = trim((string) $this->argument('capability'));
        $test = trim((string) $this->argument('test'));
        $testPath = $this->option('test-path') ? (string) $this->option('test-path') : null;

        $testFileHash = ($testPath !== null && is_file($testPath))
            ? hash('sha256', (string) file_get_contents($testPath))
            : null;
        $implFilesHash = $this->hashFiles((array) $this->option('impl'));

        // Fresh green receipt ⇒ skip the run entirely.
        if ($svc->hasGreenReceipt($capability, $test, $testFileHash, $implFilesHash)) {
            return $this->out([
                'cached' => true, 'skipped' => true, 'ran' => false, 'passed' => true,
                'capability' => $capability, 'test' => $test,
            ], self::SUCCESS);
        }

        if ($this->option('check-only')) {
            return $this->out([
                'cached' => false, 'skipped' => false, 'ran' => false,
                'capability' => $capability, 'test' => $test,
            ], self::SUCCESS);
        }

        // Miss ⇒ run and record (anti-fake-green). Red run gates the caller.
        $res = $svc->runAndRecord($capability, $test, $testPath, $testFileHash, $implFilesHash);
        $res['cached'] = false;

        return $this->out($res, ($res['passed'] ?? false) === true ? self::SUCCESS : self::FAILURE);
    }

    /**
     * Deterministic freshness key over impl files: sorted `path:sha256(content)`,
     * hashed. Order of --impl args never changes the key.
     *
     * @param  list<string>  $files
     */
    private function hashFiles(array $files): ?string
    {
        $files = array_values(array_filter(
            array_map('strval', $files),
            static fn ($f) => $f !== '' && is_file($f),
        ));
        if ($files === []) {
            return null;
        }
        sort($files);
        $parts = [];
        foreach ($files as $f) {
            $parts[] = $f.':'.hash('sha256', (string) file_get_contents($f));
        }

        return hash('sha256', implode("\n", $parts));
    }

    /** @param  array<string,mixed>  $payload */
    private function out(array $payload, int $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $code;
        }

        if (($payload['cached'] ?? false) === true) {
            $this->info("cache HIT · {$payload['capability']} · {$payload['test']} (skipped — fresh green receipt)");
        } elseif (array_key_exists('passed', $payload)) {
            $this->line((($payload['passed'] ?? false) === true ? '<info>ran GREEN</info>' : '<error>ran RED</error>').' · '.(string) ($payload['test'] ?? ''));
        } else {
            $this->line('cache MISS · would run');
        }

        return $code;
    }
}
