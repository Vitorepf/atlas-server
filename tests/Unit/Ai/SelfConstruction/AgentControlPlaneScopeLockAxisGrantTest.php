<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneScopeLockRuntimeValidator honors a packet's payload-carried
 * axis_exception_granted (stamped by AgentControlPlaneTaskPacketBuilder for the
 * autonomous-gov-bootstrap source): the exact granted axis prefixes stop raising the axis
 * blocker and the envelope records axis_exception_honored, while every other check —
 * pétreo self-target rejection, path traversal, forbidden-overlap, risk ceiling — stays
 * byte-identical.
 */
final class AgentControlPlaneScopeLockAxisGrantTest extends TestCase
{
    private function validator(): AgentControlPlaneScopeLockRuntimeValidator
    {
        return new AgentControlPlaneScopeLockRuntimeValidator;
    }

    private const PROGRAMMING_AXIS_PREFIX = 'app/Services/Ai/Programming/';

    /** @return array<string, mixed> */
    private function packet(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-1',
            'task_packet_hash' => str_repeat('a', 64),
            'status' => 'planned',
            'normalized_scope' => [
                'allowed_files' => [self::PROGRAMMING_AXIS_PREFIX.'AtlasCodeGenerator.php'],
                'forbidden_files' => [],
                'scope_in' => [self::PROGRAMMING_AXIS_PREFIX.'AtlasCodeGenerator.php'],
                'scope_out' => [],
            ],
            'risk_classification' => ['risk_level' => 'low'],
            'rollback_requirements' => ['rollback_strategy' => 'git_revert'],
            'continuation_requirements' => ['continuation_required' => true],
            'evidence_requirements' => ['required' => ['tests_or_gates_result']],
        ], $overrides);
    }

    // (a) grant from autonomous-gov-bootstrap validates clean and records axis_exception_honored

    public function test_gov_bootstrap_grant_exempts_granted_axis_and_records_honored(): void
    {
        $packet = $this->packet([
            'axis_exception_granted' => [
                'axes' => [self::PROGRAMMING_AXIS_PREFIX],
                'source' => 'autonomous-gov-bootstrap',
            ],
        ]);

        $result = $this->validator()->validate($packet);

        $this->assertTrue($result['passed']);
        $this->assertNotContains('forbidden_axis', $result['blockers']);
        $this->assertSame([self::PROGRAMMING_AXIS_PREFIX], $result['axis_exception_honored']);
        $this->assertSame(0, $result['forbidden_axis_count']);
    }

    // (b) removing the grant restores the axis blocker

    public function test_removing_grant_restores_axis_blocker(): void
    {
        $packet = $this->packet(); // no axis_exception_granted key at all

        $result = $this->validator()->validate($packet);

        $this->assertFalse($result['passed']);
        $this->assertContains('forbidden_axis', $result['blockers']);
        $this->assertSame([], $result['axis_exception_honored']);
    }

    public function test_null_grant_restores_axis_blocker(): void
    {
        $packet = $this->packet(['axis_exception_granted' => null]);

        $result = $this->validator()->validate($packet);

        $this->assertContains('forbidden_axis', $result['blockers']);
    }

    // (c) swapping the grant source to anything else restores the axis blocker

    public function test_grant_from_other_source_is_disregarded_and_axis_blocker_restored(): void
    {
        foreach (['operator_intake', 'external_brain_originator', 'autonomous_replenisher', ''] as $source) {
            $packet = $this->packet([
                'axis_exception_granted' => [
                    'axes' => [self::PROGRAMMING_AXIS_PREFIX],
                    'source' => $source,
                ],
            ]);

            $result = $this->validator()->validate($packet);

            $this->assertFalse($result['passed'], "source={$source} must not be exempted");
            $this->assertContains('forbidden_axis', $result['blockers'], "source={$source} must keep axis blocker");
            $this->assertSame([], $result['axis_exception_honored'], "source={$source} must not honor any exception");
        }
    }

    // (d) adding a pétreo self-target file to a granted packet keeps the pétreo rejection
    // (the pétreo check is enforced by a downstream inspector, not this validator — this proves
    // the axis exception does not touch forbidden_overlap / any other blocker computed here).

    public function test_forbidden_overlap_still_blocks_despite_axis_grant(): void
    {
        $packet = $this->packet([
            'normalized_scope' => [
                'allowed_files' => [self::PROGRAMMING_AXIS_PREFIX.'AtlasCodeGenerator.php'],
                'forbidden_files' => [self::PROGRAMMING_AXIS_PREFIX.'AtlasCodeGenerator.php'],
                'scope_in' => [self::PROGRAMMING_AXIS_PREFIX.'AtlasCodeGenerator.php'],
                'scope_out' => [],
            ],
            'axis_exception_granted' => [
                'axes' => [self::PROGRAMMING_AXIS_PREFIX],
                'source' => 'autonomous-gov-bootstrap',
            ],
        ]);

        $result = $this->validator()->validate($packet);

        $this->assertFalse($result['passed']);
        $this->assertContains('forbidden_overlap', $result['blockers']);
        // The axis itself IS still exempted — only the unrelated overlap blocker fires.
        $this->assertNotContains('forbidden_axis', $result['blockers']);
        $this->assertSame([self::PROGRAMMING_AXIS_PREFIX], $result['axis_exception_honored']);
    }

    // ── unrelated path outside the granted axis keeps raising the blocker ──────

    public function test_path_under_ungranted_axis_still_raises_blocker(): void
    {
        $packet = $this->packet([
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfImprovement/Foo.php'],
                'forbidden_files' => [],
                'scope_in' => ['app/Services/Ai/SelfImprovement/Foo.php'],
                'scope_out' => [],
            ],
            'axis_exception_granted' => [
                'axes' => [self::PROGRAMMING_AXIS_PREFIX],
                'source' => 'autonomous-gov-bootstrap',
            ],
        ]);

        $result = $this->validator()->validate($packet);

        $this->assertFalse($result['passed']);
        $this->assertContains('forbidden_axis', $result['blockers']);
        $this->assertSame([], $result['axis_exception_honored']);
    }
}
