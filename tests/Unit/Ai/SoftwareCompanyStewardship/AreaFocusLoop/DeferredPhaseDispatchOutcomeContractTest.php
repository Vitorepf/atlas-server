<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeferredPhaseDispatchOutcomeContract;
use Tests\TestCase;

final class DeferredPhaseDispatchOutcomeContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DeferredPhaseDispatchOutcomeContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(DeferredPhaseDispatchOutcomeContract::class));
    }

    public function test_default_shape_exposes_deferred_dispatch_fields(): void
    {
        $shape = DeferredPhaseDispatchOutcomeContract::defaults()->toArray();

        $this->assertSame(DeferredPhaseDispatchOutcomeContract::SCHEMA, $shape['schema_version']);
        $this->assertSame(0, $shape['deferred_dispatch_count']);
        $this->assertNull($shape['next_claimed_envelope_hash']);
    }

    public function test_from_array_preserves_count_and_envelope_hash(): void
    {
        $shape = DeferredPhaseDispatchOutcomeContract::fromArray([
            'deferred_dispatch_count' => 3,
            'next_claimed_envelope_hash' => 'sha256:'.str_repeat('c', 64),
        ])->toArray();

        $this->assertSame(3, $shape['deferred_dispatch_count']);
        $this->assertSame('sha256:'.str_repeat('c', 64), $shape['next_claimed_envelope_hash']);
    }

    public function test_from_dispatch_snapshot_hashes_next_envelope(): void
    {
        $envelope = [
            'phase_out' => 'topology',
            'intent_id' => 'intent-abc',
            'outputs' => ['aawr_invocation' => 'deferred'],
        ];

        $shape = DeferredPhaseDispatchOutcomeContract::fromDispatchSnapshot(2, $envelope)->toArray();

        $this->assertSame(2, $shape['deferred_dispatch_count']);
        $this->assertSame(
            'sha256:'.MissionCanonicalHash::sha256($envelope),
            $shape['next_claimed_envelope_hash'],
        );
    }
}
