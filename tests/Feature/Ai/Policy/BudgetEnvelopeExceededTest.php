<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\BudgetEnvelopeService;
use App\Services\Ai\Policy\PolicyCanon;
use InvalidArgumentException;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class BudgetEnvelopeExceededTest extends TestCase
{
    use CreatesPolicySafetyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolicySafetyTables();
    }

    protected function tearDown(): void
    {
        $this->dropPolicySafetyTables();
        parent::tearDown();
    }

    public function test_envelope_check_returns_reasons_when_exceeded(): void
    {
        $service = app(BudgetEnvelopeService::class);
        $envelope = $service->open(PolicyCanon::SCOPE_MISSION, 'mission-xyz', [
            'max_cost' => 1.0,
            'max_tokens' => 100,
            'max_tool_calls' => 2,
        ]);

        $reasons = $service->check($envelope, [
            'cost' => 2.0,
            'tokens' => 50,
            'tool_calls' => 5,
        ]);

        $this->assertNotEmpty($reasons);
        $this->assertTrue(
            collect($reasons)->contains(fn ($r) => str_contains((string) $r, 'budget_exceeded:cost')),
            'expected budget_exceeded:cost reason'
        );
        $this->assertTrue(
            collect($reasons)->contains(fn ($r) => str_contains((string) $r, 'budget_exceeded:tool_calls')),
            'expected budget_exceeded:tool_calls reason'
        );
    }

    public function test_consume_throws_when_request_exceeds_envelope(): void
    {
        $service = app(BudgetEnvelopeService::class);
        $envelope = $service->open(PolicyCanon::SCOPE_GLOBAL, null, [
            'max_cost' => 1.0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->consume($envelope, ['cost' => 2.5]);
    }

    public function test_consume_marks_exhausted_when_limit_reached(): void
    {
        $service = app(BudgetEnvelopeService::class);
        $envelope = $service->open(PolicyCanon::SCOPE_GLOBAL, null, [
            'max_cost' => 1.0,
        ]);

        $service->consume($envelope, ['cost' => 1.0]);
        $envelope->refresh();

        $this->assertSame(BudgetEnvelopeService::STATUS_EXHAUSTED, $envelope->status);
    }
}
