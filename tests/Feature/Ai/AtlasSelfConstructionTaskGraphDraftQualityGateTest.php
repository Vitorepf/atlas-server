<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphDraftQualityGate;
use Tests\TestCase;

final class AtlasSelfConstructionTaskGraphDraftQualityGateTest extends TestCase
{
    private function svc(): AtlasSelfConstructionTaskGraphDraftQualityGate
    {
        return new AtlasSelfConstructionTaskGraphDraftQualityGate;
    }

    /** Builds a draft that passes all checks. */
    private function passingDraft(array $overrides = []): array
    {
        return array_merge([
            'objective'                    => 'Implement AtlasSomethingService so it validates task drafts.',
            'allowed_files'                => [
                'app/Services/Ai/SelfConstruction/TaskGraph/AtlasSomethingService.php',
                'tests/Feature/Ai/AtlasSomethingServiceTest.php',
            ],
            'scope_in'                     => [
                'app/Services/Ai/SelfConstruction/TaskGraph/AtlasSomethingService.php',
                'tests/Feature/Ai/AtlasSomethingServiceTest.php',
            ],
            'acceptance_criteria'          => ['Runnable gate: php artisan test --filter=AtlasSomethingServiceTest exits 0.'],
            'required_evidence'            => ['tests_or_gates_result'],
            'final_runtime_owner'          => 'atlas_native',
            'steady_state_runtime_owner'   => 'atlas_server',
            'requires_operator'            => false,
            'requires_human'               => false,
            'requires_external_provider'   => false,
            'expected_delta'               => 'Atlas validates task drafts autonomously without human review.',
            'anti_proxy'                   => 'Gate rejects drafts lacking concrete files and runnable proof, not just format checks.',
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_output_has_required_keys(): void
    {
        $r = $this->svc()->evaluate([]);

        $this->assertArrayHasKey('schema_version', $r);
        $this->assertArrayHasKey('passed',         $r);
        $this->assertArrayHasKey('blockers',       $r);
        $this->assertArrayHasKey('facts',          $r);
        $this->assertArrayHasKey('repair_hints',   $r);
    }

    public function test_ac1_passing_draft_returns_passed_true(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft());

        $this->assertTrue($r['passed'], 'passing draft must return passed=true; blockers: '.implode(', ', $r['blockers']));
        $this->assertEmpty($r['blockers']);
    }

    // ── AC2: missing objective / allowed_files / scope_in / criteria / evidence / runnable proof ──

    public function test_ac2_missing_objective_produces_blocker_and_repair_hint(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['objective' => '']));

        $this->assertFalse($r['passed']);
        $this->assertContains('objective_missing', $r['blockers']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    public function test_ac2_empty_allowed_files_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['allowed_files' => [], 'scope_in' => []]));

