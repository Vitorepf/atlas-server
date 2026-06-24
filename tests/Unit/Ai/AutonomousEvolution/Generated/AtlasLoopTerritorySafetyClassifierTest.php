<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Generated;

use App\Services\Ai\AutonomousEvolution\Generated\AtlasLoopTerritorySafetyClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasLoopTerritorySafetyClassifierTest extends TestCase
{
    public function test_describes_the_territory_safety_classifier_contract(): void
    {
        $payload = (new AtlasLoopTerritorySafetyClassifier)->describe();

        $this->assertSame(AtlasLoopTerritorySafetyClassifier::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('add_only_forbidden_expansion', $payload['promotion_behavior']);
        $this->assertSame('frozen_classifier_loop_uneditable', $payload['determinism']);
        $this->assertSame(3, $payload['signal_count']);
        $this->assertContains('behavioral_revert_flips_battery', $payload['signals']);
        $this->assertContains('frozen_marker_declared', $payload['signals']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['workspace_mutation_allowed']);
    }

    public function test_classify_marks_behavioral_battery_flip_as_safety_critical(): void
    {
        $payload = (new AtlasLoopTerritorySafetyClassifier)->classify(
            path: 'app/Services/Ai/AutonomousEvolution/Constitution/AtlasLoopMainHealthSentinel.php',
            revertFlipsBattery: true,
        );

        $this->assertSame('safety_critical', $payload['classification']);
        $this->assertSame('add_to_forbidden', $payload['forbidden_action']);
        $this->assertContains('behavioral_revert_flips_battery', $payload['matched_signals']);
        $this->assertContains('safety_keyword_path_match', $payload['matched_signals']);
        $this->assertTrue($payload['revert_flips_battery']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['workspace_mutation_allowed']);
    }

    public function test_validate_promotion_invariant_requires_safety_file_and_robustness_case(): void
    {
        $service = new AtlasLoopTerritorySafetyClassifier;

        $passing = $service->validatePromotionInvariant([
            $service->classify(
                path: 'app/Services/Ai/AutonomousEvolution/Frozen/AtlasLoopFrozenBattery.php',
                revertFlipsBattery: false,
                hasFrozenMarker: true,
            ),
        ], 1);

        $failing = $service->validatePromotionInvariant([], 0);

        $this->assertTrue($passing['passes']);
        $this->assertTrue($passing['safety_critical_file_present']);
        $this->assertTrue($passing['requires_robustness_case']);
        $this->assertFalse($failing['passes']);
        $this->assertFalse($failing['safety_critical_file_present']);
        $this->assertFalse($failing['requires_robustness_case']);
    }
}
