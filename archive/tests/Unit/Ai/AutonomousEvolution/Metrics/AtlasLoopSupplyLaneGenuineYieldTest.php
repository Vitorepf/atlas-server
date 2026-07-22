<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Metrics;

use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopSupplyLaneGenuineYield;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopSupplyLaneGenuineYieldTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '2026_06_16_000200_create_atlas_loop_delivery_contracts_table.php',
            '2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php',
        ] as $file) {
            (require base_path('database/migrations/'.$file))->up();
        }

        if (! Schema::hasColumn('atlas_loop_delivery_contracts', 'payload')) {
            Schema::table('atlas_loop_delivery_contracts', function ($table): void {
                $table->json('payload')->nullable();
            });
        }
        if (! Schema::hasColumn('atlas_loop_delivery_contracts', 'status')) {
            Schema::table('atlas_loop_delivery_contracts', function ($table): void {
                $table->string('status')->nullable();
            });
        }
        if (! Schema::hasColumn('atlas_loop_delivery_contracts', 'outcome')) {
            Schema::table('atlas_loop_delivery_contracts', function ($table): void {
                $table->string('outcome')->nullable();
            });
        }
        if (! Schema::hasColumn('atlas_loop_origination_outcomes', 'payload')) {
            Schema::table('atlas_loop_origination_outcomes', function ($table): void {
                $table->json('payload')->nullable();
            });
        }

        DB::table('atlas_loop_delivery_contracts')->delete();
        DB::table('atlas_loop_origination_outcomes')->delete();

        $this->now = new DateTimeImmutable('2026-06-24T12:00:00Z');
    }

    public function test_lane_with_no_origins_is_absent_from_lanes(): void
    {
        $out = $this->measure();

        $this->assertSame('atlas.loop.supply_lane_genuine_yield.v1', $out['schema']);
        $this->assertSame(30, $out['window_days']);
        $this->assertSame([], $out['lanes']);
        $this->assertSame(['originated' => 0, 'genuine_delivered' => 0, 'yield' => 0.0], $out['overall']);
    }

    public function test_three_orphan_wiring_origins_with_one_genuine_delivery_yields_point_333(): void
    {
        $this->insertOrigin('orphan_wiring', 'app/Foo/A.php');
        $this->insertOrigin('orphan_wiring', 'app/Foo/B.php');
        $this->insertOrigin('orphan_wiring', 'app/Foo/C.php');
        $this->insertDelivery('app/Foo/A.php', ['status' => 'certified', 'target' => 'app/Foo/A.php']);

        $lane = $this->lane('orphan_wiring');

        $this->assertSame(3, $lane['originated']);
        $this->assertSame(1, $lane['genuine_delivered']);
        $this->assertSame(0.333, $lane['yield']);
        $this->assertSame(['app/Foo/B.php', 'app/Foo/C.php'], $lane['sample_dead_targets']);
    }

    public function test_anti_refactor_behavior_preserved_delivery_does_not_count_as_genuine_yield(): void
    {
        $this->insertOrigin('dedup', 'app/Foo/Refactor.php');
        $this->insertDelivery('app/Foo/Refactor.php', [
            'status' => 'certified',
            'target' => 'app/Foo/Refactor.php',
            'behavior_preserved' => true,
        ]);

        $lane = $this->lane('dedup');

        $this->assertSame(1, $lane['originated']);
        $this->assertSame(0, $lane['genuine_delivered']);
        $this->assertSame(0.0, $lane['yield']);
        $this->assertSame(['app/Foo/Refactor.php'], $lane['sample_dead_targets']);
    }

    public function test_delivery_contract_outside_the_30_day_window_does_not_count(): void
    {
        $this->insertOrigin('doc_gap', 'app/Docs/Gap.php');
        $this->insertDelivery(
            'app/Docs/Gap.php',
            ['status' => 'certified', 'target' => 'app/Docs/Gap.php'],
            $this->now->modify('-60 days')
        );

        $lane = $this->lane('doc_gap');

        $this->assertSame(1, $lane['originated']);
        $this->assertSame(0, $lane['genuine_delivered']);
        $this->assertSame(0.0, $lane['yield']);
    }

    public function test_unknown_lane_captures_origins_without_lane_payload_keys(): void
    {
        $this->insertOrigin(null, 'app/Unknown/Target.php');

        $lane = $this->lane('unknown');

        $this->assertSame(1, $lane['originated']);
        $this->assertSame(0, $lane['genuine_delivered']);
        $this->assertSame(['app/Unknown/Target.php'], $lane['sample_dead_targets']);
    }

    private function measure(): array
    {
        return (new AtlasLoopSupplyLaneGenuineYield)->measure($this->now);
    }

    private function lane(string $lane): array
    {
        $lanes = array_column($this->measure()['lanes'], null, 'lane');

        $this->assertArrayHasKey($lane, $lanes);

        return $lanes[$lane];
    }

    private function insertOrigin(?string $lane, string $target, ?DateTimeImmutable $createdAt = null): void
    {
        $createdAt ??= $this->now->modify('-1 day');
        $payload = ['target' => $target];
        if ($lane !== null) {
            $payload['supply_lane'] = $lane;
        }

        DB::table('atlas_loop_origination_outcomes')->insert([
            'id' => Str::uuid()->toString(),
            'shape_token' => 'shape-'.bin2hex(random_bytes(4)),
            'accepted' => true,
            'proposal_id' => 'proposal-'.bin2hex(random_bytes(4)),
            'target_path' => $target,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertDelivery(string $target, array $payload, ?DateTimeImmutable $createdAt = null): void
    {
        $createdAt ??= $this->now->modify('-1 day');

        DB::table('atlas_loop_delivery_contracts')->insert([
            'id' => Str::uuid()->toString(),
            'candidate_hash' => substr(hash('sha1', $target.'|'.random_bytes(4)), 0, 40),
            'target_path' => $target,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status' => $payload['status'] ?? null,
            'outcome' => $payload['outcome'] ?? null,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }
}
