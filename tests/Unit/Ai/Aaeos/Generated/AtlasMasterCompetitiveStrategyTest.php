<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMasterCompetitiveStrategyService;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasMasterCompetitiveStrategyTest extends TestCase
{
    private AtlasMasterCompetitiveStrategyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasMasterCompetitiveStrategyService();
    }

    public function testGovernedUpperLayerIsTheOnlyAdmissiblePosture(): void
    {
        // Doc Position: Atlas competes as the governed upper layer, never model-vs-model.
        $governed = $this->service->classifyPosition(AtlasMasterCompetitiveStrategyService::POSTURE_GOVERNED_UPPER_LAYER);
        $this->assertTrue($governed['admissible']);
        $this->assertSame('governed_upper_layer', $governed['competes_as']);

        $modelVsModel = $this->service->classifyPosition(AtlasMasterCompetitiveStrategyService::POSTURE_MODEL_VS_MODEL);
        $this->assertFalse($modelVsModel['admissible']);
    }

    public function testAbsorptionLoopHasFiveOrderedStepsAndPreservesSingleChannel(): void
    {
        // Doc Absorption Loop: classify -> compare -> map -> update -> preserve channel.
        $loop = $this->service->resolveAbsorptionLoop('provider_design_mode', true, true);

        $this->assertCount(5, $loop['steps']);
        $this->assertSame(
            ['classify_capability', 'compare_when_useful', 'map_to_surface', 'update_policy_profile', 'preserve_single_channel'],
            array_column($loop['steps'], 'id'),
        );
        // Ordinals are strictly 1..5 in order.
        $this->assertSame([1, 2, 3, 4, 5], array_column($loop['steps'], 'ordinal'));
        // Step 5 invariant: the single Atlas channel is always preserved.
        $this->assertTrue($loop['single_channel_preserved']);
        // With comparison useful and evidence present, all five steps execute.
        $this->assertCount(5, $loop['executed_step_ids']);
    }

    public function testCompareStepSkippedWhenNotUsefulAndUpdateSkippedWithoutEvidenceButChannelStillPreserved(): void
    {
        // Step 2 "when useful" off, step 4 "if evidence supports it" off.
        $loop = $this->service->resolveAbsorptionLoop('provider_voice_mode', false, false);

        $byId = [];
        foreach ($loop['steps'] as $step) {
            $byId[$step['id']] = $step['executes'];
        }

        $this->assertFalse($byId['compare_when_useful']);
        $this->assertFalse($byId['update_policy_profile']);
        // Classify, map and the single-channel invariant still run.
        $this->assertTrue($byId['classify_capability']);
        $this->assertTrue($byId['map_to_surface']);
        $this->assertTrue($byId['preserve_single_channel']);
        $this->assertTrue($loop['single_channel_preserved']);
        $this->assertSame(['classify_capability', 'map_to_surface', 'preserve_single_channel'], $loop['executed_step_ids']);
    }

    public function testMoatEnumeratesExactlyEightStructuralAdvantagesInDocOrder(): void
    {
        // Doc Moat lists eight advantages providers do not own.
        $moat = $this->service->moat();
        $this->assertSame(8, $moat['count']);
        $this->assertCount(8, $moat['advantages']);
        $this->assertSame('local_operational_memory', $moat['advantages'][0]['id']);
        $this->assertSame('provider_agnostic_routing', $moat['advantages'][7]['id']);
    }

    public function testStopTheLineRequiresAbsorbOrRejectWithEvidence(): void
    {
        // Tripped + absorb -> create AP.
        $absorb = $this->service->evaluateStopTheLine(true, 'absorb');
        $this->assertTrue($absorb['line_tripped']);
        $this->assertTrue($absorb['valid']);
        $this->assertSame(AtlasMasterCompetitiveStrategyService::STOP_LINE_ABSORB, $absorb['outcome']);

        // Tripped + reject WITHOUT evidence -> invalid (doc requires evidence).
        $rejectNoEvidence = $this->service->evaluateStopTheLine(true, 'reject', false);
        $this->assertFalse($rejectNoEvidence['valid']);
        $this->assertSame(AtlasMasterCompetitiveStrategyService::STOP_LINE_INVALID, $rejectNoEvidence['outcome']);

        // Tripped + reject WITH evidence -> valid explicit rejection.
        $rejectWithEvidence = $this->service->evaluateStopTheLine(true, 'reject', true);
        $this->assertTrue($rejectWithEvidence['valid']);
        $this->assertSame(AtlasMasterCompetitiveStrategyService::STOP_LINE_REJECT, $rejectWithEvidence['outcome']);

        // Not tripped -> no action mandated.
        $notTripped = $this->service->evaluateStopTheLine(false, 'absorb');
        $this->assertFalse($notTripped['line_tripped']);
        $this->assertFalse($notTripped['action_required']);
    }

    public function testDirectProviderUsageSignalsIncompleteCoverage(): void
    {
        // Frontmatter decision: direct provider usage signals an Atlas coverage gap.
        $gap = $this->service->assessDirectProviderUsage(true, 'finance_domain_skill');
        $this->assertTrue($gap['signals_gap']);
        $this->assertFalse($gap['coverage_complete']);
        $this->assertSame('finance_domain_skill', $gap['gap_kind']);

        $covered = $this->service->assessDirectProviderUsage(false);
        $this->assertFalse($covered['signals_gap']);
        $this->assertTrue($covered['coverage_complete']);
    }

    public function testInvalidStopTheLineResponseThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->evaluateStopTheLine(true, 'ignore');
    }
}
