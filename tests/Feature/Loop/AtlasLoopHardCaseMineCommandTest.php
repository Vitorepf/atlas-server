<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the hard-case historical-failure miner is live at the operator surface: injected failure events become
 * deduped hard-case candidates, disallowed sources are dropped, and an empty source mines nothing.
 */
final class AtlasLoopHardCaseMineCommandTest extends TestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the dataset registry (used for dedupe) to an empty temp storage tree.
        $this->storage = sys_get_temp_dir().'/atlas-hardcase-mine-'.bin2hex(random_bytes(5));
        @mkdir($this->storage, 0o755, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function mine(array $records, int $sinceDays = 90): array
    {
        $exit = Artisan::call('atlas:loop:hardcase-mine', [
            '--records' => (string) json_encode($records),
            '--since-days' => $sinceDays,
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_recurring_failure_becomes_a_candidate(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->mine([
            ['source' => 'give_back', 'scope_root' => 'app/Services/Ai/Marketing', 'failure_reason' => 'judge timeout', 'diff_shape_hash' => 'h1', 'captured_at' => '2026-06-20T00:00:00Z', 'ledger_digest' => 'dig1'],
            ['source' => 'give_back', 'scope_root' => 'app/Services/Ai/Marketing', 'failure_reason' => 'judge timeout', 'diff_shape_hash' => 'h1', 'captured_at' => '2026-06-22T00:00:00Z', 'ledger_digest' => 'dig2'], // same signature ⇒ one candidate
            ['source' => 'not_a_real_source', 'scope_root' => 'app/X', 'failure_reason' => 'x', 'diff_shape_hash' => 'h9', 'captured_at' => '2026-06-23T00:00:00Z'], // dropped
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.hardcase_mine.v1', $d['schema']);
        $this->assertSame(1, $d['candidate_count'], (string) json_encode($d)); // deduped + disallowed dropped
        $this->assertSame('give_back', $d['candidates'][0]['source']);
        $this->assertSame('app/Services/Ai/Marketing', $d['candidates'][0]['scope_root']);
    }

    public function test_empty_source_mines_nothing(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->mine([]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['candidate_count']);
        $this->assertSame([], $d['candidates']);
    }

    public function test_invalid_records_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:hardcase-mine', ['--records' => '{"not":"a list"}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
