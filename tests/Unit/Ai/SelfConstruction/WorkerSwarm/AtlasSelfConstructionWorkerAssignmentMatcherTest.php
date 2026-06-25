<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\WorkerSwarm;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerAssignmentMatcher;
use Tests\TestCase;

final class AtlasSelfConstructionWorkerAssignmentMatcherTest extends TestCase
{
    private const NOW = 2_000_000_000;

    private function task(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-1',
            'required_capabilities' => ['php', 'phpunit'],
            'risk_class' => 'medium',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'required_evidence_kinds' => ['phpunit_green'],
        ];
    }

    private function worker(array $overrides = []): array
    {
        return $overrides + [
            'worker_id' => 'w-alpha',
            'capabilities' => ['php', 'phpunit'],
            'max_risk_class' => 'high',
            'allowed_scope_prefixes' => ['app/', 'tests/'],
            'evidence_kinds_supported' => ['phpunit_green', 'mutation_kills'],
            'readiness' => ['ready' => true, 'freshness_unix' => self::NOW - 60],
        ];
    }

    public function test_eligible_match_emits_matched_reason(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$this->worker()], self::NOW);

        $this->assertSame('pkt-1', $verdict['task_packet_id']);
        $this->assertCount(1, $verdict['eligible']);
        $this->assertSame('w-alpha', $verdict['eligible'][0]['worker_id']);
        $this->assertContains('matched', $verdict['eligible'][0]['reasons']);
        $this->assertSame([], $verdict['ineligible']);
    }

    public function test_risk_mismatch_is_ineligible(): void
    {
        $task = $this->task(['risk_class' => 'critical']);
        $worker = $this->worker(['max_risk_class' => 'medium']);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($task, [$worker], self::NOW);
        $this->assertSame([], $verdict['eligible']);
        $this->assertCount(1, $verdict['ineligible']);
        $this->assertStringContainsString('risk_class_too_high_for_worker', $verdict['ineligible'][0]['reasons'][0]);
    }

    public function test_scope_mismatch_is_ineligible(): void
    {
        $worker = $this->worker(['allowed_scope_prefixes' => ['lib/']]);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$worker], self::NOW);
        $this->assertSame([], $verdict['eligible']);
        $this->assertCount(1, $verdict['ineligible']);
        $reasons = $verdict['ineligible'][0]['reasons'];
        $this->assertStringContainsString('scope_out_of_worker_allowlist', implode('|', $reasons));
    }

    public function test_missing_capability_is_ineligible(): void
    {
        $worker = $this->worker(['capabilities' => ['php']]);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$worker], self::NOW);
        $this->assertSame([], $verdict['eligible']);
        $this->assertStringContainsString('missing_capabilities:phpunit', implode('|', $verdict['ineligible'][0]['reasons']));
    }

    public function test_missing_evidence_kind_is_ineligible(): void
    {
        $worker = $this->worker(['evidence_kinds_supported' => ['mutation_kills']]);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$worker], self::NOW);
        $this->assertStringContainsString('missing_evidence_kinds:phpunit_green', implode('|', $verdict['ineligible'][0]['reasons']));
    }

    public function test_stale_worker_freshness_is_ineligible(): void
    {
        $worker = $this->worker(['readiness' => ['ready' => true, 'freshness_unix' => self::NOW - 10000]]);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$worker], self::NOW, freshnessWindowSeconds: 60);
        $this->assertContains('worker_readiness_stale', $verdict['ineligible'][0]['reasons']);
    }

    public function test_not_ready_worker_is_ineligible(): void
    {
        $worker = $this->worker(['readiness' => ['ready' => false]]);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$worker], self::NOW);
        $this->assertContains('worker_not_ready', $verdict['ineligible'][0]['reasons']);
    }

    public function test_deterministic_tie_ordering_by_worker_id(): void
    {
        $w1 = $this->worker(['worker_id' => 'w-charlie']);
        $w2 = $this->worker(['worker_id' => 'w-alpha']);
        $w3 = $this->worker(['worker_id' => 'w-bravo']);

        $verdict = (new AtlasSelfConstructionWorkerAssignmentMatcher)->match($this->task(), [$w1, $w2, $w3], self::NOW);
        $this->assertSame(['w-alpha', 'w-bravo', 'w-charlie'], array_column($verdict['eligible'], 'worker_id'));
    }
}
