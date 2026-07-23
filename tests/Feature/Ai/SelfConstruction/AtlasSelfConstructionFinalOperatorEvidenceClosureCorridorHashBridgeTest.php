<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\FinalOperatorClosureCorridorHashSupport;
use Tests\TestCase;

final class AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorHashBridgeTest extends TestCase
{
    public function test_corridor_executes_its_real_hash_bridge_through_the_support_owner(): void
    {
        $corridor = new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(
            app(AtlasSelfConstructionReadinessService::class),
        );
        $payload = [
            'phase' => 'characterization',
            'nested' => ['key' => 'value'],
        ];

        $hash = (function (array $input): string {
            return $this->stableHash($input);
        })->call($corridor, $payload);

        $this->assertSame((new FinalOperatorClosureCorridorHashSupport)->stableHash($payload), $hash);
    }
}
