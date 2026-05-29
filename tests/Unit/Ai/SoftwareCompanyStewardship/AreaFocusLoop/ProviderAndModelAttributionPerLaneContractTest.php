<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderAndModelAttributionPerLaneContract;
use Tests\TestCase;

final class ProviderAndModelAttributionPerLaneContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderAndModelAttributionPerLaneContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ProviderAndModelAttributionPerLaneContract::class));
    }

    public function test_default_shape_exposes_all_lane_roles_with_null_attribution(): void
    {
        $shape = ProviderAndModelAttributionPerLaneContract::defaults()->toArray();

        $this->assertSame(ProviderAndModelAttributionPerLaneContract::SCHEMA, $shape['schema_version']);
        $this->assertSame(ProviderAndModelAttributionPerLaneContract::LANE_ROLES, array_keys($shape['lanes']));

        foreach (ProviderAndModelAttributionPerLaneContract::LANE_ROLES as $role) {
            $this->assertNull($shape['lanes'][$role]['provider']);
            $this->assertNull($shape['lanes'][$role]['model']);
        }
    }

    public function test_from_array_preserves_provider_and_model_per_lane(): void
    {
        $shape = ProviderAndModelAttributionPerLaneContract::fromArray([
            'lanes' => [
                'context_scout' => ['provider' => 'gemini_cli', 'model' => 'fast'],
                'implementer' => ['provider' => 'cursor_cli', 'model' => 'composer-2.5-fast'],
            ],
        ])->toArray();

        $this->assertSame('gemini_cli', $shape['lanes']['context_scout']['provider']);
        $this->assertSame('fast', $shape['lanes']['context_scout']['model']);
        $this->assertSame('cursor_cli', $shape['lanes']['implementer']['provider']);
        $this->assertSame('composer-2.5-fast', $shape['lanes']['implementer']['model']);
        $this->assertNull($shape['lanes']['architect']['provider']);
        $this->assertNull($shape['lanes']['judge']['model']);
    }
}
