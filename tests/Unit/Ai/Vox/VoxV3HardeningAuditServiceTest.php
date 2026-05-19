<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the audit-aggregation rules. We don't touch the
 * service end-to-end (that's the feature test). These tests pin the
 * aggregation semantics so a refactor cannot silently flip the
 * "unknown means warn" rule into "unknown means pass".
 */
final class VoxV3HardeningAuditServiceTest extends TestCase
{
    public function test_check_names_are_stable_and_cover_the_twelve_required_invariants(): void
    {
        $expected = [
            'no_raw_audio_persisted',
            'no_confirmation_bypass',
            'no_destructive_action_without_receipt',
            'terminal_execute_not_supported',
            'voice_realtime_untouched',
            'mobile_untouched',
            'provider_api_not_added',
            'confirmation_token_not_in_ledger',
            'r4_literal_required',
            'governed_execute_requires_receipt',
            'terminal_propose_command_executed_false',
            'v4_not_started',
        ];

        $this->assertSame($expected, VoxV3HardeningAuditService::checkNames());
    }

    public function test_aggregate_returns_pass_only_when_every_check_passes(): void
    {
        $checks = array_map(
            static fn (string $name) => ['name' => $name, 'status' => 'pass'],
            VoxV3HardeningAuditService::checkNames()
        );
        $this->assertSame('pass', VoxV3HardeningAuditService::aggregate($checks));
    }

    public function test_aggregate_returns_warn_when_any_check_is_unknown(): void
    {
        $checks = array_map(
            static fn (string $name) => ['name' => $name, 'status' => 'pass'],
            VoxV3HardeningAuditService::checkNames()
        );
        $checks[0]['status'] = 'unknown';

        $this->assertSame(
            'warn',
            VoxV3HardeningAuditService::aggregate($checks),
            'unknown must never silently become pass — this is the autoengano guard'
        );
    }

    public function test_aggregate_returns_warn_when_any_check_is_warn(): void
    {
        $checks = array_map(
            static fn (string $name) => ['name' => $name, 'status' => 'pass'],
            VoxV3HardeningAuditService::checkNames()
        );
        $checks[3]['status'] = 'warn';

        $this->assertSame('warn', VoxV3HardeningAuditService::aggregate($checks));
    }

    public function test_aggregate_returns_fail_when_any_check_fails_even_with_warns_and_unknowns(): void
    {
        $checks = [
            ['name' => 'a', 'status' => 'pass'],
            ['name' => 'b', 'status' => 'unknown'],
            ['name' => 'c', 'status' => 'warn'],
            ['name' => 'd', 'status' => 'fail'],
        ];

        $this->assertSame('fail', VoxV3HardeningAuditService::aggregate($checks));
    }

    public function test_aggregate_ignores_unknown_status_values(): void
    {
        $checks = [
            ['name' => 'a', 'status' => 'pass'],
            ['name' => 'b', 'status' => 'gibberish'],
        ];

        $this->assertSame('pass', VoxV3HardeningAuditService::aggregate($checks));
    }
}
