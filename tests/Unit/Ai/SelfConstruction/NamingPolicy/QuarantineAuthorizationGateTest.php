<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NamingPolicy;

use App\Services\Ai\SelfConstruction\NamingPolicy\QuarantineAuthorizationGate;
use PHPUnit\Framework\TestCase;

final class QuarantineAuthorizationGateTest extends TestCase
{
    private QuarantineAuthorizationGate $gate;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new QuarantineAuthorizationGate;
        $this->now = new \DateTimeImmutable('2026-05-26T00:00:00Z');
    }

    public function test_authorizes_when_all_three_proofs_pass(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ],
            $this->now,
        );

        $this->assertSame('authorized', $result['decision']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame(90, $result['policy']['min_days_since_last_use']);
        $this->assertSame('atlas.decision_receipt.v2', $result['policy']['required_receipt_schema']);
    }

    public function test_blocks_when_reachability_is_active(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/ActiveFile.php',
            ['reachability' => 'active_runtime', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/ActiveFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('reachability_not_dead', $result['blocking_reasons']);
        $this->assertSame('active_runtime', $result['checks']['reachability_dead']['observed']);
    }

    public function test_blocks_when_last_use_under_90_days(): void
    {
        $recentDate = $this->now->sub(new \DateInterval('P30D'))->format(\DateTimeInterface::ATOM);

        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/RecentFile.php',
            ['reachability' => 'dead', 'last_used_at' => $recentDate],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/RecentFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('last_use_within_90_days', $result['blocking_reasons']);
        $this->assertSame(30, $result['checks']['last_used_over_90_days']['days_since_last_use']);
    }

    public function test_exactly_90_days_passes_last_use_check(): void
    {
        $boundary = $this->now->sub(new \DateInterval('P90D'))->format(\DateTimeInterface::ATOM);

        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/BoundaryFile.php',
            ['reachability' => 'dead', 'last_used_at' => $boundary],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/BoundaryFile.php',
            ],
            $this->now,
        );

        $this->assertSame('authorized', $result['decision']);
        $this->assertTrue($result['checks']['last_used_over_90_days']['passed']);
    }

    public function test_blocks_when_receipt_is_missing(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/NoReceiptFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            null,
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
    }

    public function test_blocks_when_receipt_schema_is_v1(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/V1ReceiptFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v1',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/V1ReceiptFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
        $this->assertStringContainsString('schema_version', $result['checks']['receipt_valid']['reason']);
    }

    public function test_blocks_when_receipt_actor_is_not_operator(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/AgentReceiptFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'agent:some-bot',
                'file' => 'app/Services/Ai/SelfConstruction/AgentReceiptFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
    }

    public function test_blocks_when_receipt_cites_wrong_file(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/TargetFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/DifferentFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
    }

    public function test_blocks_when_path_is_out_of_scope(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Other/FileOutsideSelfConstruction.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Other/FileOutsideSelfConstruction.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('path_out_of_self_construction_scope', $result['blocking_reasons']);
    }

    public function test_blocks_when_last_used_at_is_missing(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/NoLastUseFile.php',
            ['reachability' => 'dead'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/NoLastUseFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('last_use_within_90_days', $result['blocking_reasons']);
        $this->assertNull($result['checks']['last_used_over_90_days']['days_since_last_use']);
    }

    public function test_blocks_when_last_used_at_is_malformed(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/MalformedDateFile.php',
            ['reachability' => 'dead', 'last_used_at' => 'not-a-date'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'operator:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/MalformedDateFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('last_use_within_90_days', $result['blocking_reasons']);
    }

    public function test_blocking_reasons_can_accumulate(): void
    {
        // active + recent + bad receipt + out of scope = 4 blocking reasons
        $result = $this->gate->evaluate(
            'app/Services/Other/ActiveFile.php',
            ['reachability' => 'active_runtime', 'last_used_at' => '2026-05-25T00:00:00Z'],
            null,
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertCount(4, $result['blocking_reasons']);
    }

    public function test_atlas_native_actor_authorizes_when_all_three_proofs_pass(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'atlas_native:self_construction',
                'file' => 'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ],
            $this->now,
        );

        $this->assertSame('authorized', $result['decision']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertTrue($result['checks']['receipt_valid']['passed']);
    }

    public function test_human_actor_receipt_is_blocked(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'human:vitor',
                'file' => 'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
    }

    public function test_provider_actor_receipt_is_blocked(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ['reachability' => 'dead', 'last_used_at' => '2026-01-01T00:00:00Z'],
            [
                'schema_version' => 'atlas.decision_receipt.v2',
                'actor' => 'provider:claude',
                'file' => 'app/Services/Ai/SelfConstruction/SomeDeadFile.php',
            ],
            $this->now,
        );

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('missing_decision_receipt', $result['blocking_reasons']);
    }

    public function test_emits_canonical_schema_version(): void
    {
        $result = $this->gate->evaluate(
            'app/Services/Ai/SelfConstruction/Any.php',
            [],
            null,
            $this->now,
        );

        $this->assertSame('atlas.self_construction.quarantine_authorization.v1', $result['schema_version']);
    }
}
