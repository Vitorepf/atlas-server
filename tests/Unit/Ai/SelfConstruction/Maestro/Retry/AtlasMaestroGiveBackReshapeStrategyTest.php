<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackReshapeStrategy;
use Tests\TestCase;

final class AtlasMaestroGiveBackReshapeStrategyTest extends TestCase
{
    public function test_reshape_drops_forbidden_and_adds_anchor(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'forbidden_hits' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [[
                'symbol' => 'AnchorSymbol',
                'anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            ]],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
        ], $payload['allowed_files']);
        $this->assertSame('high', $payload['confidence']);
        $this->assertFalse($payload['empty']);
    }

    public function test_test_only_anchor_produces_empty_proposal_with_pair_incomplete_reason(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'missing_symbol_traces' => [['anchor_file' => 'tests/Unit/Ai/SelfConstruction/FooTest.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('impl_test_pair_incomplete', $payload['rationale']);
    }

    public function test_impl_only_files_produce_empty_proposal_with_pair_incomplete_reason(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Foo.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('impl_test_pair_incomplete', $payload['rationale']);
    }

    public function test_parent_traversal_in_anchor_produces_empty_proposal(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/../Secret.php'],
            'scope_in' => [],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/../Secret.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('parent_traversal_rejected', $payload['rationale']);
    }

    public function test_petreo_anchor_is_never_returned_in_allowed_files(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php',
                'tests/Unit/PetreoOrganTest.php',
            ],
            'petreo_files' => ['app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php'],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php',
                'tests/Unit/PetreoOrganTest.php',
            ],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('forbidden_or_petreo_anchor_rejected', $payload['rationale']);
    }

    public function test_reshape_refuses_when_no_anchor_evidence(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'forbidden_hits' => [],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([], $payload['allowed_files']);
        $this->assertSame('none', $payload['confidence']);
        $this->assertTrue($payload['empty']);
    }

    // ── AC2: root-cause classification (encoded as 'root_cause:<name>' in rationale) ──

    public function test_happy_path_reshape_classifies_scope_missing_when_anchor_outside_original_allowed_files(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'missing_symbol_traces' => [[
                'anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            ]],
        ]);

        $payload = $proposal->toArray();
        $this->assertFalse($payload['empty']);
        $this->assertContains('root_cause:scope_missing', $payload['rationale']);
        $this->assertContains('acceptance_repair_recommended:widen_allowed_files_to_include_anchor', $payload['rationale']);
        $this->assertContains('app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php', $payload['allowed_files']);
    }

    public function test_no_anchor_evidence_classifies_weak_acceptance_with_minimum_missing_evidence(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php'],
            'missing_symbol_traces' => [],
        ]);

        $payload = $proposal->toArray();
        $this->assertTrue($payload['empty']);
        $this->assertContains('root_cause:weak_acceptance', $payload['rationale']);
        $this->assertContains('min_missing_evidence:missing_symbol_traces_with_anchor_file', $payload['rationale']);
    }

    public function test_forbidden_anchor_classifies_forbidden_target_and_recommends_quarantine(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'forbidden_hits' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertTrue($payload['empty']);
        $this->assertContains('root_cause:forbidden_target', $payload['rationale']);
        $this->assertContains('quarantine_recommended', $payload['rationale']);
    }

    public function test_contradiction_evidence_classifies_contradiction_and_recommends_acceptance_repair(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php']],
            'contradiction_evidence' => true,
        ]);

        $payload = $proposal->toArray();
        $this->assertTrue($payload['empty']);
        $this->assertSame([], $payload['allowed_files']);
        $this->assertContains('root_cause:contradiction', $payload['rationale']);
        $this->assertContains('acceptance_repair_recommended:clarify_acceptance_criteria_to_remove_contradiction', $payload['rationale']);
    }

    public function test_duplicate_capability_evidence_classifies_duplicate_capability_and_recommends_quarantine(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php']],
            'duplicate_capability_evidence' => true,
        ]);

        $payload = $proposal->toArray();
        $this->assertTrue($payload['empty']);
        $this->assertContains('root_cause:duplicate_capability', $payload['rationale']);
        $this->assertContains('quarantine_recommended', $payload['rationale']);
        $this->assertContains('min_missing_evidence:confirmation_this_capability_is_not_already_delivered', $payload['rationale']);
    }

    public function test_impl_only_incomplete_pair_classifies_scope_missing_when_anchor_outside_allowed_files(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php', 'app/Services/Ai/SelfConstruction/Bar.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Bar.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertTrue($payload['empty']);
        $this->assertContains('impl_test_pair_incomplete', $payload['rationale']);
        $this->assertContains('root_cause:scope_missing', $payload['rationale']);
        $this->assertContains('min_missing_evidence:impl_and_test_file_pair_for_anchor', $payload['rationale']);
    }

    // ── forbidden target still takes priority over contradiction/duplicate evidence ──

    public function test_forbidden_target_outranks_contradiction_and_duplicate_evidence(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'forbidden_hits' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Forbidden.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php']],
            'contradiction_evidence' => true,
            'duplicate_capability_evidence' => true,
        ]);

        $payload = $proposal->toArray();
        $this->assertContains('root_cause:forbidden_target', $payload['rationale']);
        $this->assertNotContains('root_cause:contradiction', $payload['rationale']);
        $this->assertNotContains('root_cause:duplicate_capability', $payload['rationale']);
    }
}
