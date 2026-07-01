<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use PHPUnit\Framework\TestCase;

final class AtlasVerificationCourtFalseGreenDetectorTest extends TestCase
{
    private AtlasVerificationCourtFalseGreenDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AtlasVerificationCourtFalseGreenDetector();
    }

    // AC: replay with passed=true but missing output_hash → blocked with replay_output_hash_missing
    public function test_passed_but_missing_output_hash_is_blocked(): void
    {
        $result = $this->detector->detect([
            'passed' => true,
            'planned_commands' => [
                ['command_id' => 'cmd-1', 'output_hash' => 'hash-aaa'],
            ],
            'replay_results' => [
                ['command_id' => 'cmd-1', 'exit_code' => 0, 'output_hash' => null],
            ],
        ]);

        $this->assertNotSame('passed', $result['verdict']);
        $this->assertTrue(
            count(array_filter($result['reasons'], fn ($r) => str_contains($r, 'replay_output_hash_missing'))) > 0,
            'must include replay_output_hash_missing reason'
        );
    }

    public function test_hash_mismatch_when_passed_is_failed(): void
    {
        $result = $this->detector->detect([
            'passed' => true,
            'planned_commands' => [
                ['command_id' => 'cmd-1', 'output_hash' => 'hash-aaa'],
            ],
            'replay_results' => [
                ['command_id' => 'cmd-1', 'exit_code' => 0, 'output_hash' => 'hash-bbb'],
            ],
        ]);

        $this->assertSame('failed', $result['verdict']);
    }

    public function test_all_hashes_match_passes(): void
    {
        $result = $this->detector->detect([
            'passed' => true,
            'planned_commands' => [
                ['command_id' => 'cmd-1', 'output_hash' => 'hash-aaa'],
                ['command_id' => 'cmd-2', 'output_hash' => 'hash-bbb'],
            ],
            'replay_results' => [
                ['command_id' => 'cmd-1', 'exit_code' => 0, 'output_hash' => 'hash-aaa'],
                ['command_id' => 'cmd-2', 'exit_code' => 0, 'output_hash' => 'hash-bbb'],
            ],
        ]);

        $this->assertSame('passed', $result['verdict']);
    }

    public function test_replay_missing_command_is_blocked(): void
    {
        $result = $this->detector->detect([
            'passed' => true,
            'planned_commands' => [
                ['command_id' => 'cmd-1', 'output_hash' => 'hash-aaa'],
                ['command_id' => 'cmd-2', 'output_hash' => 'hash-bbb'],
            ],
            'replay_results' => [
                ['command_id' => 'cmd-1', 'exit_code' => 0, 'output_hash' => 'hash-aaa'],
                // cmd-2 missing
            ],
        ]);

        $this->assertNotSame('passed', $result['verdict']);
        $this->assertTrue(
            count(array_filter($result['reasons'], fn ($r) => str_contains($r, 'replay_missing:cmd-2'))) > 0
        );
    }

    public function test_empty_planned_commands_passes(): void
    {
        $result = $this->detector->detect([
            'passed' => true,
            'planned_commands' => [],
            'replay_results' => [],
        ]);

        $this->assertSame('passed', $result['verdict']);
    }
}
