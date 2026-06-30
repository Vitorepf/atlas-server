<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricMuscleReadyPacketCompiler;
use Tests\TestCase;

final class AtlasTaskFabricMuscleReadyPacketCompilerTest extends TestCase
{
    private function svc(): AtlasTaskFabricMuscleReadyPacketCompiler
    {
        return new AtlasTaskFabricMuscleReadyPacketCompiler;
    }

    private function validIntent(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Implement AtlasFooService so it validates foo inputs.',
            'implementation_target' => 'app/Services/Ai/Foo/AtlasFooService.php',
            'test_target' => 'tests/Unit/Ai/Foo/AtlasFooServiceTest.php',
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AtlasFooServiceTest exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    // ── AC1: valid intent compiles into a packet draft ─────────────────────────

    public function test_valid_intent_compiles_into_admitted_packet_draft(): void
    {
        $r = $this->svc()->compile($this->validIntent());

        $this->assertTrue($r['admitted']);
        $this->assertSame([], $r['blockers']);
        $this->assertArrayHasKey('packet', $r);
        $this->assertContains('app/Services/Ai/Foo/AtlasFooService.php', $r['packet']['allowed_files']);
        $this->assertContains('tests/Unit/Ai/Foo/AtlasFooServiceTest.php', $r['packet']['allowed_files']);
        $this->assertNotEmpty($r['packet']['acceptance_criteria']);
    }

    public function test_packet_carries_required_evidence(): void
    {
        $r = $this->svc()->compile($this->validIntent());

        $this->assertContains('tests_or_gates_result', $r['packet']['required_evidence']);
    }

    public function test_packet_carries_objective(): void
    {
        $r = $this->svc()->compile($this->validIntent());

        $this->assertSame('Implement AtlasFooService so it validates foo inputs.', $r['packet']['objective']);
    }

    public function test_output_has_schema_key(): void
    {
        $r = $this->svc()->compile($this->validIntent());
        $this->assertSame(AtlasTaskFabricMuscleReadyPacketCompiler::SCHEMA, $r['schema']);
    }

    // ── AC2: refusals with exact blockers ───────────────────────────────────────

    public function test_test_only_intent_is_refused_with_missing_implementation_target(): void
    {
        $r = $this->svc()->compile($this->validIntent(['implementation_target' => '']));

        $this->assertFalse($r['admitted']);
        $this->assertContains('missing_implementation_target', $r['blockers']);
        $this->assertArrayNotHasKey('packet', $r);
    }

    public function test_implementation_only_intent_is_refused_with_missing_test_target(): void
    {
        $r = $this->svc()->compile($this->validIntent(['test_target' => '']));

        $this->assertFalse($r['admitted']);
        $this->assertContains('missing_test_target', $r['blockers']);
    }

    public function test_human_dependent_intent_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_human' => true]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('requires_human', $r['blockers']);
    }

    public function test_operator_dependent_intent_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_operator' => true]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('requires_operator', $r['blockers']);
    }

    public function test_provider_dependent_intent_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_external_provider' => true]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('requires_external_provider', $r['blockers']);
    }

    public function test_no_runnable_acceptance_intent_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['acceptance_criteria' => ['it should look nice']]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('no_runnable_acceptance', $r['blockers']);
    }

    public function test_empty_acceptance_intent_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['acceptance_criteria' => []]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('no_runnable_acceptance', $r['blockers']);
    }

    public function test_missing_required_evidence_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['required_evidence' => []]));

        $this->assertFalse($r['admitted']);
        $this->assertContains('missing_required_evidence', $r['blockers']);
    }

    public function test_missing_objective_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(['objective' => '']));

        $this->assertFalse($r['admitted']);
        $this->assertContains('missing_objective', $r['blockers']);
    }

    // ── AC3: duplicate live_targets refused before packet draft creation ───────

    public function test_duplicate_live_targets_is_refused_before_packet_draft(): void
    {
        $r = $this->svc()->compile($this->validIntent(), [
            'app/Services/Ai/Bar.php',
            'app/Services/Ai/Bar.php',
        ]);

        $this->assertFalse($r['admitted']);
        $this->assertContains('duplicate_live_targets', $r['blockers']);
        $this->assertArrayNotHasKey('packet', $r);
    }

    public function test_scope_collision_with_implementation_target_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(), [
            'app/Services/Ai/Foo/AtlasFooService.php',
        ]);

        $this->assertFalse($r['admitted']);
        $this->assertContains('scope_collision_implementation_target', $r['blockers']);
    }

    public function test_scope_collision_with_test_target_is_refused(): void
    {
        $r = $this->svc()->compile($this->validIntent(), [
            'tests/Unit/Ai/Foo/AtlasFooServiceTest.php',
        ]);

        $this->assertFalse($r['admitted']);
        $this->assertContains('scope_collision_test_target', $r['blockers']);
    }

    public function test_clean_live_targets_do_not_block_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), [
            'app/Services/Ai/Unrelated.php',
            'tests/Unit/Ai/UnrelatedTest.php',
        ]);

        $this->assertTrue($r['admitted']);
    }

    // ── AC4: pure — no I/O ───────────────────────────────────────────────────────

    public function test_compile_does_not_create_implementation_file(): void
    {
        $this->svc()->compile($this->validIntent());

        $this->assertFileDoesNotExist(base_path('app/Services/Ai/Foo/AtlasFooService.php'));
    }

    public function test_multiple_blockers_are_all_reported(): void
    {
        $r = $this->svc()->compile([
            'objective' => '',
            'requires_human' => true,
        ]);

        $this->assertFalse($r['admitted']);
        $this->assertContains('missing_objective', $r['blockers']);
        $this->assertContains('missing_implementation_target', $r['blockers']);
        $this->assertContains('missing_test_target', $r['blockers']);
        $this->assertContains('requires_human', $r['blockers']);
        $this->assertContains('no_runnable_acceptance', $r['blockers']);
        $this->assertContains('missing_required_evidence', $r['blockers']);
    }

    public function test_compile_is_deterministic(): void
    {
        $intent = $this->validIntent();
        $r1 = $this->svc()->compile($intent);
        $r2 = $this->svc()->compile($intent);

        $this->assertSame(
            json_encode($r1, JSON_UNESCAPED_SLASHES),
            json_encode($r2, JSON_UNESCAPED_SLASHES),
        );
    }
}
