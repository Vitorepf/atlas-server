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

    public function test_acceptance_and_evidence_fields_are_preserved(): void
    {
        $out = (new AtlasProjectLaneTaskFabricRouter)->route($this->lane(), $this->candidate([
            'acceptance_criteria' => ['A', 'B', 'C'],
            'required_evidence' => ['lint', 'phpunit', 'mutop'],
        ]));

        $this->assertSame(['A', 'B', 'C'], $out['acceptance_criteria']);
        $this->assertSame(['lint', 'phpunit', 'mutop'], $out['required_evidence']);
    }
}
