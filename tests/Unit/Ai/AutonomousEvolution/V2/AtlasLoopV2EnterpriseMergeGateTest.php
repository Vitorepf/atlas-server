<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiRepoMergeAuthority;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2EnterpriseMergeGate;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use Tests\TestCase;

final class AtlasLoopV2EnterpriseMergeGateTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai.loop.auto_merge_to_main', true);
        config()->set('atlas.ai.loop.multi_repo.enabled', false);
        config()->set('atlas.ai.loop.multi_repo.allowed_repos', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_unknown_scope_denies_fail_closed(): void
    {
        [$gate] = $this->gate([]);

        $result = $gate->authorize('missing', $this->safeProposal());

        $this->assertFalse($result['allowed']);
        $this->assertSame('unknown_scope', $result['deny_reason']);
        $this->assertSame('not_evaluated', $result['authority_scope']);
        $this->assertSame('not_calculated', $result['blast_tier']);
    }

    public function test_multi_repo_authority_denial_wins_before_blast(): void
    {
        config()->set('atlas.ai.loop.auto_merge_to_main', false);
        [$gate] = $this->gate([$this->scope('home')]);

        $result = $gate->authorize('home', $this->criticalProposal());

        $this->assertFalse($result['allowed']);
        $this->assertSame('multi_repo_denied:home_repo_auto_merge_disabled', $result['deny_reason']);
        $this->assertSame('home', $result['authority_scope']);
        $this->assertSame('not_calculated', $result['blast_tier']);
    }

    public function test_critical_blast_denies_low_risk_scope(): void
    {
        [$gate] = $this->gate([$this->scope('home', riskTier: 'low')]);

        $result = $gate->authorize('home', $this->criticalProposal());

        $this->assertFalse($result['allowed']);
        $this->assertSame('blast_radius_above_scope_tier', $result['deny_reason']);
        $this->assertSame('critical', $result['blast_tier']);
    }

    public function test_wide_blast_requires_operator_approval(): void
    {
        [$gate] = $this->gate([$this->scope('home', riskTier: 'high')]);

        $result = $gate->authorize('home', $this->wideProposal());

        $this->assertFalse($result['allowed']);
        $this->assertSame('operator_approval_required_for_wide_blast', $result['deny_reason']);
        $this->assertSame('wide', $result['blast_tier']);
    }

    public function test_wide_blast_with_operator_approval_is_allowed(): void
    {
        [$gate] = $this->gate([$this->scope('home', riskTier: 'high')]);

        $result = $gate->authorize('home', $this->wideProposal([
            'approved' => true,
            'operator_id' => 'operator-1',
        ]));

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['deny_reason']);
        $this->assertSame('wide', $result['blast_tier']);
    }

    public function test_safe_blast_with_manifest_and_authority_ok_is_allowed(): void
    {
        [$gate] = $this->gate([$this->scope('home', riskTier: 'low')]);

        $result = $gate->authorize('home', $this->safeProposal());

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['deny_reason']);
        $this->assertSame('safe', $result['blast_tier']);
        $this->assertSame('home', $result['authority_scope']);
    }

    public function test_critical_high_scope_is_not_denied_by_scope_tier_rule(): void
    {
        [$gate] = $this->gate([$this->scope('home', riskTier: 'high')]);

        $result = $gate->authorize('home', $this->criticalProposal());

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['deny_reason']);
        $this->assertSame('critical', $result['blast_tier']);
    }

    public function test_audit_line_hash_matches_journal_tail_line_hash(): void
    {
        [$gate, $journal] = $this->gate([$this->scope('home')]);

        $result = $gate->authorize('home', $this->safeProposal());
        $tail = $journal->tail(1);

        $this->assertSame('atlas.ai.loop_v2.merge_gate.v1', $result['schema_version']);
        $this->assertSame('merge_gate_decision', $tail[0]['event_type']);
        $this->assertSame($tail[0]['line_hash'], $result['audit_line_hash']);
        $this->assertSame('', $tail[0]['payload']['audit_line_hash']);
    }

    /**
     * @param  list<array<string,mixed>>  $scopes
     * @return array{AtlasLoopV2EnterpriseMergeGate,AtlasLoopV2AuditJournal}
     */
    private function gate(array $scopes): array
    {
        $journal = new AtlasLoopV2AuditJournal($this->tmpPath(), static fn (): string => '2026-06-24T17:30:00+00:00');

        return [
            new AtlasLoopV2EnterpriseMergeGate(
                new AtlasLoopV2ScopeManifestRegistry($scopes),
                new AtlasLoopMultiRepoMergeAuthority,
                new AtlasLoopV2BlastRadiusCalculator,
                $journal,
            ),
            $journal,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scope(string $id, string $riskTier = 'low'): array
    {
        return [
            'id' => $id,
            'repo_root_absolute' => base_path(),
            'discovery_roots' => ['app/Services/Ai/AutonomousEvolution'],
            'frozen_safety_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'territory_name' => 'Atlas '.$id,
            'max_parallel_workers' => 1,
            'risk_tier' => $riskTier,
            'promotion_required_certified_leaps' => 3,
        ];
    }

    /**
     * @param  array<string,mixed>  $approval
     * @return array<string,mixed>
     */
    private function safeProposal(array $approval = []): array
    {
        return [
            'changed_files' => ['app/Services/Ai/AutonomousEvolution/Foo.php'],
            'import_graph' => [],
            'critical_paths' => ['config/atlas.php'],
            'operator_approval' => $approval,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function criticalProposal(): array
    {
        return [
            'changed_files' => ['config/atlas.php'],
            'import_graph' => [],
            'critical_paths' => ['config/atlas.php'],
            'operator_approval' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $approval
     * @return array<string,mixed>
     */
    private function wideProposal(array $approval = []): array
    {
        return [
            'changed_files' => array_map(
                static fn (int $i): string => 'app/Services/Ai/AutonomousEvolution/Wide'.$i.'.php',
                range(1, 25),
            ),
            'import_graph' => [],
            'critical_paths' => ['config/atlas.php'],
            'operator_approval' => $approval,
        ];
    }

    private function tmpPath(): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-v2-enterprise-merge-gate-'.uniqid('', true).'.ndjson';
        $this->paths[] = $path;

        return $path;
    }
}
