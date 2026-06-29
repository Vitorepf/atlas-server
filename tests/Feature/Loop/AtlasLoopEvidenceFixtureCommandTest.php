<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the operator-evidence-receipt fixture lab is live at the operator surface and emits a deterministic,
 * strictly read-only fixture: it carries the receipt diagnostics, synthetic catalog and anti-cheat policy,
 * persists nothing, and every non-execution guarantee is OFF.
 */
final class AtlasLoopEvidenceFixtureCommandTest extends TestCase
{
    public function test_builds_a_read_only_evidence_fixture(): void
    {
        $exit = Artisan::call('atlas:loop:evidence-fixture', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.operator_evidence_receipt_fixture_lab.v1', $decoded['schema_version']);
        $this->assertSame('read_only_operator_evidence_receipt_fixture_lab', $decoded['mode']);
        $this->assertSame('available', $decoded['status']);
        $this->assertFalse($decoded['persistence_allowed_here']);

        $this->assertTrue($decoded['non_execution_guarantees']['does_not_persist']);
        $this->assertTrue($decoded['anti_cheat_policy']['test_fixtures_are_not_operator_evidence']);
        $this->assertArrayHasKey('synthetic_fixture_catalog', $decoded);
        $this->assertArrayHasKey('receipt_diagnostics', $decoded);
    }
}