        $this->assertFalse($r['passed']);
        $this->assertContains('allowed_files_empty', $r['blockers']);
    }

    public function test_ac2_bare_directory_in_allowed_files_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'scope_in'      => ['app/Services/Ai/SelfConstruction/'],
        ]));

        $this->assertFalse($r['passed']);
        $blocked = implode(',', $r['blockers']);
        $this->assertStringContainsString('bare_directories', $blocked);
    }

    public function test_ac2_scope_in_missing_allowed_file_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft([
            'scope_in' => ['tests/Feature/Ai/AtlasSomethingServiceTest.php'],  // missing impl file
        ]));

        $this->assertFalse($r['passed']);
        $this->assertContains('scope_in_does_not_cover_allowed_files', $r['blockers']);
    }

    public function test_ac2_missing_acceptance_criteria_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['acceptance_criteria' => []]));

        $this->assertFalse($r['passed']);
        $this->assertContains('acceptance_criteria_missing', $r['blockers']);
    }

    public function test_ac2_missing_required_evidence_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['required_evidence' => []]));

        $this->assertFalse($r['passed']);
        $this->assertContains('required_evidence_missing', $r['blockers']);
    }

    public function test_ac2_no_runnable_proof_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft([
            'required_evidence'   => ['implementation_notes'],  // no tests_or_gates_result
            'acceptance_criteria' => ['It should work correctly'],  // no artisan/phpunit ref
        ]));

        $this->assertFalse($r['passed']);
        $this->assertContains('runnable_proof_missing', $r['blockers']);
    }

    public function test_ac2_artisan_test_in_acceptance_criteria_satisfies_runnable_proof(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft([
            'required_evidence'   => ['implementation_notes'],
            'acceptance_criteria' => ['Runnable gate: php artisan test --filter=Foo exits 0'],
        ]));

        // runnable proof via acceptance_criteria; must NOT block on runnable_proof_missing
        $this->assertNotContains('runnable_proof_missing', $r['blockers']);
    }

    public function test_ac2_each_blocker_has_corresponding_repair_hint(): void
    {
        $r = $this->svc()->evaluate([]);  // everything missing

        $this->assertGreaterThan(0, count($r['blockers']));
        $this->assertCount(count($r['blockers']), $r['repair_hints'],
            'each blocker must have exactly one corresponding repair_hint');
    }

    // ── AC3: unknown depends_on is blocked when known_packet_ids supplied ─────

    public function test_ac3_unknown_depends_on_is_blocked_when_known_ids_supplied(): void
    {
        $r = $this->svc()->evaluate(
            $this->passingDraft(['depends_on' => ['non-existent-packet-id']]),
            ['known_packet_ids' => ['real-packet-1', 'real-packet-2']],
        );

        $this->assertFalse($r['passed']);
        $blocked = implode(',', $r['blockers']);
        $this->assertStringContainsString('depends_on_unknown', $blocked);
    }

    public function test_ac3_known_depends_on_passes(): void
    {
        $r = $this->svc()->evaluate(
            $this->passingDraft(['depends_on' => ['real-packet-1']]),
            ['known_packet_ids' => ['real-packet-1', 'real-packet-2']],
        );

        $blocked = implode(',', $r['blockers']);
        $this->assertStringNotContainsString('depends_on_unknown', $blocked);
    }

    public function test_ac3_depends_on_not_validated_when_known_packet_ids_absent(): void
    {
        // Without queueFacts.known_packet_ids, depends_on check is skipped.
        $r = $this->svc()->evaluate($this->passingDraft(['depends_on' => ['could-be-anything']]));

        $blocked = implode(',', $r['blockers']);
        $this->assertStringNotContainsString('depends_on_unknown', $blocked);
    }

    // ── AC4: autonomy contract — owner / requires_* flags ────────────────────

    public function test_ac4_requires_operator_true_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['requires_operator' => true]));

        $this->assertFalse($r['passed']);
        $this->assertContains('requires_operator_must_be_false', $r['blockers']);
    }

    public function test_ac4_requires_human_true_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['requires_human' => true]));

        $this->assertFalse($r['passed']);
        $this->assertContains('requires_human_must_be_false', $r['blockers']);
    }

    public function test_ac4_requires_external_provider_true_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['requires_external_provider' => true]));

        $this->assertFalse($r['passed']);
        $this->assertContains('requires_external_provider_must_be_false', $r['blockers']);
    }

    public function test_ac4_non_atlas_native_final_owner_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['final_runtime_owner' => 'human']));

        $this->assertFalse($r['passed']);
        $blocked = implode(',', $r['blockers']);
        $this->assertStringContainsString('final_runtime_owner_not_atlas_native', $blocked);
    }

    public function test_ac4_non_atlas_server_steady_state_owner_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['steady_state_runtime_owner' => 'claude_code']));

        $this->assertFalse($r['passed']);
        $blocked = implode(',', $r['blockers']);
        $this->assertStringContainsString('steady_state_runtime_owner_not_atlas_server', $blocked);
    }

    public function test_ac4_empty_final_runtime_owner_is_blocked(): void
    {
        $r = $this->svc()->evaluate($this->passingDraft(['final_runtime_owner' => '']));

        $this->assertFalse($r['passed']);
        $blocked = implode(',', $r['blockers']);
        $this->assertStringContainsString('final_runtime_owner_not_atlas_native', $blocked);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_evaluate(): void
    {
        $draft = $this->passingDraft();
        $svc   = $this->svc();
        $this->assertSame(
            json_encode($svc->evaluate($draft), JSON_UNESCAPED_SLASHES),
            json_encode($svc->evaluate($draft), JSON_UNESCAPED_SLASHES),
        );
    }
}
