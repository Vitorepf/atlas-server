<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\OperatorModelDecisionBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Maxn02OperatorModelDecisionBlockTest extends TestCase
{
    #[Test]
    public function do_not_do_rule_excludes_violating_route(): void
    {
        $out = OperatorModelDecisionBlock::apply([
            ['id' => 'shell', 'tool' => 'shell', 'score' => 1.0],
            ['id' => 'structured', 'tool' => 'native_worker', 'score' => 1.0],
        ], [[
            'effect' => 'do_not_do',
            'rule' => ['value' => ['tool' => 'shell'], 'privacy_class' => 'normal', 'provider_safe' => true],
            'priority' => 95,
        ]]);

        $this->assertSame(['structured'], array_column($out['eligible_routes'], 'id'));
        $this->assertSame(['shell'], $out['excluded_route_ids']);
    }

    #[Test]
    public function tool_preference_breaks_ties_between_equivalent_routes(): void
    {
        $out = OperatorModelDecisionBlock::apply([
            ['id' => 'a', 'tool' => 'artisan', 'score' => 1.0],
            ['id' => 'b', 'tool' => 'native_worker', 'score' => 1.0],
        ], [[
            'effect' => 'tool_preference',
            'rule' => ['value' => ['tool' => 'native_worker'], 'privacy_class' => 'normal', 'provider_safe' => true],
            'priority' => 70,
        ]]);

        $this->assertSame('b', $out['recommended_route_id']);
        $this->assertSame('tool_preference', $out['basis']);
    }

    #[Test]
    public function private_policy_is_omitted_for_provider_external_receipts(): void
    {
        $out = OperatorModelDecisionBlock::apply([
            ['id' => 'a', 'tool' => 'shell', 'score' => 1.0],
        ], [[
            'effect' => 'do_not_do',
            'rule' => ['value' => ['tool' => 'shell'], 'privacy_class' => 'private', 'provider_safe' => false],
            'priority' => 95,
        ]], ['provider_external' => true]);

        $this->assertSame([], $out['policies']);
        $this->assertSame(['private_policy_omitted'], $out['omitted']);
        $this->assertSame(['a'], array_column($out['eligible_routes'], 'id'));
    }
}
