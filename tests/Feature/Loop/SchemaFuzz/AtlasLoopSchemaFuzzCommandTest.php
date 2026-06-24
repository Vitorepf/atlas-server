<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\SchemaFuzz;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopSchemaFuzzCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/atlas-loop-schema-fuzz-'.bin2hex(random_bytes(6));
        @mkdir($this->basePath, 0777, true);

        $this->app->instance(
            AtlasLoopSchemaFuzzReceiptLedger::class,
            new AtlasLoopSchemaFuzzReceiptLedger($this->basePath)
        );
        $this->app->instance(AiProviderManager::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new \RuntimeException('provider_manager_must_not_be_called');
            }
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteTree($this->basePath);
    }

    public function test_run_with_explicit_seed_is_end_to_end_deterministic(): void
    {
        $first = $this->runJson('atlas:loop:schema:fuzz', ['action' => 'run', '--seed' => '42', '--json' => true]);
        $second = $this->runJson('atlas:loop:schema:fuzz', ['action' => 'run', '--seed' => '42', '--json' => true]);

        $this->assertNotSame($first['receipt']['run_id'], $second['receipt']['run_id']);
        $this->assertSame($first['receipt']['per_row_digest_root'], $second['receipt']['per_row_digest_root']);
    }

    public function test_history_lists_receipts_in_append_order_and_report_preserves_reject_reason_code_without_forbidden_tokens(): void
    {
        $first = $this->runJson('atlas:loop:schema:fuzz', ['action' => 'run', '--seed' => '42', '--json' => true]);
        $second = $this->runJson('atlas:loop:schema:fuzz', ['action' => 'run', '--seed' => '77', '--json' => true]);

        $history = $this->runJson('atlas:loop:schema:fuzz', ['action' => 'history', '--json' => true]);
        $this->assertSame(
            [$first['receipt']['run_id'], $second['receipt']['run_id']],
            array_column($history['receipts'], 'run_id')
        );

        $exit = Artisan::call('atlas:loop:schema:fuzz', [
            'action' => 'report',
            '--run-id' => $first['receipt']['run_id'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $raw = Artisan::output();
        $report = json_decode($raw, true);

        $this->assertIsArray($report);
        $this->assertSame($first['receipt']['run_id'], $report['run_id']);
        $flattened = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($flattened);
        $this->assertStringContainsString('reject_reason_code', $flattened);
        $this->assertStringNotContainsString('pass', strtolower($raw));
        $this->assertStringNotContainsString('fail', strtolower($raw));
        $this->assertStringNotContainsString('score', strtolower($raw));
        $this->assertStringNotContainsString('grade', strtolower($raw));
    }

    public function test_command_writes_only_inside_schema_fuzz_storage_root_and_makes_no_provider_calls(): void
    {
        $outsideBefore = $this->snapshotLoopStorageOutsideBase();

        $exit = Artisan::call('atlas:loop:schema:fuzz', [
            'action' => 'run',
            '--seed' => '42',
            '--json' => true,
        ]);

        $outsideAfter = $this->snapshotLoopStorageOutsideBase();

        $this->assertSame(0, $exit);
        $this->assertSame($outsideBefore, $outsideAfter);
    }

    private function runJson(string $command, array $arguments): array
    {
        $exit = Artisan::call($command, $arguments);
        $this->assertSame(0, $exit);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function snapshotLoopStorageOutsideBase(): array
    {
        $root = storage_path('atlas/loop');
        if (! is_dir($root)) {
            return [];
        }

        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if (str_starts_with($path, $this->basePath)) {
                continue;
            }

            $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            $result[] = str_replace('\\', '/', $relative).':'.($item->isDir() ? 'dir' : 'file');
        }
        sort($result, SORT_STRING);

        return $result;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->deleteTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
