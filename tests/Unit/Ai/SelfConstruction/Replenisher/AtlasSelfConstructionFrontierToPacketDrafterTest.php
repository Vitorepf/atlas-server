<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionFrontierToPacketDrafter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasSelfConstructionFrontierToPacketDrafter: a normalized frontier produces a packet with
 * allowed_files == frontier.allowed_file_candidates (no invention); duplicate suppression_key drops a
 * second identical entry; empty evidence_obligations ⇒ throws; bare directory in allowed_file_candidates
 * ⇒ throws; suppression_key is byte-stable across calls; depends_on + wave threaded through.
 */
final class AtlasSelfConstructionFrontierToPacketDrafterTest extends TestCase
{
    private function frontier(string $id = 'f-1', array $overrides = []): array
    {
        return array_merge([
            'frontier_id' => $id,
            'owner_organ' => 'TF',
            'target_scope' => 'app/Demo',
            'capability_gap' => 'add Foo',
            'allowed_file_candidates' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_obligations' => ['phpunit green'],
            'evidence_obligations' => ['test_run_id'],
            'risk_class' => 'standard',
        ], $overrides);
    }

    public function test_normalized_frontier_yields_packet_with_allowed_files_unchanged(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier()]);
        $this->assertCount(1, $packets);
        $this->assertSame(['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'], $packets[0]['allowed_files']);
        $this->assertSame(['app/Demo/Foo.php'], $packets[0]['scope_in']);
    }

    public function test_duplicate_suppression_key_drops_second_entry(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-1'),
            $this->frontier('f-1'), // identical ⇒ same suppression_key
        ]);
        $this->assertCount(1, $packets);
    }

    public function test_empty_evidence_obligations_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty_evidence_obligations/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier('f-empty', ['evidence_obligations' => []])]);
    }

    public function test_bare_directory_in_allowed_files_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bare_directory_or_empty_path/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-bare', ['allowed_file_candidates' => ['app/Demo/']]),
        ]);
    }

    public function test_suppression_key_is_byte_stable_across_calls(): void
    {
        $d = new AtlasSelfConstructionFrontierToPacketDrafter;
        $a = $d->draft([$this->frontier()]);
        $b = $d->draft([$this->frontier()]);
        $this->assertSame($a[0]['suppression_key'], $b[0]['suppression_key']);
    }

    public function test_depends_on_and_wave_are_threaded_through(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft(
            [$this->frontier('f-1')],
            waveByFrontierId: ['f-1' => 3],
            dependsByFrontierId: ['f-1' => ['f-prev']],
        );
        $this->assertSame(3, $packets[0]['wave']);
        $this->assertSame(['f-prev'], $packets[0]['depends_on']);
    }

    public function test_autonomy_contract_is_attached_with_atlas_native_owner(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier()]);
        $this->assertSame('atlas_native', $packets[0]['autonomy_contract']['runtime_owner']);
        $this->assertNull($packets[0]['autonomy_contract']['provider_prompt']);
    }

    // ── AC: complete frontier emits test_paths, draft_id, anti_template_rationale ──

    public function test_complete_frontier_emits_test_paths_draft_id_and_anti_template_rationale(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier()]);

        $this->assertSame(['tests/Unit/Demo/FooTest.php'], $packets[0]['test_paths']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $packets[0]['draft_id']);
        $this->assertStringContainsString('add Foo', $packets[0]['anti_template_rationale']);
        $this->assertStringContainsString('TF', $packets[0]['anti_template_rationale']);
    }

    // ── AC: missing test path is rejected ───────────────────────────────────────

    public function test_missing_test_path_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing_test_path/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-no-test', ['allowed_file_candidates' => ['app/Demo/Foo.php']]),
        ]);
    }

    // ── AC: weak frontier (no capability_gap) is rejected ───────────────────────

    public function test_weak_frontier_with_empty_capability_gap_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/weak_frontier_missing_capability_gap/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-weak', ['capability_gap' => '']),
        ]);
    }

    public function test_weak_frontier_with_whitespace_only_capability_gap_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/weak_frontier_missing_capability_gap/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-weak2', ['capability_gap' => '   ']),
        ]);
    }

    // ── AC: dependency propagation influences the deterministic draft_id ────────

    public function test_dependency_propagation_changes_draft_id_but_not_suppression_key(): void
    {
        $d = new AtlasSelfConstructionFrontierToPacketDrafter;
        $withoutDeps = $d->draft([$this->frontier('f-1')]);
        $withDeps = $d->draft([$this->frontier('f-1')], dependsByFrontierId: ['f-1' => ['f-prev']]);

        $this->assertSame($withoutDeps[0]['suppression_key'], $withDeps[0]['suppression_key'], 'content identity unchanged');
        $this->assertNotSame($withoutDeps[0]['draft_id'], $withDeps[0]['draft_id'], 'draft identity changes with placement');
        $this->assertSame(['f-prev'], $withDeps[0]['depends_on']);
    }

    // ── AC: deterministic draft ids ──────────────────────────────────────────────

    public function test_draft_id_is_byte_stable_across_calls(): void
    {
        $d = new AtlasSelfConstructionFrontierToPacketDrafter;
        $a = $d->draft([$this->frontier()], waveByFrontierId: ['f-1' => 2], dependsByFrontierId: ['f-1' => ['f-x', 'f-y']]);
        $b = $d->draft([$this->frontier()], waveByFrontierId: ['f-1' => 2], dependsByFrontierId: ['f-1' => ['f-y', 'f-x']]);

        $this->assertSame($a[0]['draft_id'], $b[0]['draft_id'], 'depends_on order must not affect draft_id');
    }

    public function test_draft_id_differs_when_wave_differs(): void
    {
        $d = new AtlasSelfConstructionFrontierToPacketDrafter;
        $waveOne = $d->draft([$this->frontier()], waveByFrontierId: ['f-1' => 1]);
        $waveTwo = $d->draft([$this->frontier()], waveByFrontierId: ['f-1' => 2]);

        $this->assertNotSame($waveOne[0]['draft_id'], $waveTwo[0]['draft_id']);
    }
}
