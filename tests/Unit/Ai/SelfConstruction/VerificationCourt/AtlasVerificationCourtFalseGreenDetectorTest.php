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

    // ── Proof specificity: vague green claims without command and target path ──

    public function test_vague_green_claim_without_command_and_target_path_returns_failed(): void
    {
        // A green claim with no replay plan (no commands) and no evidence contract acceptance
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => false],
            'replay_plan_result' => ['plan_status' => 'not_ready', 'commands' => [], 'blockers' => ['no_commands']],
            'replay_outcomes' => [],
            'changed_files' => [],
            'allowed_files' => [],
        ]);

        $this->assertNotSame('passed', $result['verdict']);
        $this->assertTrue(
            in_array('evidence_contract_not_accepted', $result['reasons'], true),
            'must reject when evidence contract not accepted'
        );
    }

    public function test_green_claim_with_no_replay_outcomes_is_blocked(): void
    {
        // Claim has planned commands but no outcomes — vague green
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'commands' => [
                    ['id' => 'cmd-1', 'name' => 'phpunit'],
                ],
                'blockers' => [],
            ],
            'replay_outcomes' => [],
            'changed_files' => ['app/Service.php'],
            'allowed_files' => ['app/Service.php'],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue(
            in_array('replay_missing_for:cmd-1', $result['reasons'], true),
            'must block when replay outcome missing for planned command'
        );
    }

    // ── Stale evidence ──

    public function test_stale_result_evidence_returns_blocked(): void
    {
        // Evidence contract rejected = stale/invalid evidence
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => false],
            'replay_plan_result' => ['plan_status' => 'ready', 'commands' => [], 'blockers' => []],
            'replay_outcomes' => [],
            'changed_files' => [],
            'allowed_files' => [],
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue(
            in_array('evidence_contract_not_accepted', $result['reasons'], true),
            'must block when evidence is stale/rejected'
        );
    }

    // ── Fresh command, target path, result and scope proof pass ──

    public function test_fresh_command_target_path_result_and_scope_proof_pass(): void
    {
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'commands' => [
                    ['id' => 'cmd-1', 'name' => 'php artisan test --filter=MyTest'],
                ],
                'blockers' => [],
            ],
            'replay_outcomes' => [
                [
                    'command_id' => 'cmd-1',
                    'name' => 'php artisan test --filter=MyTest',
                    'passed' => true,
                    'output_present' => true,
                ],
            ],
            'changed_files' => ['app/Services/MyService.php'],
            'allowed_files' => ['app/Services/MyService.php'],
        ]);

        $this->assertSame('passed', $result['verdict']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_scope_violation_returns_failed(): void
    {
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => [
                'plan_status' => 'ready',
                'commands' => [
                    ['id' => 'cmd-1', 'name' => 'phpunit'],
                ],
                'blockers' => [],
            ],
            'replay_outcomes' => [
                ['command_id' => 'cmd-1', 'name' => 'phpunit', 'passed' => true, 'output_present' => true],
            ],
            'changed_files' => ['app/Services/MyService.php', 'app/Forbidden.php'],
            'allowed_files' => ['app/Services/MyService.php'],
        ]);

        $this->assertSame('failed', $result['verdict']);
        $this->assertTrue(
            in_array('changed_file_outside_allowed:app/Forbidden.php', $result['reasons'], true),
            'must fail when changed file outside allowed scope'
        );
    }

    public function test_proxy_only_evidence_returns_failed(): void
    {
        $result = $this->detector->detect([
            'evidence_contract_result' => ['accepted' => true],
            'replay_plan_result' => ['plan_status' => 'ready', 'commands' => [], 'blockers' => []],
            'replay_outcomes' => [],
            'changed_files' => [],
            'allowed_files' => [],
            'proxy_only_evidence' => true,
        ]);

        $this->assertSame('failed', $result['verdict']);
        $this->assertTrue(
            in_array('proxy_only_evidence', $result['reasons'], true),
            'must fail when only proxy evidence provided'
        );
    }
}
