<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V2 auto-rollback decider is live at the operator surface (advisory): a critical blast radius with
 * failing tests recommends revert, while a safe radius with no signals recommends none. The audit journal is
 * pointed at a temp file so the test writes nothing real.
 */
final class AtlasLoopRollbackDecisionCommandTest extends TestCase
{
    private string $journal = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = sys_get_temp_dir().'/atlas-rollback-decision-'.bin2hex(random_bytes(5)).'.jsonl';
        config(['atlas.loop.v2.rollback_journal_path' => $this->journal]);
    }

    protected function tearDown(): void
    {
        @unlink($this->journal);
        parent::tearDown();
    }

    public function test_critical_blast_with_test_failures_recommends_revert(): void
    {
        $exit = Artisan::call('atlas:loop:rollback-decision', [
            '--blast-tier' => 'critical',
            '--tests-failing' => 1,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('revert', $decoded['action']);
        $this->assertSame('critical_blast_radius_with_test_failures', $decoded['reason']);
    }

    public function test_safe_blast_with_no_signals_recommends_none(): void
    {
        $exit = Artisan::call('atlas:loop:rollback-decision', [
            '--blast-tier' => 'safe',
            '--tests-failing' => 0,
            '--red-streak' => 0,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('none', $decoded['action']);
    }
}
