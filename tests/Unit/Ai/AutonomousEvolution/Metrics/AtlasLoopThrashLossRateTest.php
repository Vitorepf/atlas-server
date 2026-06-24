<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Metrics;

use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopThrashLossRate;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopThrashLossRateTest extends TestCase
{
    private DateTimeImmutable $now;

    private string $projectionOutcomesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-06-24T12:00:00Z');
        $this->projectionOutcomesDir = storage_path('framework/testing/thrash-loss-rate-'.Str::uuid()->toString());
        mkdir($this->projectionOutcomesDir, 0777, true);

        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php'))->up();
        }
        if (! Schema::hasColumn('atlas_loop_origination_outcomes', 'payload')) {
            Schema::table('atlas_loop_origination_outcomes', function ($table): void {
                $table->json('payload')->nullable();
            });
        }

        DB::table('atlas_loop_origination_outcomes')->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectionOutcomesDir);

        parent::tearDown();
    }

    public function test_zero_observed_reports_zero_rate(): void
    {
        $out = $this->measure();

        $this->assertSame('atlas.loop.thrash_loss_rate.v1', $out['schema']);
        $this->assertSame(14, $out['window_days']);
        $this->assertSame(0, $out['obras_observed']);
        $this->assertSame(0, $out['obras_thrashed']);
        $this->assertSame(0.0, $out['rate']);
        $this->assertSame([], $out['top_dominant_reasons']);
    }

    public function test_three_parked_same_reason_inside_twelve_hours_counts_as_thrashed(): void
    {
        $this->writeProjectionOutcomes('c1', [
            $this->outcome('/App/Foo.php', 'parked', 'No Winner', '-12 hours'),
            $this->outcome('app/Foo.php', 'parked', ' no   winner ', '-6 hours'),
            $this->outcome('app/Foo.php', 'parked', 'NO WINNER', '-1 hour'),
        ]);

        $out = $this->measure();

        $this->assertSame(1, $out['obras_observed']);
        $this->assertSame(1, $out['obras_thrashed']);
        $this->assertSame(1.0, $out['rate']);
        $this->assertSame([['reason' => 'no winner', 'obras' => 1]], $out['top_dominant_reasons']);
    }

    public function test_three_parked_same_reason_spread_across_thirty_six_hours_does_not_count(): void
    {
        $this->writeProjectionOutcomes('c1', [
            $this->outcome('app/Foo.php', 'parked', 'same blocker', '-36 hours'),
            $this->outcome('app/Foo.php', 'parked', 'same blocker', '-18 hours'),
            $this->outcome('app/Foo.php', 'parked', 'same blocker', '-1 hour'),
        ]);

        $out = $this->measure();

        $this->assertSame(1, $out['obras_observed']);
        $this->assertSame(0, $out['obras_thrashed']);
        $this->assertSame(0.0, $out['rate']);
        $this->assertSame([], $out['top_dominant_reasons']);
    }

    public function test_five_parked_outcomes_with_different_reasons_do_not_count(): void
    {
        $this->writeProjectionOutcomes('c1', [
            $this->outcome('app/Foo.php', 'parked', 'reason a', '-5 hours'),
            $this->outcome('app/Foo.php', 'parked', 'reason b', '-4 hours'),
            $this->outcome('app/Foo.php', 'parked', 'reason c', '-3 hours'),
            $this->outcome('app/Foo.php', 'parked', 'reason d', '-2 hours'),
            $this->outcome('app/Foo.php', 'parked', 'reason e', '-1 hour'),
        ]);

        $out = $this->measure();

        $this->assertSame(1, $out['obras_observed']);
        $this->assertSame(0, $out['obras_thrashed']);
        $this->assertSame(0.0, $out['rate']);
    }

    public function test_three_parked_then_converged_still_counts_the_prior_thrash(): void
    {
        $this->writeProjectionOutcomes('c1', [
            $this->outcome('app/Foo.php', 'parked', 'blast radius', '-4 hours'),
            $this->outcome('app/Foo.php', 'parked', 'blast radius', '-3 hours'),
            $this->outcome('app/Foo.php', 'parked', 'blast radius', '-2 hours'),
            $this->outcome('app/Foo.php', 'converged', 'certified', '-1 hour'),
        ]);

        $out = $this->measure();

        $this->assertSame(1, $out['obras_observed']);
        $this->assertSame(1, $out['obras_thrashed']);
        $this->assertSame(1.0, $out['rate']);
        $this->assertSame([['reason' => 'blast radius', 'obras' => 1]], $out['top_dominant_reasons']);
    }

    public function test_origination_attempt_history_payload_is_also_counted(): void
    {
        DB::table('atlas_loop_origination_outcomes')->insert([
            'id' => Str::uuid()->toString(),
            'shape_token' => 'shape-'.bin2hex(random_bytes(4)),
            'accepted' => false,
            'proposal_id' => 'proposal-'.bin2hex(random_bytes(4)),
            'target_path' => 'app/DbBacked.php',
            'payload' => json_encode([
                'target' => 'app/DbBacked.php',
                'attempt_history' => [
                    $this->outcome('app/DbBacked.php', 'parked', 'same db reason', '-3 hours'),
                    $this->outcome('app/DbBacked.php', 'parked', 'same db reason', '-2 hours'),
                    $this->outcome('app/DbBacked.php', 'parked', 'same db reason', '-1 hour'),
                ],
                'rounds' => 99,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $this->now->modify('-1 day')->format('Y-m-d H:i:s'),
            'updated_at' => $this->now->modify('-1 day')->format('Y-m-d H:i:s'),
        ]);

        $out = $this->measure();

        $this->assertSame(1, $out['obras_observed']);
        $this->assertSame(1, $out['obras_thrashed']);
        $this->assertSame([['reason' => 'same db reason', 'obras' => 1]], $out['top_dominant_reasons']);
    }

    private function measure(): array
    {
        return (new AtlasLoopThrashLossRate($this->projectionOutcomesDir))->measure($this->now);
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     */
    private function writeProjectionOutcomes(string $campaign, array $outcomes): void
    {
        $path = $this->projectionOutcomesDir.'/'.$campaign.'.json';
        file_put_contents($path, (string) json_encode([
            'schema_version' => 'atlas.loop.projection_outcomes.v1',
            'outcomes' => $outcomes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{target:string,status:string,reason:string,ts:string}
     */
    private function outcome(string $target, string $status, string $reason, string $relativeTime): array
    {
        return [
            'target' => $target,
            'status' => $status,
            'reason' => $reason,
            'ts' => $this->now->modify($relativeTime)->format(DateTimeImmutable::ATOM),
        ];
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
