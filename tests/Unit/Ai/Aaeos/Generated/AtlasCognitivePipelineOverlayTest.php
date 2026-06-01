<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitivePipelineOverlayService;
use Tests\TestCase;

/**
 * Pins the decidable contracts of the cognitive Pipeline Overlay doc:
 * authority ordering, flow-catalog maturity readiness, the ten mandatory gates,
 * the two hard forbidden actions (mastery without transfer_proof; Core Memory
 * promotion without review), stage-14 repair, and temporal-loop windows.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
 */
class AtlasCognitivePipelineOverlayTest extends TestCase
{
    private function service(): AtlasCognitivePipelineOverlayService
    {
        return new AtlasCognitivePipelineOverlayService;
    }

    public function test_authority_chain_pipeline_beats_overlay_beats_learning(): void
    {
        $service = $this->service();

        // doc "Authority": atlas-ai-pipeline > ... > este doc > domains/learning.
        $this->assertSame(
            'atlas-ai-pipeline',
            $service->resolveAuthority('pipeline-overlay', 'atlas-ai-pipeline')['winner'],
            'canonical pipeline outranks the overlay'
        );
        $this->assertSame(
            'pipeline-overlay',
            $service->resolveAuthority('domains/learning', 'pipeline-overlay')['winner'],
            'overlay outranks domains/learning'
        );
        // path/.md tolerant + unknown ranks weakest.
        $this->assertSame(
            'atlas-ai-flow-visual-map',
            $service->resolveAuthority('docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md', 'something-uncatalogued')['winner']
        );
    }

