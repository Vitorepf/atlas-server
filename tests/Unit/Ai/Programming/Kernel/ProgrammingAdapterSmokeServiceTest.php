<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Kernel;

use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\Kernel\ProgrammingAdapterSmokeService;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit coverage for the factory-critical programming adapter smoke service.
 *
 * Feature suites exercise run() end-to-end; this file guards prompt defaults and
 * the ok-gate composition without spinning up Domain Runtime tables.
 */
final class ProgrammingAdapterSmokeServiceTest extends TestCase
{
    public function test_resolve_prompts_uses_canonical_defaults_when_null(): void
    {
        $prompts = ProgrammingAdapterSmokeService::resolvePrompts();

        $this->assertSame(ProgrammingAdapterSmokeService::DEFAULT_DEV_PROMPT, $prompts['dev']);
        $this->assertSame(ProgrammingAdapterSmokeService::DEFAULT_FORGE_PROMPT, $prompts['forge']);
    }

    public function test_resolve_prompts_preserves_explicit_overrides(): void
    {
        $prompts = ProgrammingAdapterSmokeService::resolvePrompts('custom dev prompt', 'custom forge prompt');

        $this->assertSame('custom dev prompt', $prompts['dev']);
        $this->assertSame('custom forge prompt', $prompts['forge']);
    }

    public function test_is_smoke_successful_requires_completed_dev_certification_and_forge_handoff(): void
    {
        $this->assertTrue(ProgrammingAdapterSmokeService::isSmokeSuccessful(
            [
                'mission_status' => MissionLifecycleService::STATUS_COMPLETED,
                'certification_status' => 'passed',
            ],
            [
                'handoff_receipt_hash' => str_repeat('a', 64),
            ],
        ));
    }

    public function test_is_smoke_successful_returns_false_when_dev_not_completed(): void
    {
        $this->assertFalse(ProgrammingAdapterSmokeService::isSmokeSuccessful(
            [
                'mission_status' => MissionLifecycleService::STATUS_RUNNING,
                'certification_status' => 'passed',
            ],
            [
                'handoff_receipt_hash' => str_repeat('a', 64),
            ],
        ));
    }

    public function test_is_smoke_successful_returns_false_when_certification_not_passed(): void
    {
        $this->assertFalse(ProgrammingAdapterSmokeService::isSmokeSuccessful(
            [
                'mission_status' => MissionLifecycleService::STATUS_COMPLETED,
                'certification_status' => 'failed',
            ],
            [
                'handoff_receipt_hash' => str_repeat('a', 64),
            ],
        ));
    }

    public function test_is_smoke_successful_returns_false_when_forge_handoff_missing(): void
    {
        $this->assertFalse(ProgrammingAdapterSmokeService::isSmokeSuccessful(
            [
                'mission_status' => MissionLifecycleService::STATUS_COMPLETED,
                'certification_status' => 'passed',
            ],
            [
                'handoff_receipt_hash' => null,
            ],
        ));
    }
}
