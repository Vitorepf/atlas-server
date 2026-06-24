<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopFrozenContractCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $tmpDir) {
            $this->removeDirectory($tmpDir);
        }

        parent::tearDown();
    }

    public function test_registry_and_drift_actions_emit_json_facts(): void
    {
        $this->bindTmpLedger();

        $registryExit = Artisan::call('atlas:loop:frozen', [
            'action' => 'registry',
            '--json' => true,
        ]);
        $registry = $this->jsonOutput();

        $this->assertSame(0, $registryExit, Artisan::output());
        $this->assertArrayHasKey('registered_count', $registry);
        $this->assertArrayHasKey('missing', $registry);
        $this->assertIsArray($registry['missing']);

        $driftExit = Artisan::call('atlas:loop:frozen', [
            'action' => 'drift',
            '--json' => true,
        ]);
        $drift = $this->jsonOutput();

        $this->assertSame(0, $driftExit, Artisan::output());
        $this->assertIsArray($drift);
        $this->assertArrayHasKey('fqcn', $drift[0]);
        $this->assertArrayHasKey('expected_sha', $drift[0]);
        $this->assertArrayHasKey('actual_sha', $drift[0]);
        $this->assertArrayHasKey('drift', $drift[0]);
    }

    public function test_audit_reads_diff_from_stdin_appends_one_ledger_line_and_prints_same_verdict(): void
    {
        $tmp = $this->makeTmpDir();
        $ledgerPath = $tmp.'/frozen-contract-receipts.jsonl';
        $stdin = json_encode([
            'changed_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
        ], JSON_THROW_ON_ERROR);
        $process = new Process(
            [PHP_BINARY, 'artisan', 'atlas:loop:frozen', 'audit', '--json'],
            base_path(),
            ['APP_ENV' => 'testing', 'ATLAS_LOOP_FROZEN_CONTRACT_RECEIPT_LEDGER_PATH' => $ledgerPath],
            $stdin,
        );

        $process->mustRun();
        $verdict = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $lines = $this->readJsonLines($ledgerPath);

        $this->assertCount(1, $lines);
        $this->assertSame('audit', $lines[0]['source']);
        $this->assertSame($verdict, $lines[0]['verdict_or_facts']);
        $this->assertSame('BLOCK', $verdict['verdict']);
        $this->assertSame(
            'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge',
            $verdict['offending_pairs'][0]['fqcn'],
        );
    }

    private function bindTmpLedger(): string
    {
        $tmp = $this->makeTmpDir();
        $ledgerPath = $tmp.'/frozen-contract-receipts.jsonl';
        $this->app->forgetInstance(AtlasLoopFrozenContractReceiptLedger::class);
        $this->app->singleton(
            AtlasLoopFrozenContractReceiptLedger::class,
            static fn () => new AtlasLoopFrozenContractReceiptLedger($ledgerPath),
        );

        return $ledgerPath;
    }

    /**
     * @return array<string,mixed>|list<mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    private function makeTmpDir(): string
    {
        $path = sys_get_temp_dir().'/atlas-frozen-cli-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);
        $this->tmpDirs[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
