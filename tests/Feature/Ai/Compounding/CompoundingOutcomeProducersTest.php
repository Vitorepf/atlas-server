<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

/**
 * OUTC-01(d): Conductor LIVE compound default + automatic promotion checks.
 */
final class CompoundingOutcomeProducersTest extends TestCase
{
    use BootsCompoundingSchema;

    private string $diaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        config()->set('atlas.engineering_conductor.compound_default_live', true);
        config()->set('atlas.patamar4.swarm_production_resolver_enabled', true);
        $this->diaryPath = sys_get_temp_dir().'/atlas-compounding-diary-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->diaryPath);
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_strong_candidate_auto_promotes_compounding_memory_with_diary_entry(): void
    {
        $runtime = app(AtlasCompoundingRuntimeService::class);
        $result = $runtime->recordExecution([
            'outcome_status' => 'passed',
            'flow_id' => 'atlas_forge',
            'run_id' => 'outc-promote-'.bin2hex(random_bytes(4)),
            'evidence_refs' => ['forge_evidence:verification_receipt:vr-1'],
            'learning_signal' => [
                'claim' => 'Forge cycle with harness-captured phpunit evidence should compound.',
                'memory_type' => 'forge_handoff_memory',
                'scope' => 'engineering',
                'confidence' => 75,
                'flow_id' => 'atlas_forge',
                'evidence_refs' => ['forge_evidence:verification_receipt:vr-1'],
            ],
        ]);

        $this->assertSame('recorded', $result['status']);
        $this->assertTrue((bool) data_get($result, 'learning_candidate.promotion_allowed'));
        $this->assertNotNull($result['compounding_memory']['id'] ?? null);
        $this->assertSame(1, AiCompoundingMemory::query()->where('status', 'active')->count());
    }

    public function test_weak_candidate_stays_held_and_never_becomes_active_memory(): void
    {
        $runtime = app(AtlasCompoundingRuntimeService::class);
        $result = $runtime->recordExecution([
            'outcome_status' => 'passed',
            'flow_id' => 'atlas_forge',
            'run_id' => 'outc-held-'.bin2hex(random_bytes(4)),
            'evidence_refs' => ['forge_evidence:diff:only'],
            'learning_signal' => [
                'claim' => 'Weak evidence should stay held.',
                'memory_type' => 'forge_handoff_memory',
                'scope' => 'engineering',
                'confidence' => 55,
                'flow_id' => 'atlas_forge',
                'evidence_refs' => ['forge_evidence:diff:only'],
            ],
        ]);

        $this->assertSame('recorded', $result['status']);
        $this->assertFalse((bool) data_get($result, 'learning_candidate.promotion_allowed'));
        $this->assertSame(0, AiCompoundingMemory::query()->where('status', 'active')->count());
        $candidate = DB::table('ai_learning_candidates')->first();
        $this->assertNotNull($candidate);
        $payload = json_decode((string) $candidate->payload, true);
        $this->assertContains('confidence_below_70', (array) ($payload['missing_evidence'] ?? []));
    }

    public function test_live_conductor_compounds_by_default_without_explicit_option(): void
    {
        config()->set('atlas.engineering_conductor.compound_default_live', true);
        $conductor = app(AtlasEngineeringRunConductorService::class);
        $method = new ReflectionMethod($conductor, 'compoundEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $conductor,
            [],
            AtlasEngineeringRunConductorService::MODE_LIVE,
        ));
        $this->assertFalse($method->invoke(
            $conductor,
            ['compound' => false],
            AtlasEngineeringRunConductorService::MODE_LIVE,
        ));
        $this->assertFalse($method->invoke(
            $conductor,
            [],
            AtlasEngineeringRunConductorService::MODE_SHADOW,
        ));
    }
}