    public function test_flow_catalog_maturity_drives_runtime_readiness(): void
    {
        $service = $this->service();

        // doc table: 24 catalogued flows.
        $this->assertCount(24, AtlasCognitivePipelineOverlayService::FLOW_CATALOG);

        // base/scaffold are design-only (AP status governs) -> NOT runtime-ready.
        $base = $service->classifyFlow('learning.objective_design');
        $this->assertTrue($base['known']);
        $this->assertSame('base', $base['maturity']);
        $this->assertFalse($base['runtime_ready']);

        $scaffold = $service->classifyFlow('learning.active_recall');
        $this->assertSame('scaffold', $scaffold['maturity']);
        $this->assertFalse($scaffold['runtime_ready']);

        // read_model / implemented_partial ARE runtime-ready.
        $readModel = $service->classifyFlow('learning.pattern_extraction');
        $this->assertSame('read_model', $readModel['maturity']);
        $this->assertTrue($readModel['runtime_ready']);

        $partial = $service->classifyFlow('learning.productive_failure');
        $this->assertSame('implemented_partial', $partial['maturity']);
        $this->assertTrue($partial['runtime_ready']);

        // unknown flow is reported, never guessed.
        $unknown = $service->classifyFlow('learning.telepathy');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['runtime_ready']);
    }

    public function test_all_ten_mandatory_gates_required_to_complete(): void
    {
        $service = $this->service();

        $this->assertCount(10, AtlasCognitivePipelineOverlayService::MANDATORY_GATES);

        // all ten -> may complete.
        $all = array_fill_keys(AtlasCognitivePipelineOverlayService::MANDATORY_GATES, true);
        $green = $service->evaluateGates($all);
        $this->assertTrue($green['may_complete']);
        $this->assertSame(10, $green['satisfied']);
        $this->assertSame([], $green['missing']);

        // drop transfer_proof -> blocked and listed as missing.
        $all['transfer_proof'] = false;
        $blocked = $service->evaluateGates($all);
        $this->assertFalse($blocked['may_complete']);
        $this->assertSame(9, $blocked['satisfied']);
        $this->assertContains('transfer_proof', $blocked['missing']);
    }

    public function test_forbidden_mark_mastered_without_transfer_proof(): void
    {
        $service = $this->service();

        // doc "Forbidden actions": Marcar dominado sem transfer_proof.
        $blocked = $service->guardMasteryClaim('bayesian_inference', false);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('forbidden_mark_mastered_without_transfer_proof', $blocked['reason']);

        $allowed = $service->guardMasteryClaim('bayesian_inference', true);
        $this->assertTrue($allowed['allowed']);
    }

    public function test_forbidden_core_memory_promotion_without_review(): void
    {
        $service = $this->service();

        // doc "Forbidden actions": Promover learning result a Core Memory sem review.
        $this->assertFalse($service->guardCoreMemoryPromotion(false)['allowed']);
        $this->assertSame(
            'forbidden_promote_learning_result_to_core_memory_without_review',
            $service->guardCoreMemoryPromotion(false)['reason']
        );
        $this->assertTrue($service->guardCoreMemoryPromotion(true)['allowed']);
    }

    public function test_stage14_repair_high_load_reschedules_and_transfer_fail_opens_probe_block(): void
    {
        $service = $this->service();

        // doc pipeline row 14: load alto -> reagendar (takes precedence, never mastered).
        $highLoad = $service->resolveRepair(true, 'failed');
        $this->assertSame('reschedule', $highLoad['action']);
        $this->assertFalse($highLoad['open_new_block']);
        $this->assertFalse($highLoad['mark_mastered']);

        // transfer fail (load ok) -> bloco novo com probe; never mastered.
        $transferFail = $service->resolveRepair(false, 'failed');
        $this->assertSame('open_new_block_with_probe', $transferFail['action']);
        $this->assertTrue($transferFail['open_new_block']);
        $this->assertTrue($transferFail['attach_probe']);
        $this->assertFalse($transferFail['mark_mastered']);

        // nothing wrong -> proceed.
        $this->assertSame('proceed', $service->resolveRepair(false, 'passed')['action']);
    }

    public function test_temporal_loops_map_budget_to_documented_window(): void
    {
        $service = $this->service();

        // doc "Loops Temporais": Reflex <30s, Reaction 5-15min, Deliberation 60-120min,
        // Heartbeat diario, Cycle mensal.
        $this->assertSame('reflex', $service->selectLoop(20)['loop']);          // <30s
        $this->assertSame('reflex', $service->selectLoop(0)['loop']);           // floor
        $this->assertSame('reaction', $service->selectLoop(600)['loop']);       // 10min
        $this->assertSame('deliberation', $service->selectLoop(5400)['loop']);  // 90min
        $this->assertSame('heartbeat', $service->selectLoop(86400)['loop']);    // 1 day
        $this->assertSame('cycle', $service->selectLoop(2592000)['loop']);      // 30 days
        $this->assertSame('cycle', $service->selectLoop(99999999)['loop']);     // open-ended ceiling

        // negative budget clamps to reflex floor.
        $this->assertSame('reflex', $service->selectLoop(-50)['loop']);
    }

    public function test_stage4_cognitive_envelope_requires_documented_hooks(): void
    {
        $service = $this->service();

        // doc pipeline row 4: cognitive input carries the three hooks.
        $complete = $service->validateEnvelope([
            'input_kind' => 'cognitive',
            'study_session_id' => 'ss_1',
            'cognitive_load_snapshot' => ['level' => 'low'],
            'dreyfus_stage_target' => 'competent',
        ]);
        $this->assertTrue($complete['valid']);
        $this->assertSame([], $complete['missing_fields']);

        // missing snapshot -> invalid + listed.
        $partial = $service->validateEnvelope([
            'input_kind' => 'cognitive',
            'study_session_id' => 'ss_1',
            'dreyfus_stage_target' => 'competent',
        ]);
        $this->assertFalse($partial['valid']);
        $this->assertContains('cognitive_load_snapshot', $partial['missing_fields']);

        // non-cognitive input is exempt (overlay is additive).
        $this->assertTrue($service->validateEnvelope(['input_kind' => 'transaction'])['valid']);
    }
}
