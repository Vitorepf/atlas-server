<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Compounding\AtlasLearningAbstractionDerivedFromGate;
use App\Services\Ai\Compounding\AtlasLearningAbstractionLadderService;
use App\Services\Ai\Compounding\AtlasLearningAbstractionPackSelector;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

/**
 * MULTJ-06 — Escada de abstração: tática → padrão → princípio.
 */
final class Multj06AbstractionLadderTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        config([
            'atlas.ai.abstraction_ladder.enqueue_enabled' => false,
            'atlas.ai.abstraction_ladder.pack_injection_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_three_distinct_signatures_same_cause_yield_one_level_three_principle(): void
    {
        $signatures = [
            ['routing_memory', 'atlas-server'],
            ['debug_memory', 'atlas-server'],
            ['procedural', 'global'],
        ];
        foreach ($signatures as [$memoryType, $scope]) {
            $this->candidate(
                runId: 'run-'.$memoryType.'-'.$scope,
                memoryType: $memoryType,
                scope: $scope,
                primaryCause: 'poor_spec_quality',
            );
        }

        $report = app(AtlasLearningAbstractionLadderService::class)->propose();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(3, $report['totals']['patterns']);
        $this->assertSame(1, $report['totals']['principles_accepted']);
        $this->assertSame(0, $report['totals']['principles_rejected']);

        $principle = $report['levels']['principles'][0];
        $this->assertSame(3, $principle['abstraction_level']);
        $this->assertSame('poor_spec_quality', $principle['primary_cause']);
        $this->assertCount(3, $principle['derived_from']);
        $this->assertSame(3, $principle['case_count']);
    }

    public function test_derived_from_refs_resolve_against_pattern_index(): void
    {
        foreach (['routing_memory', 'debug_memory', 'procedural'] as $memoryType) {
            $this->candidate(
                runId: 'resolve-'.$memoryType,
                memoryType: $memoryType,
                scope: 'atlas-server',
                primaryCause: 'good_execution',
            );
        }

        $report = app(AtlasLearningAbstractionLadderService::class)->propose();
        $principle = $report['levels']['principles'][0];
        $patterns = $report['levels']['patterns'];
        $patternRefs = array_column($patterns, 'ref');

        foreach ($principle['derived_from'] as $ref) {
            $this->assertContains($ref, $patternRefs, "derived_from ref {$ref} must resolve to a pattern");
        }

        $gate = new AtlasLearningAbstractionDerivedFromGate;
        $index = [];
        foreach ($patterns as $pattern) {
            $index[(string) $pattern['ref']] = $pattern;
        }
        $verdict = $gate->assess($principle, $index);
        $this->assertTrue($verdict['admit']);
        $this->assertSame(AtlasLearningAbstractionDerivedFromGate::REASON_OK, $verdict['reason']);
    }

    public function test_broken_derived_from_is_rejected_by_gate(): void
    {
        $gate = new AtlasLearningAbstractionDerivedFromGate;
        $verdict = $gate->assess([
            'derived_from' => [
                'pattern:routing_memory|poor_spec_quality|atlas-server',
                'pattern:missing|poor_spec_quality|atlas-server',
            ],
        ], [
            'pattern:routing_memory|poor_spec_quality|atlas-server' => ['ref' => 'pattern:routing_memory|poor_spec_quality|atlas-server'],
        ]);

        $this->assertFalse($verdict['admit']);
        $this->assertSame(AtlasLearningAbstractionDerivedFromGate::REASON_UNRESOLVABLE, $verdict['reason']);
        $this->assertContains('pattern:missing|poor_spec_quality|atlas-server', $verdict['unresolved']);
    }

    public function test_k_is_pinned_in_freeze(): void
    {
        $freeze = AcosMaxLote2MeasureService::freezePayload('MULTJ-06');
        $this->assertSame(3, data_get($freeze, 'thresholds.distinct_signature_k'));
        $this->assertSame('multj.abstraction_ladder.v1', $freeze['formula_version']);
    }

    public function test_pack_selector_prefers_higher_abstraction_level(): void
    {
        $selector = new AtlasLearningAbstractionPackSelector;
        $selected = $selector->selectBestMatch([
            ['abstraction_level' => 1, 'case_count' => 10, 'claim' => 'tactical'],
            ['abstraction_level' => 3, 'case_count' => 3, 'claim' => 'principle'],
            ['abstraction_level' => 2, 'case_count' => 5, 'claim' => 'pattern'],
        ]);

        $this->assertSame(3, $selected['abstraction_level']);
        $this->assertSame('principle', $selected['claim']);
        $this->assertFalse($selector->injectionMeta()['wired_into_packfor']);
    }

    public function test_flag_off_enqueue_does_not_write_candidates(): void
    {
        foreach (['routing_memory', 'debug_memory', 'procedural'] as $memoryType) {
            $this->candidate(
                runId: 'no-enqueue-'.$memoryType,
                memoryType: $memoryType,
                scope: 'atlas-server',
                primaryCause: 'poor_spec_quality',
            );
        }

        $before = AiLearningCandidate::query()->count();
        $report = app(AtlasLearningAbstractionLadderService::class)->propose(enqueue: true);
        $after = AiLearningCandidate::query()->count();

        $this->assertSame(3, $before);
        $this->assertSame(3, $after);
        $this->assertSame(0, $report['totals']['enqueued']);
        $this->assertTrue($report['claim_policy']['read_only']);
    }

    public function test_enqueue_flag_on_materialises_level_three_candidate_in_same_queue(): void
    {
        config(['atlas.ai.abstraction_ladder.enqueue_enabled' => true]);

        foreach (['routing_memory', 'debug_memory', 'procedural'] as $memoryType) {
            $this->candidate(
                runId: 'enqueue-'.$memoryType,
                memoryType: $memoryType,
                scope: 'atlas-server',
                primaryCause: 'worker_mismatch',
            );
        }

        $before = AiLearningCandidate::query()->count();
        $report = app(AtlasLearningAbstractionLadderService::class)->propose(enqueue: true);
        $after = AiLearningCandidate::query()->count();

        $this->assertSame(1, $report['totals']['enqueued']);
        $this->assertSame($before + 1, $after);

        $enqueued = AiLearningCandidate::query()
            ->where('payload->abstraction_level', 3)
            ->first();
        $this->assertNotNull($enqueued);
        $this->assertCount(3, data_get($enqueued->payload, 'derived_from'));
        $this->assertFalse((bool) data_get($enqueued->payload, 'source.frontier_promotes'));
        $this->assertSame('ASI-02', data_get($enqueued->payload, 'source.admission_door'));
    }

    public function test_command_emits_json_report(): void
    {
        foreach (['routing_memory', 'debug_memory', 'procedural'] as $memoryType) {
            $this->candidate(
                runId: 'cmd-'.$memoryType,
                memoryType: $memoryType,
                scope: 'atlas-server',
                primaryCause: 'poor_spec_quality',
            );
        }

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:ai:abstraction-ladder', ['--json' => true], $output);
        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['totals']['principles_accepted']);
    }

    private function candidate(
        string $runId,
        string $memoryType,
        string $scope,
        string $primaryCause,
    ): AiLearningCandidate {
        $outcome = AiRunOutcome::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'run_id' => $runId,
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'evidence_quality' => 80,
            'learning_required' => true,
            'evidence_refs' => ['receipt:'.$runId],
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
        ]);

        return AiLearningCandidate::query()->create([
            'schema_version' => 'atlas.ai.compounding.learning_candidate.v1',
            'run_outcome_id' => $outcome->id,
            'candidate_hash' => hash('sha256', 'candidate-'.$runId),
            'status' => 'held_for_evidence',
            'decision' => 'hold',
            'memory_type' => $memoryType,
            'scope' => $scope,
            'claim' => 'Fixture candidate '.$runId,
            'confidence' => 40,
            'promotion_allowed' => false,
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [
                'caused_by' => [
                    'schema' => 'atlas.ai.credit_assignment.v1',
                    'primary_cause' => $primaryCause,
                    'contributing_causes' => [],
                    'confidence' => 'medium',
                    'refs' => ['receipt:'.$runId],
                ],
            ],
            'receipt_hash' => hash('sha256', 'receipt-'.$runId),
            'decided_at' => now(),
        ]);
    }
}
