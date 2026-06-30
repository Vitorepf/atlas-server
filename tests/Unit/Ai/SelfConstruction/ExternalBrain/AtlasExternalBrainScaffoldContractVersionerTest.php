<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldContractVersioner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldContractVersionerTest extends TestCase
{
    private function versioner(): AtlasExternalBrainScaffoldContractVersioner
    {
        return new AtlasExternalBrainScaffoldContractVersioner;
    }

    private function check(string $name, bool $safety = false, bool $required = true): array
    {
        return ['name' => $name, 'is_safety_check' => $safety, 'required' => $required];
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->versioner()->version([]);
        $this->assertSame(AtlasExternalBrainScaffoldContractVersioner::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('next_version',     $r);
        $this->assertArrayHasKey('compatibility',    $r);
        $this->assertArrayHasKey('migration_notes',  $r);
        $this->assertArrayHasKey('retired_checks',   $r);
        $this->assertArrayHasKey('rollout_guidance', $r);
    }

    // ── Compatible changes ────────────────────────────────────────────────────

    public function test_adding_optional_check_is_compatible(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [$this->check('existing')],
            'proposed_checks' => [$this->check('existing'), $this->check('new_opt', false, false)],
        ]);
        $this->assertSame('compatible', $r['compatibility']);
        $this->assertSame('1.0.1', $r['next_version']);
    }

    public function test_relaxing_required_to_optional_is_compatible(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '2.3.4',
            'current_checks'  => [$this->check('alpha', false, true)],
            'proposed_checks' => [$this->check('alpha', false, false)],
        ]);
        $this->assertSame('compatible', $r['compatibility']);
        $this->assertSame('2.3.5', $r['next_version']);
    }

    // ── Migration-required changes ────────────────────────────────────────────

    public function test_removing_non_safety_check_is_migration_required(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [$this->check('alpha'), $this->check('beta')],
            'proposed_checks' => [$this->check('alpha')],
        ]);
        $this->assertSame('migration_required', $r['compatibility']);
        $this->assertSame('1.1.0', $r['next_version']);
    }

    public function test_adding_required_check_is_migration_required(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.2.0',
            'current_checks'  => [$this->check('existing')],
            'proposed_checks' => [$this->check('existing'), $this->check('new_req', false, true)],
        ]);
        $this->assertSame('migration_required', $r['compatibility']);
        $this->assertSame('1.3.0', $r['next_version']);
    }

    // ── Breaking changes ──────────────────────────────────────────────────────

    public function test_tightening_optional_to_required_is_breaking(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [$this->check('alpha', false, false)],
            'proposed_checks' => [$this->check('alpha', false, true)],
        ]);
        $this->assertSame('breaking', $r['compatibility']);
        $this->assertSame('2.0.0', $r['next_version']);
    }

    // ── AC3: silent safety removal → breaking ────────────────────────────────

    public function test_silent_safety_removal_is_breaking(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [$this->check('guard', safety: true)],
            'proposed_checks' => [], // guard removed without explicit retirement
        ]);
        $this->assertSame('breaking', $r['compatibility']);
        $this->assertStringContainsString('VIOLATION', $r['migration_notes'][0]);
        $this->assertStringContainsString('guard', $r['migration_notes'][0]);
    }

    public function test_explicit_safety_retirement_with_migration_proof_is_migration_required(): void
    {
        $r = $this->versioner()->version([
            'current_version'      => '1.0.0',
            'current_checks'       => [$this->check('guard', safety: true)],
            'proposed_checks'      => [],
            'explicit_retirements' => ['guard'],
            'rollout_evidence'     => ['migration-guide-v2.md', 'test_guard_replaced_by_new_gate'],
        ]);
        $this->assertSame('migration_required', $r['compatibility']);
        $this->assertContains('guard', $r['retired_checks']);
        $this->assertNotEmpty($r['migration_notes']);
    }

    public function test_explicit_safety_retirement_without_migration_proof_is_breaking(): void
    {
        $r = $this->versioner()->version([
            'current_version'      => '1.0.0',
            'current_checks'       => [$this->check('guard', safety: true)],
            'proposed_checks'      => [],
            'explicit_retirements' => ['guard'],
            // no rollout_evidence → breaking
        ]);
        $this->assertSame('breaking', $r['compatibility']);
        $this->assertStringContainsString('VIOLATION', $r['migration_notes'][0]);
    }

    public function test_silent_safety_removal_overrides_other_compatible_changes(): void
    {
        // Even if other changes are compatible, AC3 violation forces breaking.
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [
                $this->check('guard', safety: true),
                $this->check('opt', false, false),
            ],
            'proposed_checks' => [$this->check('opt', false, false)],
        ]);
        $this->assertSame('breaking', $r['compatibility']);
    }

    // ── Explicit retirement of non-safety check ───────────────────────────────

    public function test_explicit_non_safety_retirement_tracked(): void
    {
        $r = $this->versioner()->version([
            'current_version'     => '1.0.0',
            'current_checks'      => [$this->check('legacy')],
            'proposed_checks'     => [],
            'explicit_retirements' => ['legacy'],
        ]);
        $this->assertContains('legacy', $r['retired_checks']);
        $this->assertSame('migration_required', $r['compatibility']);
    }

    // ── Version bumping ───────────────────────────────────────────────────────

    public function test_patch_bump_increments_patch(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '3.7.9',
            'current_checks'  => [],
            'proposed_checks' => [$this->check('new', false, false)],
        ]);
        $this->assertSame('3.7.10', $r['next_version']);
    }

    public function test_minor_bump_resets_patch(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.4.8',
            'current_checks'  => [$this->check('old')],
            'proposed_checks' => [],
        ]);
        $this->assertSame('1.5.0', $r['next_version']);
    }

    public function test_major_bump_resets_minor_and_patch(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '2.9.3',
            'current_checks'  => [$this->check('guard', safety: true)],
            'proposed_checks' => [],
        ]);
        $this->assertSame('3.0.0', $r['next_version']);
    }

    // ── rollout_guidance ──────────────────────────────────────────────────────

    public function test_rollout_guidance_mentions_coordinated_for_breaking(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [$this->check('guard', safety: true)],
            'proposed_checks' => [],
        ]);
        $this->assertStringContainsString('coordinated', strtolower($r['rollout_guidance']));
    }

    public function test_rollout_guidance_mentions_freely_for_compatible(): void
    {
        $r = $this->versioner()->version([
            'current_version' => '1.0.0',
            'current_checks'  => [],
            'proposed_checks' => [$this->check('new', false, false)],
        ]);
        $this->assertStringContainsString('freely', strtolower($r['rollout_guidance']));
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'current_version' => '1.2.3',
            'current_checks'  => [$this->check('alpha'), $this->check('guard', true)],
            'proposed_checks' => [$this->check('alpha'), $this->check('guard', true), $this->check('new', false, false)],
        ];
        $a = $this->versioner()->version($facts);
        $b = $this->versioner()->version($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
