<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricMuscleReadyPacketCompiler;
use Tests\TestCase;

/**
 * Shallow ideas are refused before they become give_back work for muscles: missing objective,
 * missing implementation target, missing test target, missing required evidence, human/operator/
 * provider dependencies, duplicate live targets, exact scope collisions, family collisions, no
 * runnable acceptance, and acceptance not bound to the test target all block admission. Admitted
 * intents compile into a packet draft carrying objective/allowed_files/scope_in/acceptance_criteria/
 * required_evidence.
 */
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

    public function test_admitted_packet_carries_objective_allowed_files_scope_in_acceptance_and_evidence(): void
    {
        $r = $this->svc()->compile($this->validIntent());

        self::assertTrue($r['admitted']);
        self::assertSame('Implement AtlasFooService so it validates foo inputs.', $r['packet']['objective']);
        self::assertContains('app/Services/Ai/Foo/AtlasFooService.php', $r['packet']['allowed_files']);
        self::assertContains('tests/Unit/Ai/Foo/AtlasFooServiceTest.php', $r['packet']['scope_in']);
        self::assertNotEmpty($r['packet']['acceptance_criteria']);
        self::assertContains('tests_or_gates_result', $r['packet']['required_evidence']);
    }

    public function test_missing_objective_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['objective' => '']));

        self::assertFalse($r['admitted']);
        self::assertContains('missing_objective', $r['blockers']);
    }

    public function test_missing_implementation_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['implementation_target' => '']));

        self::assertFalse($r['admitted']);
        self::assertContains('missing_implementation_target', $r['blockers']);
    }

    public function test_missing_test_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['test_target' => '']));

        self::assertFalse($r['admitted']);
        self::assertContains('missing_test_target', $r['blockers']);
    }

    public function test_missing_required_evidence_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['required_evidence' => []]));

        self::assertFalse($r['admitted']);
        self::assertContains('missing_required_evidence', $r['blockers']);
    }

    public function test_human_dependency_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_human' => true]));

        self::assertFalse($r['admitted']);
        self::assertContains('requires_human', $r['blockers']);
    }

    public function test_operator_dependency_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_operator' => true]));

        self::assertFalse($r['admitted']);
        self::assertContains('requires_operator', $r['blockers']);
    }

    public function test_external_provider_dependency_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['requires_external_provider' => true]));

        self::assertFalse($r['admitted']);
        self::assertContains('requires_external_provider', $r['blockers']);
    }

    public function test_duplicate_live_targets_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), [
            'app/Services/Ai/Bar.php',
            'app/Services/Ai/Bar.php',
        ]);

        self::assertFalse($r['admitted']);
        self::assertContains('duplicate_live_targets', $r['blockers']);
    }

    public function test_exact_scope_collision_with_implementation_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), ['app/Services/Ai/Foo/AtlasFooService.php']);

        self::assertFalse($r['admitted']);
        self::assertContains('scope_collision_implementation_target', $r['blockers']);
    }

    public function test_exact_scope_collision_with_test_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), ['tests/Unit/Ai/Foo/AtlasFooServiceTest.php']);

        self::assertFalse($r['admitted']);
        self::assertContains('scope_collision_test_target', $r['blockers']);
    }

    public function test_family_collision_with_implementation_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), ['app/Services/Ai/OtherDir/AtlasFooService.php']);

        self::assertFalse($r['admitted']);
        self::assertContains('family_collision_implementation_target', $r['blockers']);
    }

    public function test_family_collision_with_test_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(), ['tests/Unit/Ai/OtherDir/AtlasFooServiceTest.php']);

        self::assertFalse($r['admitted']);
        self::assertContains('family_collision_test_target', $r['blockers']);
    }

    public function test_no_runnable_acceptance_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['acceptance_criteria' => ['it should look nice']]));

        self::assertFalse($r['admitted']);
        self::assertContains('no_runnable_acceptance', $r['blockers']);
    }

    public function test_generic_passing_language_without_naming_test_target_blocks_admission(): void
    {
        $r = $this->svc()->compile($this->validIntent(['acceptance_criteria' => ['php artisan test exits 0']]));

        self::assertFalse($r['admitted']);
        self::assertContains('acceptance_not_bound_to_test_target', $r['blockers']);
    }

    public function test_acceptance_naming_the_concrete_test_path_admits(): void
    {
        $r = $this->svc()->compile($this->validIntent([
            'acceptance_criteria' => ['php artisan test tests/Unit/Ai/Foo/AtlasFooServiceTest.php exits 0'],
        ]));

        self::assertTrue($r['admitted']);
    }

    public function test_acceptance_naming_the_test_basename_admits(): void
    {
        $r = $this->svc()->compile($this->validIntent([
            'acceptance_criteria' => ['php artisan test passes for AtlasFooServiceTest.php'],
        ]));

        self::assertTrue($r['admitted']);
    }

    public function test_acceptance_with_explicit_filter_flag_admits(): void
    {
        $r = $this->svc()->compile($this->validIntent([
            'acceptance_criteria' => ['php artisan test --filter=AtlasFooServiceTest passes'],
        ]));

        self::assertTrue($r['admitted']);
    }
}
