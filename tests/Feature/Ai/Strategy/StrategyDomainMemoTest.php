<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\StrategyDomainException;
use App\Services\Ai\Strategy\StrategyMemoService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainMemoTest extends TestCase
{
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_memo_requires_decision_rationale_next_actions(): void
    {
        /** @var StrategyMemoService $svc */
        $svc = app(StrategyMemoService::class);
        $memo = $svc->create([
            'memo_kind' => StrategyMemoService::KIND_VENTURE,
            'title' => 'Decision: launch Atlas Strategy Runtime',
            'decision' => 'scale',
            'rationale' => ['evidence_pack_attached', 'experiment_decided'],
            'next_actions' => [['action' => 'wire_control_plane', 'owner' => 'atlas-ai']],
            'status' => StrategyMemoService::STATUS_DECIDED,
        ]);
        $this->assertNotEmpty($memo->memo_hash);
        $this->assertSame(StrategyMemoService::STATUS_DECIDED, $memo->status);
    }

    public function test_memo_rejects_missing_next_actions(): void
    {
        /** @var StrategyMemoService $svc */
        $svc = app(StrategyMemoService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'memo_kind' => StrategyMemoService::KIND_VENTURE,
            'title' => 'Missing next actions',
            'decision' => 'scale',
            'rationale' => ['x'],
            'next_actions' => [],
        ]);
    }

    public function test_memo_rejects_invalid_kind(): void
    {
        /** @var StrategyMemoService $svc */
        $svc = app(StrategyMemoService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'memo_kind' => 'random-kind',
            'title' => 'invalid',
            'decision' => 'scale',
            'rationale' => ['x'],
            'next_actions' => [['action' => 'x']],
        ]);
    }
}
