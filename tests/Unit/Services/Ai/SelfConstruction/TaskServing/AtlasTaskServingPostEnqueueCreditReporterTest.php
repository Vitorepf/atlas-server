<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingPostEnqueueCreditReporter;
use Tests\TestCase;

final class AtlasTaskServingPostEnqueueCreditReporterTest extends TestCase
{
    private function reporter(): AtlasTaskServingPostEnqueueCreditReporter
    {
        return new AtlasTaskServingPostEnqueueCreditReporter;
    }

    private function cleanInput(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'event' => 'prepared_and_enqueued',
            'post_round_health' => 'healthy',
            'enqueue_conflict' => false,
            'emitted_target_collisions' => [],
            'global_pre_existing_collisions' => [],
            'emitted_targets' => ['implementation', 'testing'],
        ];
    }

    // ── AC: prepared events with clean gates credit ──

    public function test_prepared_event_with_clean_gates_credits(): void
    {
        $result = $this->reporter()->report($this->cleanInput());

        $this->assertSame('credited', $result['verdict']);
        $this->assertSame(1, $result['credits_granted']);
        $this->assertSame([], $result['denial_reasons']);
    }

    // ── AC: enqueue conflicts do not credit ──

    public function test_enqueue_conflict_does_not_credit(): void
    {
        $result = $this->reporter()->report(array_merge($this->cleanInput(), [
            'enqueue_conflict' => true,
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credits_granted']);
    }

    // ── AC: global pre-existing collisions are separated from emitted-target collisions ──

    public function test_global_pre_existing_collisions_separated_from_emitted(): void
    {
        $result = $this->reporter()->report(array_merge($this->cleanInput(), [
            'global_pre_existing_collisions' => ['some_old_target'],
            'emitted_target_collisions' => [],
        ]));

        // Global pre-existing collisions don't deny credit — only emitted-target collisions do.
        $this->assertSame('credited', $result['verdict']);
        $this->assertSame(['some_old_target'], $result['global_pre_existing_collisions']);
        $this->assertSame([], $result['emitted_target_collisions']);
    }

    public function test_emitted_target_collision_denies_credit(): void
    {
        $result = $this->reporter()->report(array_merge($this->cleanInput(), [
            'emitted_target_collisions' => [
                ['target_family' => 'implementation', 'severity' => 'critical'],
            ],
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertContains('implementation', $result['emitted_target_collisions']);
    }

    public function test_non_enqueued_event_denies(): void
    {
        $result = $this->reporter()->report(array_merge($this->cleanInput(), [
            'event' => 'rejected',
        ]));

        $this->assertSame('denied', $result['verdict']);
    }

    public function test_unhealthy_post_round_denies(): void
    {
        $result = $this->reporter()->report(array_merge($this->cleanInput(), [
            'post_round_health' => 'degraded',
        ]));

        $this->assertSame('denied', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->reporter()->report($this->cleanInput());

        $this->assertSame(AtlasTaskServingPostEnqueueCreditReporter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('credits_granted', $result);
        $this->assertArrayHasKey('denial_reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = $this->cleanInput();
        $a = $this->reporter()->report($input);
        $b = $this->reporter()->report($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
