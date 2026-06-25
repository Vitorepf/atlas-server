<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\ScopeRuntimeFacts;
use App\Services\Ai\AutonomousEvolution\Discovery\ScopeRuntimeFactsWithTestPresence;
use Tests\TestCase;

final class AtlasLoopScopeComprehensionQueryTestPresenceTest extends TestCase
{
    private function fixtureRoot(): string
    {
        return base_path('tests/Fixtures/loop-comprehension-scope');
    }

    private function query(?ScopeRuntimeFacts $runtimeFacts = null): AtlasLoopScopeComprehensionQuery
    {
        return new AtlasLoopScopeComprehensionQuery(
            new AtlasLoopScopeComprehensionModelBuilder,
            $this->fixtureRoot(),
            ['docs_roots' => ['docs']],
            $runtimeFacts,
        );
    }

    public function test_has_test_defaults_to_true_when_no_runtime_facts_source_is_wired(): void
    {
        $q = $this->query(null);
        $q->model('app/Scope');
        $lv = $q->levelVector('App\\Scope\\Caller');
        $this->assertTrue($lv['has_test'], 'safe degradation: backward-compatible true default');

        $transitions = $q->transitionsFor('App\\Scope\\Caller');
        $hasTestTransition = array_filter($transitions, static fn (array $t): bool => $t['from_fact'] === 'has_test');
        $this->assertSame([], array_values($hasTestTransition), 'untested->tested must NOT fire under safe degradation');
    }

    public function test_unit_with_observed_edge_returns_has_test_true_via_runtime_facts(): void
    {
        $facts = new InMemoryRuntimeFacts(testedRelPaths: ['app/Scope/Caller.php' => true]);
        $q = $this->query($facts);
        $q->model('app/Scope');

        $lv = $q->levelVector('App\\Scope\\Caller');
        $this->assertTrue($lv['has_test']);

        $transitions = $q->transitionsFor('App\\Scope\\Caller');
        $hasTestFire = array_filter($transitions, static fn (array $t): bool => $t['from_fact'] === 'has_test');
        $this->assertSame([], array_values($hasTestFire));
    }

    public function test_unit_with_zero_observed_edges_returns_has_test_false_and_fires_transition(): void
    {
        // tested set is empty ⇒ Caller has no observed edge ⇒ has_test=false.
        $facts = new InMemoryRuntimeFacts(testedRelPaths: []);
        $q = $this->query($facts);
        $q->model('app/Scope');

        $lv = $q->levelVector('App\\Scope\\Caller');
        $this->assertFalse($lv['has_test'], 'observed-zero-edges must flip has_test to false');

        $transitions = $q->transitionsFor('App\\Scope\\Caller');
        $hasTestFire = array_values(array_filter($transitions, static fn (array $t): bool => $t['from_fact'] === 'has_test'));
        $this->assertCount(1, $hasTestFire, 'has_test=false MUST fire untested->tested transition exactly once');
        $this->assertSame('untested->tested', $hasTestFire[0]['transition']);
    }
}

/** Minimal in-memory ScopeRuntimeFacts double — never touches the database. */
final class InMemoryRuntimeFacts implements ScopeRuntimeFacts, ScopeRuntimeFactsWithTestPresence
{
    /** @param array<string,true> $testedRelPaths */
    public function __construct(private readonly array $testedRelPaths) {}

    public function hasGateBlock(string $relPath): bool
    {
        return false;
    }

    public function lastMergeClean(string $relPath): bool
    {
        return false;
    }

    public function hasTest(string $relPath): bool
    {
        return isset($this->testedRelPaths[ltrim(str_replace('\\', '/', trim($relPath)), '/')]);
    }
}
