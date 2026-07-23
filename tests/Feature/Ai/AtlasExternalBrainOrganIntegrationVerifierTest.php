<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganIntegrationVerifier;
use Tests\TestCase;

final class AtlasExternalBrainOrganIntegrationVerifierTest extends TestCase
{
    public function test_implementation_plus_tests_alone_never_produces_integrated_status(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [
                ['organ_id' => 'organ-a', 'has_tests' => true, 'has_implementation' => true],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $result['results'][0]['status']);
        $this->assertTrue($result['results'][0]['capability_island']);
    }

    public function test_missing_circuit_elements_appear_in_circuit_gaps(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-b']],
        ]);

        $gaps = $result['results'][0]['circuit_gaps'];
        $this->assertContains('no_input_source', $gaps);
        $this->assertContains('no_decision_role', $gaps);
        $this->assertContains('no_output_consumer', $gaps);
        $this->assertContains('no_learning_feedback', $gaps);
        $this->assertContains('no_failure_handling', $gaps);
    }

    public function test_fully_wired_organ_is_integrated(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-c']],
            'input_sources' => ['organ-c' => ['some_source']],
            'decision_roles' => ['organ-c' => true],
            'flow_usage' => ['organ-c' => ['SomeConsumer']],
            'learning_feedback' => ['organ-c' => true],
            'failure_handling' => ['organ-c' => true],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $result['results'][0]['status']);
        $this->assertSame([], $result['results'][0]['circuit_gaps']);
    }

    public function test_no_output_consumer_flags_true_orphan(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-orphan']],
        ]);

        $this->assertTrue($result['results'][0]['orphan']);
        $this->assertFalse($result['results'][0]['one_way_output']);
    }

    public function test_output_consumer_without_learning_feedback_flags_one_way_output(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-oneway']],
            'input_sources' => ['organ-oneway' => ['source']],
            'decision_roles' => ['organ-oneway' => true],
            'flow_usage' => ['organ-oneway' => ['Consumer']],
        ]);

        $this->assertFalse($result['results'][0]['orphan']);
        $this->assertTrue($result['results'][0]['one_way_output']);
    }

    public function test_missing_failure_handling_flags_missing_failure_path(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-fail']],
            'input_sources' => ['organ-fail' => ['source']],
            'decision_roles' => ['organ-fail' => true],
            'flow_usage' => ['organ-fail' => ['Consumer']],
            'learning_feedback' => ['organ-fail' => true],
        ]);

        $this->assertTrue($result['results'][0]['missing_failure_path']);
    }

    public function test_intentionally_standalone_organ_is_classified_correctly(): void
    {
        $result = (new AtlasExternalBrainOrganIntegrationVerifier)->verify([
            'organ_inventory' => [['organ_id' => 'organ-standalone']],
            'standalone_justifications' => ['organ-standalone' => 'reference-only utility'],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTENTIONALLY_STANDALONE,
            $result['results'][0]['status'],
        );
    }
}
