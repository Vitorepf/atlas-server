<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Rivals\Core\CampaignManifest;
use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use App\Services\Ai\Rivals\Core\WorldTrialReadiness;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A hermetic world-trial dry run: drive the claim lifecycle end-to-end on synthetic
 * fixtures through issue -> reject -> revoke, and prove that no production claim survives
 * from synthetic data. This exercises the machinery; it never issues a real claim.
 */
class RivalsWorldTrialDryRunTest extends TestCase
{
    /** @return array<string,mixed> */
    private function syntheticEvidence(): array
    {
        return [
            'adjudication_status' => 'passed', 'claim_level' => 'world_10x_quality_proven',
            'run_state' => 'bundled', 'metric_weights' => ['quality_loss' => 1.0],
            'scope' => ['suite' => 'synthetic', 'mode' => 'dev', 'risk' => 'R3', 'duration' => 'durable_task', 'stack' => 'php_laravel', 'unit_population' => 'synthetic_units'],
            'baseline_hash' => str_repeat('a', 64), 'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64), 'effect' => 0.2, 'ci_low' => 0.08, 'ci_high' => 0.32,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z', 'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change'], 'evidence_refs' => ['rivals://evidence-pack/b'],
        ];
    }

    public function test_dry_trial_runs_issue_reject_revoke_without_a_production_claim(): void
    {
        $events = [];
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('record')->willReturnCallback(function ($type, array $payload) use (&$events) {
            $events[] = $payload['event_name'];

            return null;
        });

        $authority = new RivalsClaimAuthority;

        // REJECT arm: a blocked adjudication must emit claim.evaluated but never claim.issued
        try {
            $authority->issue(array_replace($this->syntheticEvidence(), ['adjudication_status' => 'blocked']), $ledger);
            $this->fail('blocked adjudication must not issue');
        } catch (InvalidArgumentException) {
        }
        $this->assertNotContains('claim.issued', $events, 'a rejected dry attempt must not emit claim.issued');

        // ISSUE + REVOKE arm on synthetic evidence: lifecycle works, identity preserved
        $issued = $authority->issue($this->syntheticEvidence(), $ledger);
        $revoked = $authority->revoke($issued, 'dry_run_teardown', $ledger);
        $this->assertSame($issued['claim_id'], $revoked['claim_id']);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertContains('claim.revoked', $events);

        // READINESS: the synthetic campaign set is never eligible for a production claim
        $specs = [];
        foreach (['R3' => ['u1', 'u2'], 'R4' => ['u3', 'u4'], 'R5' => ['u5', 'u6']] as $risk => $units) {
            $specs[] = [
                'stack' => 'php_laravel', 'risk' => $risk, 'duration' => 'durable_task', 'unit_ids' => $units,
                'power' => 0.95, 'outcome_days' => 30, 'synthetic' => true,
                'contamination_free' => true, 'itt_complete' => true, 'critical_dimensions' => [],
            ];
        }
        $manifest = (new CampaignManifest)->assemble('dev', $specs, ['required_exposure' => 2]);
        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        $this->assertFalse($readiness['eligible_claim']);
        $this->assertFalse($readiness['claim_issued']);
    }
}
