<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2TerritoryPromotionOrchestrator;
use DomainException;
use Tests\TestCase;

final class AtlasLoopV2TerritoryPromotionOrchestratorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_missing_manifest_throws_domain_exception_and_is_audited(): void
    {
        [$orchestrator, $journal] = $this->orchestrator([]);

        try {
            $orchestrator->evaluate('missing-scope', $this->state());
            $this->fail('Expected missing manifest to throw.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('missing-scope', $e->getMessage());
        }

        $tail = $journal->tail(1);
        $this->assertSame('territory_promotion_decision', $tail[0]['event_type']);
        $this->assertSame('missing-scope', $tail[0]['payload']['scope_id']);
        $this->assertSame(['scope_manifest_missing:missing-scope'], $tail[0]['payload']['violations']);
    }

    public function test_happy_path_uses_manifest_threshold_and_promotes(): void
    {
        [$orchestrator] = $this->orchestrator([
            $this->scope('home', threshold: 3),
        ]);

        $result = $orchestrator->evaluate('home', $this->state(certifiedLeaps: 5));

        $this->assertSame('atlas.ai.loop_v2.territory_promotion.v1', $result['schema_version']);
        $this->assertSame('home', $result['scope_id']);
        $this->assertSame(3, $result['manifest_threshold']);
        $this->assertTrue($result['invariant_holds']);
        $this->assertTrue($result['promotion_rule_met']);
        $this->assertTrue($result['promotable']);
        $this->assertSame([], $result['violations']);
    }

    public function test_certified_leaps_below_manifest_threshold_blocks_promotion(): void
    {
        [$orchestrator] = $this->orchestrator([
            $this->scope('home', threshold: 3),
        ]);

        $result = $orchestrator->evaluate('home', $this->state(certifiedLeaps: 2));

        $this->assertFalse($result['promotable']);
        $this->assertFalse($result['promotion_rule_met']);
        $this->assertTrue($result['invariant_holds']);
        $this->assertSame(['insufficient_certified_leaps:2/3'], $result['violations']);
    }

    public function test_unprotected_discovery_root_fails_ladder_invariant_without_mocking(): void
    {
        [$orchestrator] = $this->orchestrator([
            $this->scope(
                'wide',
                discoveryRoots: ['app/Services/Foo'],
                frozenSafetyFiles: ['app/Services/Other/Judge.php'],
            ),
        ]);

        $result = $orchestrator->evaluate('wide', $this->state(certifiedLeaps: 5));

        $this->assertFalse($result['promotable']);
        $this->assertFalse($result['invariant_holds']);
        $this->assertTrue($result['promotion_rule_met']);
        $this->assertSame(['unprotected_root:app/Services/Foo'], $result['violations']);
    }

    public function test_each_evaluate_appends_matching_journal_payload(): void
    {
        [$orchestrator, $journal] = $this->orchestrator([
            $this->scope('home', threshold: 4),
        ]);

        $first = $orchestrator->evaluate('home', $this->state(certifiedLeaps: 4));
        $second = $orchestrator->evaluate('home', $this->state(certifiedLeaps: 1));
        $tail = $journal->tail(2);

        $this->assertSame('territory_promotion_decision', $tail[0]['event_type']);
        $this->assertSame('territory_promotion_decision', $tail[1]['event_type']);
        $this->assertSame($first, $tail[0]['payload']);
        $this->assertSame($second, $tail[1]['payload']);
        $this->assertSame(4, $second['manifest_threshold']);
        $this->assertSame(['insufficient_certified_leaps:1/4'], $second['violations']);
    }

    /**
     * @param  list<array<string,mixed>>  $scopes
     * @return array{AtlasLoopV2TerritoryPromotionOrchestrator,AtlasLoopV2AuditJournal}
     */
    private function orchestrator(array $scopes): array
    {
        $journal = new AtlasLoopV2AuditJournal($this->tmpPath(), static fn (): string => '2026-06-24T17:20:00+00:00');

        return [
            new AtlasLoopV2TerritoryPromotionOrchestrator(
                new AtlasLoopV2ScopeManifestRegistry($scopes),
                new AtlasLoopTerritoryLadder,
                $journal,
            ),
            $journal,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scope(
        string $id,
        int $threshold = 3,
        array $discoveryRoots = ['app/Services/Ai/AutonomousEvolution'],
        array $frozenSafetyFiles = ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
    ): array {
        return [
            'id' => $id,
            'repo_root_absolute' => '/tmp/atlas/'.$id,
            'discovery_roots' => $discoveryRoots,
            'frozen_safety_files' => $frozenSafetyFiles,
            'territory_name' => 'Atlas '.$id,
            'max_parallel_workers' => 1,
            'risk_tier' => 'low',
            'promotion_required_certified_leaps' => $threshold,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function state(
        int $certifiedLeaps = 5,
        int $redMainInWindow = 0,
        bool $compoundingTrendUp = true,
        int $robustnessCases = 1,
    ): array {
        return [
            'certified_leaps' => $certifiedLeaps,
            'red_main_in_window' => $redMainInWindow,
            'compounding_trend_up' => $compoundingTrendUp,
            'robustness_cases' => $robustnessCases,
        ];
    }

    private function tmpPath(): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-v2-territory-promotion-'.uniqid('', true).'.ndjson';
        $this->paths[] = $path;

        return $path;
    }
}
