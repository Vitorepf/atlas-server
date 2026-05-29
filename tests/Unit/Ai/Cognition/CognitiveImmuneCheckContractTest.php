<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
use ReflectionClass;
use Tests\TestCase;

final class CognitiveImmuneCheckContractTest extends TestCase
{
    public function test_contract_lives_in_dedicated_psr4_artifact_file(): void
    {
        $path = app_path('Services/Ai/Cognition/CognitiveImmuneCheckContract.php');

        $this->assertFileExists($path);
        $this->assertSame(
            'CognitiveImmuneCheckContract',
            (new ReflectionClass(CognitiveImmuneCheckContract::class))->getShortName(),
        );
        $this->assertSame($path, (new ReflectionClass(CognitiveImmuneCheckContract::class))->getFileName());
        $this->assertNotSame(
            (new ReflectionClass(AtlasCognitionScoreCardService::class))->getFileName(),
            (new ReflectionClass(CognitiveImmuneCheckContract::class))->getFileName(),
        );
    }

    public function test_default_shape_matches_cognitive_immune_check_schema(): void
    {
        $shape = CognitiveImmuneCheckContract::defaults('e10_cognitive_immune_g0_g8_live')->toArray();

        $this->assertSame(CognitiveImmuneCheckContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('e10_cognitive_immune_g0_g8_live', $shape['finding_id']);
        $this->assertSame('autonomous_engineering', $shape['decision_surface']);
        $this->assertSame([], $shape['inputs']['target_paths']);
        $this->assertSame(
            array_fill_keys(CognitiveImmuneCheckContract::GATE_IDS, CognitiveImmuneCheckContract::DEFAULT_GATE_STATUS),
            $shape['inputs']['gate_statuses'],
        );
        $this->assertSame(CognitiveImmuneCheckContract::CHECK_CATEGORIES, $shape['inputs']['check_categories']);
        $this->assertFalse($shape['outputs']['autonomous_execution_allowed']);
        $this->assertSame([], $shape['outputs']['blockers']);
        $this->assertSame(CognitiveImmuneCheckContract::GATE_IDS, $shape['outputs']['pending_gates']);
    }

    public function test_from_array_preserves_gate_statuses_and_blockers(): void
    {
        $shape = CognitiveImmuneCheckContract::fromArray([
            'finding_id' => 'afdf_test_cross',
            'decision_surface' => 'area_focus_autonomy',
            'target_paths' => ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'],
            'gate_statuses' => [
                'G4' => 'block',
                'G5' => 'pass',
                'G9' => 'pass',
            ],
            'blockers' => ['scope_creep_detected'],
            'autonomous_execution_allowed' => false,
        ])->toArray();

        $this->assertSame('afdf_test_cross', $shape['finding_id']);
        $this->assertSame('area_focus_autonomy', $shape['decision_surface']);
        $this->assertSame(
            ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'],
            $shape['inputs']['target_paths'],
        );
        $this->assertSame('block', $shape['inputs']['gate_statuses']['G4']);
        $this->assertSame('pass', $shape['inputs']['gate_statuses']['G5']);
        $this->assertSame('pending', $shape['inputs']['gate_statuses']['G0']);
        $this->assertArrayNotHasKey('G9', $shape['inputs']['gate_statuses']);
        $this->assertSame(['scope_creep_detected'], $shape['outputs']['blockers']);
        $this->assertFalse($shape['outputs']['autonomous_execution_allowed']);
    }
}
