<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane governance dossier is live at the operator surface and emits deterministic facts:
 * the supplied per-gate evidence rolls up into a proof summary and the autonomy state is carried through.
 * A missing --project-id is a usage error.
 */
final class AtlasLoopLaneGovernanceCommandTest extends TestCase
{
    public function test_requires_project_id(): void
    {
        $exit = Artisan::call('atlas:loop:lane-governance', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_rolls_up_gate_evidence_into_proof_summary(): void
    {
        $sections = [
            'admission' => ['admitted' => true],
            'isolation' => ['passed' => true],
            'verification_court' => ['passed' => true],
            'release_governor' => ['passed' => true],
            'receipt_policy' => ['passed' => true],
            'rollback_policy' => ['plan' => 'revert-on-red'],
            'knowledge_sync' => ['ready' => true],
        ];
        $autonomy = ['state' => 'ready', 'blockers' => [], 'holds' => []];

        $exit = Artisan::call('atlas:loop:lane-governance', [
            '--project-id' => 'blackink',
            '--sections' => json_encode($sections),
            '--autonomy-readiness' => json_encode($autonomy),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.multiproject.lane_governance_dossier.v1', $decoded['schema_version']);
        $this->assertSame('blackink', $decoded['project_id']);
        $this->assertSame('ready', $decoded['state']);

        $summary = $decoded['proof_summary'];
        $this->assertTrue($summary['admission_admitted']);
        $this->assertTrue($summary['isolation_passed']);
        $this->assertTrue($summary['verification_court_passed']);
        $this->assertTrue($summary['rollback_policy_present']);
        $this->assertSame('ready', $summary['autonomy_state']);
        $this->assertNotEmpty($decoded['dossier_id']);
    }
}
