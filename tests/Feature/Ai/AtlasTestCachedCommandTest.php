<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAaeosTestRunReceipt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3 (Obra #19) — `atlas:test:cached` skips a run when a fresh GREEN receipt
 * matches the (test-file, impl-files) hashes, and reports a miss when the impl
 * changed. The heavy runAndRecord path is the service's own tested behaviour;
 * here we prove the CACHE DECISION (the new logic) deterministically.
 */
final class AtlasTestCachedCommandTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php');
        $this->migration->down();
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        parent::tearDown();
    }

    public function test_cache_hit_skips_the_run_when_hashes_match(): void
    {
        [$impl, $testPath] = $this->fixtures();
        $implHash = $this->implHash([$impl]);
        $testHash = hash('sha256', (string) file_get_contents($testPath));

        AtlasAaeosTestRunReceipt::query()->create([
            'id' => (string) Str::uuid(),
            'capability_id' => 'cap-p3',
            'test_ref' => 'FooTest::test_bar',
            'filter' => 'FooTest::test_bar',
            'passed' => true,
            'tests_run' => 3,
            'exit_code' => 0,
            'test_file_hash' => $testHash,
            'impl_files_hash' => $implHash,
            'ran_at' => now(),
        ]);

        $code = Artisan::call('atlas:test:cached', [
            'capability' => 'cap-p3',
            'test' => 'FooTest::test_bar',
            '--impl' => [$impl],
            '--test-path' => $testPath,
            '--json' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"cached": true', $out);
        $this->assertStringContainsString('"skipped": true', $out);
        // Skipped ⇒ NO new receipt was recorded (the run never happened).
        $this->assertSame(1, AtlasAaeosTestRunReceipt::query()->count());
    }

    public function test_cache_miss_when_impl_changed(): void
    {
        [$impl, $testPath] = $this->fixtures();
        // Receipt carries a STALE impl hash (impl since edited).
        AtlasAaeosTestRunReceipt::query()->create([
            'id' => (string) Str::uuid(),
            'capability_id' => 'cap-p3',
            'test_ref' => 'FooTest::test_bar',
            'filter' => 'FooTest::test_bar',
            'passed' => true,
            'tests_run' => 3,
            'exit_code' => 0,
            'test_file_hash' => hash('sha256', (string) file_get_contents($testPath)),
            'impl_files_hash' => 'stale-hash-does-not-match',
            'ran_at' => now(),
        ]);

        $code = Artisan::call('atlas:test:cached', [
            'capability' => 'cap-p3',
            'test' => 'FooTest::test_bar',
            '--impl' => [$impl],
            '--test-path' => $testPath,
            '--check-only' => true,
            '--json' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"cached": false', $out);
    }

    /** @return array{0:string,1:string} [implPath, testPath] */
    private function fixtures(): array
    {
        $dir = sys_get_temp_dir().'/atlas-p3-'.bin2hex(random_bytes(5));
        @mkdir($dir, 0775, true);
        $impl = $dir.'/Impl.php';
        $test = $dir.'/FooTest.php';
        file_put_contents($impl, "<?php // impl\n");
        file_put_contents($test, "<?php // test\n");

        return [$impl, $test];
    }

    /** @param list<string> $files */
    private function implHash(array $files): string
    {
        sort($files);
        $parts = [];
        foreach ($files as $f) {
            $parts[] = $f.':'.hash('sha256', (string) file_get_contents($f));
        }

        return hash('sha256', implode("\n", $parts));
    }
}
