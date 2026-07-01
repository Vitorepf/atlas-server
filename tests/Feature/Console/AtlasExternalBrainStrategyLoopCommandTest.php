<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainStrategyLoopCommandTest extends TestCase
{
    private string $inputPath;

    private string $ledgerPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        if (isset($this->ledgerPath) && is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'strategy_loop_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:strategy-loop', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function readyAmbitionFacts(): array
    {
        return [
            'leverage_rank' => 'high',
            'risk_class' => 'low',
            'available_budget_units' => 10,
            'required_budget_units' => 5,
            'autonomy_mode' => 'execute_guarded',
            'dependency_readiness' => ['verification' => true, 'rollback' => true, 'knowledge_sync' => true],
            'evidence_present' => true,
        ];
    }

    private function validCandidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'organ' => 'cortex',
            'capability_gap' => 3,
            'user_impact' => 2,
            'autonomy_unlock' => 1,
            'waste_reduction' => 0,
            'risk' => 1,
            'evidence_refs' => ['evh-'.$id],
            'dependency_count' => 0,
            'evidence_path' => 'docs/evidence/'.$id.'.md',
            'owner_scope' => 'engineering',
            'duplicate_key' => 'dup-'.$id,
        ], $overrides);
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:strategy-loop');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_winning_candidate_with_standard_ambition_persists_a_decision(): void
    {
        $this->ledgerPath = sys_get_temp_dir().'/atlas_strategy_loop_test_'.bin2hex(random_bytes(6)).'.jsonl';

        $result = $this->callCommand([
            'candidates' => [$this->validCandidate('c1')],
            'ambition_facts' => $this->readyAmbitionFacts(),
            'decided_at' => '2026-06-25T00:00:00Z',
            'ledger_path' => $this->ledgerPath,
        ]);

        $this->assertSame(1, $result['filtered']['kept_count']);
        $this->assertSame('c1', $result['ranked'][0]['candidate_id']);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD, $result['ambition']['ambition_level']);
        $this->assertNull($result['decision_skipped_reason']);
        $this->assertSame(AtlasStrategyCouncilDecisionLedger::STATUS_OK, $result['decision']['status']);
        $this->assertSame('c1', $result['decision']['row']['selected_candidate_id']);
    }

    public function test_proxy_kind_candidate_is_filtered_before_ranking(): void
    {
        $result = $this->callCommand([
            'candidates' => [$this->validCandidate('c1', ['kind' => 'cosmetic_cleanup'])],
            'ambition_facts' => $this->readyAmbitionFacts(),
        ]);

        $this->assertSame(0, $result['filtered']['kept_count']);
        $this->assertSame([], $result['ranked']);
        $this->assertSame('dropped:proxy_only', $result['filtered']['dropped'][0]['drop_reason']);
        $this->assertSame('no_ranked_candidate_survived_filter_and_rank', $result['decision_skipped_reason']);
    }

    public function test_proxy_signals_only_candidate_is_rejected_by_ranker(): void
    {
        $result = $this->callCommand([
            'candidates' => [$this->validCandidate('c1', [
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
                'proxy_signals' => ['novelty', 'task_count'],
            ])],
            'ambition_facts' => $this->readyAmbitionFacts(),
        ]);

        $this->assertSame(1, $result['filtered']['kept_count']);
        $this->assertSame([], $result['ranked']);
        $this->assertCount(1, $result['rejected_by_ranker']);
        $this->assertSame('no_ranked_candidate_survived_filter_and_rank', $result['decision_skipped_reason']);
    }

    public function test_ambition_hold_skips_decision_persistence(): void
    {
        $this->ledgerPath = sys_get_temp_dir().'/atlas_strategy_loop_test_'.bin2hex(random_bytes(6)).'.jsonl';

        $result = $this->callCommand([
            'candidates' => [$this->validCandidate('c1')],
            'ambition_facts' => array_merge($this->readyAmbitionFacts(), ['evidence_present' => false]),
            'decided_at' => '2026-06-25T00:00:00Z',
            'ledger_path' => $this->ledgerPath,
        ]);

        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $result['ambition']['ambition_level']);
        $this->assertSame('ambition_level_hold', $result['decision_skipped_reason']);
        $this->assertNull($result['decision']);
        $this->assertFalse(is_file($this->ledgerPath));
    }

    public function test_higher_leverage_candidate_wins_and_lower_one_is_a_rejected_alternative(): void
    {
        $this->ledgerPath = sys_get_temp_dir().'/atlas_strategy_loop_test_'.bin2hex(random_bytes(6)).'.jsonl';

        $result = $this->callCommand([
            'candidates' => [
                $this->validCandidate('low', ['capability_gap' => 1, 'autonomy_unlock' => 0]),
                $this->validCandidate('high', ['capability_gap' => 5, 'autonomy_unlock' => 3]),
            ],
            'ambition_facts' => $this->readyAmbitionFacts(),
            'decided_at' => '2026-06-25T00:00:00Z',
            'ledger_path' => $this->ledgerPath,
        ]);

        $this->assertSame('high', $result['ranked'][0]['candidate_id']);
        $this->assertSame('high', $result['decision']['row']['selected_candidate_id']);
        $this->assertContains('low', $result['decision']['row']['rejected_candidate_ids']);
    }
}
