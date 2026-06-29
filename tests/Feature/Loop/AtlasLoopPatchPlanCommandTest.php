<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchPlanner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the native patch planner is live at the operator surface: a packet with allowed_files and a
 * template-matching task_shape plans target files + a matched template; a packet with no allowed_files is
 * refused (planning only — no patch applied).
 */
final class AtlasLoopPatchPlanCommandTest extends TestCase
{
    private function plan(array $packet): array
    {
        $exit = Artisan::call('atlas:loop:patch-plan', [
            '--packet' => (string) json_encode($packet),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_packet_plans_over_its_allowed_files(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->plan([
            'allowed_files' => ['app/Foo.php'],
            'task_shape' => ['kind' => 'value_object'],
            'test_files' => ['tests/Unit/FooTest.php'],
            'context' => ['namespace' => 'App', 'class_name' => 'Foo'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionNativePatchPlanner::SCHEMA, $d['schema']);
        $this->assertContains('app/Foo.php', $d['target_files'], (string) json_encode($d));
        $this->assertContains('value_object', $d['template_ids']);
        $this->assertSame(['tests/Unit/FooTest.php'], $d['test_plan']['test_files']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['plan_id']);
    }

    public function test_empty_allowed_files_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->plan([
            'allowed_files' => [],
            'task_shape' => ['kind' => 'value_object'],
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertSame('plan_refused', $d['reason']);
        $this->assertStringContainsString('allowed_files empty', $d['message']);
    }

    public function test_no_matching_template_is_refused(): void
    {
        ['d' => $d] = $this->plan([
            'allowed_files' => ['app/Foo.php'],
            'task_shape' => ['kind' => 'totally_unknown_shape'],
        ]);

        $this->assertSame('plan_refused', $d['reason']);
        $this->assertStringContainsString('no_matching_template_for_task_shape', $d['message']);
    }

    public function test_missing_packet_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:patch-plan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
