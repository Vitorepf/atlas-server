<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogRegenerationEngineService;
use Tests\TestCase;

final class BacklogRegenerationEngineServiceTest extends TestCase
{
    private function service(): BacklogRegenerationEngineService
    {
        return app(BacklogRegenerationEngineService::class);
    }

    /**
     * A populated source bundle: a runtime gap, a blocked-cycle gap, a quality-drift
     * gap (broad/high => packet), plus one filler item that MUST be rejected.
     *
     * @return array<string,mixed>
     */
    private function populatedSources(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-regen-001',
            'runtime_gaps' => [
                [
                    'finding_id' => 'RUNTIME-001',
                    'kind' => 'runtime_gap',
                    'title' => 'Loop control plane misses a transactional checkpoint',
                    'why_it_matters' => 'A crash mid-cycle leaves the ledger inconsistent.',
                    'severity' => 'medium',
                    'evidence_refs' => ['flight_recorder:cycle-42'],
                ],
            ],
            'blocked_cycles' => [
                [
                    'finding_id' => 'BLOCKED-007',
                    'kind' => 'blocked_cycle_gap',
                    'title' => 'Repeated review_locked block on AAEOS-009',
                    'why_it_matters' => 'Seven cycles burned on the same review lock.',
                    'severity' => 'medium',
                    'evidence_refs' => ['post_cycle:AAEOS-009'],
                ],
            ],
            'quality_drift' => [
                [
                    'finding_id' => 'DRIFT-003',
                    'kind' => 'quality_drift_gap',
                    'title' => 'Cycle quality score trending down across the integration lane',
                    'why_it_matters' => 'Mergeable rate dropped 18% over the last window; cross-system fix needed.',
                    'severity' => 'high',
                    'cross_system' => true,
                    'evidence_refs' => ['quality_drift:window-12'],
                ],
            ],
            // FILLER — must be rejected, never counted.
            'evidence_gaps' => [
                [
                    'kind' => 'filler',
                    'is_filler' => true,
                    'title' => 'Filler: add a missing test for an unrelated helper',
                    'why_it_matters' => 'Keeps the loop busy.',
                ],
            ],
        ];
    }

    public function test_gaps_blocked_drift_become_canonical_findings_with_ranked_packets(): void
    {
        $report = $this->service()->regenerate($this->populatedSources());

        $this->assertSame(BacklogRegenerationEngineService::STATUS_OK, $report['status']);
        $this->assertSame('LHL-10', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertSame(BacklogRegenerationEngineService::REPORT_SCHEMA, $report['schema_version']);

        // Three usable signals => three canonical findings; the filler is excluded.
        $this->assertSame(3, $report['regenerated_count']);
        $ids = array_map(static fn (array $f): string => $f['finding_id'], $report['regenerated_findings']);
        $this->assertContains('RUNTIME-001', $ids);
        $this->assertContains('BLOCKED-007', $ids);
        $this->assertContains('DRIFT-003', $ids);

        // Every regenerated finding is canonical-finding-shaped.
        foreach ($report['regenerated_findings'] as $finding) {
            $this->assertArrayHasKey('finding_id', $finding);
            $this->assertArrayHasKey('kind', $finding);
            $this->assertArrayHasKey('severity', $finding);
            $this->assertArrayHasKey('why_it_matters', $finding);
            $this->assertSame('backlog_regeneration', $finding['origin_type']);
        }

        // The broad (high + cross_system) drift finding becomes a bounded packet proposal.
        $this->assertSame(1, $report['proposed_packet_count']);
        $packet = $report['proposed_packets'][0];
        $this->assertSame('DRIFT-003', $packet['parent_finding_id']);
        $this->assertSame('planned', $packet['status']);
        $this->assertTrue($packet['proposal_only']);
        $this->assertFalse($packet['auto_execution_allowed']);
        $this->assertStringNotContainsStringIgnoringCase('implement the whole feature', $packet['objective']);

        // Ranking covers every regenerated finding with sequential ranks.
        $this->assertCount(3, $report['ranking']);
        $this->assertSame(1, $report['ranking'][0]['rank']);
        $this->assertSame([1, 2, 3], array_column($report['ranking'], 'rank'));

        // Honesty: filler captured in rejected_filler, never counted as regeneration.
        $this->assertSame(1, $report['rejected_filler_count']);
        $this->assertFalse($report['claim_policy']['filler_counted_as_work']);
    }

    public function test_filler_item_is_rejected_and_not_counted(): void
    {
        // A source bundle of ONLY filler / recovery must produce zero regeneration.
        $input = [
            'runtime_gaps' => [
                ['kind' => 'filler', 'is_filler' => true, 'title' => 'Filler busywork'],
            ],
            'blocked_cycles' => [
                ['is_starvation_recovery' => true, 'origin_type' => 'starvation_recovery', 'title' => 'Starvation recovery filler'],
            ],
            'evidence_gaps' => [
                ['kind' => 'missing_test', 'strategic_value' => false, 'title' => 'Routine missing test'],
            ],
        ];

        $report = $this->service()->regenerate($input);

        $this->assertSame(BacklogRegenerationEngineService::STATUS_EMPTY, $report['status']);
        $this->assertSame(0, $report['regenerated_count']);
        $this->assertSame([], $report['regenerated_findings']);
        $this->assertSame(3, $report['rejected_filler_count']);

        $reasons = array_map(static fn (array $r): string => $r['rejection_reason'], $report['rejected_filler']);
        $this->assertContains(BacklogRegenerationEngineService::REJECT_FILLER, $reasons);
        $this->assertContains(BacklogRegenerationEngineService::REJECT_STARVATION_RECOVERY, $reasons);

        foreach ($report['rejected_filler'] as $rejected) {
            $this->assertFalse($rejected['counted_as_regeneration'], 'filler must never count as regeneration');
        }
        $this->assertContains('only_filler_or_unusable_signals_present', $report['warnings']);
    }

    public function test_ranking_orders_by_multiplier_safety_executability(): void
    {
        // Three findings with explicit scores so the product ordering is unambiguous.
        $input = [
            'runtime_gaps' => [
                [
                    'finding_id' => 'LOW',
                    'title' => 'Low value gap',
                    'why_it_matters' => 'minor',
                    'multiplier' => 0.2, 'safety' => 0.9, 'executability' => 0.9, // 0.162
                ],
                [
                    'finding_id' => 'HIGH',
                    'title' => 'High value gap',
                    'why_it_matters' => 'major',
                    'multiplier' => 0.9, 'safety' => 0.9, 'executability' => 0.9, // 0.729
                ],
                [
                    'finding_id' => 'MID',
                    'title' => 'Mid value gap',
                    'why_it_matters' => 'medium',
                    'multiplier' => 0.6, 'safety' => 0.8, 'executability' => 0.8, // 0.384
                ],
            ],
        ];

        $report = $this->service()->regenerate($input);

        $order = array_column($report['ranking'], 'finding_id');
        $this->assertSame(['HIGH', 'MID', 'LOW'], $order, 'ranking must be descending by multiplier*safety*executability');

        // rank_score must be the product of the three factors, descending.
        $scores = array_column($report['ranking'], 'rank_score');
        $this->assertSame($scores, array_values(array_reverse(array_reverse($scores))));
        $this->assertGreaterThan($report['ranking'][1]['rank_score'], $report['ranking'][0]['rank_score']);
        $this->assertGreaterThan($report['ranking'][2]['rank_score'], $report['ranking'][1]['rank_score']);
        $this->assertEqualsWithDelta(0.729, $report['ranking'][0]['rank_score'], 0.0001);
    }

    public function test_ranking_breaks_ties_deterministically_by_finding_id(): void
    {
        // Equal scores => stable order by finding_id (so the hash is stable).
        $input = [
            'runtime_gaps' => [
                ['finding_id' => 'ZED', 'title' => 'z', 'why_it_matters' => 'x', 'multiplier' => 0.5, 'safety' => 0.5, 'executability' => 0.5],
                ['finding_id' => 'ALPHA', 'title' => 'a', 'why_it_matters' => 'x', 'multiplier' => 0.5, 'safety' => 0.5, 'executability' => 0.5],
            ],
        ];

        $report = $this->service()->regenerate($input);

        $this->assertSame(['ALPHA', 'ZED'], array_column($report['ranking'], 'finding_id'));
    }

    public function test_empty_sources_yield_empty_status(): void
    {
        $report = $this->service()->regenerate([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'runtime_gaps' => [],
            'blocked_cycles' => [],
        ]);

        $this->assertSame(BacklogRegenerationEngineService::STATUS_EMPTY, $report['status']);
        $this->assertSame(0, $report['regenerated_count']);
        $this->assertSame([], $report['regenerated_findings']);
        $this->assertSame([], $report['ranking']);
        $this->assertSame([], $report['proposed_packets']);
        $this->assertSame('no_regeneration_backlog_empty', $report['next_action']);
    }

    public function test_default_empty_input_does_not_crash_and_is_empty(): void
    {
        $report = $this->service()->regenerate();

        $this->assertSame(BacklogRegenerationEngineService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(BacklogRegenerationEngineService::STATUS_EMPTY, $report['status']);
        $this->assertSame('LHL-10', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertSame(0, $report['regenerated_count']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
    }

    public function test_unusable_signal_without_reason_or_evidence_is_rejected_not_invented(): void
    {
        // A bare title with NO reason, NO evidence, NO source_doc cannot be turned
        // into a finding — it is rejected, never fabricated.
        $report = $this->service()->regenerate([
            'runtime_gaps' => [
                ['title' => '', 'kind' => 'runtime_gap'],
            ],
        ]);

        $this->assertSame(BacklogRegenerationEngineService::STATUS_EMPTY, $report['status']);
        $this->assertSame(0, $report['regenerated_count']);
        $this->assertSame(1, $report['rejected_filler_count']);
        $this->assertSame(
            BacklogRegenerationEngineService::REJECT_MISSING_SIGNAL,
            $report['rejected_filler'][0]['rejection_reason'],
        );
    }

    public function test_pulls_sources_from_nested_bundle_and_via_fixture_seam(): void
    {
        // Nested `sources` bundle is supported.
        $nested = $this->service()->regenerate([
            'sources' => [
                'runtime_gaps' => [
                    ['finding_id' => 'NEST-1', 'title' => 'Nested gap', 'why_it_matters' => 'real'],
                ],
            ],
        ]);
        $this->assertSame(BacklogRegenerationEngineService::STATUS_OK, $nested['status']);
        $this->assertSame(1, $nested['regenerated_count']);

        // The wiring phase passes the whole bundle under `fixture`; it must work too.
        $viaFixture = $this->service()->regenerate(['fixture' => $this->populatedSources()]);
        $this->assertSame(BacklogRegenerationEngineService::STATUS_OK, $viaFixture['status']);
        $this->assertSame(3, $viaFixture['regenerated_count']);
    }

    public function test_duplicate_finding_ids_are_deduped_deterministically(): void
    {
        $input = [
            'runtime_gaps' => [
                ['finding_id' => 'DUP-1', 'title' => 'first', 'why_it_matters' => 'real'],
            ],
            'blocked_cycles' => [
                ['finding_id' => 'DUP-1', 'title' => 'second copy of same id', 'why_it_matters' => 'real'],
            ],
        ];

        $report = $this->service()->regenerate($input);

        $this->assertSame(1, $report['regenerated_count']);
        // First occurrence (runtime_gaps) wins.
        $this->assertSame('first', $report['regenerated_findings'][0]['title']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->populatedSources();

        $first = $this->service()->regenerate($input);
        $second = $this->service()->regenerate($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A different source bundle must hash differently.
        $other = $input;
        $other['runtime_gaps'][0]['why_it_matters'] = 'A materially different reason changes the finding.';
        $o1 = $this->service()->regenerate($other);
        $o2 = $this->service()->regenerate($other);
        $this->assertSame($o1['report_hash'], $o2['report_hash']);
        $this->assertNotSame($first['report_hash'], $o1['report_hash']);
    }
}
