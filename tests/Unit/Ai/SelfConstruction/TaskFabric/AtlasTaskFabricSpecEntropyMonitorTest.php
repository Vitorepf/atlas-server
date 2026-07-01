<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSpecEntropyMonitor;
use Tests\TestCase;

final class AtlasTaskFabricSpecEntropyMonitorTest extends TestCase
{
    private function monitor(): AtlasTaskFabricSpecEntropyMonitor
    {
        return new AtlasTaskFabricSpecEntropyMonitor;
    }

    public function test_repeated_template_batch_is_rejected_as_low_entropy(): void
    {
        $batch = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $name) {
            $batch[] = [
                'objective' => "Upgrade {$name}Service so it validates input",
                'allowed_files' => ["app/Services/Ai/SelfConstruction/{$name}Service.php"],
                'acceptance_criteria' => ["{$name}Service exits 0"],
            ];
        }

        $result = $this->monitor()->monitor($batch);

        $this->assertSame(AtlasTaskFabricSpecEntropyMonitor::VERDICT_LOW_ENTROPY, $result['verdict']);
        $this->assertNotEmpty($result['repeated_shapes']);
        $this->assertLessThan(0.5, $result['entropy_score']);
    }

    public function test_smaller_diverse_batch_with_distinct_domains_is_admitted(): void
    {
        $batch = [
            [
                'objective' => 'Rank candidates by autonomy unlock and reject proxy-only signals',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/Ranker.php'],
                'acceptance_criteria' => ['high autonomy_unlock ranks first', 'proxy-only candidates are rejected'],
            ],
            [
                'objective' => 'Detect circular dependencies and sequence tasks by unlock value',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/TaskGraph/Sequencer.php'],
                'acceptance_criteria' => ['circular dependencies are blocked with a cycle path', 'unlock value orders the chain'],
            ],
        ];

        $result = $this->monitor()->monitor($batch);

        $this->assertSame(AtlasTaskFabricSpecEntropyMonitor::VERDICT_DIVERSE, $result['verdict']);
        $this->assertGreaterThanOrEqual(0.5, $result['entropy_score']);
        $this->assertSame(2, $result['recommended_batch_size']);
    }

    public function test_output_includes_all_four_required_fields(): void
    {
        $result = $this->monitor()->monitor([
            ['objective' => 'do something', 'allowed_files' => [], 'acceptance_criteria' => []],
        ]);

        $this->assertArrayHasKey('entropy_score', $result);
        $this->assertArrayHasKey('repeated_shapes', $result);
        $this->assertArrayHasKey('unique_behavior_verbs', $result);
        $this->assertArrayHasKey('recommended_batch_size', $result);
    }

    public function test_unique_behavior_verbs_are_detected_from_acceptance_text(): void
    {
        $result = $this->monitor()->monitor([
            ['objective' => 'a', 'acceptance_criteria' => ['the service must rank and sort candidates']],
            ['objective' => 'b', 'acceptance_criteria' => ['the gate must reject and block bad input']],
        ]);

        $this->assertContains('rank', $result['unique_behavior_verbs']);
        $this->assertContains('sort', $result['unique_behavior_verbs']);
        $this->assertContains('reject', $result['unique_behavior_verbs']);
        $this->assertContains('block', $result['unique_behavior_verbs']);
    }

    public function test_low_entropy_recommends_a_smaller_deduplicated_batch_size(): void
    {
        $batch = [];
        foreach (['One', 'Two', 'Three'] as $name) {
            $batch[] = [
                'objective' => "Upgrade {$name}Gate so it validates input",
                'allowed_files' => ["app/Services/Ai/SelfConstruction/{$name}Gate.php"],
                'acceptance_criteria' => ["{$name}Gate exits 0"],
            ];
        }

        $result = $this->monitor()->monitor($batch);

        $this->assertLessThan(3, $result['recommended_batch_size']);
    }

    // ── AC: evidence requirements are a measured entropy dimension ─────────────

    public function test_repeated_evidence_shape_alone_drags_a_batch_into_low_entropy(): void
    {
        // Objective, acceptance, allowed_files, and behavior verbs are all genuinely diverse — the
        // ONLY repeated dimension across the whole batch is required_evidence (same evidence list
        // verbatim, no class-name variance). That alone must be measured, not ignored.
        $batch = [
            [
                'objective' => 'Rank candidates by autonomy unlock and reject proxy-only signals',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/Ranker.php'],
                'acceptance_criteria' => ['high autonomy_unlock ranks first', 'proxy-only candidates are rejected'],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            ],
            [
                'objective' => 'Detect circular dependencies and sequence tasks by unlock value',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/TaskGraph/Sequencer.php'],
                'acceptance_criteria' => ['circular dependencies are blocked with a cycle path', 'unlock value orders the chain'],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            ],
        ];

        $withRepeatedEvidence = $this->monitor()->monitor($batch);

        $batch[1]['required_evidence'] = ['tests_or_gates_result', 'constitution_gate_receipt'];
        $withDiverseEvidence = $this->monitor()->monitor($batch);

        $this->assertLessThan($withDiverseEvidence['entropy_score'], $withRepeatedEvidence['entropy_score']);
    }

    public function test_diverse_evidence_requirements_contribute_to_a_diverse_verdict(): void
    {
        $batch = [
            [
                'objective' => 'Rank candidates by autonomy unlock and reject proxy-only signals',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/Ranker.php'],
                'acceptance_criteria' => ['high autonomy_unlock ranks first', 'proxy-only candidates are rejected'],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            ],
            [
                'objective' => 'Detect circular dependencies and sequence tasks by unlock value',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/TaskGraph/Sequencer.php'],
                'acceptance_criteria' => ['circular dependencies are blocked with a cycle path', 'unlock value orders the chain'],
                'required_evidence' => ['tests_or_gates_result', 'constitution_gate_receipt'],
            ],
        ];

        $result = $this->monitor()->monitor($batch);

        $this->assertSame(AtlasTaskFabricSpecEntropyMonitor::VERDICT_DIVERSE, $result['verdict']);
    }

    public function test_service_performs_no_io_and_is_deterministic(): void
    {
        $monitor = $this->monitor();
        $batch = [
            ['objective' => 'Rank candidates by leverage', 'allowed_files' => ['app/Foo.php'], 'acceptance_criteria' => ['ranks by leverage']],
        ];

        $this->assertSame($monitor->monitor($batch), $monitor->monitor($batch));
    }
}
