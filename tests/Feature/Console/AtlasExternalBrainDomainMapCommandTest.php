<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * atlas:external-brain:domain-map is a read-only control surface fusing maturity gaps,
 * capability-map drift, evidence freshness backfill, and breakthrough planning into one
 * domain-map priority verdict.
 */
final class AtlasExternalBrainDomainMapCommandTest extends TestCase
{
    private string $factsFile = '';

    protected function tearDown(): void
    {
        if ($this->factsFile !== '' && is_file($this->factsFile)) {
            unlink($this->factsFile);
        }
        parent::tearDown();
    }

    private function exec(array $facts): array
    {
        $this->factsFile = sys_get_temp_dir().'/atlas_domain_map_facts_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->factsFile, (string) json_encode($facts));

        Artisan::call('atlas:external-brain:domain-map', [
            '--facts-file' => $this->factsFile,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_json_output_contains_all_four_organ_sections(): void
    {
        $output = $this->exec([]);

        $this->assertArrayHasKey('priority_target', $output);
        $this->assertArrayHasKey('maturity_gaps', $output);
        $this->assertArrayHasKey('capability_drift', $output);
        $this->assertArrayHasKey('evidence_backfill', $output);
        $this->assertArrayHasKey('breakthrough_plan', $output);
    }

    public function test_no_facts_file_defaults_to_empty_facts_without_error(): void
    {
        Artisan::call('atlas:external-brain:domain-map', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('priority_target', $decoded);
    }

    public function test_missing_evidence_takes_priority_over_everything_else(): void
    {
        $output = $this->exec([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
            'map_entries' => [
                ['area_id' => 'area-a', 'state' => 'integrated', 'has_completion_evidence' => false],
            ],
        ]);

        $this->assertSame('backfill_evidence_first', $output['priority_target']);
        $this->assertContains('evidence_backfill_required', $output['reasons']);
        $this->assertTrue($output['evidence_backfill']['is_backfill_needed']);
    }

    public function test_high_impact_drift_takes_priority_over_maturity_gap_when_evidence_clean(): void
    {
        $output = $this->exec([
            'map_entries' => [
                ['area_id' => 'area-a', 'state' => 'integrated', 'has_completion_evidence' => false],
            ],
            'rubric' => [
                ['dimension' => 'dim-1', 'leverage' => 0.5, 'required_evidence_signals' => ['sig-1'], 'task_family' => 'fam'],
            ],
        ]);

        $this->assertSame('repair_capability_map_drift_first', $output['priority_target']);
        $this->assertContains('high_impact_capability_drift_present', $output['reasons']);
        $this->assertTrue($output['capability_drift']['has_drift']);
    }

    public function test_clean_evidence_and_drift_falls_back_to_maturity_gap_target(): void
    {
        $output = $this->exec([
            'rubric' => [
                ['dimension' => 'dim-1', 'leverage' => 0.9, 'required_evidence_signals' => ['sig-1'], 'task_family' => 'fam'],
            ],
            'control_plane_snapshot' => ['proven_evidence' => []],
        ]);

        $this->assertSame('target_maturity_gap:dim-1', $output['priority_target']);
        $this->assertSame('dim-1', $output['maturity_gaps']['gaps'][0]['dimension']);
    }

    public function test_fully_clean_domain_map_recommends_normal_origination(): void
    {
        $output = $this->exec([]);

        $this->assertSame('domain_map_clean_continue_normal_origination', $output['priority_target']);
        $this->assertContains('no_gap_or_drift_signal', $output['reasons']);
    }

    public function test_candidate_strategy_padding_is_rejected(): void
    {
        $output = $this->exec([
            'candidate_strategy' => 'padding',
            'escalation_state' => ['wave_yield' => 0.3],
            'backlog_freshness_facts' => [
                'health_snapshot' => ['dry_queue' => true],
                'replenish_urgency' => ['urgency_score' => 0.8],
            ],
        ]);

        $this->assertTrue(
            $output['breakthrough_plan']['padding_rejected'],
            'padding_rejected must be true when candidate_strategy is a padding signal',
        );
        $this->assertSame(
            [],
            $output['breakthrough_plan']['constraint_escape_moves'],
            'padding strategies must produce empty constraint_escape_moves',
        );
    }

    public function test_backlog_freshness_facts_blocks_breakthrough_when_stop_decision(): void
    {
        $output = $this->exec([
            'backlog_freshness_facts' => [
                'health_snapshot' => ['dry_queue' => false],
                'queue_age_histogram' => [
                    'claimable_depth' => 5,
                    'oldest_age_p95_seconds' => 7200,
                    'stale_threshold_seconds' => 3600,
                ],
                'worker_idle_prediction' => ['observed_consumption_count' => 0],
            ],
        ]);

        $this->assertStringStartsWith(
            'backlog_freshness_blocked:',
            $output['breakthrough_plan']['next_mode'],
            'breakthrough next_mode must be blocked when backlog freshness says stop',
        );
        $this->assertSame(
            [],
            $output['breakthrough_plan']['investigations'],
            'no investigations when backlog freshness blocks origination',
        );
    }
}
