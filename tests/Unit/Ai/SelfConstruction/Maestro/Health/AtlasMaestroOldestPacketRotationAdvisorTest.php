<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroOldestPacketRotationAdvisor;
use Tests\TestCase;

final class AtlasMaestroOldestPacketRotationAdvisorTest extends TestCase
{
    private function advisor(): AtlasMaestroOldestPacketRotationAdvisor
    {
        return new AtlasMaestroOldestPacketRotationAdvisor;
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'queue_age' => ['p95' => 10.0],
            'oldest_packet_ids' => ['tp-old-1', 'tp-old-2'],
            'claimable_depth' => 5,
            'active_leases' => 2,
            'serve_rate_per_minute' => 3.0,
        ], $overrides);
    }

    public function test_fresh_queue_with_low_depth_results_in_observe(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_OBSERVE, $result['action']);
    }

    public function test_stale_high_depth_low_consumption_with_no_active_leases_recommends_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 0,
            'serve_rate_per_minute' => 0.2,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertSame(['tp-old-1', 'tp-old-2'], $result['packet_ids_to_surface']);
    }

    public function test_stale_high_depth_low_consumption_with_active_leases_recommends_surface(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 4,
            'serve_rate_per_minute' => 0.2,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES, $result['action']);
        $this->assertNotEmpty($result['packet_ids_to_surface']);
    }

    public function test_stale_with_unknown_consumption_still_triggers_rotation_path(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 0,
            'serve_rate_per_minute' => null,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertContains('consumption_unknown', $result['reason_codes']);
    }

    public function test_stale_but_low_depth_recommends_do_not_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 3,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_DO_NOT_ROTATE, $result['action']);
    }

    public function test_stale_high_depth_but_healthy_consumption_does_not_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'serve_rate_per_minute' => 5.0,
        ]));

        $this->assertNotSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertNotSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES, $result['action']);
    }

    public function test_action_set_never_includes_origination(): void
    {
        $allActions = [
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_OBSERVE,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_DO_NOT_ROTATE,
        ];
        foreach ($allActions as $action) {
            $this->assertStringNotContainsString('originat', $action);
        }
    }

    public function test_output_includes_all_three_required_fields(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('reason_codes', $result);
        $this->assertArrayHasKey('packet_ids_to_surface', $result);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $advisor = $this->advisor();
        $facts = $this->facts(['queue_age' => ['p95' => 90.0], 'claimable_depth' => 30, 'active_leases' => 0]);

        $this->assertSame($advisor->advise($facts), $advisor->advise($facts));
    }

    public function test_source_performs_no_mutation_sleep_spawn_provider_fs_or_git_io(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Health/AtlasMaestroOldestPacketRotationAdvisor.php'));
        foreach (['->enqueue(', '->dequeue(', 'sleep(', 'usleep(', 'exec(', 'shell_exec(', 'proc_open(', 'Http::', 'file_put_contents('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "advisor must not perform {$forbidden}");
        }
    }
}
