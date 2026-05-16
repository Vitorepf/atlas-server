<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_constructs_with_explicit_values(): void
    {
        $pf = new Preflight(workspaceResolved: true, permissionMode: 'write', writeAllowed: true, operatorExplicit: false);

        $this->assertTrue($pf->workspaceResolved);
        $this->assertSame('write', $pf->permissionMode);
        $this->assertSame('atlas.dev.components.preflight.v1', $pf->schemaVersion());
    }

    public function test_canonical_array_is_sorted(): void
    {
        $pf = new Preflight(workspaceResolved: true, permissionMode: 'read', writeAllowed: false, operatorExplicit: false);
        $this->assertSame(
            ['operator_explicit', 'permission_mode', 'workspace_resolved', 'write_allowed'],
            array_keys($pf->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($pf);
    }

    public function test_round_trip_preserves_hash_and_json(): void
    {
        $pf = new Preflight(workspaceResolved: true, permissionMode: 'danger', writeAllowed: true, operatorExplicit: true);
        $rebuilt = Preflight::fromArray(json_decode($pf->toJson(), true));
        $this->assertHashStable($pf, $rebuilt);
        $this->assertJsonRoundtripStable($pf);
    }

    public function test_hash_changes_when_permission_mode_changes(): void
    {
        $a = new Preflight(workspaceResolved: true, permissionMode: 'read', writeAllowed: false, operatorExplicit: false);
        $b = new Preflight(workspaceResolved: true, permissionMode: 'write', writeAllowed: false, operatorExplicit: false);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
