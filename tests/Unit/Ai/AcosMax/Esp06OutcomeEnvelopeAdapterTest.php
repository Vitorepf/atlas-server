<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\AemorOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\CompoundingOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\DevProceduralOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\OutcomeEnvelope;
use App\Services\Ai\AcosMax\OutcomeEnvelopeBridge;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ESP-06 — OutcomeEnvelope validity, adapters, anti-unification fence.
 */
final class Esp06OutcomeEnvelopeAdapterTest extends TestCase
{
    public function test_flag_off_bridge_returns_null_and_organs_stay_without_envelope_key(): void
    {
        config(['atlas.esp_06.outcome_envelope_adapters_enabled' => false]);

        $bridge = app(OutcomeEnvelopeBridge::class);
        $this->assertNull($bridge->project('dev_procedural', ['outcome_status' => 'success']));
        $this->assertSame([], $bridge->consume('dev_procedural', ['schema_version' => OutcomeEnvelope::SCHEMA_VERSION]));

        $dev = app(DevOutcomeMemoryService::class)->build(
            ['outcome_status' => 'success', 'execution' => ['tests_run' => 1, 'exit_code' => 0]],
            ['run_id' => 'run-off', 'task_id' => 'task-off'],
        );
        $this->assertArrayNotHasKey('outcome_envelope', $dev);
    }

    public function test_dev_procedural_adapter_emits_valid_envelope_with_labeled_divergent_fields(): void
    {
        $adapter = new DevProceduralOutcomeEnvelopeAdapter;
        $native = [
            'run_id' => 'dev-run-1',
            'outcome_status' => 'success',
            'proven_real' => true,
            'fake_green' => false,
            'proof_reason' => 'tests_passed',
            'evidence_kinds' => ['phpunit'],
            'selected_tests' => ['tests/Unit/FooTest.php'],
            'changed_files' => ['app/Foo.php'],
            'learning_candidates' => [],
            'should_promote_to_aemor' => true,
        ];

        $envelope = $adapter->toEnvelope($native, ['provider' => 'claude'])->toArray();

        OutcomeEnvelope::validate($envelope);
        $this->assertSame('dev_procedural', $envelope['adapter_origin']);
        $this->assertSame('dev_procedural', $envelope['native_divergent']['origin']);
        $this->assertSame('success', $envelope['native_divergent']['fields']['outcome_status']);
        $this->assertSame('succeeded', $envelope['status']);
        $this->assertSame('gates_passed', $envelope['verified_basis']);
        $this->assertTrue($envelope['verified']);
    }

    public function test_aemor_adapter_preserves_divergent_metrics_without_normalizing(): void
    {
        $adapter = new AemorOutcomeEnvelopeAdapter;
        $native = [
            'executor' => 'forge',
            'status' => 'succeeded',
            'objective' => 'Land forge packet.',
            'evidence_refs' => ['forge_evidence:vr-1'],
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'context_utility' => ['helpful_sources' => ['memory']],
            'patch_outcome' => ['changed_files' => 2],
            'learning_claim' => 'Forge lesson claim.',
        ];
        $context = [
            'episode_id' => 'ep-1',
            'outcome_contract_v2' => [
                'verified' => true,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'task_category' => 'forge',
                'provider' => 'local',
                'evidence_ref_count' => 1,
            ],
        ];

        $envelope = $adapter->toEnvelope($native, $context)->toArray();

        OutcomeEnvelope::validate($envelope);
        $this->assertSame('aemor', $envelope['adapter_origin']);
        $this->assertSame(['tests_passed' => true, 'attribution_reviewed' => true], $envelope['native_divergent']['fields']['metrics']);
        $this->assertSame('Forge lesson claim.', $envelope['native_divergent']['fields']['learning_claim']);
    }

    public function test_compounding_adapter_round_trips_quality_fields(): void
    {
        $adapter = new CompoundingOutcomeEnvelopeAdapter;
        $native = [
            'run_id' => 'cmp-run-1',
            'flow_id' => 'atlas_forge',
            'outcome_status' => 'passed',
            'flow_quality' => 88,
            'retrieval_quality' => 70,
            'execution_quality' => 92,
            'evidence_quality' => 95,
            'learning_required' => true,
            'evidence_refs' => ['forge_evidence:vr-2'],
            'verified' => true,
        ];

        $envelope = $adapter->toEnvelope($native)->toArray();
        OutcomeEnvelope::validate($envelope);

        $roundTrip = $adapter->fromEnvelope(OutcomeEnvelope::fromArray($envelope));
        $this->assertSame(88, $roundTrip['flow_quality']);
        $this->assertSame(92, $roundTrip['execution_quality']);
        $this->assertSame('atlas_forge', $roundTrip['flow_id']);
    }

    public function test_bridge_consumer_projects_back_to_target_organ_shape_when_enabled(): void
    {
        config(['atlas.esp_06.outcome_envelope_adapters_enabled' => true]);

        $bridge = app(OutcomeEnvelopeBridge::class);
        $envelope = $bridge->project('compounding', [
            'run_id' => 'cmp-consume',
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'execution_quality' => 85,
            'evidence_quality' => 90,
            'evidence_refs' => ['dev:test'],
            'verified' => true,
        ]);
        $this->assertNotNull($envelope);

        $consumed = $bridge->consume('compounding', $envelope);
        $this->assertSame('passed', $consumed['outcome_status']);
        $this->assertSame(80, $consumed['flow_quality']);
    }

    public function test_envelope_rejects_missing_native_divergent_origin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutcomeEnvelope::validate([
            'schema_version' => OutcomeEnvelope::SCHEMA_VERSION,
            'formula_version' => OutcomeEnvelope::FORMULA_VERSION,
            'adapter_origin' => 'dev_procedural',
            'executor' => 'dev',
            'task_category' => 'dev',
            'provider' => 'absent',
            'status' => 'succeeded',
            'verified' => false,
            'verified_basis' => 'absent',
            'verified_source_present' => false,
            'evidence_ref_count' => 0,
            'native_divergent' => ['origin' => 'aemor', 'fields' => []],
        ]);
    }

    public function test_esp06_anti_unification_fence_preserves_native_organs(): void
    {
        $organs = [
            'dev_procedural' => DevOutcomeMemoryService::class,
            'aemor' => AtlasAemorRuntimeService::class,
            'compounding' => AtlasCompoundingOutcomeEvaluator::class,
        ];

        foreach ($organs as $label => $class) {
            $this->assertTrue(class_exists($class), $class.' must exist — ESP-06 forbids fusion/deletion of '.$label);
        }

        $meta = app(OutcomeEnvelopeBridge::class)->producerConsumerMeta();
        $this->assertSame(array_keys($organs), $meta['producers']);
        $this->assertSame(array_keys($organs), $meta['consumers']);
        $this->assertSame(DevOutcomeMemoryService::class, $meta['anti_unification_fence']['dev_procedural']);
        $this->assertSame(AtlasAemorRuntimeService::class, $meta['anti_unification_fence']['aemor']);
        $this->assertSame(AtlasCompoundingOutcomeEvaluator::class, $meta['anti_unification_fence']['compounding']);
        $this->assertTrue(class_exists(AtlasEngineeringOutcomeRecorder::class));
    }

    public function test_esp06_measure_is_registered_for_elev20s(): void
    {
        $registry = app(\App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry::class);
        $this->assertContains('ESP-06', $registry->sliceIds());
        $this->assertContains(OutcomeEnvelopeBridge::MEASURE_ID, $registry->seriesIds());
    }
}
