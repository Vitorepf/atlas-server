<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMeasureSeriesFreshnessReader;
use Tests\TestCase;

final class AcosMeasureSeriesFreshnessReaderTest extends TestCase
{
    public function test_jsonl_file_returns_latest_recorded_at(): void
    {
        $path = sys_get_temp_dir().'/acos-freshness-'.bin2hex(random_bytes(4)).'.jsonl';
        file_put_contents($path, implode("\n", [
            json_encode(['recorded_at' => '2026-07-01T00:00:00Z']),
            json_encode(['recorded_at' => '2026-07-10T12:00:00Z']),
            json_encode(['recorded_at' => '2026-07-05T00:00:00Z']),
        ])."\n");

        try {
            $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt([
                'source_type' => 'jsonl',
                'path' => $path,
                'timestamp_field' => 'recorded_at',
            ]);

            $this->assertNotNull($latest);
            $this->assertSame('2026-07-10T12:00:00+00:00', $latest->toIso8601String());
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_path_returns_null(): void
    {
        $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt([
            'source_type' => 'jsonl',
            'path' => '/tmp/does-not-exist-'.bin2hex(random_bytes(4)).'.jsonl',
        ]);

        $this->assertNull($latest);
    }

    public function test_m3c_acos_program_owners_must_resolve_from_cognition_acos_program(): void
    {
        foreach ([
            'AcosMaxLedgerRotationRegistry',
            'AcosMaxLote2MeasureService',
            'AcosMaxMeasureSeriesRegistry',
            'AcosMaxObraRetroService',
            'AcosMaxParallelExecutionProtocol',
            'AcosMaxProceduralSkillPromoterService',
            'AcosMaxVerifiedShareService',
            'AcosMaxWindowOrchestratorService',
            'AcosMeasureSeriesFreshnessReader',
            'AcosProgramCockpitService',
            'AmbitionRungPolicy',
            'AtlasFlywheelFunnelService',
            'AtlasLocalModelIntegrityService',
            'AtlasModelCapabilitySpecService',
            'AtlasNCaptureDrillService',
            'AtlasResourceBudgetService',
            'AttemptLifecycleLedger',
            'BeliefCascadeReverificationPlanner',
            'ComposedObraArcComposer',
            'ComposedObraArcLifecycle',
            'DogfoodingFrictionLeadMiner',
            'Esp09IndependentChallengerService',
            'EvidenceVisionThesisComposer',
            'EvidenceVisionThesisLifecycle',
            'ExecutionContextCooccurrenceService',
            'ExploratoryBetsPortfolio',
            'PortfolioBudgetAllocator',
            'PreReviewAdvisoryBand',
            'PredictedImpactBand',
            'PromotionProtocol',
            'ReactiveSaturationSignal',
            'StructuredFactSchemaMap',
            'Teto10PredictedRevertReviewDigest',
        ] as $owner) {
            $canonicalFqcn = 'App\\Services\\Ai\\Cognition\\AcosProgram\\'.$owner;

            $this->assertTrue(class_exists($canonicalFqcn), $canonicalFqcn.' must resolve from the canonical Cognition AcosProgram namespace.');
        }
    }

    public function test_legacy_acosmax_program_fqcns_remain_autoloadable_during_m3_compatibility_cycle(): void
    {
        foreach ([
            'AcosMaxLedgerRotationRegistry',
            'AcosMaxLote2MeasureService',
            'AcosMaxMeasureSeriesRegistry',
            'AcosMaxObraRetroService',
            'AcosMaxParallelExecutionProtocol',
            'AcosMaxProceduralSkillPromoterService',
            'AcosMaxVerifiedShareService',
            'AcosMaxWindowOrchestratorService',
            'AcosMeasureSeriesFreshnessReader',
            'AcosProgramCockpitService',
            'AmbitionRungPolicy',
            'AtlasFlywheelFunnelService',
            'AtlasLocalModelIntegrityService',
            'AtlasModelCapabilitySpecService',
            'AtlasNCaptureDrillService',
            'AtlasResourceBudgetService',
            'AttemptLifecycleLedger',
            'BeliefCascadeReverificationPlanner',
            'ComposedObraArcComposer',
            'ComposedObraArcLifecycle',
            'DogfoodingFrictionLeadMiner',
            'Esp09IndependentChallengerService',
            'EvidenceVisionThesisComposer',
            'EvidenceVisionThesisLifecycle',
            'ExecutionContextCooccurrenceService',
            'ExploratoryBetsPortfolio',
            'PortfolioBudgetAllocator',
            'PreReviewAdvisoryBand',
            'PredictedImpactBand',
            'PromotionProtocol',
            'ReactiveSaturationSignal',
            'StructuredFactSchemaMap',
            'Teto10PredictedRevertReviewDigest',
        ] as $owner) {
            $legacyFqcn = 'App\\Services\\Ai\\AcosMax\\'.$owner;

            $this->assertTrue(class_exists($legacyFqcn), $legacyFqcn.' must remain autoloadable through the M3 compatibility cycle.');
        }
    }
}
