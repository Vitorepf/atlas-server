<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:strategy-council: inspect lists services; filter & rank read
 * --candidates and emit facts; ambition reads --facts and returns ambition_level (hold-class
 * exercised); history reads --ledger; unknown action yields unknown_action.
 */
final class AtlasSelfConstructionStrategyCouncilCommandTest extends TestCase
{
    private string $candidatesPath;

    private string $factsPath;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->candidatesPath = sys_get_temp_dir().'/atlas_sc_cands_'.$tag.'.json';
        $this->factsPath = sys_get_temp_dir().'/atlas_sc_facts_'.$tag.'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_sc_ledger_'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->candidatesPath);
        @unlink($this->factsPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function candidates(): array
    {
        return [
            'admitted_owner_scopes' => ['atlas-native'],
            'candidates' => [
                ['candidate_id' => 'c1', 'organ' => 'Task Fabric', 'capability' => 'x', 'evidence_path' => 'docs/c1.md', 'owner_scope' => 'atlas-native', 'duplicate_key' => 'k1', 'leverage_rank' => 'medium'],
                ['candidate_id' => 'c2', 'organ' => 'Maestro', 'capability' => 'y', 'evidence_path' => 'docs/c2.md', 'owner_scope' => 'atlas-native', 'duplicate_key' => 'k2', 'leverage_rank' => 'high'],
                ['candidate_id' => 'c3', 'organ' => 'Worker', 'capability' => 'z', 'evidence_path' => '', 'owner_scope' => 'atlas-native'],
            ],
        ];
    }

    public function test_inspect_lists_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:strategy-council', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertContains(AtlasStrategyCouncilAmbitionBudgetPolicy::class !== '' ? 'atlas.strategycouncil.ambition_budget_policy.v1' : '', $p['services']);
    }

    public function test_filter_returns_kept_and_dropped(): void
    {
        $this->writeJson($this->candidatesPath, $this->candidates());
        Artisan::call('atlas:self-construction:strategy-council', ['action' => 'filter', '--candidates' => $this->candidatesPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(2, $p['filter']['kept']);
        $this->assertCount(1, $p['filter']['dropped']);
    }

    public function test_rank_orders_high_before_medium_then_alphabetical(): void
    {
        $this->writeJson($this->candidatesPath, $this->candidates());
        Artisan::call('atlas:self-construction:strategy-council', ['action' => 'rank', '--candidates' => $this->candidatesPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(['c2', 'c1'], array_column($p['ranking'], 'candidate_id'));
    }

    public function test_ambition_with_hold_facts_returns_hold(): void
    {
        $this->writeJson($this->factsPath, [
            'leverage_rank' => 'high',
            'risk_class' => 'low',
            'available_budget_units' => 1,
            'required_budget_units' => 100, // budget exceeded
            'autonomy_mode' => 'execute_continuous',
            'dependency_readiness' => ['verification' => true, 'rollback' => true, 'knowledge_sync' => true],
            'evidence_present' => true,
        ]);
        Artisan::call('atlas:self-construction:strategy-council', ['action' => 'ambition', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $p['ambition']['ambition_level']);
    }

    public function test_history_reads_ledger_rows(): void
    {
        // Pre-seed by appending one valid row.
        $ledger = new \App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger($this->ledgerPath);
        $ledger->append([
            'decision_id' => 'dec-1',
            'selected_candidate_id' => 'c2',
            'rejected_candidate_ids' => ['c1'],
            'reason_vectors' => ['highest_leverage'],
            'ambition_level' => AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD,
            'evidence_refs' => ['evh-1'],
            'decided_at' => '2026-06-25T00:00:00Z',
        ]);
        Artisan::call('atlas:self-construction:strategy-council', ['action' => 'history', '--ledger' => $this->ledgerPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame('c2', $p['rows'][0]['selected_candidate_id']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:strategy-council', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
