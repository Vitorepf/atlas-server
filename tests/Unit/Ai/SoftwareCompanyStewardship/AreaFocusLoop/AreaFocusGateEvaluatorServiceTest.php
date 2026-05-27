<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusGateEvaluatorService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Read-only contract tests for the Area Focus Safety Gate Evaluator (Slice 8,
 * AP-723). All inputs are synthetic; the evaluator performs no IO, so every
 * decision is deterministic.
 */
class AreaFocusGateEvaluatorServiceTest extends TestCase
{
    private function service(): AreaFocusGateEvaluatorService
    {
        return app(AreaFocusGateEvaluatorService::class);
    }

    /**
     * A run context where every gate passes clean.
     *
     * @return array<string,mixed>
     */
    private function passing(): array
    {
        return [
            'area_id' => 'agentic_engineering_os',
            'area_contract' => [
                'area_owner_docs' => ['docs/a.md', 'docs/b.md'],
                'dev_budget' => ['limit' => 100],
                'forge_budget' => ['limit' => 100],
                'wip_limit' => 3,
                'risk_policy' => ['inbox_only_domains' => ['auth', 'billing', 'secrets']],
                'inbox_destination' => 'morning_inbox',
                'repo_scope' => ['repos' => ['atlas-server']],
            ],
            'run' => [
                'owner_docs_present' => ['docs/a.md' => true, 'docs/b.md' => true],
                'budget_used' => ['dev' => 10, 'forge' => 10],
                'wip_used' => 1,
                'requested' => ['secrets' => false, 'merge' => false, 'deploy' => false, 'destructive_change' => false],
                'risk' => ['unresolved_high_risk' => 0, 'sensitive_domains_touched' => []],
                'kill_switch_engaged' => false,
                'evidence_pack' => ['present' => true, 'required' => true, 'complete' => true],
                'operator_inbox' => ['present' => true],
                'target_repos' => ['atlas-server'],
            ],
        ];
    }

