<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneTaskFabricRouter;
use RuntimeException;
use Tests\TestCase;

final class AtlasProjectLaneTaskFabricRouterTest extends TestCase
{
    private function lane(): array
    {
        return [
            'project_id' => 'atlas-server',
            'admitted' => true,
            'allowed_scope_roots' => ['/repos/atlas-server/app', '/repos/atlas-server/tests'],
        ];
    }

    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'do-thing',
            'objective' => 'improve foo',
            'allowed_files' => ['/repos/atlas-server/app/Foo.php', '/repos/atlas-server/tests/FooTest.php'],
            'scope_in' => ['/repos/atlas-server/app/Foo.php'],
            'acceptance_criteria' => ['exit 0 on green'],
            'required_evidence' => ['php artisan test'],
        ];
    }

    public function test_valid_routing_prefixes_project_id_and_emits_canonical_envelope(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());

        $this->assertSame('atlas.multiproject.fabric_packet.v1', $out['schema_version']);
        $this->assertSame('atlas-server:do-thing', $out['task_packet_id']);
        $this->assertSame('atlas-server', $out['project_id']);
        $this->assertSame('improve foo', $out['objective']);
        $this->assertSame('shared_local_main_with_scope_lock', $out['workspace_policy']['isolation']);
        $this->assertSame('atlas_native', $out['workspace_policy']['simplicity']);
        foreach (['allowed_files', 'scope_in', 'acceptance_criteria', 'required_evidence'] as $key) {
            $this->assertArrayHasKey($key, $out);
        }
    }

    public function test_outside_root_allowed_files_is_rejected(): void
    {
        $candidate = $this->candidate(['allowed_files' => ['/etc/passwd']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes_lane_scope/');
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $candidate);
    }

    public function test_outside_root_scope_in_is_rejected(): void
    {
        $candidate = $this->candidate(['scope_in' => ['/elsewhere/lib/file.php']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes_lane_scope/');
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $candidate);
    }

    public function test_packet_id_without_project_prefix_gets_prefixed(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate(['task_packet_id' => 'maestro-w001']));
        $this->assertSame('atlas-server:maestro-w001', $out['task_packet_id']);
    }

    public function test_packet_id_already_prefixed_is_preserved_verbatim(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate(['task_packet_id' => 'atlas-server:already-namespaced']));
        $this->assertSame('atlas-server:already-namespaced', $out['task_packet_id']);
    }

    public function test_empty_candidate_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), []);
    }

    public function test_relative_path_sharing_only_prefix_substring_with_scope_leaf_is_rejected(): void
    {
        // scope root leaf is "app"; "apparmor/escape.php" starts with "app" but is NOT under "app/"
        $candidate = $this->candidate(['allowed_files' => ['apparmor/escape.php']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes_lane_scope/');
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $candidate);
    }

    public function test_unadmitted_lane_is_refused(): void
    {
        $lane = array_replace($this->lane(), ['admitted' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lane_not_admitted/');
        (new AtlasProjectLaneTaskFabricRouter)->route($lane, $this->candidate());
    }

    public function test_missing_admitted_flag_defaults_to_refused(): void
    {
        $lane = $this->lane();
        unset($lane['admitted']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lane_not_admitted/');
        (new AtlasProjectLaneTaskFabricRouter)->route($lane, $this->candidate());
    }

    public function test_empty_acceptance_criteria_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/runnable_acceptance_criteria/');
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate(['acceptance_criteria' => []]));
    }

    public function test_empty_required_evidence_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/required_evidence/');
        (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate(['required_evidence' => []]));
    }

    public function test_duplicate_allowed_files_are_normalized_to_unique_sorted(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate([
            'allowed_files' => [
                '/repos/atlas-server/tests/FooTest.php',
                '/repos/atlas-server/app/Foo.php',
                '/repos/atlas-server/app/Foo.php', // duplicate
            ],
            'scope_in' => [
                '/repos/atlas-server/app/Foo.php',
                '/repos/atlas-server/app/Foo.php', // duplicate
            ],
        ]));

        $this->assertSame([
            '/repos/atlas-server/app/Foo.php',
            '/repos/atlas-server/tests/FooTest.php',
        ], $out['allowed_files'], 'duplicates removed, sorted ascending');
        $this->assertSame(['/repos/atlas-server/app/Foo.php'], $out['scope_in'], 'duplicate scope_in removed');
    }

    public function test_acceptance_and_evidence_fields_are_preserved(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate([
            'acceptance_criteria' => ['A', 'B', 'C'],
            'required_evidence' => ['lint', 'phpunit', 'mutop'],
        ]));

        $this->assertSame(['A', 'B', 'C'], $out['acceptance_criteria']);
        $this->assertSame(['lint', 'phpunit', 'mutop'], $out['required_evidence']);
    }

    // ── project_lane_proof_contract ───────────────────────────────────────────

    public function test_routed_packet_carries_proof_contract(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());

        $this->assertArrayHasKey('project_lane_proof_contract', $out);
    }

    public function test_proof_contract_has_all_required_fields(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());
        $pc  = $out['project_lane_proof_contract'];

        foreach (['lane_id', 'isolation_evidence_refs', 'queue_namespace', 'acceptance_command_hints', 'required_evidence', 'cross_project_leak_guard'] as $key) {
            $this->assertArrayHasKey($key, $pc, "proof_contract missing key: {$key}");
        }
    }

    public function test_proof_contract_lane_id_matches_project_id(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());

        $this->assertSame('atlas-server', $out['project_lane_proof_contract']['lane_id']);
    }

    public function test_proof_contract_queue_namespace_is_scoped_to_project(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());

        $this->assertSame('queue:atlas-server', $out['project_lane_proof_contract']['queue_namespace']);
    }

    public function test_proof_contract_isolation_evidence_refs_match_lane_scope_roots(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());
        $pc  = $out['project_lane_proof_contract'];

        $this->assertSame($this->lane()['allowed_scope_roots'], $pc['isolation_evidence_refs']);
    }

    public function test_proof_contract_acceptance_command_hints_mirror_candidate_criteria(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route(
            $this->lane(),
            $this->candidate(['acceptance_criteria' => ['runnable:test A', 'runnable:test B']])
        );

        $this->assertSame(['runnable:test A', 'runnable:test B'], $out['project_lane_proof_contract']['acceptance_command_hints']);
    }

    public function test_proof_contract_required_evidence_mirrors_candidate_evidence(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route(
            $this->lane(),
            $this->candidate(['required_evidence' => ['phpunit_green', 'mutation_kills']])
        );

        $this->assertSame(['phpunit_green', 'mutation_kills'], $out['project_lane_proof_contract']['required_evidence']);
    }

    public function test_proof_contract_cross_project_leak_guard_contains_checked_paths_and_flag(): void
    {
        $out  = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());
        $guard = $out['project_lane_proof_contract']['cross_project_leak_guard'];

        $this->assertArrayHasKey('allowed_scope_roots', $guard);
        $this->assertArrayHasKey('checked_paths', $guard);
        $this->assertTrue($guard['all_paths_within_lane']);
        $this->assertNotEmpty($guard['checked_paths']);
        $this->assertContains('/repos/atlas-server/app/Foo.php', $guard['checked_paths']);
    }

    public function test_proof_contract_preserves_canonical_packet_fields_untouched(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate());

        // These top-level fields must still exist alongside the proof contract.
        foreach (['objective', 'allowed_files', 'scope_in', 'acceptance_criteria', 'required_evidence', 'workspace_policy'] as $key) {
            $this->assertArrayHasKey($key, $out, "top-level field missing: {$key}");
        }
    }
}
