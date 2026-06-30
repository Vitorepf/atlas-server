<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldContractVersioner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldContractVersionerTest extends TestCase
{
    private AtlasExternalBrainScaffoldContractVersioner $versioner;

    protected function setUp(): void
    {
        $this->versioner = new AtlasExternalBrainScaffoldContractVersioner;
    }

    private function ver(array $overrides = []): array
    {
        return $this->versioner->version(array_merge([
            'current_version'      => '1.2.3',
            'current_checks'       => [],
            'proposed_checks'      => [],
            'explicit_retirements' => [],
        ], $overrides));
    }

    private function safety(string $name): array
    {
        return ['name' => $name, 'is_safety_check' => true, 'required' => true];
    }

    private function check(string $name, bool $required = true): array
    {
        return ['name' => $name, 'is_safety_check' => false, 'required' => $required];
    }

    // ── AC2: silent safety removal → breaking + major bump ────────────────────

    public function test_silent_safety_removal_forces_breaking(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->safety('forbidden_files_guard')],
            'proposed_checks' => [],
        ]);

        $this->assertSame('breaking', $r['compatibility']);
    }

    public function test_silent_safety_removal_forces_major_version_bump(): void
    {
        $r = $this->ver([
            'current_version' => '1.2.3',
            'current_checks'  => [$this->safety('forbidden_files_guard')],
            'proposed_checks' => [],
        ]);

        $this->assertSame('2.0.0', $r['next_version']);
    }

    public function test_silent_safety_removal_note_mentions_violation(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->safety('scope_guard')],
            'proposed_checks' => [],
        ]);

        $notes = implode(' ', $r['migration_notes']);
        $this->assertStringContainsString('scope_guard', $notes);
        $this->assertStringContainsString('silently removed', $notes);
    }

    // ── AC3: explicit retirement without rollout_evidence → still breaking ────

    public function test_explicit_safety_retirement_without_evidence_is_breaking(): void
    {
        $r = $this->ver([
            'current_checks'       => [$this->safety('honesty_gate')],
            'proposed_checks'      => [],
            'explicit_retirements' => ['honesty_gate'],
            // No rollout_evidence
        ]);

        $this->assertSame('breaking', $r['compatibility']);
    }

    public function test_explicit_safety_retirement_without_evidence_bumps_major(): void
    {
        $r = $this->ver([
            'current_version'      => '2.0.0',
            'current_checks'       => [$this->safety('honesty_gate')],
            'proposed_checks'      => [],
            'explicit_retirements' => ['honesty_gate'],
        ]);

        $this->assertSame('3.0.0', $r['next_version']);
    }

    // ── AC3: explicit retirement WITH rollout_evidence → migration_required ───

    public function test_explicit_safety_retirement_with_evidence_is_migration_required(): void
    {
        $r = $this->ver([
            'current_checks'       => [$this->safety('honesty_gate')],
            'proposed_checks'      => [],
            'explicit_retirements' => ['honesty_gate'],
            'rollout_evidence'     => ['docs/honesty-gate-migration.md'],
        ]);

        $this->assertSame('migration_required', $r['compatibility']);
    }

    public function test_explicit_safety_retirement_with_evidence_bumps_minor(): void
    {
        $r = $this->ver([
            'current_version'      => '1.2.3',
            'current_checks'       => [$this->safety('honesty_gate')],
            'proposed_checks'      => [],
            'explicit_retirements' => ['honesty_gate'],
            'rollout_evidence'     => ['docs/migration.md', 'tests/MigrationTest.php'],
        ]);

        $this->assertSame('1.3.0', $r['next_version']);
        $this->assertContains('honesty_gate', $r['retired_checks']);
    }

    // ── AC4: compatible additions → patch; non-safety migration → minor ───────

    public function test_optional_addition_is_compatible(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->check('existing')],
            'proposed_checks' => [$this->check('existing'), $this->check('new_opt', false)],
        ]);

        $this->assertSame('compatible', $r['compatibility']);
        $this->assertSame('1.2.4', $r['next_version']);
    }

    public function test_required_addition_is_migration_required(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->check('existing')],
            'proposed_checks' => [$this->check('existing'), $this->check('new_req', true)],
        ]);

        $this->assertSame('migration_required', $r['compatibility']);
        $this->assertSame('1.3.0', $r['next_version']);
    }

    public function test_non_safety_removal_is_migration_required(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->check('old')],
            'proposed_checks' => [],
        ]);

        $this->assertSame('migration_required', $r['compatibility']);
        $this->assertSame('1.3.0', $r['next_version']);
    }

    public function test_required_to_optional_relaxation_is_compatible(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->check('c', true)],
            'proposed_checks' => [$this->check('c', false)],
        ]);

        $this->assertSame('compatible', $r['compatibility']);
    }

    public function test_optional_to_required_tightening_is_breaking(): void
    {
        $r = $this->ver([
            'current_checks'  => [$this->check('c', false)],
            'proposed_checks' => [$this->check('c', true)],
        ]);

        $this->assertSame('breaking', $r['compatibility']);
    }

    public function test_no_changes_bumps_patch_only(): void
    {
        $checks = [$this->check('x'), $this->safety('y')];
        $r = $this->ver([
            'current_checks'  => $checks,
            'proposed_checks' => $checks,
        ]);

        $this->assertSame('compatible', $r['compatibility']);
        $this->assertSame('1.2.4', $r['next_version']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'current_checks'  => [$this->safety('g')],
            'proposed_checks' => [],
        ];

        $this->assertSame(json_encode($this->ver($facts)), json_encode($this->ver($facts)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->ver();

        $this->assertSame(AtlasExternalBrainScaffoldContractVersioner::SCHEMA, $r['schema_version']);
    }
}
