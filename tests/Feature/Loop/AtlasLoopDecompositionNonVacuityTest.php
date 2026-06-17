<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use Tests\TestCase;

/**
 * ACDE X4 — the plan validator refuses a self-authored DAG whose nodes all carry the SAME file-agnostic change
 * request (a copy-paste "decomposition"). Default OFF is byte-identical; distinct requests are never flagged.
 */
final class AtlasLoopDecompositionNonVacuityTest extends TestCase
{
    private const VACUOUS_REASON = 'vacuous_decomposition:identical_node_requests';

    private function plan(string $reqA, string $reqB): array
    {
        return [
            'plan_id' => 'p1',
            'nodes' => [
                ['id' => 'n1', 'request' => $reqA, 'target_area' => 'a.php', 'acceptance' => ['commands' => ['php a']]],
                ['id' => 'n2', 'request' => $reqB, 'target_area' => 'b.php', 'acceptance' => ['commands' => ['php b']]],
            ],
        ];
    }

    public function test_off_never_flags_vacuity_byte_identical(): void
    {
        // default OFF — even an identical-request plan carries no vacuity reason
        $r = (new AtlasLoopObraPlanValidator)->validate(
            $this->plan('extract Foo from a.php', 'extract Foo from b.php'),
            ['a.php', 'b.php'],
        );

        $this->assertNotContains(self::VACUOUS_REASON, $r['reasons']);
    }

    public function test_armed_refuses_identical_file_agnostic_requests(): void
    {
        config(['atlas.loop.decomposition_non_vacuity_enabled' => true]);

        // The two requests differ ONLY by the file they name => file-agnostic identical => vacuous.
        $r = (new AtlasLoopObraPlanValidator)->validate(
            $this->plan('extract Foo from a.php', 'extract Foo from b.php'),
            ['a.php', 'b.php'],
        );

        $this->assertContains(self::VACUOUS_REASON, $r['reasons']);
        $this->assertFalse($r['valid']);
    }

    public function test_armed_accepts_genuinely_distinct_requests(): void
    {
        config(['atlas.loop.decomposition_non_vacuity_enabled' => true]);

        $r = (new AtlasLoopObraPlanValidator)->validate(
            $this->plan('extract Foo from a.php', 'rename Bar and inline the loop in b.php'),
            ['a.php', 'b.php'],
        );

        $this->assertNotContains(self::VACUOUS_REASON, $r['reasons'], 'distinct requests are a real decomposition');
    }
}