    /**
     * Apply nested overrides onto the passing context (one or two levels deep).
     *
     * @param  array<string,mixed>  $overrides  e.g. ['run.requested.merge' => true]
     * @return array<string,mixed>
     */
    private function with(array $overrides): array
    {
        $input = $this->passing();
        foreach ($overrides as $path => $value) {
            $keys = explode('.', $path);
            $ref = &$input;
            foreach ($keys as $i => $k) {
                if ($i === count($keys) - 1) {
                    if ($value === '__unset__') {
                        unset($ref[$k]);
                    } else {
                        $ref[$k] = $value;
                    }
                } else {
                    $ref = &$ref[$k];
                }
            }
            unset($ref);
        }

        return $input;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function statusOf(array $report, string $gate): string
    {
        foreach ($report['gates'] as $g) {
            if ($g['gate'] === $gate) {
                return $g['status'];
            }
        }

        return 'absent';
    }

    public function test_all_gates_pass_yields_allow(): void
    {
        $report = $this->service()->evaluate($this->passing());

        $this->assertSame(AreaFocusGateEvaluatorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('allow', $report['decision']);
        $this->assertSame('read_only', $report['mode']);
        $this->assertSame(12, $report['gate_summary']['total']);
        $this->assertSame(12, $report['gate_summary']['pass']);
        $this->assertSame(0, $report['gate_summary']['block']);
        $this->assertSame([], $report['blocked_when']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
    }

    public function test_envelope_has_all_keys(): void
    {
        $report = $this->service()->evaluate($this->passing());
        foreach (['decision', 'mode', 'area_id', 'gates', 'gate_summary', 'blocked_when', 'warnings', 'required_next_actions', 'max_governed', 'claim_policy', 'report_hash', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing key {$key}");
        }
        $this->assertCount(12, $report['gates']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    #[DataProvider('blockingCases')]
    public function test_each_gate_blocks_on_its_violation(array $overrides, string $gate): void
    {
        $report = $this->service()->evaluate($this->with($overrides));

        $this->assertSame('block', $report['decision'], "expected block for {$gate}");
        $this->assertSame('block', $this->statusOf($report, $gate));
        $blockedGates = array_map(static fn (array $b): string => $b['gate'], $report['blocked_when']);
        $this->assertContains($gate, $blockedGates);
        $this->assertNotEmpty($report['required_next_actions']);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function blockingCases(): array
    {
        return [
            'owner doc missing' => [['run.owner_docs_present' => ['docs/a.md' => true, 'docs/b.md' => false]], 'area_owner_docs_present'],
            'owner doc status absent' => [['run.owner_docs_present' => '__unset__'], 'area_owner_docs_present'],
            'budget exceeded' => [['run.budget_used' => ['dev' => 150, 'forge' => 0]], 'budget_within_limit'],
            'wip exceeded' => [['run.wip_used' => 9], 'wip_within_limit'],
            'unresolved high risk' => [['run.risk' => ['unresolved_high_risk' => 2, 'sensitive_domains_touched' => []]], 'risk_policy_satisfied'],
            'kill switch engaged' => [['run.kill_switch_engaged' => true], 'kill_switch_open'],
            'secrets requested' => [['run.requested' => ['secrets' => true]], 'no_secrets_requested'],
            'merge requested' => [['run.requested' => ['merge' => true]], 'no_merge_requested'],
            'deploy requested' => [['run.requested' => ['deploy' => true]], 'no_deploy_requested'],
            'destructive requested' => [['run.requested' => ['destructive_change' => true]], 'no_destructive_change_requested'],
            'evidence absent' => [['run.evidence_pack' => ['present' => false]], 'evidence_pack_present'],
            'external repo' => [['run.target_repos' => ['blackink']], 'atlas_internal_first'],
        ];
    }

    public function test_operator_inbox_absent_blocks(): void
    {
        $report = $this->service()->evaluate($this->with([
            'run.operator_inbox' => ['present' => false],
            'area_contract.inbox_destination' => '__unset__',
        ]));

        $this->assertSame('block', $report['decision']);
        $this->assertSame('block', $this->statusOf($report, 'operator_inbox_present'));
    }

    public function test_evidence_not_provided_blocks(): void
    {
        $report = $this->service()->evaluate($this->with(['run.evidence_pack' => '__unset__']));

        $this->assertSame('block', $report['decision']);
        $this->assertSame('block', $this->statusOf($report, 'evidence_pack_present'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    #[DataProvider('warningCases')]
    public function test_warnings_are_non_blocking(array $overrides, string $gate): void
    {
        $report = $this->service()->evaluate($this->with($overrides));

        $this->assertSame('warn', $report['decision'], "expected warn (non-blocking) for {$gate}");
        $this->assertSame('warn', $this->statusOf($report, $gate));
        $this->assertSame(0, $report['gate_summary']['block']);
        $warnGates = array_map(static fn (array $w): string => $w['gate'], $report['warnings']);
        $this->assertContains($gate, $warnGates);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function warningCases(): array
    {
        return [
            'budget near limit' => [['run.budget_used' => ['dev' => 85, 'forge' => 10]], 'budget_within_limit'],
            'wip at capacity' => [['run.wip_used' => 3], 'wip_within_limit'],
            'sensitive domain touched' => [['run.risk' => ['unresolved_high_risk' => 0, 'sensitive_domains_touched' => ['auth']]], 'risk_policy_satisfied'],
            'kill switch unreported' => [['run.kill_switch_engaged' => '__unset__'], 'kill_switch_open'],
            'evidence incomplete' => [['run.evidence_pack' => ['present' => true, 'required' => true, 'complete' => false]], 'evidence_pack_present'],
        ];
    }

    public function test_block_dominates_warn(): void
    {
        // A warn (wip at capacity) plus a block (merge) -> overall block.
        $report = $this->service()->evaluate($this->with([
            'run.wip_used' => 3,
            'run.requested' => ['merge' => true],
        ]));

        $this->assertSame('block', $report['decision']);
        $this->assertGreaterThanOrEqual(1, $report['gate_summary']['warn']);
        $this->assertGreaterThanOrEqual(1, $report['gate_summary']['block']);
    }

    public function test_max_governed_is_bounded_by_gates(): void
    {
        $mg = $this->service()->evaluate($this->passing())['max_governed'];

        $this->assertSame('max_governed', $mg['mode']);
        $this->assertFalse($mg['autonomy_unbounded']);
        $this->assertSame(AreaFocusGateEvaluatorService::GATES, $mg['bounded_by']);
    }

    public function test_claim_policy_is_read_only_decides_only(): void
    {
        $policy = $this->service()->evaluate($this->passing())['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertTrue($policy['decides_only']);
        $this->assertFalse($policy['executes_work']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['merges']);
        $this->assertFalse($policy['deploys']);
        $this->assertFalse($policy['accesses_secrets']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertTrue($policy['max_governed_bounded']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['is_new_os']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = $this->with(['run.budget_used' => ['dev' => 85, 'forge' => 20]]);
        $a = $this->service()->evaluate($input);
        $b = $this->service()->evaluate($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['gates'], $b['gates']);
    }

    public function test_gates_are_evaluated_in_canonical_order(): void
    {
        $gates = array_map(static fn (array $g): string => $g['gate'], $this->service()->evaluate($this->passing())['gates']);

        $this->assertSame(AreaFocusGateEvaluatorService::GATES, $gates);
    }
}
