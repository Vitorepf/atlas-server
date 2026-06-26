<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Grinder;

use App\Services\Ai\AutonomousEvolution\Grinder\AtlasLoopGrinderTierReasonResolver;
use Tests\TestCase;

class AtlasLoopGrinderTierReasonResolverTest extends TestCase
{
    public function test_result_has_certified_winner_true(): void
    {
        self::assertTrue(AtlasLoopGrinderTierReasonResolver::resultHasCertifiedWinner(['proposals' => [['a' => 1]]]));
    }

    public function test_result_has_certified_winner_empty(): void
    {
        self::assertFalse(AtlasLoopGrinderTierReasonResolver::resultHasCertifiedWinner(['proposals' => []]));
    }

    public function test_result_has_certified_winner_missing(): void
    {
        self::assertFalse(AtlasLoopGrinderTierReasonResolver::resultHasCertifiedWinner([]));
    }

    public function test_tier_reason_certified(): void
    {
        self::assertSame('certified', AtlasLoopGrinderTierReasonResolver::tierReason(['proposals' => [['a' => 1]]]));
    }

    public function test_tier_reason_first_exploration_rejection(): void
    {
        $result = [
            'proposals' => [],
            'explorations' => [
                ['rejected_reasons' => ['', '  ']],
                ['rejected_reasons' => ['', 'syntax error']],
            ],
        ];

        self::assertSame('syntax error', AtlasLoopGrinderTierReasonResolver::tierReason($result));
    }

    public function test_tier_reason_no_winner_when_empty(): void
    {
        self::assertSame('no_winner', AtlasLoopGrinderTierReasonResolver::tierReason([]));
    }

    public function test_certification_rejection_reasons_extracts_lines(): void
    {
        $result = [
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => false, 'level' => 'mutation', 'reasons' => ['mutation_inadequate', 'other']],
                    ['certified' => true, 'level' => 'consumer', 'reasons' => ['certified']],
                ],
            ],
        ];

        $out = AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result);

        self::assertSame(['cert:mutation: mutation_inadequate'], $out);
    }

    public function test_certification_rejection_reasons_handles_missing_cert(): void
    {
        self::assertSame([], AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons([]));
    }

    public function test_certification_rejection_reasons_handles_empty_reasons(): void
    {
        $result = [
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => false, 'level' => 'level1', 'reasons' => []],
                ],
            ],
        ];

        $out = AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result);

        self::assertSame(['cert:level1'], $out);
    }

    public function test_certification_rejection_reasons_uses_only_level(): void
    {
        $result = [
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => false, 'level' => 'mutation'],
                ],
            ],
        ];

        $out = AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result);

        self::assertSame(['cert:mutation'], $out);
    }

    public function test_certification_rejection_reasons_uses_only_first_reason(): void
    {
        $result = [
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => false, 'level' => 'lvl', 'reasons' => ['', 'first', 'second']],
                ],
            ],
        ];

        $out = AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result);

        self::assertSame(['cert:lvl: first'], $out);
    }

    public function test_certification_rejection_reasons_skips_empty_line(): void
    {
        $result = [
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => false, 'level' => '', 'reasons' => ['', '']],
                ],
            ],
        ];

        self::assertSame([], AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result));
    }
}