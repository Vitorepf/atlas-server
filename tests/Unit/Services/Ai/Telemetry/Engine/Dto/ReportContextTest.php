<?php

namespace Tests\Unit\Services\Ai\Telemetry\Engine\Dto;

use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Engine F0 — pin the contract of ReportContext.
 *
 * Properties under test:
 *  1. Construction stores values immutably (readonly class).
 *  2. Mode helpers (isReplay, isShadow, shouldEmit) reflect runMode + engineVersion correctly.
 *  3. Default values are safe (live mode, no replayRunId).
 */
class ReportContextTest extends TestCase
{
    public function test_construction_with_defaults_is_live_mode(): void
    {
        $clock = CarbonImmutable::parse('2026-05-01T07:00:00-03:00');
        $start = CarbonImmutable::parse('2026-04-30T00:00:00-03:00');
        $end = CarbonImmutable::parse('2026-04-30T23:59:59-03:00');

        $ctx = new ReportContext(
            clock: $clock,
            windowStart: $start,
            windowEnd: $end,
            timezone: 'America/Sao_Paulo',
            reportType: 'daily',
            engineVersion: 'legacy',
        );

        $this->assertSame('live', $ctx->runMode);
        $this->assertNull($ctx->replayRunId);
        $this->assertTrue($ctx->shouldEmit(),
            'Default live mode must emit so legacy users see no behavior change.');
        $this->assertFalse($ctx->isReplay());
        $this->assertFalse($ctx->isShadow(),
            'engineVersion=legacy AND runMode=live must NOT classify as shadow.');
    }

    public function test_replay_mode_disables_emission(): void
    {
        $ctx = $this->makeContext(runMode: 'replay', replayRunId: 'run-uuid-123');

        $this->assertTrue($ctx->isReplay());
        $this->assertFalse($ctx->shouldEmit(),
            'Replay must NOT create a new inbox item — that would be a duplicate notification.');
        $this->assertSame('run-uuid-123', $ctx->replayRunId);
    }

    public function test_shadow_engine_version_classifies_as_shadow(): void
    {
        $ctx = $this->makeContext(engineVersion: 'shadow');

        $this->assertTrue($ctx->isShadow(),
            'engineVersion=shadow means engine runs but legacy still produces the payload.');
    }

    public function test_dry_run_mode_disables_emission(): void
    {
        $ctx = $this->makeContext(runMode: 'dry_run');

        $this->assertFalse($ctx->shouldEmit(),
            'Dry-run is for command-line preview only — no DB writes, no emission.');
    }

    public function test_engine_version_next_with_live_mode_emits_normally(): void
    {
        $ctx = $this->makeContext(engineVersion: 'next', runMode: 'live');

        $this->assertTrue($ctx->shouldEmit(),
            'engine_version=next is the cutover state — must emit normally.');
        $this->assertFalse($ctx->isShadow(),
            'next + live is the post-cutover state, NOT shadow.');
    }

    public function test_clock_is_immutable_reference(): void
    {
        $clock = CarbonImmutable::parse('2026-05-01T07:00:00Z');
        $ctx = $this->makeContext(clock: $clock);

        $this->assertSame($clock, $ctx->clock,
            'Clock must be the exact instance injected — no mutation, no copy.');
    }

    private function makeContext(
        ?CarbonImmutable $clock = null,
        string $engineVersion = 'legacy',
        string $runMode = 'live',
        ?string $replayRunId = null,
    ): ReportContext {
        $clock ??= CarbonImmutable::parse('2026-05-01T07:00:00Z');

        return new ReportContext(
            clock: $clock,
            windowStart: $clock->startOfDay(),
            windowEnd: $clock->endOfDay(),
            timezone: 'UTC',
            reportType: 'daily',
            engineVersion: $engineVersion,
            runMode: $runMode,
            replayRunId: $replayRunId,
        );
    }
}
