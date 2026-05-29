<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderTimeoutRecoveryPathContract;
use Tests\TestCase;

final class LoopChaosCertificationServiceTest extends TestCase
{
    private function service(): LoopChaosCertificationService
    {
        return app(LoopChaosCertificationService::class);
    }

    /**
     * Tests bullet: "every fault yields a safe outcome (block/retry/cleanup/critical),
     * never success". Default profile=pre_24h with no observations models the
     * contractually-mandated safe outcome for every canonical fault.
     */
    public function test_every_fault_yields_a_safe_outcome_never_success(): void
    {
        $report = $this->service()->certify(['profile' => 'pre_24h']);

        $this->assertSame(LoopChaosCertificationService::STATUS_PASS, $report['status']);
        $this->assertSame([], $report['false_success']);
        $this->assertSame($report['fault_count'], $report['handled_safely_count']);
        $this->assertNotSame(0, $report['fault_count']);

        $safe = $report['safe_outcomes'];
        foreach ($report['faults'] as $fault) {
            $this->assertTrue((bool) $fault['ok'], 'fault not handled safely: '.$fault['fault']);
            $this->assertContains(
                $fault['observed_outcome'],
                $safe,
                'fault resolved to a non-safe outcome: '.$fault['fault'],
            );
            $this->assertFalse((bool) $fault['is_false_success']);
            // No fault may resolve to a success/merge outcome.
            $this->assertNotContains($fault['observed_outcome'], ['success', 'merged', 'implemented', 'valid_success']);
        }

        $this->assertTrue($report['gates_24h']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertTrue($report['claim_policy']['fixture_only']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
    }

    /**
     * Tests bullet: "a fixture forcing false_success makes status=fail".
     * A chaos fixture that claims a fault produced a successful/merged cycle must
     * never certify — it is recorded as a false_success and fails the run.
     */
    public function test_fixture_forcing_false_success_fails(): void
    {
        $report = $this->service()->certify([
            'profile' => 'pre_24h',
            'observations' => [
                // The loop falsely claims a merge happened despite a judge repair.
                'judge_repair_required' => 'merged',
            ],
        ]);

        $this->assertSame(LoopChaosCertificationService::STATUS_FAIL, $report['status']);
        $this->assertContains('judge_repair_required', $report['false_success']);
        $this->assertContains('fault_produced_false_success:judge_repair_required', $report['blockers']);
        $this->assertFalse($report['gates_24h']);
        $this->assertSame('stop_chaos_certification_failed', $report['next_action']);

        // The offending fault row is flagged and not ok.
        $row = $this->faultRow($report, 'judge_repair_required');
        $this->assertTrue((bool) $row['is_false_success']);
        $this->assertFalse((bool) $row['ok']);
    }

    /**
     * Tests bullet: "a fixture forcing false_success makes status=fail" — also
     * covers a recovery/filler outcome being dressed as progress under a fault.
     */
    public function test_recovery_progress_under_fault_is_false_success(): void
    {
        $report = $this->service()->certify([
            'profile' => 'pre_24h',
            'observations' => [
                'provider_killed_mid_cycle' => 'recovery_progress',
            ],
        ]);

        $this->assertSame(LoopChaosCertificationService::STATUS_FAIL, $report['status']);
        $this->assertContains('provider_killed_mid_cycle', $report['false_success']);
    }

    /**
     * Tests bullet: "profile pre_24h covers the full fault set". The default
     * pre_24h profile must enumerate every canonical fault; a narrowed fault list
     * under pre_24h flags incomplete coverage and fails.
     */
    public function test_profile_pre_24h_covers_full_fault_set(): void
    {
        $report = $this->service()->certify(['profile' => 'pre_24h']);

        $canonical = array_keys($this->service()->canonicalFaults());
        $covered = $report['profile_coverage']['covered_faults'];

        sort($canonical);
        $sortedCovered = $covered;
        sort($sortedCovered);

        $this->assertSame($canonical, $sortedCovered);
        $this->assertTrue($report['profile_coverage']['complete']);
        $this->assertSame([], $report['profile_coverage']['missing_faults']);
        // The canonical set must include the AP-808 Part 3 fault list.
        $this->assertContains('provider_timeout', $covered);
        $this->assertContains('corrupted_ledger_tail', $covered);
        $this->assertContains('kill_switch_during_execution', $covered);
        $this->assertContains('duplicate_packet_selected_after_block', $covered);
    }

    public function test_pre_24h_with_narrowed_fault_set_is_incomplete_and_fails(): void
    {
        $report = $this->service()->certify([
            'profile' => 'pre_24h',
            'faults' => ['provider_timeout', 'live_lock'],
        ]);

        $this->assertSame(LoopChaosCertificationService::STATUS_FAIL, $report['status']);
        $this->assertFalse($report['profile_coverage']['complete']);
        $this->assertContains('profile_fault_coverage_incomplete', $report['blockers']);
        $this->assertNotEmpty($report['profile_coverage']['missing_faults']);
    }

    /**
     * A fault whose observed outcome is safe but is NOT the mandated outcome must
     * be rejected (cannot silently accept a different resolution path).
     */
    public function test_fault_outcome_mismatch_is_rejected(): void
    {
        $report = $this->service()->certify([
            'profile' => 'pre_24h',
            'observations' => [
                // live_lock must preflight_block; a bounded_retry is safe-shaped but wrong.
                'live_lock' => LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY,
            ],
        ]);

        $this->assertSame(LoopChaosCertificationService::STATUS_FAIL, $report['status']);
        $this->assertContains('fault_outcome_mismatch:live_lock', $report['blockers']);
        $row = $this->faultRow($report, 'live_lock');
        $this->assertFalse((bool) $row['ok']);
        $this->assertFalse((bool) $row['is_false_success']);
    }

    /**
     * An unknown fault has no contractually-mandated outcome and therefore cannot
     * be certified safe — the harness must refuse, never pass on ignorance.
     */
    public function test_unknown_fault_cannot_be_certified(): void
    {
        $report = $this->service()->certify([
            'faults' => ['totally_unknown_fault'],
        ]);

        $this->assertSame(LoopChaosCertificationService::STATUS_FAIL, $report['status']);
        $this->assertContains('unknown_fault_cannot_certify:totally_unknown_fault', $report['blockers']);
        $row = $this->faultRow($report, 'totally_unknown_fault');
        $this->assertNull($row['expected_outcome']);
        $this->assertFalse((bool) $row['ok']);
    }

    /**
     * The list-of-objects shape [{fault, observed_outcome}] composes directly from a
     * chaos test fixture / flight recorder. A safe observed outcome passes that fault.
     */
    public function test_accepts_faults_list_of_objects_shape(): void
    {
        $report = $this->service()->certify([
            'faults' => [
                ['fault' => 'provider_timeout', 'observed_outcome' => 'bounded_retry'],
                ['fault' => 'receipt_write_failure', 'observed_outcome' => 'critical_violation_stop'],
            ],
        ]);

        // Only 2 of the canonical faults are under test -> pre_24h coverage incomplete
        // (so overall fail), but those two individual faults are handled safely.
        $this->assertTrue((bool) $this->faultRow($report, 'provider_timeout')['ok']);
        $this->assertTrue((bool) $this->faultRow($report, 'receipt_write_failure')['ok']);
        $this->assertSame([], $report['false_success']);
    }

    /**
     * Tests bullet: "stable hash". Same input twice => identical report_hash, and
     * the hash excludes volatile fields (timestamp, the hash itself).
     */
    public function test_deterministic_report_hash(): void
    {
        $input = [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'profile' => 'pre_24h',
            'observations' => [
                'provider_timeout' => 'bounded_retry',
                'live_lock' => 'preflight_block',
            ],
        ];

        $first = $this->service()->certify($input);
        $second = $this->service()->certify($input);

        $this->assertSame($first['report_hash'], $second['report_hash']);
        $this->assertSame($first['certification_id'], $second['certification_id']);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        // Volatile fields differ-or-not but must never be part of the hash basis.
        $this->assertArrayHasKey('checked_at', $first);
    }

    public function test_no_input_defaults_to_full_canonical_pass(): void
    {
        $report = $this->service()->certify();

        $this->assertSame(LoopChaosCertificationService::STATUS_PASS, $report['status']);
        $this->assertSame('agentic_engineering_os', $report['area']);
        $this->assertSame('dev_forge', $report['focus']);
        $this->assertSame('pre_24h', $report['profile']);
        $this->assertSame(LoopChaosCertificationService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('AP-808', $report['ap_contract']);
        $this->assertSame('LHL-06', $report['slice_id']);
    }

    public function test_provider_timeout_recovery_path_empty_input_returns_default_contract(): void
    {
        $path = $this->service()->providerTimeoutRecoveryPath([]);

        $this->assertSame(
            ProviderTimeoutRecoveryPathContract::defaults()->toArray(),
            $path,
        );
        $this->assertSame(ProviderTimeoutRecoveryPathContract::SCHEMA, $path['schema_version']);
        $this->assertSame('provider_timeout_recovery_path', $path['scenario_id']);
        $this->assertSame('provider_timeout', $path['fault_id']);
        $this->assertFalse($path['outputs']['recovery_path_valid']);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function faultRow(array $report, string $faultId): array
    {
        foreach ($report['faults'] as $fault) {
            if (($fault['fault'] ?? null) === $faultId) {
                return $fault;
            }
        }

        $this->fail('fault row not found: '.$faultId);
    }
}
