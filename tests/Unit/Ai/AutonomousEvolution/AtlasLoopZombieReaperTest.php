<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopZombieReaper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopZombieReaperTest extends TestCase
{
    private const TABLE = 'atlas_loop_zombie_reaper_test_tasks';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('status')->nullable();
            $table->integer('claimed_by_pid')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    public function test_releases_only_dead_pid_claims_older_than_grace_and_spares_live_pid(): void
    {
        $this->insertClaim('dead-old', 222, '2026-06-24 16:00:00');
        $this->insertClaim('live-old', 111, '2026-06-24 16:00:00');
        $this->insertClaim('dead-fresh', 333, '2026-06-24 16:59:45');

        $result = (new AtlasLoopZombieReaper)->reap($this->topology([111]), [
            'tables' => [self::TABLE],
            'apply' => true,
            'grace_seconds' => 60,
            'now' => '2026-06-24T17:00:00+00:00',
            'release_status_by_table' => [self::TABLE => 'claimable'],
        ]);

        $this->assertSame('atlas.loop.zombie_reaper.v1', $result['schema_version']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(['dead-old'], array_column($result['reaped'], 'row_id'));
        $this->assertSame(['dead-fresh', 'live-old'], array_column($result['spared'], 'row_id'));
        $this->assertSame('claimable', DB::table(self::TABLE)->where('id', 'dead-old')->value('status'));
        $this->assertNull(DB::table(self::TABLE)->where('id', 'dead-old')->value('claimed_by_pid'));
        $this->assertSame('claimed', DB::table(self::TABLE)->where('id', 'live-old')->value('status'));
        $this->assertSame(111, DB::table(self::TABLE)->where('id', 'live-old')->value('claimed_by_pid'));
    }

    public function test_dry_run_default_records_intended_reaps_without_mutating_db(): void
    {
        $this->insertClaim('dead-old', 222, '2026-06-24 16:00:00');

        $result = (new AtlasLoopZombieReaper)->reap($this->topology([]), [
            'tables' => [self::TABLE],
            'grace_seconds' => 60,
            'now' => '2026-06-24T17:00:00+00:00',
            'release_status_by_table' => [self::TABLE => 'claimable'],
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(['dead-old'], array_column($result['reaped'], 'row_id'));
        $this->assertSame('claimed', DB::table(self::TABLE)->where('id', 'dead-old')->value('status'));
        $this->assertSame(222, DB::table(self::TABLE)->where('id', 'dead-old')->value('claimed_by_pid'));
    }

    public function test_output_receipts_are_schema_versioned_arrays_and_canonical_key_sorted(): void
    {
        $this->insertClaim('b-row', 222, '2026-06-24 16:00:00');
        $this->insertClaim('a-row', 333, '2026-06-24 16:00:00');

        $result = (new AtlasLoopZombieReaper)->reap($this->topology([]), [
            'tables' => [self::TABLE],
            'grace_seconds' => 60,
            'now' => '2026-06-24T17:00:00+00:00',
        ]);

        $this->assertSame('atlas.loop.zombie_reaper.v1', $result['schema_version']);
        $this->assertSame(['a-row', 'b-row'], array_column($result['reaped'], 'row_id'));
        $this->assertSame([], $result['spared']);
        $this->assertSame(
            ['checked_at', 'claim_age_seconds', 'claim_column', 'pid', 'reason', 'released', 'row_id', 'status_before', 'table'],
            array_keys($result['reaped'][0]),
        );
    }

    public function test_source_has_no_shell_network_or_provider_calls(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/Resilience/AtlasLoopZombieReaper.php'));

        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('pgrep', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Process', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('curl_', $source);
    }

    private function insertClaim(string $id, int $pid, string $claimedAt): void
    {
        DB::table(self::TABLE)->insert([
            'id' => $id,
            'status' => 'claimed',
            'claimed_by_pid' => $pid,
            'claimed_by' => 'worker-'.$pid,
            'claimed_at' => $claimedAt,
            'lease_expires_at' => '2026-06-24 18:00:00',
            'heartbeat_at' => $claimedAt,
            'created_at' => $claimedAt,
            'updated_at' => $claimedAt,
        ]);
    }

    /**
     * @param  list<int>  $pids
     * @return array<string,mixed>
     */
    private function topology(array $pids): array
    {
        return [
            'schema_version' => 'atlas.loop.process_topology.v1',
            'processes' => array_map(static fn (int $pid): array => ['pid' => $pid, 'role' => 'grind'], $pids),
        ];
    }
}
