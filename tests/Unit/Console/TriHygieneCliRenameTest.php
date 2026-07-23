<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TriHygieneCliRenameTest extends TestCase
{
    public function test_control_plane_aaeos_signatures_exist(): void
    {
        foreach (['atlas:aaeos:run', 'atlas:aaeos:cycle', 'atlas:aaeos:scorecard', 'atlas:aaeos:certify'] as $cmd) {
            $this->assertSame(0, Artisan::call($cmd, ['--help' => true]));
        }
    }

    public function test_renamed_aeos_signatures_exist(): void
    {
        foreach ([
            'atlas:aeos:maturity',
            'atlas:aeos:department-status',
            'atlas:aeos:department-registry',
            'atlas:aeos:choreography-status',
            'atlas:aeos:verify-tests',
            'atlas:learning:proposals-decision',
            'atlas:memory:cognitive-immune-kernel',
            'atlas:aeos:deferred-worker',
            'atlas:review:codex-chain-contract',
            'atlas:acos:simplify-cycle',
        ] as $cmd) {
            $this->assertSame(0, Artisan::call($cmd, ['--help' => true]), $cmd);
        }
    }

    public function test_deprecated_aliases_still_registered(): void
    {
        foreach ([
            'atlas:aaeos:maturity',
            'atlas:aaeos:department-status',
            'atlas:aaeos:learning-proposals',
        ] as $cmd) {
            $this->assertSame(0, Artisan::call($cmd, ['--help' => true]), $cmd);
        }
    }
}
